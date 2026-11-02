<?php
/** Tarea del worker (tick cada 15 s): conectividad, reenvío de la cola a Google y limpieza de la caché. v0.4.0 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib_red.php';
$db = nodo_pdo('iproy');
$e = red_estado();
$pend = red_cola_pendientes($db);
// Se prueba la red cada 30 s, o en cada tick si hay cambios esperando
if ($pend > 0 || time() - (int) ($e['ts_prueba'] ?? 0) >= 30) {
    $ok = red_probar();
    red_estado(['ts_prueba' => time()]);
} else { $ok = ($e['online'] ?? true) !== false; }
$out = $ok ? 'en_linea' : 'sin_red';
if ($ok && $pend > 0) $out .= ' cola:' . red_vaciar_cola($db);
// Limpieza diaria: lecturas en caché sin usar hace más de 90 días
if ((int) date('G') === 3 && (int) (nodo_kv($db, 'cache_limpieza') ?? 0) < time() - 20 * 3600) {
    $n = $db->exec('DELETE FROM iproy_gas_cache WHERE actualizado < NOW() - INTERVAL 90 DAY');
    nodo_kv($db, 'cache_limpieza', (string) time());
    $out .= " cache_borradas:$n";
}
return $out;
