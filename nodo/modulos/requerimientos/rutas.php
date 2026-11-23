<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use function Modulos\Requerimientos\{yo, fijar_servicio, recordar};
use function Nucleo\filas;

return [
    ['GET', '/yo', 'pim.usar', fn(array $c) => yo((array) $c['usuario']) + ['cfg' => ['por_pagina' => (int) ($c['cfg']['por_pagina'] ?? 50), 'max_ordenes' => (int) ($c['cfg']['max_ordenes'] ?? 1000)]]],
    ['PUT', '/yo/ultimo', 'pim.usar', function (array $c) { recordar((array) $c['usuario'], (string) ($c['cuerpo']['local_id'] ?? '')); return ['ultimo' => (string) ($c['cuerpo']['local_id'] ?? '')]; }],
    ['GET', '/usuarios', 'requerimientos.configurar', fn(array $c) => ['usuarios' => filas('SELECT u.usuario, u.nombre, u.rol, u.activo, r.local_id, r.local_nombre FROM nucleo_usuario u LEFT JOIN req_usuario r ON r.usuario = u.usuario ORDER BY u.usuario')]],
    ['PUT', '/usuarios/{usuario}/servicio', 'requerimientos.configurar', function (array $c) { fijar_servicio($c['params']['usuario'], $c['cuerpo'], (array) $c['usuario']); return ['usuario' => $c['params']['usuario']]; }],
    // operarios de cada empresa y su trabajo en las órdenes
    ['GET', '/operarios', 'pim.ordenes.operar', fn(array $c) => ['operarios' => \Modulos\Requerimientos\operarios((array) $c['usuario'], (string) ($c['query']['org'] ?? ''), !empty($c['query']['todos']))]],
    ['POST', '/operarios', 'pim.ordenes.operar', fn(array $c) => ['_http' => 201, 'operario' => \Modulos\Requerimientos\guardar_operario((array) $c['usuario'], null, (array) $c['cuerpo'])]],
    ['PUT', '/operarios/{id}', 'pim.ordenes.operar', fn(array $c) => ['operario' => \Modulos\Requerimientos\guardar_operario((array) $c['usuario'], (int) $c['params']['id'], (array) $c['cuerpo'])]],
    ['GET', '/operarios/reporte', 'pim.ordenes.operar', fn(array $c) => \Modulos\Requerimientos\reporte_operarios((array) $c['usuario'], (array) $c['query'])],
    ['GET', '/trabajos', 'pim.ordenes.operar', fn(array $c) => ['trabajos' => \Modulos\Requerimientos\trabajos((array) $c['usuario'], explode(',', (string) ($c['query']['ordenes'] ?? '')))]],
    ['POST', '/trabajos', 'pim.ordenes.operar', fn(array $c) => ['_http' => 201, 'trabajo' => \Modulos\Requerimientos\guardar_trabajo((array) $c['usuario'], null, (array) $c['cuerpo'])]],
    ['PUT', '/trabajos/{id}', 'pim.ordenes.operar', fn(array $c) => ['trabajo' => \Modulos\Requerimientos\guardar_trabajo((array) $c['usuario'], (int) $c['params']['id'], (array) $c['cuerpo'])]],
    ['POST', '/trabajos/{id}/borrar', 'pim.ordenes.operar', function (array $c) { \Modulos\Requerimientos\borrar_trabajo((array) $c['usuario'], (int) $c['params']['id']); return ['borrado' => (int) $c['params']['id']]; }],
];
