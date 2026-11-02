<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Tick del núcleo (bus, colmena/hub, tareas de los módulos). Lo ejecuta tick.php cada 15 s, en el mismo proceso. */
if (!is_file(dirname(__DIR__, 2) . '/nucleo/lib/nucleo.php')) return 'sin núcleo';
require_once dirname(__DIR__, 2) . '/nucleo/lib/nucleo.php';
\Nucleo\cargar();
try {
    $h = \Nucleo\tick(40.0);
    $n = 0; foreach ($h as $k => $v) if (!in_array($k, ['bus', 'bus2', 'colmena', 'limpieza'], true)) $n++;
    return 'ok, ' . $n . ' tareas, ' . (($h['bus']['entregados'] ?? 0) + ($h['bus2']['entregados'] ?? 0)) . ' eventos';
} catch (\Throwable $e) { return 'error: ' . $e->getMessage(); }
