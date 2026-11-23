<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use Nucleo\Fallo;
use function Modulos\Reportes\{auditoria, buscar_codigo, orden, calificar, organizaciones, empresa, situacion, reconstruir};
use function Nucleo\asentar;

return [
    ['GET', '/situacion', null, function (array $c) { \Nucleo\puede('reportes.auditoria') || \Nucleo\exigir('reportes.empresa'); return ['situacion' => situacion()]; }],
    ['GET', '/organizaciones', 'reportes.empresa', fn(array $c) => ['organizaciones' => organizaciones($c['usuario'])]],
    ['GET', '/auditoria', 'reportes.auditoria', fn(array $c) => auditoria($c['query'], $c['usuario'], $c['cfg'])],
    // Ola P (P4): documento .docx nativo a partir de un modelo (certificación mensual, reporte de la empresa)
    ['POST', '/docx', null, function (array $c) { \Nucleo\puede('reportes.auditoria') || \Nucleo\exigir('reportes.empresa');
        return ['nombre' => \Nucleo\nombre_archivo((string) ($c['cuerpo']['archivo'] ?? 'documento'), 'docx'), 'tipo' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'base64' => base64_encode(\Modulos\Reportes\docx((array) ($c['cuerpo']['modelo'] ?? [])))]; }],
    ['GET', '/codigo', 'reportes.auditoria', fn(array $c) => buscar_codigo((string) ($c['query']['q'] ?? ''), $c['usuario'])],
    ['GET', '/ordenes/{id}', 'reportes.empresa', fn(array $c) => ['orden' => orden($c['params']['id'], $c['usuario'])]],
    ['POST', '/ordenes/{id}/calificar', 'reportes.calificar', fn(array $c) => ['orden' => calificar($c['params']['id'], $c['cuerpo'], (array) $c['usuario'])]],
    ['GET', '/insumos', 'reportes.empresa', fn(array $c) => ['insumos' => \Modulos\Reportes\insumos_de(explode(',', (string) ($c['query']['ordenes'] ?? '')), $c['usuario'])]],
    ['GET', '/empresa', 'reportes.empresa', fn(array $c) => empresa($c['query'], $c['usuario'])],
    // 1.2 · Tendencias y Pronóstico, Reporte General (como «Admin» del sistema anterior)
    ['GET', '/tendencias', 'reportes.auditoria', fn(array $c) => \Modulos\Reportes\tendencias_ot($c['query'], $c['usuario'], $c['cfg'])],
    ['GET', '/general', 'reportes.auditoria', fn(array $c) => \Modulos\Reportes\general($c['query'], $c['usuario'])],
    ['GET', '/series', 'reportes.auditoria', fn(array $c) => \Modulos\Reportes\series_pronostico($c['query'], $c['usuario'])],
    ['POST', '/pronosticos', 'reportes.pronosticos', fn(array $c) => ['_http' => 201] + \Modulos\Reportes\guardar_pronosticos($c['cuerpo'], $c['usuario'])],
    ['GET', '/pronosticos', 'reportes.auditoria', fn(array $c) => ['corridas' => \Nucleo\filas('SELECT * FROM rep_corrida ORDER BY id DESC LIMIT 20')]],
    ['GET', '/contrato', 'reportes.auditoria', fn(array $c) => ['contrato' => array_intersect_key($c['cfg'], array_flip(['fecha_contrato', 'contrato_entrante', 'contrato_saliente', 'areas_criticas']))]],
    ['PUT', '/contrato', 'reportes.pronosticos', function (array $c) {
        $b = $c['cuerpo']; $cf = \Nucleo\cfg_modulo('reportes');
        if (array_key_exists('fecha_contrato', $b)) { $f = (string) $b['fecha_contrato']; if ($f !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) throw new Fallo(422, 'Fecha del cambio de contrato: AAAA-MM-DD'); $cf['fecha_contrato'] = $f ?: null; }
        foreach (['contrato_entrante', 'contrato_saliente'] as $k) if (array_key_exists($k, $b)) $cf[$k] = mb_substr(trim((string) $b[$k]), 0, 64) ?: null;
        if (array_key_exists('areas_criticas', $b)) $cf['areas_criticas'] = array_values(array_slice(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 160), (array) $b['areas_criticas'])), 0, 60));
        \Nucleo\cfg_modulo('reportes', $cf); \Nucleo\asentar('reporte.contrato', array_intersect_key($cf, array_flip(['fecha_contrato', 'contrato_entrante', 'contrato_saliente'])));
        return ['contrato' => array_intersect_key($cf, array_flip(['fecha_contrato', 'contrato_entrante', 'contrato_saliente', 'areas_criticas']))];
    }],
    ['GET', '/tareas', 'reportes.empresa', fn(array $c) => ['tareas' => \Modulos\Reportes\tareas_ot($c['usuario'])]],
    ['GET', '/servicios', 'reportes.empresa', fn(array $c) => ['servicios' => \Modulos\Reportes\servicios_ot($c['usuario'])]],
    ['POST', '/reconstruir', 'reportes.reconstruir', function (array $c) { $r = reconstruir(); asentar('reporte.reconstruido', $r); return $r; }],
];
