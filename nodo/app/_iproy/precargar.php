<?php
/**
 * iProy Portable · «Preparar para trabajar sin Internet» · v0.4.4 · 29-10-2026
 * Recorre app/iProy, junta las librerías de CDN, las APIs públicas y los archivos estáticos de producción
 * que la app pide (p. ej. /servicios.txt) y los descarga a la copia del nodo pasando por /_iproy/cdn/.
 * Solo desde este equipo, con el token del panel o del worker. Lo llaman el panel de Redes y `iProy-Control precargar`.
 * Las consultas a los Apps Script no se pueden adivinar: se guardan solas al usar cada widget con red.
 */
declare(strict_types=1);
require __DIR__ . '/lib_red.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_time_limit(300);
$c = nodo_cfg();
$ok = red_es_local() && (hash_equals(red_token_panel(), (string) ($_SERVER['HTTP_X_IPROY_PANEL'] ?? ''))
      || (!empty($c['tick_token']) && hash_equals((string) $c['tick_token'], (string) ($_SERVER['HTTP_X_IPROY_TICK'] ?? ''))));
if (!$ok) { http_response_code(403); echo '{"ok":false}'; exit; }

$app = dirname(__DIR__) . '/iProy';
if (!is_dir($app)) { echo '{"ok":false,"mensaje":"no hay app/iProy"}'; exit; }
$hosts = 'cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|unpkg\.com|code\.jquery\.com|ajax\.googleapis\.com|fonts\.googleapis\.com|use\.fontawesome\.com|cdn\.tailwindcss\.com|dolarapi\.com|api\.bluelytics\.com\.ar';
$urls = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!preg_match('/\.(js|php|html?|css)$/i', $f->getFilename()) || $f->getSize() > 3 * 1048576) continue;
    $t = (string) @file_get_contents($f->getPathname());
    if (preg_match_all('~https://(' . $hosts . ')(/[A-Za-z0-9._\~@%+,:=/-]+)~i', $t, $m, PREG_SET_ORDER))
        foreach ($m as $x) $urls['/_iproy/cdn/' . strtolower($x[1]) . rtrim($x[2], '.,')] = 1;
    // Archivos estáticos pedidos a la raíz del sitio que no viven dentro de iProy (p. ej. fetch('/servicios.txt'))
    if (preg_match_all('~[\'"`](/(?!_iproy/|fhir/|iProy/)[A-Za-z0-9_./-]+\.(?:txt|csv|json))[\'"`]~', $t, $m))
        foreach ($m[1] as $r) if (!is_file(dirname(__DIR__) . $r)) $urls['/_iproy/cdn/iproy.com.ar' . $r] = 1;
}
$urls = array_keys($urls);
$env = [];
foreach ((array) @file(red_dir() . '/iproy.env', FILE_IGNORE_NEW_LINES) as $l) if (preg_match('/^(IPROY_[A-Z_]+)="?([^"]*)"?$/', (string) $l, $mm)) $env[$mm[1]] = $mm[2];
$base = 'http://127.0.0.1:' . (int) ($env['IPROY_PUERTO'] ?? 8080);
$res = ['ok' => true, 'total' => count($urls), 'listos' => 0, 'fallidos' => []];
foreach (array_chunk($urls, 3) as $lote) {
    $mh = curl_multi_init(); $hs = [];
    foreach ($lote as $u) {
        $ch = curl_init($base . $u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_NOPROXY => '*',
                                CURLOPT_USERAGENT => $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0 IPROY-Portable']);
        curl_multi_add_handle($mh, $ch); $hs[$u] = $ch;
    }
    do { $s = curl_multi_exec($mh, $act); if ($act) curl_multi_select($mh, 0.5); } while ($act && $s === CURLM_OK);
    foreach ($hs as $u => $ch) {
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 200) $res['listos']++; else $res['fallidos'][] = ['url' => substr($u, 10), 'http' => $code];
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
}
echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
