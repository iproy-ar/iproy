<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use function Nucleo\{estado, valor};

return [
    ['GET', '/estado', 'mqtt.ver', fn(array $c) => ['habilitado' => (bool) $c['cfg']['habilitado'], 'broker' => $c['cfg']['broker'] . ':' . $c['cfg']['puerto'],
        'ultima_lectura' => estado('mqtt'), 'cola_salida' => (int) valor('SELECT COUNT(*) FROM mqtt_saliente')]],
];
