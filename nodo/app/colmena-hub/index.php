<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Hub propio del nodo (malla de la colmena, núcleo 1.7.0).
 * Cada nodo ofrece acá el mismo servicio de buzón que el hub central (mismo protocolo: ?r=estado|registrar|directorio|enviar|buzon|ack),
 * pero solo a los nodos con los que tiene vínculo activo. Se enciende y se apaga en Colmena › Red. Toda la lógica está en nucleo/lib/malla.php.
 */
declare(strict_types=1);
if (!is_file(dirname(__DIR__) . '/nucleo/lib/nucleo.php')) { http_response_code(404); exit; }
require dirname(__DIR__) . '/nucleo/lib/nucleo.php';
\Nucleo\cargar();
try { \Nucleo\hubp_atender(); }
catch (\Nucleo\Fallo $e) { http_response_code($e->http); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
catch (\Throwable $e) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => 'Error interno del hub del nodo'], JSON_UNESCAPED_UNICODE); }
