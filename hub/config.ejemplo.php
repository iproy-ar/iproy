<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Copiá este archivo como config.php y ajustalo. Si es posible, poné dir_datos FUERA de la carpeta pública del hosting.
return [
    'dir_datos'         => __DIR__ . '/datos',   // base SQLite (protegida por .htaccess si queda dentro de public_html)
    'codigo_registro'   => '',                   // si no está vacío, un nodo nuevo solo se registra presentando este código
    'nodos_permitidos'  => [],                   // lista blanca opcional de identificadores (vacía = cualquiera con el código)
    'ttl_dias'          => 7,                    // un sobre no retirado en este plazo se descarta
    'max_sobre_kb'      => 512,
    'max_pendientes'    => 5000,                 // por destinatario
    'limite_por_minuto' => 240,                  // pedidos por nodo
];
