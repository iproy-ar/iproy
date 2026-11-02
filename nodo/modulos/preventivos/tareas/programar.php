<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Planes por calendario que vencen (con su anticipación) → orden en PIM. */
declare(strict_types=1);

use function Modulos\Preventivos\{generar, abierta};
use function Nucleo\{filas, asentar};

return function (array $ctx): string {
    $n = 0;
    foreach (filas('SELECT * FROM prev_plan WHERE habilitado = 1 AND cada_dias > 0 AND proxima IS NOT NULL AND DATE_SUB(proxima, INTERVAL anticipacion_dias DAY) <= CURDATE()') as $p) {
        if (abierta((int) $p['id'])) continue;
        try { generar($p, 'calendario'); $n++; } catch (\Throwable $e) { asentar('preventivo.error', ['error' => $e->getMessage()], (string) $p['id']); }
    }
    return "$n órdenes preventivas generadas";
};
