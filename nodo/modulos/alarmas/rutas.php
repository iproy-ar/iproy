<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use Nucleo\Fallo;
use function Modulos\Alarmas\{accion, vista, validar_def, kpi, estado_de};
use function Nucleo\{filas, fila, q, pdo, asentar};

$lista = "SELECT d.*, e.estado, e.condicion, e.valor, e.desde, e.activada, e.archivada_hasta, e.motivo, e.orden FROM alm_definicion d LEFT JOIN alm_estado e ON e.def_id = d.id";
return [
    // Lista de anuncio: lo que el operador tiene que ver, ordenado por prioridad y antigüedad
    ['GET', '/activas', 'alarmas.ver', fn(array $c) => ['alarmas' => filas("$lista WHERE e.estado IN ('no-reconocida','reconocida','rtn-no-reconocida','enclavada') ORDER BY d.prioridad, e.activada")]],
    ['GET', '/definiciones', 'alarmas.ver', fn(array $c) => ['alarmas' => filas("$lista ORDER BY d.etiqueta")]],
    ['GET', '/historial', 'alarmas.ver', fn(array $c) => ['historial' => filas('SELECT h.*, d.etiqueta FROM alm_historial h JOIN alm_definicion d ON d.id = h.def_id '
        . (isset($c['query']['id']) ? 'WHERE h.def_id = ? ' : '') . 'ORDER BY h.id DESC LIMIT 300', isset($c['query']['id']) ? [(int) $c['query']['id']] : [])]],
    ['GET', '/kpi', 'alarmas.ver', fn(array $c) => ['kpi' => kpi($c['cfg'])]],
    ['POST', '/definiciones', 'alarmas.configurar', function (array $c) {
        $f = validar_def($c['cuerpo'], true);
        q('INSERT INTO alm_definicion (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')', array_values($f));
        $id = (int) pdo()->lastInsertId(); estado_de($id);
        asentar('alarma.definida', $f, $f['etiqueta']);
        return ['_http' => 201, 'alarma' => vista($id)];
    }],
    ['PUT', '/definiciones/{id}', 'alarmas.configurar', function (array $c) {
        $id = (int) $c['params']['id']; fila('SELECT 1 FROM alm_definicion WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe');
        $f = validar_def($c['cuerpo'], false);
        if ($f) q('UPDATE alm_definicion SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?', [...array_values($f), $id]);
        asentar('alarma.modificada', $f, (string) $id);
        return ['alarma' => vista($id)];
    }],
    ['POST', '/{id}/{accion}', 'alarmas.operar', fn(array $c) => ['alarma' => accion((int) $c['params']['id'], $c['params']['accion'], $c['cuerpo'], $c['cfg'])]],
];
