<?php
/** Worker del nodo: lo llama iProy-Control cada 15 s (reemplaza cron y triggers de GAS). Solo localhost + token. */
declare(strict_types=1);
require __DIR__ . '/lib_nodo.php';
header('Content-Type: application/json; charset=utf-8');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$c = nodo_cfg();
if (!in_array($ip, ['127.0.0.1', '::1'], true) || empty($c['tick_token'])
    || !hash_equals((string) $c['tick_token'], (string) ($_SERVER['HTTP_X_IPROY_TICK'] ?? ''))) {
    http_response_code(403); echo '{"ok":false}'; exit;
}
$lock = fopen(dirname(__DIR__, 2) . '/data/run/tick.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo '{"ok":true,"saltado":"en curso"}'; exit; }
$hecho = [];
try {
    $p = nodo_pdo();
    nodo_kv($p, 'worker_ultimo', date('d-m-Y H:i:s'));
    $hecho[] = 'latido';
    // Tareas de las fases siguientes (sync:lan, sync:hub, cola:enviar, iot:vigilar, exp:portal, pmp:programar…)
    // se registran en _iproy/tareas/*.php con su horario. En la Fase 2 solo existe el latido.
    foreach (glob(__DIR__ . '/tareas/*.php') ?: [] as $t) { $hecho[] = basename($t, '.php') . ':' . (string) (require $t); }
    echo json_encode(['ok' => true, 'hecho' => $hecho]);
} catch (Throwable $x) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $x->getMessage()]);
} finally { flock($lock, LOCK_UN); }
