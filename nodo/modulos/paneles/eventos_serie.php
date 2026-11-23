<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Ola 4: historial traído en lote por una fuente (telemetria/serie) → detalle fino y historial por hora.
declare(strict_types=1);

use function Modulos\Paneles\anotar_serie;

return function (array $ev, array $ctx): void { anotar_serie((array) $ev['datos'], $ctx['cfg']); };
