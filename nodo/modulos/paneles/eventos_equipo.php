<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Ola 4: eventos y alarmas que informa el propio equipo (equipos/evento), con su inicio y su fin.
declare(strict_types=1);

use function Modulos\Paneles\anotar_evento;

return function (array $ev, array $ctx): void { anotar_evento((array) $ev['datos']); };
