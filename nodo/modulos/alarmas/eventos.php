<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use function Modulos\Alarmas\evaluar;
use function Nucleo\filas;

return function (array $ev, array $ctx): void {
    $v = $ev['datos'];
    foreach (filas('SELECT * FROM alm_definicion WHERE activo_id = ? AND punto = ? AND habilitada = 1', [(string) ($v['activo_id'] ?? ''), (string) ($v['punto'] ?? '')]) as $d)
        evaluar($d, (float) $v['valor'], (string) ($v['ts'] ?? date('Y-m-d H:i:s')));
};
