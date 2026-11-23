<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

namespace Modulos\Telemetria;

use Nucleo\Fallo;
use function Nucleo\{pim, q, fila, filas, publicar};

/** Activo PIM a partir de su id (26 caracteres) o de su código de inventario (identifier). Caché de 1 h; null si no existe. */
function resolver_activo(string $a): ?array {
    if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $a)) return ['id' => $a, 'nombre' => null];
    $m = fila('SELECT * FROM tel_mapa WHERE codigo = ? AND actualizado > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$a]);
    if ($m) return $m['activo_id'] ? ['id' => $m['activo_id'], 'nombre' => $m['nombre']] : null;
    $r = pim('GET', 'Device?identifier=' . rawurlencode($a) . '&_count=1', null, ['rol' => 'FT', 'exigir' => true]);
    $d = $r['cuerpo']['entry'][0]['resource'] ?? null;
    q('REPLACE INTO tel_mapa (codigo, activo_id, nombre, actualizado) VALUES (?,?,?,NOW())', [$a, $d['id'] ?? null, $d['deviceName'][0]['name'] ?? null]);
    return $d ? ['id' => $d['id'], 'nombre' => $d['deviceName'][0]['name'] ?? null] : null;
}
/** Valida una medición «cruda» (de MQTT, Modbus, HTTP…). */
function normalizar(array $m): array {
    $act = trim((string) ($m['activo'] ?? ''));
    $pto = strtoupper(trim((string) ($m['punto'] ?? '')));
    if ($act === '' || !preg_match('/^[A-Z0-9\-]{2,40}$/', $pto)) throw new Fallo(422, 'Medición: activo y punto (A-Z 0-9 -) son obligatorios');
    if (!is_numeric($m['valor'] ?? null)) throw new Fallo(422, "Medición $act/$pto sin valor numérico");
    $u = trim((string) ($m['unidad'] ?? '1')); if ($u === '' || mb_strlen($u) > 20) $u = '1';
    $ts = isset($m['ts']) ? strtotime((string) $m['ts']) : time();
    if (!$ts || $ts > time() + 300) $ts = time();
    return ['activo' => $act, 'punto' => $pto, 'valor' => (float) $m['valor'], 'unidad' => $u, 'ts' => date('Y-m-d H:i:s', $ts), 'origen' => mb_substr((string) ($m['origen'] ?? 'api'), 0, 40)]
        + (($e = trim((string) ($m['etiqueta'] ?? ''))) !== '' ? ['etiqueta' => mb_substr($e, 0, 80)] : []);   // 1.1.0: nombre legible del punto («Temperatura ambiente»)
}
/** 1.1.0: momento de una lectura histórica: epoch en ms o en s, o texto de fecha. null si no se entiende o es futuro. */
function ts_hist(mixed $t): ?int {
    if (is_int($t) || is_float($t) || (is_string($t) && preg_match('/^\d{9,13}$/', $t))) { $n = (float) $t; $n = $n > 1e11 ? $n / 1000 : $n; }
    else $n = strtotime((string) $t) ?: 0;
    return $n > 946684800 && $n <= time() + 300 ? (int) floor($n) : null;
}
/** 1.1.0: historial en lote (una serie de un punto: [[ts, valor], …]). Guarda en la serie los momentos que todavía no están
 *  (repetir la carga no duplica) y devuelve los pares nuevos, para avisarlos en telemetria/serie. No va al Servidor PIM ni a las alarmas. */
function guardar_historial(array $a, string $punto, array $pares): array {
    // Ola 4: se descartan solo los momentos que ya están (al segundo). Antes se salteaba todo lo que caía entre el primero y el último
    // guardados, y un historial que llega en varios lotes (o con huecos) quedaba incompleto para siempre.
    $l = []; foreach ($pares as $x) { if (!is_array($x) || count($x) < 2 || !is_numeric($x[1])) continue; $t = ts_hist($x[0]); if ($t !== null) $l[$t] = (float) $x[1]; }
    if (!$l) return [];
    $ya = array_flip(array_map('intval', array_column(filas('SELECT FLOOR(UNIX_TIMESTAMP(ts)) t FROM tel_serie WHERE activo_id = ? AND punto = ? AND ts BETWEEN ? AND ?',
        [$a['id'], $punto, date('Y-m-d H:i:s', min(array_keys($l))), date('Y-m-d H:i:s', max(array_keys($l)) + 1)]), 't')));
    $nuevos = []; foreach ($l as $t => $v) if (!isset($ya[$t])) $nuevos[] = [$t, $v];
    usort($nuevos, fn($p, $q) => $p[0] <=> $q[0]);
    foreach (array_chunk($nuevos, 500) as $b)
        q('INSERT INTO tel_serie (activo_id, punto, ts, valor) VALUES ' . implode(',', array_fill(0, count($b), '(?,?,?,?)')),
          array_merge(...array_map(fn($p) => [$a['id'], $punto, date('Y-m-d H:i:s', $p[0]), $p[1]], $b)));
    return $nuevos;
}
