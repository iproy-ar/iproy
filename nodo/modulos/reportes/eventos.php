<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use function Modulos\Reportes\sincronizar;

return function (array $ev, array $ctx): void { if (!empty($ev['datos']['id'])) sincronizar((string) $ev['datos']['id']); };
