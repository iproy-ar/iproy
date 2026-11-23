<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Manifiesto de la aplicación instalable (1.7.0): nombre y colores salen de producto.json, igual que la página.
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
$p = json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/producto.json'), true) ?: ['nombre' => 'iProy'];
$c = ($p['colores'] ?? []) + ['primario' => '#0d47a1'];
echo json_encode([
    'name' => (string) $p['nombre'], 'short_name' => mb_substr((string) $p['nombre'], 0, 12), 'description' => (string) ($p['descripcion'] ?? ''), 'lang' => 'es',
    'id' => '/app/', 'start_url' => '/app/', 'scope' => '/app/', 'display' => 'standalone', 'orientation' => 'any',
    'background_color' => '#f3f6fb', 'theme_color' => (string) $c['primario'],
    'icons' => [['src' => 'logo.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'], ['src' => 'icono-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any']],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
