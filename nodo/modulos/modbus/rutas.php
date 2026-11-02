<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use function Nucleo\estado;

return [
    ['GET', '/estado', 'modbus.ver', fn(array $c) => ['habilitado' => (bool) $c['cfg']['habilitado'], 'equipos' => estado('modbus')]],
];
