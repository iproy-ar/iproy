<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use Nucleo\Fallo;
use function Modulos\Widgets\{catalogo, grupos, guardar_grupo, borrar_grupo, widget, vista_widget, guardar_widget, borrar_widget, perfiles, guardar_perfil, borrar_perfil,
    asignar_perfil, widget_usable, crear_dato, cambiar_dato, listar_datos, indicadores, exportar, importar};
use function Nucleo\filas;

return [
    // lo que arma el menú y el inicio de quien está ingresado
    ['GET', '/catalogo', 'widgets.usar', fn(array $c) => catalogo((array) $c['usuario'])],

    // administración del catálogo
    ['GET', '/admin', 'widgets.configurar', fn(array $c) => ['grupos' => grupos(true), 'widgets' => array_map(fn($w) => vista_widget($w), filas('SELECT * FROM wid_widget ORDER BY orden, nombre')),
        'perfiles' => perfiles(), 'usuarios' => filas('SELECT u.usuario, u.nombre, u.rol, u.activo, x.perfil FROM nucleo_usuario u LEFT JOIN wid_usuario x ON x.usuario = u.usuario ORDER BY u.usuario'),
        'tipos' => \Modulos\Widgets\TIPOS, 'campos' => \Modulos\Widgets\CAMPOS]],
    ['POST', '/grupos', 'widgets.configurar', fn(array $c) => ['_http' => 201, 'grupo' => guardar_grupo(null, $c['cuerpo'], (array) $c['usuario'])]],
    ['PUT', '/grupos/{id}', 'widgets.configurar', fn(array $c) => ['grupo' => guardar_grupo($c['params']['id'], $c['cuerpo'], (array) $c['usuario'])]],
    ['POST', '/grupos/{id}/borrar', 'widgets.configurar', function (array $c) { borrar_grupo($c['params']['id']); return ['borrado' => $c['params']['id']]; }],
    ['POST', '/widgets', 'widgets.configurar', function (array $c) { $id = guardar_widget(null, $c['cuerpo'], (array) $c['usuario'], $c['cfg']); return ['_http' => 201, 'widget' => vista_widget(widget($id))]; }],
    ['PUT', '/widgets/{id}', 'widgets.configurar', function (array $c) { $id = guardar_widget($c['params']['id'], $c['cuerpo'], (array) $c['usuario'], $c['cfg']); return ['widget' => vista_widget(widget($id))]; }],
    ['POST', '/widgets/{id}/borrar', 'widgets.configurar', function (array $c) { borrar_widget($c['params']['id'], !empty($c['cuerpo']['con_datos'])); return ['borrado' => $c['params']['id']]; }],
    ['POST', '/perfiles', 'widgets.configurar', fn(array $c) => ['_http' => 201, 'perfil' => guardar_perfil(null, $c['cuerpo'], (array) $c['usuario'])]],
    ['PUT', '/perfiles/{id}', 'widgets.configurar', fn(array $c) => ['perfil' => guardar_perfil($c['params']['id'], $c['cuerpo'], (array) $c['usuario'])]],
    ['POST', '/perfiles/{id}/borrar', 'widgets.configurar', function (array $c) { borrar_perfil($c['params']['id']); return ['borrado' => $c['params']['id']]; }],
    ['PUT', '/usuarios/{usuario}/perfil', 'widgets.configurar', function (array $c) { asignar_perfil($c['params']['usuario'], (string) ($c['cuerpo']['perfil'] ?? ''), (array) $c['usuario']); return ['usuario' => $c['params']['usuario'], 'perfil' => (string) ($c['cuerpo']['perfil'] ?? '')]; }],
    ['POST', '/exportar', 'widgets.configurar', fn(array $c) => ['paquete' => exportar((array) ($c['cuerpo']['widgets'] ?? []))]],
    ['POST', '/importar', 'widgets.configurar', fn(array $c) => ['importados' => importar((array) ($c['cuerpo']['paquete'] ?? []), (array) $c['usuario'], $c['cfg'])]],

    // uso de los widgets creados
    ['GET', '/datos/{w}', 'widgets.usar', function (array $c) { $w = widget_usable($c['params']['w'], (array) $c['usuario']);
        if ($w['tipo'] === 'indicadores') return indicadores($w, $c['cfg']);
        if ($w['tipo'] === 'pagina') throw new Fallo(404, 'Una página no guarda registros');
        $r = listar_datos($w, $c['query'], $c['cfg']);
        if ($w['tipo'] === 'formulario' && !\Nucleo\puede('widgets.configurar')) {   // quien carga un formulario ve solo lo suyo
            $yo = (string) ($c['usuario']['usuario'] ?? ''); $r['registros'] = array_values(array_filter($r['registros'], fn($x) => $x['creado_por'] === $yo)); $r['total'] = count($r['registros']); unset($r['por_estado']); }
        return $r; }],
    ['POST', '/datos/{w}', 'widgets.usar', function (array $c) { $w = widget_usable($c['params']['w'], (array) $c['usuario']);
        if (!in_array($w['tipo'], ['formulario', 'sabana'], true)) throw new Fallo(404, 'Ese widget no recibe registros');
        if ($w['tipo'] === 'sabana' && empty(json_decode((string) $w['definicion'], true)['alta']) && !\Nucleo\puede('widgets.configurar')) throw new Fallo(403, 'Esta sábana no admite altas');
        return ['_http' => 201, 'registro' => crear_dato($w, $c['cuerpo'], (array) $c['usuario'])]; }],
    ['PUT', '/datos/{w}/{id}', 'widgets.usar', function (array $c) { $w = widget_usable($c['params']['w'], (array) $c['usuario']);
        if (!in_array($w['tipo'], ['formulario', 'sabana'], true)) throw new Fallo(404, 'Ese widget no tiene registros');
        return ['registro' => cambiar_dato($w, (int) $c['params']['id'], $c['cuerpo'], (array) $c['usuario'])]; }],
];
