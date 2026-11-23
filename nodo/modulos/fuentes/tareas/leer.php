<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Lee las fuentes activas a las que les toca (cada una según su «cada_s»). Un origen caído no frena a los demás. */
declare(strict_types=1);

use function Modulos\Fuentes\leer;
use function Nucleo\filas;

return function (array $ctx): string {
    $t0 = microtime(true); $ok = 0; $err = 0; $m = 0; $e = 0;
    foreach (filas('SELECT * FROM fue_fuente WHERE activa = 1 AND (ultimo_intento IS NULL OR ultimo_intento <= DATE_SUB(NOW(), INTERVAL cada_s - 5 SECOND)) ORDER BY ultimo_intento IS NOT NULL, ultimo_intento LIMIT 20') as $f) {
        if (microtime(true) - $t0 > 40) break;
        try { $r = leer($f, $ctx['cfg']); $ok++; $m += $r['mediciones']; $e += $r['eventos']; } catch (\Throwable) { $err++; }
    }
    return "fuentes: $ok leídas" . ($err ? ", $err con error" : '') . ", $m lecturas y $e eventos nuevos";
};
