<?php
/**
 * iProy · Servidor PIM v1.0 · disparador del puente iProy → PIM · 30-10-2026
 * Solo desde el propio equipo y con el token del worker (X-IPROY-Tick). Lo llama el worker de iProy-Control
 * cada 5 minutos y el comando «iProy-Control pim importar». Responde un resumen JSON (sin datos).
 */
declare(strict_types=1);

namespace IproyPim;

require dirname(__DIR__) . '/lib/nucleo.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/modelo.php';
require dirname(__DIR__) . '/lib/api.php';
require dirname(__DIR__) . '/lib/importar.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$n = is_file(raiz() . '/data/php/nodo.php') ? (require raiz() . '/data/php/nodo.php') : [];
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || empty($n['tick_token'])
    || !hash_equals((string) $n['tick_token'], (string) ($_SERVER['HTTP_X_IPROY_TICK'] ?? ''))) {
    http_response_code(403); echo '{"ok":false,"error":"solo el worker del nodo"}'; exit;
}
$lock = fopen(raiz() . '/data/pim/run/importar.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo '{"ok":false,"error":"ya hay una importación en curso"}'; exit; }
try {
    echo json_encode(importar(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $t) {
    error_log('[pim-importar] ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'falló la importación (detalle en data/logs/php_errors.log)']);
}
