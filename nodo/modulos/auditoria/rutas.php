<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use function Modulos\Auditoria\{areas, guardar_areas};

return [
    ['GET', '/estado', 'reportes.auditoria', fn(array $c) => ['por_pagina' => max(10, min(200, (int) ($c['cfg']['por_pagina'] ?? 50))), 'configura' => \Nucleo\puede('auditoria.configurar')]],
    // empresas o áreas que ofrece cada panel (vacío = todas)
    ['GET', '/areas/{panel}', 'reportes.auditoria', fn(array $c) => ['panel' => $c['params']['panel'], 'empresas' => areas($c['params']['panel'])]],
    ['PUT', '/areas/{panel}', 'auditoria.configurar', fn(array $c) => ['panel' => $c['params']['panel'], 'empresas' => guardar_areas($c['params']['panel'], (array) ($c['cuerpo']['empresas'] ?? []), (array) $c['usuario'])]],
];
