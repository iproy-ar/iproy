<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use Nucleo\Fallo;
use function Modulos\Preventivos\{validar, generar, proyectar};
use function Nucleo\{filas, fila, q, pdo, asentar};

return [
    ['GET', '/planes', 'preventivos.ver', fn(array $c) => ['planes' => filas("SELECT p.*, (SELECT task_id FROM prev_generada g WHERE g.plan_id = p.id AND g.estado = 'abierta' ORDER BY g.id DESC LIMIT 1) AS orden_abierta,
        (SELECT COUNT(*) FROM prev_generada g WHERE g.plan_id = p.id AND g.estado = 'hecha') AS hechas FROM prev_plan p ORDER BY p.proxima IS NULL, p.proxima, p.titulo")]],
    ['GET', '/calendario', 'preventivos.ver', fn(array $c) => ['calendario' => proyectar(max(1, min(366, (int) ($c['query']['dias'] ?? 90))))]],
    ['GET', '/generadas', 'preventivos.ver', fn(array $c) => ['generadas' => filas('SELECT g.*, p.titulo FROM prev_generada g JOIN prev_plan p ON p.id = g.plan_id ORDER BY g.id DESC LIMIT 200')]],
    ['POST', '/planes', 'preventivos.editar', function (array $c) {
        $f = validar($c['cuerpo'], true);
        q('INSERT INTO prev_plan (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')', array_values($f));
        $id = (int) pdo()->lastInsertId(); asentar('preventivo.plan', $f, (string) $id);
        return ['_http' => 201, 'plan' => fila('SELECT * FROM prev_plan WHERE id = ?', [$id])];
    }],
    ['PUT', '/planes/{id}', 'preventivos.editar', function (array $c) {
        $id = (int) $c['params']['id']; fila('SELECT 1 FROM prev_plan WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe el plan');
        $f = validar($c['cuerpo'], false);
        if ($f) q('UPDATE prev_plan SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?', [...array_values($f), $id]);
        asentar('preventivo.plan_modificado', $f, (string) $id);
        return ['plan' => fila('SELECT * FROM prev_plan WHERE id = ?', [$id])];
    }],
    ['POST', '/planes/{id}/generar', 'preventivos.editar', fn(array $c) => ['_http' => 201] + generar(fila('SELECT * FROM prev_plan WHERE id = ?', [(int) $c['params']['id']]) ?? throw new Fallo(404, 'No existe el plan'), 'manual')],
];
