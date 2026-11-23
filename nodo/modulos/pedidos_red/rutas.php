<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use function Modulos\PedidosRed\{nodos, buscar, pedir, actualizar, pedido, pedidos};

return [
    ['GET', '/nodos', 'pedidos_red.ver', fn(array $c) => ['nodos' => nodos(), 'prioridades' => \Modulos\PedidosRed\PRIORIDADES]],
    ['GET', '/buscar', 'pedidos_red.pedir', fn(array $c) => ['resultados' => buscar((string) ($c['query']['par'] ?? ''), (string) ($c['query']['q'] ?? ''))]],
    ['GET', '/pedidos', 'pedidos_red.ver', fn(array $c) => ['pedidos' => pedidos($c['query'])]],
    ['POST', '/pedidos', 'pedidos_red.pedir', fn(array $c) => ['_http' => 201, 'pedido' => pedir($c['cuerpo'], (array) $c['usuario'])]],
    ['GET', '/pedidos/{id}', 'pedidos_red.ver', fn(array $c) => ['pedido' => pedido((int) $c['params']['id'])]],
    ['POST', '/pedidos/{id}/actualizar', 'pedidos_red.ver', fn(array $c) => ['pedido' => actualizar((int) $c['params']['id'])]],
];
