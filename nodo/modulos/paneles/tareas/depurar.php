<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Borra el detalle fino más viejo que «detalle_dias» y el historial por hora y los eventos más viejos que «retener_dias». */
declare(strict_types=1);

use function Nucleo\q;

return function (array $ctx): string {
    $borrar = function (string $sql, int $d): int { $n = 0; for ($i = 0; $i < 40; $i++) { $b = q($sql . ' LIMIT 5000', [$d])->rowCount(); $n += $b; if ($b < 5000) break; } return $n; };
    $det = \Modulos\Paneles\detalle_dias($ctx['cfg']); $ret = max(30, (int) ($ctx['cfg']['retener_dias'] ?? 400));
    $a = $borrar('DELETE FROM pan_serie WHERE ts < NOW() - INTERVAL ? DAY', $det);
    $b = $borrar('DELETE FROM pan_hora WHERE ts < NOW() - INTERVAL ? DAY', $ret);
    $c = $borrar('DELETE FROM pan_evento WHERE ts < NOW() - INTERVAL ? DAY', $ret);
    return "$a intervalos finos (más de $det días), $b horas y $c eventos (más de $ret días) borrados";
};
