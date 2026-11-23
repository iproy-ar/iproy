<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Requerimientos por servicio. Las solicitudes y las órdenes viven en el Servidor PIM (la interfaz las crea y las lee por su API);
 * este módulo guarda una sola cosa propia: el servicio de cada usuario (req_usuario).
 */
declare(strict_types=1);

namespace Modulos\Requerimientos;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, valor, pdo, asentar, puede, pim};

const RX_ID = '/^[0-9A-Za-z][0-9A-Za-z._-]{0,63}$/';

function yo(array $u): array {
    $r = fila('SELECT local_id, local_nombre, ultimo FROM req_usuario WHERE usuario = ?', [(string) ($u['usuario'] ?? '')]);
    return ['servicio' => $r['local_id'] ?? null, 'servicio_nombre' => $r['local_nombre'] ?? null, 'ultimo' => $r['ultimo'] ?? null, 'transversal' => puede('requerimientos.transversal', $u)];
}
function fijar_servicio(string $usuario, array $d, array $quien): void {
    if (!fila('SELECT usuario FROM nucleo_usuario WHERE usuario = ?', [$usuario])) throw new Fallo(404, 'No existe ese usuario');
    $id = trim((string) ($d['local_id'] ?? ''));
    if ($id !== '' && !preg_match(RX_ID, $id)) throw new Fallo(422, 'Servicio: identificador inválido');
    $nom = mb_substr(trim((string) ($d['local_nombre'] ?? '')), 0, 160);
    if ($id !== '' && $nom === '') throw new Fallo(422, 'Falta el nombre del servicio');
    q('INSERT INTO req_usuario (usuario, local_id, local_nombre, modificado, modificado_por) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE local_id = VALUES(local_id), local_nombre = VALUES(local_nombre), modificado = VALUES(modificado), modificado_por = VALUES(modificado_por)',
      [$usuario, $id === '' ? null : $id, $id === '' ? null : $nom, date('Y-m-d H:i:s'), (string) ($quien['usuario'] ?? '')]);
    asentar('requerimientos.servicio', ['servicio' => $id === '' ? null : $nom], $usuario);
}
/** Quien puede elegir el servicio: el sistema recuerda el último. */
function recordar(array $u, string $id): void {
    if ($id !== '' && !preg_match(RX_ID, $id)) throw new Fallo(422, 'Servicio: identificador inválido');
    q('INSERT INTO req_usuario (usuario, ultimo) VALUES (?,?) ON DUPLICATE KEY UPDATE ultimo = VALUES(ultimo)', [(string) ($u['usuario'] ?? ''), $id === '' ? null : $id]);
}

