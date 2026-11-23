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
    'espera_max'        => 25,                   // 1.1.0: segundos que el buzón puede esperar sobres (0 = sin espera larga; tope 55).
                                                 // Cada nodo escuchando ocupa un proceso PHP mientras espera: bajarlo si el hosting limita procesos.
    'mantenimiento'     => false,                // true = el hub responde 503 a todo (para trabajos en el hosting): los nodos siguen por sus
                                                 // otros caminos (directo, hubs de otros nodos) y lo que no tenga camino espera en la cola de cada nodo
    'desfase_prueba_s'  => 0,                    // SOLO PRUEBAS: corre la hora del hub para probar la corrección de reloj de los nodos
    'demora_prueba_ms'  => 0,                    // SOLO PRUEBAS: demora cada «enviar» para probar que los nodos eligen el camino más rápido
];
