<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Repasa todas las órdenes del Servidor PIM y pone al día las que falten o estén atrasadas (por si se perdió algún evento). */
declare(strict_types=1);

use function Modulos\Reportes\reconstruir;

return function (array $ctx): string { $r = reconstruir(); return "{$r['revisadas']} órdenes revisadas, {$r['actualizadas']} puestas al día"; };
