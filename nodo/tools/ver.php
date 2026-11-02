<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Muestra legible la respuesta JSON de la API del nodo (la usa el lanzador). Sale con 1 si ok=false. */
$raw = stream_get_contents(STDIN);
$j = json_decode((string) $raw, true);
if (!is_array($j)) { fwrite(STDERR, "respuesta no JSON: " . substr((string) $raw, 0, 300) . "\n"); exit(1); }
$ver = function ($v, int $n = 0) use (&$ver) {
    $sp = str_repeat('  ', $n);
    foreach ($v as $k => $x) {
        if (is_array($x) && $x === []) { echo "$sp$k: —\n"; continue; }
        if (is_array($x) && array_is_list($x) && !array_filter($x, 'is_array')) { echo "$sp$k: " . implode(', ', array_map(fn($y) => var_export($y, true) === 'NULL' ? '—' : (is_bool($y) ? ($y ? 'sí' : 'no') : (string) $y), $x)) . "\n"; continue; }
        if (is_array($x)) { echo "$sp$k:\n"; $ver($x, $n + 1); continue; }
        echo "$sp$k: " . ($x === null ? '—' : (is_bool($x) ? ($x ? 'sí' : 'no') : $x)) . "\n";
    }
};
if (($j['ok'] ?? true) === false) { fwrite(STDERR, "✘ " . ($j['error'] ?? 'error') . "\n"); exit(1); }
unset($j['ok']);
$ver($j);
