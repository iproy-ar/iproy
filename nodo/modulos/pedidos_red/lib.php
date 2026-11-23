<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

namespace Modulos\PedidosRed;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, asentar, pdo, rpc, rpc_estado, vinculo, vigente, nodo};

const PRIORIDADES = ['routine' => 'Normal', 'urgent' => 'Urgente', 'asap' => 'Lo antes posible', 'stat' => 'Inmediata'];
/** Estados PIM de la orden que ya no cambian: el seguimiento deja de consultar. */
const FINALES = ['finalizada', 'cerrada', 'rechazada', 'anulada', 'cancelada'];

/** Vínculos activos de la colmena a los que se les puede pedir (este nodo no sabe qué rol le dio el otro: si no es SO, el otro responde 403). */
function nodos(): array {
    return array_map(fn($v) => ['par' => $v['par'], 'nombre' => $v['nombre'], 'via' => $v['via'], 'ultimo_contacto' => $v['ultimo_contacto']],
        array_values(array_filter(filas("SELECT * FROM nucleo_vinculo WHERE estado = 'activo' ORDER BY nombre"), fn($v) => vigente($v))));
}
function exigir_par(string $par): array {
    $v = vinculo($par) ?? throw new Fallo(404, "No hay vínculo con $par");
    if (!vigente($v)) throw new Fallo(409, "El vínculo con {$v['par']} no está activo");
    return $v;
}
/** Texto del error que devolvió el otro nodo, en castellano llano. */
function motivo_remoto(array $r): string {
    $c = $r['respuesta']['cuerpo'] ?? [];
    $d = $c['issue'][0]['diagnostics'] ?? ($c['error'] ?? ($c['mensaje'] ?? ''));
    if ((int) ($r['http'] ?? 0) === 403) return 'El otro nodo no le dio a este el rol SO (solicitante): pedile a su administrador que lo cambie en Colmena' . ($d ? " ($d)" : '');
    return $d !== '' ? (string) $d : 'HTTP ' . ($r['http'] ?? '?');
}

/** Busca equipos e instalaciones en el otro nodo (por nombre y por código). Por la intranet responde enseguida; por el hub puede tardar. */
function buscar(string $par, string $q): array {
    exigir_par($par); $q = trim($q); if (mb_strlen($q) < 2) throw new Fallo(422, 'Escribí al menos 2 letras del nombre o del código');
    $l = []; $err = null;
    foreach (['Device?device-name=' . rawurlencode($q) . '&_count=30', 'Device?identifier=' . rawurlencode($q) . '&_count=10', 'Location?name=' . rawurlencode($q) . '&_count=20'] as $ruta) {
        $r = rpc($par, 'GET', $ruta, null, null, 25);
        if ($r['estado'] !== 'respondido') { $err = $r['estado'] === 'en-cola' ? 'No hay camino al otro nodo ahora: el pedido quedó en la cola de salida' : 'El otro nodo todavía no respondió (hub): probá de nuevo en unos segundos'; break; }
        if ((int) $r['http'] >= 300) { $err = motivo_remoto($r); break; }
        foreach ($r['respuesta']['cuerpo']['entry'] ?? [] as $e) {
            $x = $e['resource'] ?? []; $ref = ($x['resourceType'] ?? '') . '/' . ($x['id'] ?? '');
            if (isset($l[$ref]) || in_array($x['status'] ?? '', ['inactive', 'entered-in-error'], true)) continue;
            $l[$ref] = ['ref' => $ref, 'tipo' => $x['resourceType'] === 'Device' ? 'equipo' : 'lugar', 'nombre' => $x['deviceName'][0]['name'] ?? ($x['name'] ?? $ref),
                        'codigo' => $x['identifier'][0]['value'] ?? null, 'lugar' => $x['location']['display'] ?? null];
        }
    }
    if ($err && !$l) throw new Fallo(502, $err);
    return array_values($l);
}

