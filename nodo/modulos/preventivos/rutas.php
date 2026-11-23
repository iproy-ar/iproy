<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use Nucleo\Fallo;
use function Modulos\Preventivos\{validar, generar, proyectar, importar_planes, orden_tareas, registrar_tareas, siguiente_no_vencida,
    cronograma, crear_tarea, modificar_tarea, marcar_desde, alternar_semana, copiar_anio, marcar_lote, vista_semana, generar_semana, registrar_semana, cumplimiento, semana_de,
    guardar_relevamiento, relevamiento, relevamientos, aprobar_relevamiento, rechazar_relevamiento, plantillas, guardar_plantilla, importar_tareas};
use function Nucleo\{filas, fila, q, pdo, asentar};

return [
    ['GET', '/planes', 'preventivos.ver', fn(array $c) => ['planes' => filas("SELECT p.*, (SELECT task_id FROM prev_generada g WHERE g.plan_id = p.id AND g.estado = 'abierta' ORDER BY g.id DESC LIMIT 1) AS orden_abierta,
        (SELECT COUNT(*) FROM prev_generada g WHERE g.plan_id = p.id AND g.estado = 'hecha') AS hechas FROM prev_plan p ORDER BY p.proxima IS NULL, p.proxima, p.titulo")]],
    ['GET', '/calendario', 'preventivos.ver', fn(array $c) => ['calendario' => proyectar(max(1, min(366, (int) ($c['query']['dias'] ?? 90))))]],
    ['GET', '/generadas', 'preventivos.ver', function (array $c) {   // ?plan=N: las de un plan, con el resumen de su registro por tarea
        $pl = (int) ($c['query']['plan'] ?? 0); $w = $pl ? ' WHERE g.plan_id = ?' : ''; $a = $pl ? [$pl] : [];
        return ['generadas' => filas("SELECT g.*, p.titulo, (SELECT COUNT(*) FROM prev_tarea t WHERE t.generada_id = g.id) tareas,
            (SELECT COUNT(*) FROM prev_tarea t WHERE t.generada_id = g.id AND t.resultado = 'hecha') hechas, (SELECT COUNT(*) FROM prev_tarea t WHERE t.generada_id = g.id AND t.resultado = 'no_hecha') no_hechas
            FROM prev_generada g JOIN prev_plan p ON p.id = g.plan_id$w ORDER BY g.id DESC LIMIT 200", $a)];
    }],
    ['POST', '/planes', 'preventivos.editar', function (array $c) {
        $f = validar($c['cuerpo'], true);
        q('INSERT INTO prev_plan (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')', array_values($f));
        $id = (int) pdo()->lastInsertId(); asentar('preventivo.plan', $f, (string) $id);
        return ['_http' => 201, 'plan' => fila('SELECT * FROM prev_plan WHERE id = ?', [$id])];
    }],
    ['PUT', '/planes/{id}', 'preventivos.editar', function (array $c) {
        $id = (int) $c['params']['id']; fila('SELECT 1 FROM prev_plan WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe el plan');
        $f = validar($c['cuerpo'], false);
        $p = array_replace(fila('SELECT * FROM prev_plan WHERE id = ?', [$id]), $f);   // no se habilita un plan sin frecuencia (no generaría nada y confunde)
        if (!empty($p['habilitado']) && empty($p['cada_dias']) && (empty($p['cada_valor']) || empty($p['punto']))) throw new Fallo(422, 'Para habilitar el plan elegí una frecuencia');
        if (!empty($p['cada_dias']) && empty($p['proxima'])) $f['proxima'] = date('Y-m-d', strtotime('+' . (int) $p['cada_dias'] . ' days'));
        if ($f) q('UPDATE prev_plan SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?', [...array_values($f), $id]);
        asentar('preventivo.plan_modificado', $f, (string) $id);
        return ['plan' => fila('SELECT * FROM prev_plan WHERE id = ?', [$id])];
    }],
    // 1.2.0: importación (la usa Configuración › Migración, destino «Planes preventivos»; también se puede llamar directo)
    ['POST', '/importar', 'preventivos.editar', fn(array $c) => importar_planes((array) ($c['cuerpo']['filas'] ?? []), !empty($c['cuerpo']['simular']), ($c['cuerpo']['agrupar'] ?? true) !== false, !empty($c['cuerpo']['habilitar']))],
    ['POST', '/planes/habilitar', 'preventivos.editar', function (array $c) {   // en bloque (planes importados que quedaron en pausa); los que no tienen frecuencia se saltean
        $ids = array_values(array_filter(array_map('intval', (array) ($c['cuerpo']['ids'] ?? [])))); if (!$ids || count($ids) > 2000) throw new Fallo(422, 'ids: de 1 a 2000 planes');
        $reprog = 0;
        if (!empty($c['cuerpo']['reprogramar_vencidas'])) {   // las vencidas pasan a la primera fecha de su ciclo que no está vencida (si no, generan todas su orden en la próxima pasada)
            foreach (filas('SELECT id, proxima, cada_dias FROM prev_plan WHERE habilitado = 0 AND cada_dias > 0 AND proxima < CURDATE() AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $p)
                $reprog += q('UPDATE prev_plan SET proxima = ? WHERE id = ?', [siguiente_no_vencida($p['proxima'], (int) $p['cada_dias']), $p['id']])->rowCount();
        }
        $n = q('UPDATE prev_plan SET habilitado = 1 WHERE habilitado = 0 AND ((cada_dias > 0 AND proxima IS NOT NULL) OR (cada_valor > 0 AND punto IS NOT NULL)) AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids)->rowCount();
        asentar('preventivo.habilitados', ['planes' => $n, 'reprogramadas' => $reprog]); return ['habilitados' => $n, 'reprogramadas' => $reprog];
    }],
    // 2.0.0: plan preventivo por semanas (cronograma anual, una orden por equipo y semana, registro por tarea con fecha real)
    ['GET', '/pmp/cronograma', 'preventivos.ver', fn(array $c) => cronograma((int) ($c['query']['anio'] ?? date('Y')), $c['query'])],
    ['POST', '/pmp/tareas', 'preventivos.editar', fn(array $c) => ['_http' => 201, 'tarea' => crear_tarea($c['cuerpo'], (array) $c['usuario'])]],
    ['PUT', '/pmp/tareas/{id}', 'preventivos.editar', fn(array $c) => ['tarea' => modificar_tarea((int) $c['params']['id'], $c['cuerpo'], (array) $c['usuario'])]],
    ['POST', '/pmp/tareas/{id}/marcar', 'preventivos.editar', fn(array $c) => ['semanas' => marcar_desde((int) $c['params']['id'], (int) ($c['cuerpo']['anio'] ?? 0), (int) ($c['cuerpo']['semana_inicio'] ?? 0))]],
    ['POST', '/pmp/tareas/{id}/semana', 'preventivos.editar', fn(array $c) => ['semanas' => alternar_semana((int) $c['params']['id'], (int) ($c['cuerpo']['anio'] ?? 0), (int) ($c['cuerpo']['semana'] ?? 0))]],
    ['POST', '/pmp/tareas/lote', 'preventivos.editar', fn(array $c) => marcar_lote($c['cuerpo'], (array) $c['usuario'])],
    ['POST', '/pmp/copiar', 'preventivos.editar', fn(array $c) => copiar_anio((int) ($c['cuerpo']['anio'] ?? 0))],
    ['GET', '/pmp/semana', 'preventivos.ver', function (array $c) { [$y, $w] = semana_de(date('Y-m-d')); return vista_semana((int) ($c['query']['anio'] ?? $y), (int) ($c['query']['semana'] ?? $w), (array) $c['usuario']); }],
    ['POST', '/pmp/semana/generar', 'preventivos.editar', fn(array $c) => generar_semana((int) ($c['cuerpo']['anio'] ?? 0), (int) ($c['cuerpo']['semana'] ?? 0))],
    ['PUT', '/pmp/ordenes/{id}', 'preventivos.registrar', fn(array $c) => registrar_semana((int) $c['params']['id'], (array) ($c['cuerpo']['tareas'] ?? []), (array) $c['usuario'])],
    ['GET', '/pmp/cumplimiento', 'preventivos.ver', fn(array $c) => ['anio' => (int) ($c['query']['anio'] ?? date('Y')), 'semanas' => cumplimiento((int) ($c['query']['anio'] ?? date('Y')))]],
    ['POST', '/pmp/importar', 'preventivos.editar', fn(array $c) => importar_tareas((array) ($c['cuerpo']['filas'] ?? []), !empty($c['cuerpo']['simular']))],
    // relevamiento en recorrida: el operario (Gestor o Ejecutor) envía; el Gestor aprueba o rechaza
    ['GET', '/pmp/relevamientos', 'preventivos.relevar', fn(array $c) => ['relevamientos' => relevamientos((array) $c['usuario'], (string) ($c['query']['estado'] ?? ''))]],
    ['POST', '/pmp/relevamientos', 'preventivos.relevar', fn(array $c) => ['relevamiento' => guardar_relevamiento($c['cuerpo'], (array) $c['usuario'])]],
    ['GET', '/pmp/relevamientos/{id}', 'preventivos.relevar', fn(array $c) => ['relevamiento' => relevamiento((int) $c['params']['id'], (array) $c['usuario'])]],
    ['POST', '/pmp/relevamientos/{id}/aprobar', 'preventivos.editar', fn(array $c) => ['relevamiento' => aprobar_relevamiento((int) $c['params']['id'], $c['cuerpo'], (array) $c['usuario'])]],
    ['POST', '/pmp/relevamientos/{id}/rechazar', 'preventivos.editar', fn(array $c) => ['relevamiento' => rechazar_relevamiento((int) $c['params']['id'], $c['cuerpo'], (array) $c['usuario'])]],
    ['GET', '/pmp/plantillas', 'preventivos.relevar', fn(array $c) => ['plantillas' => plantillas()]],
    ['PUT', '/pmp/plantillas', 'preventivos.editar', fn(array $c) => guardar_plantilla($c['cuerpo'])],
    // 1.3.0: registro por tarea de cada orden preventiva (GM; el Ejecutor, solo en órdenes de su empresa)
    ['GET', '/generadas/{id}/tareas', 'preventivos.ver', fn(array $c) => orden_tareas((int) $c['params']['id'], (array) $c['usuario'])],
    ['PUT', '/generadas/{id}/tareas', 'preventivos.registrar', fn(array $c) => registrar_tareas((int) $c['params']['id'], (array) ($c['cuerpo']['tareas'] ?? []), (array) $c['usuario'])],
    ['POST', '/planes/{id}/generar', 'preventivos.editar', fn(array $c) => ['_http' => 201] + generar(fila('SELECT * FROM prev_plan WHERE id = ?', [(int) $c['params']['id']]) ?? throw new Fallo(404, 'No existe el plan'), 'manual')],
];
