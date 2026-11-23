<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use Nucleo\Fallo;
use function Modulos\Paneles\{paneles, guardar_panel, vista_panel, serie, resumen, portada, panel_visible, curvas, eventos, informe, guardar_area, vista_area, areas_idx, exportar_paquete, importar_paquete, opciones};
use function Nucleo\{fila, filas, q, asentar, puede};

$panel = fn(int $id) => fila('SELECT * FROM pan_panel WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe ese panel');
$area = fn(int $id) => fila('SELECT * FROM pan_area WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe esa área');
return [
    // portada de Paneles IoT: las áreas que ve el usuario, con el estado de sus paneles
    ['GET', '/portada', 'paneles.ver', fn(array $c) => ['areas' => portada($c['usuario'], $c['cfg']), 'configura' => puede('paneles.configurar', $c['usuario'])]],
    ['GET', '/paneles', 'paneles.ver', fn(array $c) => ['paneles' => paneles($c['usuario'], $c['cfg'], true, isset($c['query']['area']) ? (string) $c['query']['area'] : null), 'roles' => \Modulos\Paneles\ROLES, 'tipos' => \Modulos\Paneles\TIPOS]],
    ['GET', '/paneles/{id}', 'paneles.ver', fn(array $c) => ['panel' => vista_panel(panel_visible((int) $c['params']['id'], $c['usuario']), $c['cfg'], puede('paneles.configurar', $c['usuario']))]],
    ['GET', '/resumen', 'paneles.ver', fn(array $c) => ['resumen' => resumen($c['usuario'], $c['cfg'])]],
    ['GET', '/serie/{item}', 'paneles.ver', fn(array $c) => serie((int) $c['params']['item'], $c['query'], $c['usuario'], $c['cfg'])],
    // varias señales del panel a la vez: ?p=CÓDIGO|PUNTO,…&horas=24 (o desde/hasta)
    ['GET', '/curvas/{id}', 'paneles.ver', fn(array $c) => curvas((int) $c['params']['id'], $c['query'], $c['usuario'], $c['cfg'])],
    ['GET', '/eventos/{id}', 'paneles.ver', fn(array $c) => eventos((int) $c['params']['id'], $c['query'], $c['usuario'])],
    ['GET', '/informe/{id}', 'paneles.ver', fn(array $c) => informe((int) $c['params']['id'], $c['query'], $c['usuario'], $c['cfg'])],
    // señales que ya enviaron algún valor: de acá se eligen para armar un panel
    ['GET', '/puntos', 'paneles.configurar', fn(array $c) => ['puntos' => filas('SELECT activo_id, punto, activo codigo, nombre, valor, unidad, ts, alarma, etiqueta FROM pan_punto ORDER BY nombre, punto')]],
    ['POST', '/paneles', 'paneles.configurar', function (array $c) use ($panel) {
        $id = guardar_panel(null, $c['cuerpo'], (array) $c['usuario']);
        return ['_http' => 201, 'panel' => vista_panel($panel($id), $c['cfg'], true)];
    }],
    ['PUT', '/paneles/{id}', 'paneles.configurar', function (array $c) use ($panel) {
        $id = (int) $c['params']['id']; $p = $panel($id);
        guardar_panel($id, $c['cuerpo'] + ['nombre' => $p['nombre'], 'descripcion' => $p['descripcion'], 'color' => $p['color'], 'orden' => $p['orden'], 'roles' => $p['roles'], 'usuarios' => $p['usuarios'] ?? '', 'activo' => $p['activo'],
            'tipo' => $p['tipo'], 'vista' => $p['vista'] ? json_decode((string) $p['vista'], true) : null, 'area_id' => $p['area_id'], 'clave' => $p['clave']], (array) $c['usuario']);
        return ['panel' => vista_panel($panel($id), $c['cfg'], true)];
    }],
    ['POST', '/paneles/{id}/borrar', 'paneles.configurar', function (array $c) use ($panel) {
        $id = (int) $c['params']['id']; $p = $panel($id);
        q('DELETE FROM pan_item WHERE panel_id = ?', [$id]); q('DELETE FROM pan_panel WHERE id = ?', [$id]);
        asentar('panel.borrado', ['nombre' => $p['nombre']], (string) $id);
        return ['borrado' => $id];
    }],
    // áreas y sus permisos (en la interfaz, desde Configuración › Paneles IoT)
    ['GET', '/areas', 'paneles.configurar', fn(array $c) => ['areas' => array_map(fn($a) => vista_area($a, true) + ['paneles' => (int) \Nucleo\valor('SELECT COUNT(*) FROM pan_panel WHERE area_id = ?', [$a['id']])], array_values(areas_idx(true))), 'opciones' => opciones()]],
    ['POST', '/areas', 'paneles.configurar', fn(array $c) => ['_http' => 201, 'area' => vista_area($area(guardar_area(null, $c['cuerpo'], (array) $c['usuario'])), true)]],
    ['PUT', '/areas/{id}', 'paneles.configurar', function (array $c) use ($area) {
        $id = (int) $c['params']['id']; $a = $area($id);
        $x = $c['cuerpo'] + ['clave' => $a['clave'], 'nombre' => $a['nombre'], 'descripcion' => $a['descripcion'], 'icono' => $a['icono'], 'color' => $a['color'], 'orden' => $a['orden'], 'activo' => $a['activo'], 'reglas' => json_decode((string) ($a['reglas'] ?? '[]'), true) ?: []];
        return ['area' => vista_area($area(guardar_area($id, $x, (array) $c['usuario'])), true)];
    }],
    ['POST', '/areas/{id}/borrar', 'paneles.configurar', function (array $c) use ($area) {
        $id = (int) $c['params']['id']; $a = $area($id);
        q('UPDATE pan_panel SET area_id = NULL WHERE area_id = ?', [$id]); q('DELETE FROM pan_area WHERE id = ?', [$id]);
        asentar('panel.area.borrada', ['clave' => $a['clave']], (string) $id);
        return ['borrada' => $id];
    }],
    ['GET', '/paquete', 'paneles.configurar', fn(array $c) => exportar_paquete()],
    ['POST', '/paquete', 'paneles.configurar', fn(array $c) => importar_paquete((array) $c['cuerpo'], (array) $c['usuario'])],
];
