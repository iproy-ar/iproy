<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Pone al día el estado de los pedidos abiertos hechos a otros nodos. */
declare(strict_types=1);

return function (array $ctx): string {
    $r = \Modulos\PedidosRed\seguimiento();
    return "pedidos a otros nodos: {$r['revisados']} revisados" . ($r['errores'] ? ", {$r['errores']} con error" : '');
};
