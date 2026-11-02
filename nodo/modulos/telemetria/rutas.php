<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use Nucleo\Fallo;
use function Modulos\Telemetria\normalizar;
use function Nucleo\{filas, publicar};

return [
    ['GET', '/ultimos', 'telemetria.ver', fn(array $c) => ['ultimos' => filas('SELECT u.*, m.codigo, COALESCE(m.nombre, u.activo_id) AS nombre FROM tel_ultimo u
        LEFT JOIN tel_mapa m ON m.activo_id = u.activo_id ORDER BY nombre, u.punto')]],
    ['GET', '/serie', 'telemetria.ver', fn(array $c) => ['serie' => filas('SELECT ts, valor FROM tel_serie WHERE activo_id = ? AND punto = ? AND ts > DATE_SUB(NOW(), INTERVAL ? HOUR) ORDER BY ts',
        [(string) ($c['query']['activo'] ?? ''), (string) ($c['query']['punto'] ?? ''), max(1, min(168, (int) ($c['query']['horas'] ?? 24)))])]],
    // Ingreso por HTTP (gateways, PLC con cliente HTTP, scripts): una medición o {"mediciones": [...]} (hasta 1000)
    ['POST', '/medicion', 'telemetria.escribir', function (array $c) {
        $l = isset($c['cuerpo']['mediciones']) ? (array) $c['cuerpo']['mediciones'] : [$c['cuerpo']];
        if (count($l) > 1000) throw new Fallo(413, 'Máximo 1000 mediciones por pedido');
        $ids = [];
        foreach ($l as $m) { $n = normalizar((array) $m + ['origen' => 'http']); $ids[] = publicar('telemetria/medicion', $n, 'http:' . ($c['usuario']['usuario'] ?? '?')); }
        return ['_http' => 202, 'aceptadas' => count($ids)];
    }],
];
