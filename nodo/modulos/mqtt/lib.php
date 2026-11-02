<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Cliente MQTT 3.1.1 mínimo (OASIS, 2014): CONNECT, SUBSCRIBE, PUBLISH QoS 0/1, PUBACK, PINGREQ, DISCONNECT. */
declare(strict_types=1);

namespace Modulos\Mqtt;

use Nucleo\Fallo;

final class Cliente {
    private $s; private int $pid = 0; private string $buf = '';
    public array $recibidos = [];

    public function __construct(private array $c) {}
    private static function cad(string $s): string { return pack('n', strlen($s)) . $s; }
    private static function largo(int $n): string { $o = ''; do { $b = $n % 128; $n = intdiv($n, 128); $o .= chr($n > 0 ? $b | 0x80 : $b); } while ($n > 0); return $o; }
    private function enviar(int $tipo, int $flags, string $cuerpo): void {
        $p = chr(($tipo << 4) | $flags) . self::largo(strlen($cuerpo)) . $cuerpo;
        for ($i = 0; $i < strlen($p);) { $w = @fwrite($this->s, substr($p, $i)); if (!$w) throw new Fallo(502, 'MQTT: conexión cerrada al escribir'); $i += $w; }
    }
    /** Lee un paquete completo o null si vence el tiempo. */
    private function leer(float $hasta): ?array {
        while (true) {
            if (strlen($this->buf) >= 2) {
                $m = 1; $v = 0; $i = 1;
                do { if ($i >= strlen($this->buf)) { $v = -1; break; } $b = ord($this->buf[$i++]); $v += ($b & 127) * $m; $m *= 128; } while ($b & 128);
                if ($v >= 0 && strlen($this->buf) >= $i + $v) {
                    $h = ord($this->buf[0]); $cu = substr($this->buf, $i, $v); $this->buf = (string) substr($this->buf, $i + $v);
                    return ['tipo' => $h >> 4, 'flags' => $h & 15, 'cuerpo' => $cu];
                }
            }
            $resta = $hasta - microtime(true); if ($resta <= 0) return null;
            $r = [$this->s]; $w = null; $e = null;
            if (!@stream_select($r, $w, $e, (int) $resta, (int) (($resta - floor($resta)) * 1e6))) continue;
            $d = fread($this->s, 65536);
            if ($d === '' || $d === false) { if (feof($this->s)) throw new Fallo(502, 'MQTT: el broker cerró la conexión'); continue; }
            $this->buf .= $d;
        }
    }
    public function conectar(): void {
        $esq = $this->c['tls'] ? 'tls' : 'tcp';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $this->s = @stream_socket_client("$esq://{$this->c['broker']}:{$this->c['puerto']}", $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->s) throw new Fallo(502, "MQTT: no se pudo conectar a {$this->c['broker']}:{$this->c['puerto']} ($es)");
        stream_set_blocking($this->s, true);
        $fl = 0x00; $pl = self::cad($this->c['cliente_id']);
        if ($this->c['usuario'] !== '') { $fl |= 0x80; $pl .= self::cad($this->c['usuario']); }
        if ($this->c['clave'] !== '') { $fl |= 0x40; $pl .= self::cad($this->c['clave']); }
        // clean session = 0: sesión persistente, el broker guarda los QoS 1 mientras el nodo no escucha
        $this->enviar(1, 0, self::cad('MQTT') . chr(4) . chr($fl) . pack('n', (int) $this->c['keepalive']) . $pl);
        $p = $this->leer(microtime(true) + 5);
        if (!$p || $p['tipo'] !== 2) throw new Fallo(502, 'MQTT: sin CONNACK');
        $rc = ord($p['cuerpo'][1] ?? "\xff");
        if ($rc !== 0) throw new Fallo(502, 'MQTT: conexión rechazada (' . ([1 => 'versión de protocolo', 2 => 'id de cliente', 3 => 'servidor no disponible', 4 => 'usuario o clave', 5 => 'no autorizado'][$rc] ?? "código $rc") . ')');
    }
    public function suscribir(array $filtros, int $qos): void {
        if (!$filtros) return;
        $id = ++$this->pid; $pl = pack('n', $id);
        foreach ($filtros as $f) $pl .= self::cad($f) . chr($qos);
        $this->enviar(8, 2, $pl);
        $hasta = microtime(true) + 5;
        while ($p = $this->leer($hasta)) { if ($p['tipo'] === 9) return; $this->tratar($p); }
        throw new Fallo(502, 'MQTT: sin SUBACK');
    }
    public function publicar(string $tema, string $carga, int $qos = 1, bool $retener = false): void {
        $id = ++$this->pid;
        $this->enviar(3, ($qos << 1) | ($retener ? 1 : 0), self::cad($tema) . ($qos ? pack('n', $id) : '') . $carga);
        if (!$qos) return;
        $hasta = microtime(true) + 5;
        while ($p = $this->leer($hasta)) { if ($p['tipo'] === 4 && unpack('n', $p['cuerpo'])[1] === $id) return; $this->tratar($p); }
        throw new Fallo(502, 'MQTT: sin PUBACK');
    }
    private function tratar(array $p): void {
        if ($p['tipo'] !== 3) return;
        $qos = ($p['flags'] >> 1) & 3; $c = $p['cuerpo'];
        $lt = unpack('n', $c)[1]; $tema = substr($c, 2, $lt); $o = 2 + $lt; $id = null;
        if ($qos > 0) { $id = unpack('n', substr($c, $o, 2))[1]; $o += 2; }
        $this->recibidos[] = ['tema' => $tema, 'carga' => (string) substr($c, $o), 'retenido' => (bool) ($p['flags'] & 1)];
        if ($qos === 1) $this->enviar(4, 0, pack('n', $id));
        if ($qos === 2) $this->enviar(5, 0, pack('n', $id));                    // PUBREC (QoS 2 se acepta como «al menos una vez»)
    }
    /** Escucha hasta $segundos; devuelve los mensajes recibidos. */
    public function escuchar(float $segundos): array {
        $hasta = microtime(true) + $segundos;
        while (microtime(true) < $hasta) { $p = $this->leer($hasta); if (!$p) break; if ($p['tipo'] === 6) $this->enviar(7, 0, substr($p['cuerpo'], 0, 2)); else $this->tratar($p); }
        $r = $this->recibidos; $this->recibidos = []; return $r;
    }
    public function cerrar(): void { if ($this->s) { @$this->enviar(14, 0, ''); @fclose($this->s); $this->s = null; } }
}

/** Tema → {refes, activo, punto} según la plantilla configurada. */
function interpretar_tema(string $tema, string $plantilla): ?array {
    $t = explode('/', $tema); $p = explode('/', $plantilla);
    if (count($t) !== count($p)) return null;
    $r = [];
    foreach ($p as $i => $x) {
        if (preg_match('/^\{([a-z]+)\}$/', $x, $m)) $r[$m[1]] = $t[$i];
        elseif ($x !== $t[$i]) return null;
    }
    return $r;
}
/** Carga: número, o JSON {"v"|"valor", "u"|"unidad", "t"|"ts"}. */
function interpretar_carga(string $c): ?array {
    $c = trim($c);
    if (is_numeric($c)) return ['valor' => (float) $c];
    $j = json_decode($c, true);
    if (!is_array($j)) return null;
    $v = $j['v'] ?? $j['valor'] ?? $j['value'] ?? null;
    if (!is_numeric($v)) return null;
    return array_filter(['valor' => (float) $v, 'unidad' => $j['u'] ?? $j['unidad'] ?? $j['unit'] ?? null, 'ts' => $j['t'] ?? $j['ts'] ?? null], fn($x) => $x !== null);
}
