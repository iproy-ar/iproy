<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Aplicación de gestión del nodo: una sola página que arma sus pestañas con los módulos cargados (cada uno aporta su ui.js).
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self'");
$p = json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/producto.json'), true) ?: ['nombre' => 'iProy'];
$c = ($p['colores'] ?? []) + ['primario' => '#0d47a1', 'secundario' => '#1e88e5', 'claro' => '#e3f0fd'];
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$v = $e($p['version'] ?? '1');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($p['nombre']) ?></title>
<link rel="icon" href="logo.png">
<style>
:root{--az:<?= $e($c['primario']) ?>;--az3:<?= $e($c['secundario']) ?>;--cel:<?= $e($c['claro']) ?>;--fondo:#f3f8fe;--borde:#cfe0f5;--txt:#16324f;--gris:#5b7089;--ok:#2e7d32;--mal:#c62828;--ama:#ef6c00}
*{box-sizing:border-box}
body{margin:0;font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--txt);background:var(--fondo)}
header{background:linear-gradient(120deg,var(--az) 0%,var(--az3) 60%,#64b5f6 100%);color:#fff;padding:12px 18px 0}
header .fila{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
header img{width:40px;height:40px;border-radius:10px;background:#fff;padding:3px}
header h1{font-size:20px;margin:0;font-weight:700}
.chip{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.45);border-radius:999px;padding:3px 11px;font-size:12.5px;color:#fff}
.der{margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.der button{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.5);border-radius:9px;padding:5px 12px;font:inherit;cursor:pointer}
nav{display:flex;gap:4px;margin-top:10px;overflow-x:auto}
nav button{border:0;background:transparent;color:#e8f2ff;padding:10px 14px;font:inherit;font-weight:600;border-radius:10px 10px 0 0;cursor:pointer;white-space:nowrap}
nav button.activo{background:var(--fondo);color:var(--az)}
main{padding:18px;max-width:1320px;margin:0 auto;min-width:0}
.tarjeta{background:#fff;border:1px solid var(--borde);border-radius:14px;padding:16px;margin-bottom:16px;box-shadow:0 1px 3px rgba(13,71,161,.06);min-width:0;overflow-x:auto}
.tarjeta h2{font-size:16px;margin:0 0 12px;color:var(--az)}
.kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px}
.kpi{background:linear-gradient(160deg,#fff,var(--cel));border:1px solid var(--borde);border-radius:12px;padding:10px 12px}
.kpi b{display:block;font-size:24px;color:var(--az3)}.kpi span{font-size:12.5px;color:var(--gris)}
table{width:100%;border-collapse:collapse;font-size:14px}
th{text-align:left;color:var(--gris);font-weight:600;border-bottom:2px solid var(--borde);padding:7px 6px;white-space:nowrap}
td{border-bottom:1px solid #e6eef8;padding:7px 6px;vertical-align:top}
tr.clic{cursor:pointer}tr.clic:hover{background:var(--cel)}
.et{display:inline-block;border-radius:999px;padding:1px 9px;font-size:12px;font-weight:600;background:var(--cel);color:var(--az3);white-space:nowrap}
.et.rojo{background:#fde7e7;color:var(--mal)}.et.nar{background:#fff1e0;color:var(--ama)}.et.ama{background:#fff8d6;color:#8a6d00}.et.verde{background:#e8f5e9;color:var(--ok)}.et.gris{background:#eceff1;color:#455a64}
.filtros{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;align-items:end}
label{display:block;font-size:12.5px;color:var(--gris);margin-bottom:3px;font-weight:600}
input,select,textarea{font:inherit;color:var(--txt);border:1px solid var(--borde);border-radius:9px;padding:8px 10px;background:#fff;min-height:38px;width:100%}
textarea{min-height:80px}
.grid2{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
button.b{border:0;border-radius:9px;padding:9px 16px;min-height:38px;font:inherit;font-weight:600;cursor:pointer;background:var(--az3);color:#fff}
button.b.sec{background:#fff;color:var(--az3);border:1px solid var(--az3)}
button.b.peligro{background:#fff;color:var(--mal);border:1px solid #e57373}
button.b:disabled{opacity:.5;cursor:default}
.acciones{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.aviso{border-radius:10px;padding:10px 12px;margin:10px 0;font-size:14px}
.aviso.ok{background:#e8f5e9;color:var(--ok);border:1px solid #a5d6a7}.aviso.mal{background:#fdecea;color:var(--mal);border:1px solid #f5b5b1}.aviso.info{background:var(--cel);color:var(--az);border:1px solid var(--borde)}
.panel{position:fixed;inset:0 0 0 auto;width:min(640px,100%);background:#fff;box-shadow:-8px 0 30px rgba(13,71,161,.18);overflow-y:auto;z-index:20;padding:18px;display:none}
.panel.abierto{display:block}
.velo{position:fixed;inset:0;background:rgba(13,71,161,.12);z-index:19;display:none}.velo.abierto{display:block}
dl{display:grid;grid-template-columns:160px 1fr;gap:6px 10px;margin:0}dt{color:var(--gris);font-size:13px}dd{margin:0;overflow-wrap:anywhere}
.mini{font-size:12.5px;color:var(--gris)}
code,pre{font-size:12.5px;background:var(--cel);padding:1px 5px;border-radius:5px}pre{padding:10px;overflow:auto;max-height:340px}
.login{max-width:380px;margin:9vh auto;background:#fff;border:1px solid var(--borde);border-radius:16px;padding:24px;box-shadow:0 8px 30px rgba(13,71,161,.12)}
.login img{width:84px;display:block;margin:0 auto 8px}
.login h2{text-align:center;color:var(--az);margin:4px 0 16px}
.login .campo{margin-bottom:12px}
footer{text-align:center;font-size:12px;color:var(--gris);padding:18px}
footer a{color:var(--az3)}
@media (max-width:640px){dl{grid-template-columns:1fr}main{padding:12px}table{display:block;overflow-x:auto}}
</style>
</head>
<body>
<div id="raiz"></div>
<div class="velo" id="velo"></div>
<aside class="panel" id="panel"></aside>
<footer id="pie"></footer>
<script src="app.js?v=<?= $v ?>"></script>
</body>
</html>
