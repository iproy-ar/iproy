<?php
/** Ping del nodo. ?id=1 → identidad pública (para que otros nodos de la colmena lo reconozcan). Sin secretos.
 *  Ruta neutral /colmena/ping y campo «protocolo»: nodos con distinto nombre de software (IPROY, iProy…) se reconocen (Paso 105). */
header('Cache-Control: no-store');
if (($_GET['id'] ?? '') === '1') {
    require __DIR__ . '/lib_nodo.php';
    $c = nodo_cfg();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'protocolo' => 'colmena/1', 'servicio' => 'iproy-portable', 'node_id' => $c['node_id'] ?? null, 'nombre' => $c['nombre'] ?? null,
                      'version' => $c['json']['version'] ?? null, 'hora' => date('c')], JSON_UNESCAPED_UNICODE);
    exit;
}
header('Content-Type: text/plain'); echo 'ok';
