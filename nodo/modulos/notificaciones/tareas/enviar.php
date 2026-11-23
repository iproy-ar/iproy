<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Envía los avisos en cola (con reintentos) y borra el historial viejo. */
declare(strict_types=1);

use function Modulos\Notificaciones\{enviar_pendientes, depurar};

return function (array $ctx): string {
    $r = enviar_pendientes($ctx['cfg']);
    $b = depurar($ctx['cfg']);
    return "{$r['enviados']} enviados, {$r['fallidos']} con falla, {$r['pendientes']} en cola" . ($b ? ", $b viejos borrados" : '');
};
