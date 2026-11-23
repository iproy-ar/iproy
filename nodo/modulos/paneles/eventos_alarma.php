<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use function Modulos\Paneles\anotar_alarma;

return function (array $ev, array $ctx): void { anotar_alarma((array) $ev['datos']); };
