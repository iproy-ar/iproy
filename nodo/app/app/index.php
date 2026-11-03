<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Aplicación de gestión del nodo: una sola página. Tablero con accesos rápidos por categoría, widgets de los módulos,
// barra superior con navegación, menú lateral, perfil del usuario (fondo propio) y configuración institucional (logo y fondos).
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; frame-ancestors 'self'");
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
:root{--az:<?= $e($c['primario']) ?>;--az3:<?= $e($c['secundario']) ?>;--cel:<?= $e($c['claro']) ?>;--fondo:#f3f6fb;--borde:#dde6f1;--txt:#16263a;--gris:#5f6f84;--ok:#2e7d32;--mal:#c62828;--ama:#ef6c00;
--sombra:0 1px 2px rgba(16,38,70,.06),0 4px 14px rgba(16,38,70,.06);--sombra2:0 10px 28px rgba(16,38,70,.16);--r:14px;--op:.82}
*{box-sizing:border-box}html,body{height:100%}
body{margin:0;font:14.5px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans",sans-serif;color:var(--txt);background:var(--fondo) center/cover fixed no-repeat}
button{font:inherit}a{color:var(--az3)}
svg.i{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}
/* ---------- capa sobre el fondo elegido (como los temas de Gmail) ---------- */
#capa{min-height:100vh;background:rgba(243,246,251,var(--op))}
/* ---------- barra superior ---------- */
.barra{position:sticky;top:0;z-index:15;display:flex;align-items:center;gap:12px;padding:10px 18px;background:rgba(255,255,255,.92);backdrop-filter:blur(10px);border-bottom:1px solid var(--borde)}
.ib{display:inline-grid;place-items:center;width:36px;height:36px;border-radius:10px;border:1px solid var(--borde);background:#fff;color:var(--txt);cursor:pointer;transition:background .15s,transform .15s}
.ib:hover{background:var(--cel)}.ib:active{transform:scale(.95)}.ib:disabled{opacity:.4;cursor:default}.ib.sin{border:0;background:transparent}
.tit{min-width:0}.tit b{display:block;font-size:17px;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tit small{color:var(--gris);font-size:12.5px}
.navb{display:flex;gap:6px}
.der{margin-left:auto;display:flex;align-items:center;gap:10px}
.pildora{display:inline-flex;align-items:center;gap:7px;border:1px solid #b7dfbd;background:#eef9ef;color:#1b5e20;border-radius:999px;padding:5px 12px;font-weight:600;font-size:13px;cursor:pointer}
.pildora::before{content:"";width:8px;height:8px;border-radius:50%;background:#2e7d32;box-shadow:0 0 0 3px rgba(46,125,50,.15)}
.menuu{position:absolute;right:18px;top:56px;background:#fff;border:1px solid var(--borde);border-radius:12px;box-shadow:var(--sombra2);padding:6px;min-width:220px;display:none;z-index:30}
.menuu.abierto{display:block}.menuu button{display:flex;gap:10px;align-items:center;width:100%;border:0;background:none;padding:9px 10px;border-radius:8px;cursor:pointer;color:var(--txt);text-align:left}
.menuu button:hover{background:var(--cel)}.menuu hr{border:0;border-top:1px solid var(--borde);margin:4px 0}
/* ---------- menú lateral ---------- */
.lateral{position:fixed;inset:0 auto 0 0;width:290px;max-width:86vw;background:#fff;z-index:40;transform:translateX(-102%);transition:transform .25s ease;box-shadow:var(--sombra2);display:flex;flex-direction:column}
.lateral.abierto{transform:none}
.lateral .cab{display:flex;gap:10px;align-items:center;padding:16px;border-bottom:1px solid var(--borde)}
.lateral .cab img{width:42px;height:42px;object-fit:contain;border-radius:10px;background:#fff;border:1px solid var(--borde)}
.lateral .cab b{display:block;line-height:1.15}.lateral .cab small{color:var(--gris)}
.lateral nav{overflow-y:auto;padding:8px;flex:1}
.lateral h6{margin:12px 8px 4px;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--gris);display:flex;align-items:center;gap:6px}
.lateral h6 i{width:7px;height:7px;border-radius:50%}
.lateral nav button{display:flex;gap:10px;align-items:center;width:100%;border:0;background:none;padding:8px 10px;border-radius:9px;cursor:pointer;color:var(--txt);text-align:left}
.lateral nav button:hover,.lateral nav button.activo{background:var(--cel);color:var(--az)}
.velo{position:fixed;inset:0;background:rgba(16,38,70,.28);z-index:35;display:none}.velo.abierto{display:block}
/* ---------- contenido ---------- */
main{padding:22px 22px 10px;max-width:1360px;margin:0 auto;min-width:0}
.saludo h1{margin:0;font-size:24px;letter-spacing:-.01em}.saludo p{margin:4px 0 0;color:var(--gris)}
.secc{margin:22px 0 10px;font-size:12px;letter-spacing:.07em;text-transform:uppercase;color:var(--gris);font-weight:700}
/* tarjetas KPI estilo «Resumen» */
.kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(205px,1fr));gap:14px}
.kpi{position:relative;display:flex;gap:12px;align-items:center;padding:14px 16px;border-radius:var(--r);border:1px solid var(--borde);background:linear-gradient(150deg,#fff 35%,color-mix(in srgb,var(--kc,var(--az3)) 16%,#fff) 100%);box-shadow:var(--sombra);transition:transform .18s,box-shadow .18s}
.kpi:hover{transform:translateY(-3px);box-shadow:var(--sombra2)}
.kpi .ico{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;color:#fff;background:linear-gradient(135deg,var(--kc,var(--az3)),color-mix(in srgb,var(--kc,var(--az3)) 70%,#000));flex:none;box-shadow:0 4px 10px color-mix(in srgb,var(--kc,var(--az3)) 35%,transparent)}
.kpi .tx{min-width:0}.kpi small{display:block;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:color-mix(in srgb,var(--kc,var(--az)) 75%,#000)}
.kpi b{display:block;font-size:24px;line-height:1.1;color:color-mix(in srgb,var(--kc,var(--az)) 70%,#000)}.kpi span{font-size:12px;color:var(--gris)}
.widget{margin-bottom:14px}
.tarjeta{background:#fff;border:1px solid var(--borde);border-radius:var(--r);padding:16px;margin-bottom:14px;box-shadow:var(--sombra);min-width:0;overflow-x:auto}
.tarjeta h2{font-size:15.5px;margin:0 0 12px;color:var(--az)}
/* accesos rápidos */
.buscar{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.caja{position:relative;flex:0 1 420px}.caja svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--gris)}
.caja input{padding-left:40px;border-radius:12px;background:#fff;min-height:42px}.caja kbd{position:absolute;right:10px;top:50%;transform:translateY(-50%);border:1px solid var(--borde);border-radius:6px;padding:0 6px;font-size:12px;color:var(--gris);background:#f7f9fc}
.recientes{display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:13px;color:var(--gris)}
.chipr{display:inline-flex;gap:6px;align-items:center;border:1px solid var(--borde);background:#fff;border-radius:999px;padding:5px 12px;cursor:pointer;color:var(--txt);transition:border-color .15s,box-shadow .15s}
.chipr:hover{border-color:var(--az3);box-shadow:0 2px 8px rgba(30,136,229,.15)}.chipr svg{width:15px;height:15px}
.grupos{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,430px),1fr));gap:4px 28px}
.grupo h3{display:flex;align-items:center;gap:8px;margin:14px 0 8px;font-size:12.5px;color:var(--gris);font-weight:700}
.grupo h3 i{width:8px;height:8px;border-radius:50%;background:var(--gc)}.grupo h3 em{flex:1;height:1px;background:var(--borde)}.grupo h3 span{font-style:normal;font-weight:600;color:#9aa8b9}
.tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
.tile{display:flex;align-items:center;gap:12px;padding:12px 14px;min-height:62px;border-radius:var(--r);border:1px solid var(--borde);background:#fff;cursor:pointer;text-align:left;color:var(--txt);font-weight:650;box-shadow:var(--sombra);transition:transform .18s,box-shadow .18s,border-color .18s}
.tile .ico{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;color:#fff;background:linear-gradient(135deg,var(--gc),color-mix(in srgb,var(--gc) 68%,#000));flex:none;transition:transform .2s}
.tile .ico svg{width:19px;height:19px}.tile small{display:block;font-weight:400;color:var(--gris);font-size:12px;line-height:1.25}
.tile:hover{transform:translateY(-3px);box-shadow:var(--sombra2);border-color:color-mix(in srgb,var(--gc) 45%,var(--borde))}.tile:hover .ico{transform:scale(1.08) rotate(-3deg)}
.tile:focus-visible,.chipr:focus-visible,.ib:focus-visible{outline:3px solid color-mix(in srgb,var(--az3) 45%,transparent);outline-offset:2px}
.tile.oculto{display:none}
/* tablas, formularios y avisos */
table{width:100%;border-collapse:collapse;font-size:14px}
th{text-align:left;color:var(--gris);font-weight:600;border-bottom:2px solid var(--borde);padding:8px 7px;white-space:nowrap}
td{border-bottom:1px solid #edf2f8;padding:8px 7px;vertical-align:top}
tr.clic{cursor:pointer}tr.clic:hover{background:var(--cel)}
.et{display:inline-block;border-radius:999px;padding:1px 9px;font-size:12px;font-weight:600;background:var(--cel);color:var(--az3);white-space:nowrap}
.et.rojo{background:#fde7e7;color:var(--mal)}.et.nar{background:#fff1e0;color:var(--ama)}.et.ama{background:#fff8d6;color:#8a6d00}.et.verde{background:#e8f5e9;color:var(--ok)}.et.gris{background:#eceff1;color:#455a64}
.filtros{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;align-items:end}
label{display:block;font-size:12.5px;color:var(--gris);margin-bottom:3px;font-weight:600}
input,select,textarea{font:inherit;color:var(--txt);border:1px solid var(--borde);border-radius:10px;padding:8px 11px;background:#fff;min-height:40px;width:100%}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--az3);box-shadow:0 0 0 4px color-mix(in srgb,var(--az3) 16%,transparent)}
textarea{min-height:80px}input[type=range]{padding:0;min-height:0;accent-color:var(--az3)}
.grid2{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
button.b{border:0;border-radius:10px;padding:9px 16px;min-height:40px;font-weight:600;cursor:pointer;background:linear-gradient(135deg,var(--az3),var(--az));color:#fff;display:inline-flex;align-items:center;gap:8px;justify-content:center;transition:filter .15s,transform .15s}
button.b:hover{filter:brightness(1.07)}button.b:active{transform:scale(.98)}
button.b.sec{background:#fff;color:var(--az3);border:1px solid var(--az3)}button.b.peligro{background:#fff;color:var(--mal);border:1px solid #e57373}
button.b:disabled{opacity:.5;cursor:default}
.acciones{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.aviso{border-radius:10px;padding:10px 12px;margin:10px 0;font-size:14px}
.aviso.ok{background:#e8f5e9;color:var(--ok);border:1px solid #a5d6a7}.aviso.mal{background:#fdecea;color:var(--mal);border:1px solid #f5b5b1}.aviso.info{background:var(--cel);color:var(--az);border:1px solid var(--borde)}
.panel{position:fixed;inset:0 0 0 auto;width:min(660px,100%);background:#fff;box-shadow:-8px 0 30px rgba(16,38,70,.18);overflow-y:auto;z-index:45;padding:18px;transform:translateX(102%);transition:transform .25s ease}
.panel.abierto{transform:none}
dl{display:grid;grid-template-columns:160px 1fr;gap:6px 10px;margin:0}dt{color:var(--gris);font-size:13px}dd{margin:0;overflow-wrap:anywhere}
.mini{font-size:12.5px;color:var(--gris)}
code,pre{font-size:12.5px;background:var(--cel);padding:1px 5px;border-radius:5px}pre{padding:10px;overflow:auto;max-height:340px}
/* ---------- ingreso ---------- */
.ingreso{min-height:100vh;display:grid;place-items:center;padding:24px;background:center/cover no-repeat}
.vidrio{width:min(400px,100%);background:rgba(255,255,255,.88);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,.7);border-radius:22px;padding:28px 26px;box-shadow:0 20px 50px rgba(16,38,70,.25);animation:sube .45s ease}
@keyframes sube{from{opacity:0;transform:translateY(14px)}}
.vidrio .logo{display:block;margin:0 auto 10px;max-width:180px;max-height:96px;object-fit:contain}
.vidrio h1{text-align:center;font-size:19px;margin:6px 0 2px}.vidrio .sub{text-align:center;color:var(--gris);margin:0 0 18px;font-size:13.5px}
.campo{position:relative;margin-bottom:12px}.campo>svg{position:absolute;left:12px;bottom:11px;color:var(--gris)}.campo input{padding-left:40px}
.campo .ojo{position:absolute;right:6px;bottom:4px;border:0;background:none;cursor:pointer;color:var(--gris);padding:6px;display:grid;place-items:center}.campo input[type=password],.campo input[name=c]{padding-right:42px}
.vidrio .pie2{text-align:center;font-size:12px;color:var(--gris);margin-top:16px}
/* ---------- configuración, perfil y galería de fondos ---------- */
.candado{max-width:400px;margin:8vh auto;text-align:center}.candado .ico{width:52px;height:52px;border-radius:14px;display:grid;place-items:center;margin:0 auto 10px;color:#fff;background:linear-gradient(135deg,#37577a,#1c3550)}
.candado h2{margin:0 0 4px;color:var(--txt)}.candado p{color:var(--gris);margin:0 0 16px}
.pest{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}.pest button{border:1px solid var(--borde);background:#fff;border-radius:999px;padding:7px 14px;cursor:pointer;font-weight:600;color:var(--gris)}
.pest button.activo{background:var(--az3);border-color:var(--az3);color:#fff}
.galeria{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:10px}
.mini-fondo{aspect-ratio:16/10;border-radius:12px;border:2px solid transparent;cursor:pointer;background:center/cover;position:relative;box-shadow:var(--sombra);transition:transform .15s}
.mini-fondo:hover{transform:scale(1.04)}.mini-fondo.sel{border-color:var(--az3);box-shadow:0 0 0 3px color-mix(in srgb,var(--az3) 30%,transparent)}
.mini-fondo span{position:absolute;left:6px;bottom:5px;font-size:11px;font-weight:700;background:rgba(255,255,255,.85);border-radius:6px;padding:1px 6px;color:var(--txt)}
.mini-fondo.subir{display:grid;place-items:center;background:#fff;border:2px dashed var(--borde);color:var(--gris);font-size:12px;text-align:center}
.vista-logo{display:flex;gap:16px;align-items:center;flex-wrap:wrap}.vista-logo .marco{width:120px;height:120px;border-radius:16px;border:1px dashed var(--borde);display:grid;place-items:center;background:repeating-conic-gradient(#f3f6fb 0 25%,#fff 0 50%) 0/18px 18px}
.vista-logo img{max-width:104px;max-height:104px;object-fit:contain}
.orden-w{list-style:none;padding:0;margin:0}.orden-w li{display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid var(--borde);border-radius:10px;margin-bottom:6px;background:#fff}
.orden-w li b{flex:1;font-weight:600}.orden-w input{width:auto;min-height:0}
footer{text-align:center;font-size:12px;color:var(--gris);padding:16px}
footer a{color:var(--az3)}
@media (max-width:760px){.navb{display:none}main{padding:14px}dl{grid-template-columns:1fr}table{display:block;overflow-x:auto}.tit small{display:none}.saludo h1{font-size:20px}}
@media (prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}}
</style>
</head>
<body>
<div id="raiz"></div>
<div class="velo" id="velo"></div>
<aside class="panel" id="panel"></aside>
<script src="app.js?v=<?= $v ?>"></script>
</body>
</html>
