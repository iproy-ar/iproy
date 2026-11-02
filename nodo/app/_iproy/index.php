<?php
declare(strict_types=1);
require __DIR__ . '/lib_nodo.php';
header('Cache-Control: no-store');
$e = nodo_estado();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nodo IPROY <?= $h($e['node_id'] ?? '') ?></title>
<style>
  :root { --az1:#0d47a1; --az2:#1976d2; --az3:#42a5f5; --fondo:#f4f8fd; --borde:#d6e4f5; --texto:#1b2b40; --ok:#2e7d32; --mal:#c62828; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:system-ui,"Segoe UI",Roboto,sans-serif; background:var(--fondo); color:var(--texto); }
  header { background:linear-gradient(135deg,var(--az1),var(--az2) 55%,var(--az3)); color:#fff; padding:18px 16px; }
  header h1 { margin:0; font-size:1.25rem; } header p { margin:4px 0 0; opacity:.9; font-size:.9rem; }
  main { max-width:960px; margin:0 auto; padding:16px; display:grid; gap:14px; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); }
  .card { background:#fff; border:1px solid var(--borde); border-radius:12px; padding:14px 16px; box-shadow:0 1px 3px rgba(13,71,161,.06); }
  .card h2 { margin:0 0 8px; font-size:1rem; color:var(--az1); }
  .fila { display:flex; justify-content:space-between; gap:8px; padding:4px 0; border-bottom:1px dashed var(--borde); font-size:.92rem; }
  .fila:last-child { border-bottom:0; } .ok { color:var(--ok); font-weight:600; } .mal { color:var(--mal); font-weight:600; }
  a.boton { display:inline-block; min-height:44px; line-height:44px; padding:0 18px; border-radius:10px; color:#fff; text-decoration:none;
            background:linear-gradient(135deg,var(--az1),var(--az2)); font-weight:600; }
  code { background:#eef4fc; padding:1px 5px; border-radius:4px; }
  table { width:100%; border-collapse:collapse; font-size:.88rem; } td,th { text-align:left; padding:3px 2px; border-bottom:1px solid var(--borde); }
</style>
</head>
<body>
<header>
  <h1>Nodo <?= $h($e['node_id'] ?? '—') ?> · <?= $h($e['nombre'] ?? 'sin inicializar') ?></h1>
  <p>iProy Portable «Colmena» · v<?= $h($e['version'] ?? '?') ?> · modo <?= $h($e['modo'] ?? '?') ?></p>
</header>
<main>
  <section class="card">
    <h2>Estado</h2>
    <div class="fila"><span>Servidor</span><span class="ok">PHP <?= $h($e['php']) ?></span></div>
    <div class="fila"><span>Base de datos</span><span class="<?= $e['ok'] ? 'ok' : 'mal' ?>"><?= $h($e['ok'] ? 'MariaDB ' . $e['db'] : ($e['error'] ?? 'sin conexión')) ?></span></div>
    <div class="fila"><span>Esquema de sync</span><span>v<?= $h($e['schema'] ?? '?') ?></span></div>
    <div class="fila"><span>Último latido del worker</span><span><?= $h($e['worker_ultimo'] ?? '—') ?></span></div>
  </section>
  <section class="card">
    <h2>Aplicación</h2>
    <?php if ($e['app_iproy']): ?>
      <p>La copia de iProy está instalada en este nodo.</p>
      <a class="boton" href="/iProy/">Abrir iProy</a>
    <?php else: ?>
      <p>Todavía no hay copia de iProy. Copiá la carpeta a <code>descargas/iProy/</code> y ejecutá
         <code>iProy-Control importar-app</code>.</p>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2>Redes y conexión</h2>
    <?php $re = is_file(dirname(__DIR__, 2) . '/data/run/red_estado.json') ? (json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/data/run/red_estado.json'), true) ?: []) : []; ?>
    <div class="fila"><span>Servidores centrales</span><span class="<?= ($re['online'] ?? null) === false ? 'mal' : 'ok' ?>"><?= ($re['online'] ?? null) === false ? 'sin conexión · trabajando solo' : (isset($re['online']) ? 'en línea' : 'sin probar') ?></span></div>
    <p>Modo del nodo, IP, otros nodos de la colmena, hub, cola de cambios e ingreso sin Internet.</p>
    <a class="boton" href="/_iproy/red.php">Configuración › Redes</a>
  </section>
  <section class="card">
    <h2>Bases locales</h2>
    <table><tr><th>Base</th><th>Tablas</th><th>Filas (aprox.)</th></tr>
    <?php foreach ($e['bases'] ?? [] as $b): ?>
      <tr><td><?= $h($b['base']) ?></td><td><?= (int) $b['tablas'] ?></td><td><?= number_format((int) $b['filas'], 0, ',', '.') ?></td></tr>
    <?php endforeach; ?>
    </table>
    <?php if (array_sum(array_map(fn($b) => (int) $b['filas'], $e['bases'] ?? [])) === 0): ?>
      <p>Sin datos todavía: dejá los <code>.sql</code> exportados en <code>descargas/sql/</code> y ejecutá <code>iProy-Control importar-datos</code>.</p>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
