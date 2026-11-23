<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use function Modulos\Reportes\anotar_insumos;

// Un egreso de pañol ligado a una orden: se guarda en la copia propia del módulo (rep_insumo).
return function (array $ev, array $ctx): void { anotar_insumos((array) ($ev['datos'] ?? [])); };
