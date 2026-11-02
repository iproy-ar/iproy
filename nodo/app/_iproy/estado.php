<?php
declare(strict_types=1);
require __DIR__ . '/lib_nodo.php';
header('Cache-Control: no-store');
$e = nodo_estado();
if (($_GET['fmt'] ?? '') === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo $e['ok'] ? "✔ nodo {$e['node_id']} · PHP {$e['php']} · DB {$e['db']} · esquema v{$e['schema']} · worker {$e['worker_ultimo']}"
                  : '✘ ' . ($e['error'] ?? 'error');
    exit;
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($e, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