// ---------------------------------------------------------------- operarios de cada empresa y su trabajo en las órdenes
const RX_ORG = '/^[^\s\/\\\\]{1,60}$/u';
/** Código de organización sobre el que puede actuar el usuario: el Ejecutor, solo la suya. */
function org_permitida(array $u, string $org): string {
    $org = trim($org); if (!preg_match(RX_ORG, $org)) throw new Fallo(422, 'Falta la empresa o área (su código)');
    if (($u['rol'] ?? '') === 'EJ' && $org !== trim((string) ($u['organizacion'] ?? ''))) throw new Fallo(403, 'Solo podés trabajar con los operarios de tu empresa');
    return $org;
}
function operarios(array $u, string $org, bool $todos = false): array {
    $org = org_permitida($u, $org);
    return array_map(fn($r) => ['id' => (int) $r['id'], 'nombre' => $r['nombre'], 'activo' => (int) $r['activo']], filas('SELECT id, nombre, activo FROM req_operario WHERE org = ?' . ($todos ? '' : ' AND activo = 1') . ' ORDER BY nombre', [$org]));
}
function guardar_operario(array $u, ?int $id, array $d): array {
    if ($id === null) {
        $org = org_permitida($u, (string) ($d['org'] ?? '')); $nom = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($d['nombre'] ?? ''))), 0, 80);
        if (mb_strlen($nom) < 2) throw new Fallo(422, 'Escribí el nombre del operario');
        if ($ya = fila('SELECT id, activo FROM req_operario WHERE org = ? AND nombre = ?', [$org, $nom])) { if (!$ya['activo']) q('UPDATE req_operario SET activo = 1 WHERE id = ?', [$ya['id']]); return ['id' => (int) $ya['id'], 'nombre' => $nom, 'activo' => 1]; }
        q('INSERT INTO req_operario (org, nombre, activo, creado, creado_por) VALUES (?,?,1,?,?)', [$org, $nom, date('Y-m-d H:i:s'), (string) ($u['usuario'] ?? '')]);
        $id = (int) pdo()->lastInsertId(); asentar('requerimientos.operario.alta', ['org' => $org], (string) $id);
        return ['id' => $id, 'nombre' => $nom, 'activo' => 1];
    }
    $r = fila('SELECT * FROM req_operario WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe ese operario'); org_permitida($u, (string) $r['org']);
    $act = array_key_exists('activo', $d) ? (int) (bool) $d['activo'] : (int) $r['activo'];
    q('UPDATE req_operario SET activo = ? WHERE id = ?', [$act, $id]); asentar('requerimientos.operario.cambio', ['activo' => $act], (string) $id);
    return ['id' => $id, 'nombre' => $r['nombre'], 'activo' => $act];
}
function vista_trabajo(array $r): array {
    $min = $r['inicio'] && $r['fin'] ? (int) round((strtotime((string) $r['fin']) - strtotime((string) $r['inicio'])) / 60) : null;
    return ['id' => (int) $r['id'], 'orden' => $r['orden_id'], 'numero' => $r['orden_numero'], 'org' => $r['org'], 'operario_id' => (int) $r['operario_id'], 'operario' => $r['nombre'], 'inicio' => $r['inicio'], 'fin' => $r['fin'], 'minutos' => $min];
}
const SEL_TRAB = 'SELECT t.*, o.nombre FROM req_trabajo t JOIN req_operario o ON o.id = t.operario_id';
function trabajos(array $u, array $ordenes): array {
    $ids = array_values(array_unique(array_filter(array_map('strval', $ordenes), fn($x) => (bool) preg_match('/^[0-9A-Z]{26}$/', $x)))); if (!$ids) return [];
    if (count($ids) > 200) throw new Fallo(422, 'Hasta 200 órdenes por consulta');
    $w = 't.orden_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $a = $ids;
    if (($u['rol'] ?? '') === 'EJ') { $w .= ' AND t.org = ?'; $a[] = trim((string) ($u['organizacion'] ?? '')); }
    return array_map('Modulos\Requerimientos\vista_trabajo', filas(SEL_TRAB . " WHERE $w ORDER BY t.orden_id, o.nombre", $a));
}
function fecha_trab(mixed $v, string $que): ?string {
    if ($v === null || $v === '') return null; if ($v === 'ahora') return date('Y-m-d H:i:s');
    $t = strtotime(str_replace('T', ' ', (string) $v)); if ($t === false || $t < strtotime('2000-01-01') || $t > time() + 86400) throw new Fallo(422, "$que: fecha inválida");
    return date('Y-m-d H:i:s', $t);
}
function guardar_trabajo(array $u, ?int $id, array $d): array {
    if ($id === null) {
        $orden = (string) ($d['orden'] ?? ''); if (!preg_match('/^[0-9A-Z]{26}$/', $orden)) throw new Fallo(422, 'Falta la orden');
        $op = fila('SELECT * FROM req_operario WHERE id = ? AND activo = 1', [(int) ($d['operario_id'] ?? 0)]) ?? throw new Fallo(422, 'Elegí un operario de la lista');
        $org = org_permitida($u, (string) $op['org']);
        if (fila('SELECT id FROM req_trabajo WHERE orden_id = ? AND operario_id = ?', [$orden, $op['id']])) throw new Fallo(409, 'Ese operario ya está en la orden');
        // la orden tiene que ser de la empresa del operario (se consulta al Servidor PIM: el número y la empresa salen de ahí, no del pedido)
        $t = pim('GET', 'Task/' . $orden); if (($t['http'] ?? 0) !== 200 || empty($t['cuerpo']['id'])) throw new Fallo(404, 'No existe esa orden');
        $own = (string) ($t['cuerpo']['owner']['reference'] ?? ''); if (!str_starts_with($own, 'Organization/')) throw new Fallo(409, 'La orden todavía no tiene empresa asignada');
        $o = pim('GET', $own)['cuerpo'] ?? []; $cods = array_map(fn($i) => (string) ($i['value'] ?? ''), (array) ($o['identifier'] ?? []));
        if (!in_array($org, $cods, true)) throw new Fallo(409, 'La orden es de otra empresa: solo se suman operarios de la empresa asignada');
        $d['numero'] = (string) ($t['cuerpo']['identifier'][0]['value'] ?? ($d['numero'] ?? ''));
        $ini = fecha_trab($d['inicio'] ?? null, 'Inicio'); $fin = fecha_trab($d['fin'] ?? null, 'Fin');
        if ($ini && $fin && $fin < $ini) throw new Fallo(422, 'El fin no puede ser anterior al inicio');
        q('INSERT INTO req_trabajo (orden_id, orden_numero, org, operario_id, inicio, fin, creado, creado_por) VALUES (?,?,?,?,?,?,?,?)',
          [$orden, mb_substr(trim((string) ($d['numero'] ?? '')), 0, 40) ?: null, $org, (int) $op['id'], $ini, $fin, date('Y-m-d H:i:s'), (string) ($u['usuario'] ?? '')]);
        $id = (int) pdo()->lastInsertId(); asentar('requerimientos.trabajo.alta', ['operario' => (int) $op['id']], $orden);
    } else {
        $r = fila('SELECT * FROM req_trabajo WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe ese registro de trabajo'); org_permitida($u, (string) $r['org']);
        $ini = array_key_exists('inicio', $d) ? fecha_trab($d['inicio'], 'Inicio') : $r['inicio']; $fin = array_key_exists('fin', $d) ? fecha_trab($d['fin'], 'Fin') : $r['fin'];
        if ($fin && !$ini) throw new Fallo(422, 'Para marcar el fin primero tiene que estar el inicio');
        if ($ini && $fin && $fin < $ini) throw new Fallo(422, 'El fin no puede ser anterior al inicio');
        q('UPDATE req_trabajo SET inicio = ?, fin = ? WHERE id = ?', [$ini, $fin, $id]); asentar('requerimientos.trabajo.cambio', ['inicio' => $ini, 'fin' => $fin], (string) $r['orden_id']);
    }
    return vista_trabajo(fila(SEL_TRAB . ' WHERE t.id = ?', [$id]));
}
function borrar_trabajo(array $u, int $id): void {
    $r = fila('SELECT * FROM req_trabajo WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe ese registro de trabajo'); org_permitida($u, (string) $r['org']);
    q('DELETE FROM req_trabajo WHERE id = ?', [$id]); asentar('requerimientos.trabajo.baja', ['operario' => (int) $r['operario_id']], (string) $r['orden_id']);
}
/** Reporte de operarios: por operario (órdenes, tiempo total, promedio) y el registro de trabajos del período. */
function reporte_operarios(array $u, array $g): array {
    $ok = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
    $hasta = $ok($g['hasta'] ?? null) ? $g['hasta'] : date('Y-m-d'); $desde = $ok($g['desde'] ?? null) ? $g['desde'] : date('Y-m-d', strtotime($hasta . ' -29 days'));
    $w = 'COALESCE(t.inicio, t.creado) >= ? AND COALESCE(t.inicio, t.creado) <= ?'; $a = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];
    $org = ($u['rol'] ?? '') === 'EJ' ? trim((string) ($u['organizacion'] ?? '')) : trim((string) ($g['org'] ?? ''));
    if ($org !== '') { $w .= ' AND t.org = ?'; $a[] = org_permitida($u, $org); }
    $reg = array_map('Modulos\Requerimientos\vista_trabajo', filas(SEL_TRAB . " WHERE $w ORDER BY COALESCE(t.inicio, t.creado) DESC LIMIT 5000", $a));
    $por = [];
    foreach ($reg as $t) { $k = $t['operario_id']; $por[$k] ??= ['operario' => $t['operario'], 'org' => $t['org'], 'ordenes' => 0, 'cerradas' => 0, 'minutos' => 0];
        $por[$k]['ordenes']++; if ($t['minutos'] !== null) { $por[$k]['cerradas']++; $por[$k]['minutos'] += $t['minutos']; } }
    foreach ($por as &$p) $p['promedio'] = $p['cerradas'] ? (int) round($p['minutos'] / $p['cerradas']) : null; unset($p);
    usort($por, fn($x, $y) => $y['ordenes'] <=> $x['ordenes'] ?: strcmp($x['operario'], $y['operario']));
    return ['desde' => $desde, 'hasta' => $hasta, 'por_operario' => array_values($por), 'registro' => $reg];
}
