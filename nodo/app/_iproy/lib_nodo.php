<?php
/** iProy Portable · utilidades del nodo · v0.2.0 · 28-10-2026 */
declare(strict_types=1);
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

function nodo_cfg(): array {
    static $c = null;
    if ($c !== null) return $c;
    $f = dirname(__DIR__, 2) . '/data/php/nodo.php';
    $c = is_file($f) ? (require $f) : [];
    $j = dirname(__DIR__, 2) . '/data/node.json';
    $c['json'] = is_file($j) ? (json_decode((string) file_get_contents($j), true) ?: []) : [];
    return $c;
}
function nodo_pdo(string $base = 'iproy'): PDO {
    $c = nodo_cfg();
    if (empty($c['db_pass'])) throw new RuntimeException('nodo sin inicializar');
    $p = new PDO('mysql:host=' . $c['db_host'] . ';dbname=' . $base . ';charset=utf8mb4', $c['db_user'], $c['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
    $p->exec("SET time_zone = '-03:00'");
    return $p;
}
function nodo_kv(PDO $p, string $k, ?string $v = null): ?string {
    if ($v !== null) { $p->prepare('REPLACE INTO sync_kv (clave, valor) VALUES (?, ?)')->execute([$k, $v]); return $v; }
    $q = $p->prepare('SELECT valor FROM sync_kv WHERE clave = ?'); $q->execute([$k]);
    $r = $q->fetchColumn(); return $r === false ? null : (string) $r;
}
function nodo_estado(): array {
    $c = nodo_cfg();
    $e = ['ok' => false, 'node_id' => $c['node_id'] ?? null, 'nombre' => $c['nombre'] ?? null, 'modo' => $c['modo'] ?? null,
          'version' => $c['json']['version'] ?? null, 'php' => PHP_VERSION, 'servidor' => $_SERVER['SERVER_SOFTWARE'] ?? 'FrankenPHP',
          'app_iproy' => is_file(dirname(__DIR__) . '/iProy/index.php'), 'hora' => date('c')];
    try {
        $p = nodo_pdo();
        $e['db'] = $p->query('SELECT VERSION()')->fetchColumn();
        $e['schema'] = (int) $p->query('SELECT MAX(version) FROM sync_schema')->fetchColumn();
        $e['bases'] = $p->query("SELECT table_schema AS base, COUNT(*) AS tablas, COALESCE(SUM(table_rows),0) AS filas
                                 FROM information_schema.tables WHERE table_schema LIKE 'legado\\_%' OR table_schema = 'iproy'
                                 GROUP BY table_schema ORDER BY table_schema")->fetchAll(PDO::FETCH_ASSOC);
        $e['worker_ultimo'] = nodo_kv($p, 'worker_ultimo');
        $e['ok'] = true;
    } catch (Throwable $x) { $e['error'] = $x->getMessage(); }
    return $e;
}
