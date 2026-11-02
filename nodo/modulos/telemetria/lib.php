<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

namespace Modulos\Telemetria;

use Nucleo\Fallo;
use function Nucleo\{pim, q, fila, publicar};

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
    return ['activo' => $act, 'punto' => $pto, 'valor' => (float) $m['valor'], 'unidad' => $u, 'ts' => date('Y-m-d H:i:s', $ts), 'origen' => mb_substr((string) ($m['origen'] ?? 'api'), 0, 40)];
}
