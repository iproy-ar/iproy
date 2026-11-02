<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Vencimiento de archivos (shelving) y activaciones con retardo pendientes. */
declare(strict_types=1);

use function Modulos\Alarmas\{evaluar, restaurar};
use function Nucleo\{filas, fila};

return function (array $ctx): string {
    $n = 0;
    foreach (filas("SELECT d.*, e.def_id FROM alm_estado e JOIN alm_definicion d ON d.id = e.def_id WHERE e.estado = 'archivada' AND e.archivada_hasta <= NOW()") as $d) {
        restaurar($d, fila('SELECT * FROM alm_estado WHERE def_id = ?', [$d['id']]), 'venció el archivo'); $n++;
    }
    foreach (filas('SELECT d.* FROM alm_estado e JOIN alm_definicion d ON d.id = e.def_id WHERE e.pendiente_desde IS NOT NULL AND d.habilitada = 1') as $d) {
        evaluar($d, null, date('Y-m-d H:i:s')); $n++;
    }
    return "$n temporizadores";
};
