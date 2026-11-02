<?php
/**
 * Raíz del nodo · v0.4.5 · 29-10-2026
 * «/» → la app (iProy) o el panel del nodo.
 * Cualquier OTRA ruta que llegue acá es algo que no existe en el nodo (php_server usa este archivo como último
 * recurso). Antes se redirigía a /iProy/ y, dentro de un iframe (p. ej. Preventivos), aparecía el dashboard
 * duplicado. Ahora responde 404 con la ruta, para que se vea qué falta.
 */
$ruta = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($ruta === '/' || $ruta === '/index.php') {
    header('Location: ' . (is_file(__DIR__ . '/iProy/index.php') ? '/iProy/' : (is_file(__DIR__ . '/app/index.php') ? '/app/' : '/_iproy/')), true, 302);
    exit;
}
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$h = htmlspecialchars($ruta, ENT_QUOTES, 'UTF-8');
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>No está en este nodo</title>
<style>body{margin:0;font-family:system-ui,"Segoe UI",Roboto,sans-serif;background:#f4f8fd;color:#1b2b40;display:flex;min-height:100vh;align-items:center;justify-content:center}
.c{max-width:560px;margin:16px;background:#fff;border:1px solid #d6e4f5;border-radius:12px;padding:20px 22px;box-shadow:0 1px 3px rgba(13,71,161,.08)}
h1{margin:0 0 8px;font-size:1.1rem;color:#0d47a1}code{background:#eef4fc;padding:2px 6px;border-radius:4px;word-break:break-all}p{font-size:.92rem;line-height:1.45}</style></head>
<body><div class="c"><h1>Esta parte no está instalada en el nodo</h1>
<p>El nodo no tiene <code><?= $h ?></code>.</p>
<p>Si existe en el servidor, copiala dentro de <code>descargas/iProy/</code> (misma ruta) y ejecutá <code>iProy-Control importar-app</code>.</p></div></body></html>
