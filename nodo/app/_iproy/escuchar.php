<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Escucha de la colmena (1.7.0, túnel por el hub): la llama el worker entre un tick y el siguiente. Deja un pedido abierto con el hub
 * (espera larga) y procesa en el acto los sobres que llegan, así un pedido entre nodos de redes distintas tarda 1-2 s y no 15-45 s.
 * Solo localhost + token del worker. ?espera=N (1 a 25 s). Devuelve el modo para que el worker sepa si debe dormir hasta el próximo tick.
 */
declare(strict_types=1);
require __DIR__ . '/lib_nodo.php';
header('Content-Type: application/json; charset=utf-8');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$c = nodo_cfg();
if (!in_array($ip, ['127.0.0.1', '::1'], true) || empty($c['tick_token'])
    || !hash_equals((string) $c['tick_token'], (string) ($_SERVER['HTTP_X_IPROY_TICK'] ?? ''))) {
    http_response_code(403); echo '{"ok":false}'; exit;
}
$lock = fopen(dirname(__DIR__, 2) . '/data/run/escucha.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo '{"ok":true,"modo":"ocupada"}'; exit; }
try {
    if (!is_file(dirname(__DIR__) . '/nucleo/lib/nucleo.php')) { echo '{"ok":true,"modo":"sin-nucleo"}'; exit; }
    require_once dirname(__DIR__) . '/nucleo/lib/nucleo.php';
    \Nucleo\cargar();
    @set_time_limit(90);
    $r = \Nucleo\hub_escuchar(max(1, min(25, (int) ($_GET['espera'] ?? 14))));
    echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $x) {
    echo json_encode(['ok' => false, 'modo' => 'error', 'error' => $x->getMessage()], JSON_UNESCAPED_UNICODE);
} finally { flock($lock, LOCK_UN); }
