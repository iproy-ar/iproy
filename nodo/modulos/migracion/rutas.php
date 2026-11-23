<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use function Modulos\Migracion\{equivalencias, guardar_equivalencias, catalogo_pim, analizar, cargar, corridas, pim_admin};

return [
    ['GET', '/estado', 'migracion.usar', fn(array $c) => ['destinos' => \Modulos\Migracion\DESTINOS, 'listas' => \Modulos\Migracion\LISTAS, 'equivalencias' => equivalencias(), 'pim' => array_diff_key(catalogo_pim(), ['ok' => 1]),
        'corridas' => corridas(), 'hosts_url' => (array) $c['cfg']['hosts_url'], 'max_filas' => (int) $c['cfg']['max_filas'], 'ajustes' => pim_admin(['accion' => 'ajustes'], 20)['ajustes']]],
    ['PUT', '/equivalencias/{lista}', 'migracion.usar', fn(array $c) => ['guardadas' => guardar_equivalencias($c['params']['lista'], (array) ($c['cuerpo']['equivalencias'] ?? []), (array) $c['usuario'], catalogo_pim()), 'equivalencias' => equivalencias()]],
    ['POST', '/subir', 'migracion.usar', fn(array $c) => \Modulos\Migracion\subir((array) $c['cuerpo'], $c['cfg'])],
    ['POST', '/analizar', 'migracion.usar', fn(array $c) => analizar((array) ($c['cuerpo']['origen'] ?? []), (string) ($c['cuerpo']['destino'] ?? ''), $c['cuerpo']['mapa'] ?? null, $c['cfg'])],
    ['POST', '/cargar', 'migracion.usar', fn(array $c) => cargar((array) ($c['cuerpo']['origen'] ?? []), (string) ($c['cuerpo']['destino'] ?? ''), $c['cuerpo']['mapa'] ?? [], !empty($c['cuerpo']['simular']), !empty($c['cuerpo']['crear_faltantes']), (array) $c['usuario'], $c['cfg'], (array) ($c['cuerpo']['opciones'] ?? []))],
    // el detalle escrito de los pedidos siempre se guarda; este ajuste decide si el Servidor PIM lo muestra
    ['PUT', '/ajustes', 'migracion.usar', function (array $c) { $cb = []; if (array_key_exists('exponer_detalle', $c['cuerpo'])) $cb['exponer_detalle'] = !empty($c['cuerpo']['exponer_detalle']);
        if (array_key_exists('sigla', $c['cuerpo'])) $cb['sigla'] = (string) $c['cuerpo']['sigla'];
        if (!$cb) throw new \Nucleo\Fallo(422, 'Nada para cambiar');
        $a = pim_admin(['accion' => 'ajustes', 'cambio' => $cb], 20)['ajustes'];
        \Nucleo\asentar('pim.ajuste', array_intersect_key($a, $cb)); return ['ajustes' => $a]; }],
];
