<?php
// iProy · Consola PIM v1.0 · 30-10-2026 — cliente web del Servidor PIM: trabaja SOLO a través de la API /pim/r4 (vía proxy.php).
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self'");
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Consola PIM · iProy</title>
<style>
:root{--az:#0d47a1;--az2:#1565c0;--az3:#1e88e5;--cel:#e3f0fd;--fondo:#f3f8fe;--borde:#cfe0f5;--txt:#16324f;--gris:#5b7089;--ok:#2e7d32;--mal:#c62828;--ama:#ef6c00}
*{box-sizing:border-box}
body{margin:0;font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--txt);background:var(--fondo)}
header{background:linear-gradient(120deg,var(--az) 0%,var(--az3) 60%,#64b5f6 100%);color:#fff;padding:14px 18px 0}
header .fila{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
header h1{font-size:20px;margin:0;font-weight:700;letter-spacing:.2px}
header .chip{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.45);border-radius:999px;padding:3px 11px;font-size:12.5px}
nav{display:flex;gap:4px;margin-top:12px;overflow-x:auto}
nav button{border:0;background:transparent;color:#e8f2ff;padding:10px 14px;font:inherit;font-weight:600;border-radius:10px 10px 0 0;cursor:pointer;white-space:nowrap}
nav button.activo{background:var(--fondo);color:var(--az)}
main{padding:18px;max-width:1280px;margin:0 auto}
.tarjeta{background:#fff;border:1px solid var(--borde);border-radius:14px;padding:16px;margin-bottom:16px;box-shadow:0 1px 3px rgba(13,71,161,.06)}
.tarjeta h2{font-size:16px;margin:0 0 12px;color:var(--az)}
.kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
.kpi{background:linear-gradient(160deg,#fff,var(--cel));border:1px solid var(--borde);border-radius:12px;padding:10px 12px;cursor:pointer}
.kpi b{display:block;font-size:24px;color:var(--az2)}.kpi span{font-size:12.5px;color:var(--gris)}
table{width:100%;border-collapse:collapse;font-size:14px}
th{text-align:left;color:var(--gris);font-weight:600;border-bottom:2px solid var(--borde);padding:7px 6px;white-space:nowrap}
td{border-bottom:1px solid #e6eef8;padding:7px 6px;vertical-align:top}
tr.clic{cursor:pointer}tr.clic:hover{background:var(--cel)}
.et{display:inline-block;border-radius:999px;padding:1px 9px;font-size:12px;font-weight:600;background:var(--cel);color:var(--az2);white-space:nowrap}
.et.stat{background:#fde7e7;color:var(--mal)}.et.asap{background:#fff1e0;color:var(--ama)}.et.urgent{background:#fff8d6;color:#8a6d00}.et.leg{background:#eceff1;color:#455a64}
.et.A{background:#fde7e7;color:var(--mal)}.et.B{background:#fff1e0;color:var(--ama)}.et.C{background:#fff8d6;color:#8a6d00}.et.D{background:#e8f5e9;color:var(--ok)}
.filtros{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;align-items:end}
label{display:block;font-size:12.5px;color:var(--gris);margin-bottom:3px;font-weight:600}
input,select,textarea{font:inherit;color:var(--txt);border:1px solid var(--borde);border-radius:9px;padding:8px 10px;background:#fff;min-height:38px;width:100%}
textarea{min-height:80px}
.campo{min-width:160px;flex:1}
.grid2{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
button.b{border:0;border-radius:9px;padding:9px 16px;min-height:38px;font:inherit;font-weight:600;cursor:pointer;background:var(--az2);color:#fff}
button.b.sec{background:#fff;color:var(--az2);border:1px solid var(--az3)}
button.b.peligro{background:#fff;color:var(--mal);border:1px solid #e57373}
button.b:disabled{opacity:.5;cursor:default}
.acciones{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.aviso{border-radius:10px;padding:10px 12px;margin:10px 0;font-size:14px}
.aviso.ok{background:#e8f5e9;color:var(--ok);border:1px solid #a5d6a7}.aviso.mal{background:#fdecea;color:var(--mal);border:1px solid #f5b5b1}.aviso.info{background:var(--cel);color:var(--az2);border:1px solid var(--borde)}
.panel{position:fixed;inset:0 0 0 auto;width:min(620px,100%);background:#fff;box-shadow:-8px 0 30px rgba(13,71,161,.18);overflow-y:auto;z-index:20;padding:18px;display:none}
.panel.abierto{display:block}
.velo{position:fixed;inset:0;background:rgba(13,71,161,.12);z-index:19;display:none}.velo.abierto{display:block}
dl{display:grid;grid-template-columns:150px 1fr;gap:6px 10px;margin:0}dt{color:var(--gris);font-size:13px}dd{margin:0}
.nota{background:var(--fondo);border-left:3px solid var(--az3);padding:6px 10px;margin:6px 0;border-radius:0 8px 8px 0;font-size:14px}
.mini{font-size:12.5px;color:var(--gris)}
.oculto{display:none}
.pag{display:flex;gap:8px;align-items:center;margin-top:10px}
code{font-size:12.5px;background:var(--cel);padding:1px 5px;border-radius:5px}
@media (max-width:640px){dl{grid-template-columns:1fr}main{padding:12px}table{display:block;overflow-x:auto}header .chip{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}}
main,.tarjeta{min-width:0;max-width:100%}
</style>
</head>
<body>
<header>
  <div class="fila">
    <h1>Consola PIM</h1>
    <span class="chip" id="chipNodo">conectando…</span>
    <span class="chip" id="chipVer"></span>
  </div>
  <nav id="nav">
    <button data-v="tablero" class="activo">Tablero</button>
    <button data-v="ordenes">Órdenes</button>
    <button data-v="solicitud">Nueva solicitud</button>
    <button data-v="inventario">Inventario</button>
    <button data-v="telemetria">Telemetría</button>
    <button data-v="acerca">Acerca de</button>
  </nav>
</header>
<main id="main"></main>
<div class="velo" id="velo"></div>
<aside class="panel" id="panel"></aside>
<script src="app.js?v=1.1.2"></script>
</body>
</html>