/** Crea el pedido en el otro nodo (ServiceRequest; el Servidor PIM del otro lado genera la orden y devuelve su referencia). */
function pedir(array $c, array $u): array {
    $par = strtoupper(trim((string) ($c['par'] ?? ''))); $v = exigir_par($par);
    $ref = trim((string) ($c['activo'] ?? '')); if (!preg_match('#^(Device|Location)/[A-Za-z0-9\-\.]{1,64}$#', $ref)) throw new Fallo(422, 'Elegí el equipo o la instalación del otro nodo');
    $det = trim((string) ($c['detalle'] ?? '')); if (mb_strlen($det) < 5) throw new Fallo(422, 'Describí el trabajo (al menos 5 letras)');
    $pr = (string) ($c['prioridad'] ?? 'routine'); if (!isset(PRIORIDADES[$pr])) throw new Fallo(422, 'prioridad: ' . implode(' | ', array_keys(PRIORIDADES)));
    $yo = nodo(); $quien = trim((string) ($u['nombre'] ?? '')) ?: (string) ($u['usuario'] ?? '');
    $sr = ['resourceType' => 'ServiceRequest', 'status' => 'active', 'intent' => 'order', 'priority' => $pr, 'subject' => ['reference' => $ref],
           'code' => ['text' => mb_substr('Pedido de ' . (($yo['nombre'] ?? '') ?: $yo['id']) . " ($quien): " . $det, 0, 200)],   // el Servidor PIM del otro lado pone como solicitante a este nodo (cliente de la colmena); quién lo pidió va en el texto
           'requester' => ['display' => mb_substr(($yo['nombre'] ?? $yo['id']) . ' · ' . $quien, 0, 200)],
           'note' => [['text' => mb_substr($det . "\n\nPedido por la colmena desde el nodo {$yo['id']} (" . ($yo['nombre'] ?? '') . ") por $quien.", 0, 3800)]]];
    q('INSERT INTO red_pedido (par, par_nombre, activo_ref, activo_nombre, activo_codigo, detalle, prioridad, creado_por) VALUES (?,?,?,?,?,?,?,?)',
      [$par, $v['nombre'], $ref, mb_substr(trim((string) ($c['activo_nombre'] ?? '')), 0, 200) ?: null, mb_substr(trim((string) ($c['activo_codigo'] ?? '')), 0, 80) ?: null, mb_substr($det, 0, 2000), $pr, (string) ($u['usuario'] ?? '')]);
    $id = (int) pdo()->lastInsertId();
    try { $r = rpc($par, 'POST', 'ServiceRequest', $sr, null, 25); }
    catch (\Throwable $e) { q("UPDATE red_pedido SET estado = 'error', error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 300), $id]); throw $e; }
    q('UPDATE red_pedido SET rpc_alta = ? WHERE id = ?', [$r['id'], $id]);
    asentar('pedidos_red.pedido', ['par' => $par, 'activo' => $ref, 'estado_rpc' => $r['estado']], (string) $id);
    return actualizar($id);
}

/** Pone al día un pedido: completa la orden con la respuesta del alta y consulta su estado en el otro nodo. $consultar = false solo lee lo que ya llegó. */
function actualizar(int $id, bool $consultar = true): array {
    $p = pedido($id);
    if (!$p['task_id'] && $p['rpc_alta']) {
        $r = rpc_estado($p['rpc_alta']);
        if ($r && $r['estado'] === 'respondido') {
            if ((int) $r['http'] >= 300) { q("UPDATE red_pedido SET estado = 'rechazado', error = ? WHERE id = ?", [mb_substr(motivo_remoto($r), 0, 300), $id]); return pedido($id); }
            $cab = array_change_key_case((array) ($r['respuesta']['cab'] ?? [])); $task = substr((string) ($cab['x-pim-task'] ?? ''), 5);
            q("UPDATE red_pedido SET sr_id = ?, task_id = ?, estado = 'solicitada', error = NULL WHERE id = ?", [(string) ($r['respuesta']['cuerpo']['id'] ?? '') ?: null, $task ?: null, $id]);
        } elseif ($r && in_array($r['estado'], ['vencido', 'anulado', 'error'], true)) { q("UPDATE red_pedido SET estado = 'error', error = ? WHERE id = ?", ['El pedido no llegó al otro nodo (' . $r['estado'] . ')', $id]); return pedido($id); }
        else { q('UPDATE red_pedido SET estado = ? WHERE id = ?', [$r && $r['estado'] === 'en-cola' ? 'en-cola' : ($r && $r['via'] === 'hub' ? 'por-hub' : 'enviando'), $id]); return pedido($id); }
        $p = pedido($id);
    }
    if (!$p['task_id']) return $p;
    // respuesta de la última consulta (por el hub llega después)
    if ($p['rpc_consulta'] && ($r = rpc_estado($p['rpc_consulta'])) && $r['estado'] === 'respondido' && (int) $r['http'] < 300) aplicar_task($id, (array) ($r['respuesta']['cuerpo'] ?? []));
    $p = pedido($id);
    if ($consultar && !in_array($p['estado'], FINALES, true)) {
        try { $r = rpc($p['par'], 'GET', 'Task/' . $p['task_id'], null, null, 15); q('UPDATE red_pedido SET rpc_consulta = ?, consultado = NOW() WHERE id = ?', [$r['id'], $id]);
              if ($r['estado'] === 'respondido' && (int) $r['http'] < 300) aplicar_task($id, (array) ($r['respuesta']['cuerpo'] ?? [])); }
        catch (\Throwable $e) { q('UPDATE red_pedido SET error = ? WHERE id = ?', [mb_substr('No se pudo consultar: ' . $e->getMessage(), 0, 300), $id]); }
    }
    return pedido($id);
}
function aplicar_task(int $id, array $t): void {
    if (($t['resourceType'] ?? '') !== 'Task') return;
    $est = (string) ($t['businessStatus']['coding'][0]['code'] ?? ($t['status'] ?? ''));
    q('UPDATE red_pedido SET numero = COALESCE(?, numero), estado = ?, motivo = ?, responsable = ?, error = NULL WHERE id = ?',
      [$t['identifier'][0]['value'] ?? null, $est ?: 'solicitada', mb_substr((string) ($t['statusReason']['text'] ?? ''), 0, 300) ?: null,
       mb_substr((string) ($t['owner']['display'] ?? ''), 0, 200) ?: null, $id]);
}
function pedido(int $id): array { return fila('SELECT * FROM red_pedido WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe ese pedido'); }
function pedidos(array $g): array {
    $w = []; $a = [];
    if (($v = strtoupper(trim((string) ($g['par'] ?? '')))) !== '') { $w[] = 'par = ?'; $a[] = $v; }
    if (($g['abiertos'] ?? '') === '1') $w[] = "estado NOT IN ('" . implode("','", FINALES) . "','rechazado','error')";
    return filas('SELECT * FROM red_pedido' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY creado DESC, id DESC LIMIT 300', $a);
}
/** Tarea periódica: pone al día los pedidos abiertos (los finalizados ya no se consultan). */
function seguimiento(): array {
    $n = 0; $err = 0;
    foreach (filas("SELECT id FROM red_pedido WHERE estado NOT IN ('" . implode("','", FINALES) . "','rechazado','error') AND creado > DATE_SUB(NOW(), INTERVAL 180 DAY) ORDER BY consultado IS NOT NULL, consultado LIMIT 40") as $p) {
        try { actualizar((int) $p['id']); $n++; } catch (\Throwable) { $err++; }
    }
    return ['revisados' => $n, 'errores' => $err];
}
