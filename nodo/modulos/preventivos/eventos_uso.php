<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Planes por uso (preventivo condicional): cuando el contador avanza cada_valor desde la última orden, se genera la siguiente. */
declare(strict_types=1);

use function Modulos\Preventivos\{generar, abierta};
use function Nucleo\{filas, q};

return function (array $ev, array $ctx): void {
    $v = $ev['datos'];
    foreach (filas('SELECT * FROM prev_plan WHERE habilitado = 1 AND cada_valor > 0 AND activo_id = ? AND punto = ?', [(string) ($v['activo_id'] ?? ''), (string) ($v['punto'] ?? '')]) as $p) {
        $x = (float) $v['valor'];
        if ($p['ultimo_valor'] === null) { q('UPDATE prev_plan SET ultimo_valor = ? WHERE id = ?', [$x, $p['id']]); continue; }   // primera lectura: referencia
        if ($x - (float) $p['ultimo_valor'] >= (float) $p['cada_valor'] && !abierta((int) $p['id'])) {
            $p['ultimo_valor'] = $x;
            generar($p, 'uso');
            q('UPDATE prev_plan SET ultimo_valor = ?, ultima = CURDATE() WHERE id = ?', [$x, $p['id']]);
        }
    }
};
