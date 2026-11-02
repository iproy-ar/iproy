<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * SBOM CycloneDX 1.5 (JSON) de una distribución: producto, núcleo, módulos y componentes de terceros del runtime.
 *   php herramientas/sbom.php [carpeta-de-la-distribución=../nodo] > sbom.cdx.json
 * Sirve para el inventario de componentes que piden la CRA europea y los compradores públicos.
 */
declare(strict_types=1);
$d = rtrim($argv[1] ?? __DIR__ . '/../nodo', '/');
$p = json_decode((string) @file_get_contents("$d/producto.json"), true) ?: ['nombre' => 'iProy', 'version' => '0.0.0', 'licencia' => 'AGPL-3.0-or-later'];
$nucleo = preg_match("/const VERSION = '([^']+)'/", (string) @file_get_contents("$d/app/nucleo/lib/nucleo.php"), $m) ? $m[1] : '?';
$c = [['type' => 'library', 'bom-ref' => 'nucleo', 'name' => 'nucleo', 'version' => $nucleo, 'licenses' => [['license' => ['id' => 'AGPL-3.0-or-later']]], 'description' => 'Núcleo (módulos, bus, diario, colmena)']];
foreach (glob("$d/modulos/*/modulo.json") ?: [] as $f) {
    $j = json_decode((string) file_get_contents($f), true) ?: [];
    $c[] = ['type' => 'library', 'bom-ref' => 'modulo:' . ($j['id'] ?? basename(dirname($f))), 'name' => 'modulo-' . ($j['id'] ?? '?'), 'version' => $j['version'] ?? '?',
            'licenses' => [['license' => ['id' => $j['licencia'] ?? 'NOASSERTION']]], 'description' => $j['nombre'] ?? '',
            'properties' => [['name' => 'iproy:abi', 'value' => (string) ($j['abi'] ?? '?')], ['name' => 'iproy:estabilidad', 'value' => (string) ($j['estabilidad'] ?? '?')]]];
}
// runtime de terceros (versiones fijadas por tools/bajar-runtime.*; se confirman en runtime/ si existe)
foreach ([['frankenphp', '1.13.3', 'MIT'], ['php', '8.5.12', 'PHP-3.01'], ['caddy', '2.11.7', 'Apache-2.0'], ['mariadb-server', '11.4', 'GPL-2.0-only']] as [$n, $v, $l])
    $c[] = ['type' => 'application', 'bom-ref' => "runtime:$n", 'name' => $n, 'version' => $v, 'licenses' => [['license' => ['id' => $l]]], 'scope' => 'required'];
echo json_encode(['bomFormat' => 'CycloneDX', 'specVersion' => '1.5', 'serialNumber' => 'urn:uuid:' . (function () { $h = bin2hex(random_bytes(16)); $h[12] = '4'; $h[16] = dechex(8 + random_int(0, 3)); return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20); })(), 'version' => 1,
    'metadata' => ['timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'component' => ['type' => 'application', 'bom-ref' => 'producto', 'name' => $p['nombre'], 'version' => $p['version'],
        'licenses' => [['license' => ['id' => $p['licencia']]]], 'externalReferences' => ($p['fuente'] ?? '') ? [['type' => 'vcs', 'url' => $p['fuente']]] : []]],
    'components' => $c], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
