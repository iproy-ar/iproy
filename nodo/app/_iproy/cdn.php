<?php
/**
 * iProy Portable · copia local de librerías y fuentes externas · v0.4.0 · 29-10-2026
 * /_iproy/cdn/<host>/<ruta>  (Caddy reescribe a este archivo)
 * Primera vez con red: se descarga y se guarda en data/cdn/. Después se sirve siempre del nodo
 * (se revalida cada 30 días si hay red). Sin red y sin copia: 504. Solo hosts de la lista.
 * v0.4.4: también
 *   · APIs públicas que usa la app (cotización del dólar): copia válida 6 h; sin red, la última;
 *   · archivos ESTÁTICOS de producción que no están en el nodo (p. ej. /servicios.txt en la raíz del sitio):
 *     Caddy los reescribe a /_iproy/cdn/iproy.com.ar/… Solo extensiones estáticas, nunca .php. Copia válida 1 día.
 */
declare(strict_types=1);
require __DIR__ . '/lib_red.php';

const CDN_HOSTS = ['cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com', 'code.jquery.com', 'ajax.googleapis.com',
                   'fonts.googleapis.com', 'fonts.gstatic.com', 'use.fontawesome.com', 'cdn.tailwindcss.com',
                   'dolarapi.com', 'api.bluelytics.com.ar', 'iproy.com.ar'];
const CDN_VIGENCIA = ['dolarapi.com' => 6 * 3600, 'api.bluelytics.com.ar' => 6 * 3600, 'iproy.com.ar' => 86400];   // resto: 30 días
const ESTATICO = '/\.(txt|csv|json|png|jpe?g|gif|svg|ico|webp|css|js|map|woff2?|ttf|pdf)$/i';

$ruta = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
// Archivo estático que falta en el nodo: Caddy (@faltante) manda acá la ruta original → se busca en producción
if (!str_starts_with($ruta, '/_iproy/cdn/')) $ruta = '/_iproy/cdn/iproy.com.ar' . $ruta;
$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
if (!preg_match('#^/_iproy/cdn/([a-z0-9.-]+)(/[A-Za-z0-9._~@%+,:=/-]*)$#i', $ruta, $m) || !in_array(strtolower($m[1]), CDN_HOSTS, true)
    || str_contains($m[2], '..')) { http_response_code(404); exit; }
$host = strtolower($m[1]);
if ($host === 'iproy.com.ar' && !preg_match(ESTATICO, $m[2])) { http_response_code(404); exit; }   // nunca páginas dinámicas
$url = 'https://' . $host . $m[2] . ($qs !== '' ? '?' . $qs : '');
$dir = red_dir() . '/cdn/' . $host;
$base = $dir . '/' . sha1($url);
$meta = is_file($base . '.json') ? (json_decode((string) file_get_contents($base . '.json'), true) ?: null) : null;

function servir(string $base, array $meta, string $via): never {
    header('Content-Type: ' . ($meta['tipo'] ?: 'application/octet-stream'));
    header('Cache-Control: public, max-age=604800');
    header('X-IPROY-Via: ' . $via);
    header('Access-Control-Allow-Origin: *');
    readfile($base . '.bin');
    exit;
}

$fresco = $meta && is_file($base . '.bin') && time() - (int) $meta['ts'] < (CDN_VIGENCIA[$host] ?? 30 * 86400);
if ($fresco || ($meta && !red_probablemente_en_linea())) servir($base, $meta, 'cdn-local');

// Google Fonts entrega woff2 solo a navegadores modernos: se reenvía el agente del navegador
$cab = ['Accept: */*'];
if ($host === 'fonts.googleapis.com' && !empty($_SERVER['HTTP_USER_AGENT'])) $cab[] = 'User-Agent: ' . substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 300);
$r = red_http('GET', $url, $cab, null, 20, red_probablemente_en_linea() ? null : 2);
if ($r['errno'] === 0 && $r['code'] === 200 && $r['cuerpo'] !== '') {
    $tipo = $r['tipo'];
    $cuerpo = $r['cuerpo'];
    if (stripos($tipo, 'text/css') !== false)      // las hojas de estilo apuntan a fuentes de otros hosts
        $cuerpo = preg_replace('~url\((["\']?)https://(' . implode('|', array_map('preg_quote', CDN_HOSTS)) . ')/~i', 'url($1/_iproy/cdn/$2/', $cuerpo) ?? $cuerpo;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    file_put_contents($base . '.bin.tmp', $cuerpo); rename($base . '.bin.tmp', $base . '.bin');
    $meta = ['url' => $url, 'tipo' => $tipo, 'ts' => time(), 'bytes' => strlen($cuerpo)];
    file_put_contents($base . '.json', json_encode($meta, JSON_UNESCAPED_SLASHES));
    servir($base, $meta, 'cdn-descargado');
}
if (red_sin_red($r)) red_estado(['online' => false, 'ultimo_error' => $r['error']]);
if ($meta && is_file($base . '.bin')) servir($base, $meta, 'cdn-local-vencido');
http_response_code($r['code'] >= 400 ? $r['code'] : 504);
header('Content-Type: text/plain; charset=utf-8');
echo $r['code'] >= 400 ? 'El recurso no existe en el origen (HTTP ' . $r['code'] . ').' : 'Recurso no disponible sin conexión: todavía no hay copia en el nodo (abrí la app una vez con Internet o usá «precargar»).';
