<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use function Modulos\Pim\{inventario, plantilla, exportar, importar, guardar_activo, estado_activo, ficha, guardar_ubicacion, establecimiento};

$fmt = fn(array $c) => ($c['query']['formato'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
$hoja = fn(array $c) => ($c['query']['hoja'] ?? '') === 'ubicaciones' ? 'ubicaciones' : 'activos';
return [
    ['GET', '/inventario', 'pim.usar', fn(array $c) => inventario()],
    ['GET', '/inventario/plantilla', 'pim.usar', fn(array $c) => plantilla($fmt($c), $hoja($c))],
    ['GET', '/inventario/exportar', 'pim.usar', fn(array $c) => exportar($fmt($c), $hoja($c), ($c['query']['todos'] ?? '1') !== '0')],
    ['POST', '/inventario/importar', 'pim.inventario.editar', fn(array $c) => importar((array) $c['cuerpo'], (array) $c['usuario'])],
    ['POST', '/activos', 'pim.inventario.editar', fn(array $c) => ['_http' => 201] + guardar_activo(null, (array) $c['cuerpo'], (array) $c['usuario'])],
    ['GET', '/activos/{id}', 'pim.usar', fn(array $c) => ficha($c['params']['id'])],
    ['PUT', '/activos/{id}', 'pim.inventario.editar', fn(array $c) => guardar_activo($c['params']['id'], (array) $c['cuerpo'], (array) $c['usuario'])],
    ['POST', '/activos/{id}/estado', 'pim.inventario.editar', fn(array $c) => estado_activo($c['params']['id'], (array) $c['cuerpo'], (array) $c['usuario'])],
    ['POST', '/ubicaciones', 'pim.inventario.editar', fn(array $c) => ['_http' => 201] + guardar_ubicacion(null, (array) $c['cuerpo'], (array) $c['usuario'])],
    ['PUT', '/ubicaciones/{id}', 'pim.inventario.editar', fn(array $c) => guardar_ubicacion($c['params']['id'], (array) $c['cuerpo'], (array) $c['usuario'])],
    ['PUT', '/establecimiento', 'pim.inventario.editar', fn(array $c) => establecimiento((string) ($c['cuerpo']['nombre'] ?? ''), (array) $c['usuario'])],
];
