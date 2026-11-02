<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Cliente Modbus TCP mínimo: MBAP + FC3 (holding registers) / FC4 (input registers). */
declare(strict_types=1);

namespace Modulos\Modbus;

use Nucleo\Fallo;

const EXCEPCIONES = [1 => 'función no soportada', 2 => 'dirección inválida', 3 => 'valor inválido', 4 => 'falla del equipo', 6 => 'equipo ocupado', 10 => 'gateway sin ruta', 11 => 'el equipo detrás del gateway no responde'];
const PALABRAS = ['int16' => 1, 'uint16' => 1, 'int32' => 2, 'uint32' => 2, 'float32' => 2];

final class Cliente {
    private $s; private int $tid = 0;
    public function __construct(private string $host, private int $puerto, private int $unidad, private float $timeout = 3.0) {}
    public function conectar(): void {
        $this->s = @stream_socket_client("tcp://{$this->host}:{$this->puerto}", $en, $es, $this->timeout);
        if (!$this->s) throw new Fallo(502, "Modbus: sin conexión con {$this->host}:{$this->puerto} ($es)");
        stream_set_timeout($this->s, (int) ceil($this->timeout));
    }
    private function exacto(int $n): string {
        $d = '';
        while (strlen($d) < $n) { $x = fread($this->s, $n - strlen($d)); if ($x === '' || $x === false) throw new Fallo(504, 'Modbus: el equipo no respondió a tiempo'); $d .= $x; }
        return $d;
    }
    /** Lee $cant registros desde $dir con la función 3 o 4. Devuelve enteros sin signo de 16 bits. */
    public function leer(int $fc, int $dir, int $cant): array {
        if (!in_array($fc, [3, 4], true) || $cant < 1 || $cant > 125) throw new Fallo(422, 'Modbus: función 3|4 y 1..125 registros');
        $this->tid = ($this->tid + 1) & 0xffff;
        fwrite($this->s, pack('nnnCCnn', $this->tid, 0, 6, $this->unidad, $fc, $dir, $cant));
        $h = unpack('ntid/nproto/nlargo/Cunidad', $this->exacto(7));
        $pdu = $this->exacto($h['largo'] - 1);
        if ($h['tid'] !== $this->tid) throw new Fallo(502, 'Modbus: respuesta de otra transacción');
        $f = ord($pdu[0]);
        if ($f & 0x80) throw new Fallo(502, 'Modbus: excepción ' . (EXCEPCIONES[ord($pdu[1])] ?? ord($pdu[1])));
        $n = ord($pdu[1]);
        return array_values(unpack('n*', substr($pdu, 2, $n)));
    }
    public function cerrar(): void { if ($this->s) @fclose($this->s); }
}
/** Registros (palabras de 16 bits) → número, según tipo y orden de palabras (ABCD = big-endian; CDAB = palabras invertidas). */
function decodificar(array $w, string $tipo, string $orden = 'ABCD'): float {
    if (PALABRAS[$tipo] === 2 && strtoupper($orden) === 'CDAB') $w = [$w[1], $w[0]];
    $b = PALABRAS[$tipo] === 2 ? pack('nn', $w[0], $w[1]) : pack('n', $w[0]);
    return match ($tipo) {
        'uint16' => (float) $w[0],
        'int16' => (float) unpack('s', pack('S', $w[0]))[1],
        'uint32' => (float) unpack('N', $b)[1],
        'int32' => (float) unpack('l', pack('L', unpack('N', $b)[1]))[1],
        'float32' => (float) unpack('G', $b)[1],
    };
}
/** Agrupa registros contiguos (por función) para leerlos en pocas tramas. */
function bloques(array $regs): array {
    $porFc = [];
    foreach ($regs as $r) $porFc[(int) ($r['funcion'] ?? 3)][] = $r;
    $out = [];
    foreach ($porFc as $fc => $l) {
        usort($l, fn($a, $b) => $a['direccion'] <=> $b['direccion']);
        $act = null;
        foreach ($l as $r) {
            $fin = (int) $r['direccion'] + PALABRAS[$r['tipo'] ?? 'uint16'];
            if ($act && $r['direccion'] - $act['fin'] <= 8 && $fin - $act['desde'] <= 120) { $act['fin'] = max($act['fin'], $fin); $act['regs'][] = $r; }
            else { if ($act) $out[] = $act; $act = ['fc' => $fc, 'desde' => (int) $r['direccion'], 'fin' => $fin, 'regs' => [$r]]; }
        }
        if ($act) $out[] = $act;
    }
    return $out;
}
