<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Cambios de alarma → cola de publicación MQTT (se envían en la próxima conexión). */
declare(strict_types=1);

use function Nucleo\q;

return function (array $ev, array $ctx): void {
    $c = $ctx['cfg'];
    if (empty($c['habilitado']) || empty($c['publicar_alarmas'])) return;
    $d = $ev['datos'];
    $tema = strtr($c['tema_alarmas'], ['{refes}' => $c['refes'], '{activo}' => (string) ($d['activo_id'] ?? '-'), '{punto}' => (string) ($d['punto'] ?? '-')]);
    q('INSERT INTO mqtt_saliente (tema, carga, retener) VALUES (?,?,1)', [$tema, json_encode(['etiqueta' => $d['etiqueta'] ?? null, 'estado' => $d['a'] ?? null,
        'evento' => $d['evento'] ?? null, 'prioridad' => $d['prioridad'] ?? null, 'mensaje' => $d['mensaje'] ?? null, 'valor' => $d['valor'] ?? null, 't' => $ev['ts']], JSON_UNESCAPED_UNICODE)]);
};
