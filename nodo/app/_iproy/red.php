<?php
/**
 * iProy Portable · Configuración › Redes · v0.4.0 · 29-10-2026
 * GET  /_iproy/red.php             → panel (solo desde este equipo)
 * GET  /_iproy/red.php?estado=1    → {online, cola, dudosos} (lo consulta iproy-offline.js; sin datos personales)
 * GET  /_iproy/red.php?ca=1        → certificado PÚBLICO de la CA del nodo (para confiar en él desde otras PC)
 * POST /_iproy/red.php             → acciones del panel (solo desde este equipo + token del panel)
 */
declare(strict_types=1);
require __DIR__ . '/lib_red.php';
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');   // v0.4.5: se muestra dentro de Configuración de iProy

$caFile = red_dir() . '/caddy/pki/authorities/local/root.crt';

if (isset($_GET['estado'])) {
    header('Content-Type: application/json; charset=utf-8');
    $e = red_estado(); $nc = nodo_cfg();
    $o = ['online' => $e['online'] ?? null, 'ultimo_ok' => $e['ultimo_ok'] ?? null, 'cola' => 0, 'dudosos' => 0,
          'node_id' => $nc['node_id'] ?? null, 'nombre' => $nc['nombre'] ?? null, 'local' => red_es_local()];
    try { $db = nodo_pdo('iproy');
        foreach ($db->query("SELECT estado, COUNT(*) n FROM iproy_gas_cola WHERE estado IN ('pendiente','dudoso','error') GROUP BY estado") as $f)
            $o[$f['estado'] === 'pendiente' ? 'cola' : ($f['estado'] === 'dudoso' ? 'dudosos' : 'errores')] = (int) $f['n'];
    } catch (Throwable $x) { $o['db'] = false; }
    echo json_encode($o); exit;
}
if (isset($_GET['ca'])) {
    if (!is_file($caFile)) { http_response_code(404); echo 'El nodo todavía no tiene CA (se crea al iniciar).'; exit; }
    header('Content-Type: application/x-x509-ca-cert');
    header('Content-Disposition: attachment; filename="CA-nodo-' . (nodo_cfg()['node_id'] ?? 'IPROY') . '.crt"');
    readfile($caFile); exit;
}
if (!red_es_local()) { http_response_code(403); echo 'La configuración de red solo se puede cambiar desde el equipo del nodo.'; exit; }

$env = [];
foreach ((array) @file(red_dir() . '/iproy.env', FILE_IGNORE_NEW_LINES) as $l)
    if (preg_match('/^(IPROY_[A-Z_]+)="?([^"]*)"?$/', (string) $l, $m)) $env[$m[1]] = $m[2];
$puerto = (int) ($env['IPROY_PUERTO'] ?? 8080); $puertoTls = (int) ($env['IPROY_PUERTO_TLS'] ?? 8443);

/* ================================================================== acciones */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $org = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if (!hash_equals(red_token_panel(), (string) ($_SERVER['HTTP_X_IPROY_PANEL'] ?? ''))
        || ($org !== '' && !preg_match('~^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$~', $org))) {
        http_response_code(403); echo '{"ok":false,"mensaje":"token del panel inválido: recargá la página"}'; exit;
    }
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $db = nodo_pdo('iproy');
    $ok = fn(array $x = []) => print(json_encode(['ok' => true] + $x, JSON_UNESCAPED_UNICODE));
    $mal = function (string $m) { http_response_code(400); echo json_encode(['ok' => false, 'mensaje' => $m], JSON_UNESCAPED_UNICODE); exit; };

    switch ((string) ($in['accion'] ?? '')) {
        case 'guardar':
            $c = [];
            if (isset($in['modo'])) { if (!in_array($in['modo'], ['local', 'lan'], true)) $mal('modo inválido'); $c['modo'] = $in['modo']; }
            if (isset($in['hub_url'])) {
                $h = trim((string) $in['hub_url']);
                if ($h !== '' && !preg_match('~^https://[A-Za-z0-9.-]+(:\d+)?(/[^\s]*)?$~', $h)) $mal('el hub debe ser una dirección https://');
                $c['hub_url'] = $h;
            }
            if (isset($in['login_offline'])) $c['login_offline'] = (bool) $in['login_offline'];
            if (isset($in['login_offline_dias'])) $c['login_offline_dias'] = max(0, min(365, (int) $in['login_offline_dias']));
            if (isset($in['cdn_local'])) $c['cdn_local'] = (bool) $in['cdn_local'];
            if (isset($in['proxy'])) {
                $px = trim((string) $in['proxy']);
                if ($px !== '' && !preg_match('~^https?://[A-Za-z0-9.\-\[\]:]+/?$~', $px)) $mal('proxy inválido (ej. http://proxy.hospital:3128)');
                $c['proxy'] = $px;
            }
            red_guardar($c);
            $reinicio = isset($c['modo']) && $c['modo'] !== (trim((string) @file_get_contents(red_dir() . '/run/modo_activo')) ?: (nodo_cfg()['modo'] ?? 'local'));
            $ok(['reiniciar' => $reinicio]); exit;

        case 'par_alta':
            $id = strtoupper(trim((string) ($in['peer_id'] ?? ''))); $dir = rtrim(trim((string) ($in['direccion'] ?? '')), '/');
            if (!preg_match('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{3}$/', $id)) $mal('el identificador del nodo son 3 caracteres (ej. K7P)');
            if ($id === (nodo_cfg()['node_id'] ?? '')) $mal('ese es el identificador de este mismo nodo');
            if (!preg_match('~^https?://[A-Za-z0-9.\-\[\]:]+(:\d+)?$~', $dir)) $mal('dirección inválida (ej. https://192.168.1.20:8443)');
            $db->prepare("INSERT INTO sync_peers (peer_id, via, direccion, descubierto, activo) VALUES (?, 'lan', ?, ?, 1)
                          ON DUPLICATE KEY UPDATE direccion = VALUES(direccion), activo = 1")
               ->execute([$id, $dir, ($in['descubierto'] ?? '') === 'escaneo' ? 'mdns' : 'manual']);
            $ok(); exit;

        case 'par_baja':
            $db->prepare('UPDATE sync_peers SET activo = 0 WHERE peer_id = ? AND via = ?')->execute([(string) ($in['peer_id'] ?? ''), (string) ($in['via'] ?? 'lan')]);
            $ok(); exit;

        case 'par_probar':
            $dir = rtrim(trim((string) ($in['direccion'] ?? '')), '/');
            if (!preg_match('~^https?://[A-Za-z0-9.\-\[\]:]+(:\d+)?$~', $dir)) $mal('dirección inválida');
            $r = probar_par($dir);
            if (!empty($in['peer_id'])) $db->prepare('UPDATE sync_peers SET ultimo_error = ? WHERE peer_id = ? AND via = ?')
                ->execute([$r['ok'] ? null : substr($r['mensaje'], 0, 255), (string) $in['peer_id'], 'lan']);
            $ok(['resultado' => $r]); exit;

        case 'buscar':
            $p = (int) ($in['puerto'] ?? $puertoTls);
            $ok(['encontrados' => buscar_nodos($p > 0 && $p < 65536 ? $p : $puertoTls)]); exit;

        case 'hub_probar':
            $h = rtrim((string) (red_cfg()['hub_url'] ?? ''), '/');
            if ($h === '') $mal('todavía no cargaste la dirección del hub');
            $r = red_http('GET', $h . '/', ['Accept: application/json'], null, 10);
            $ok(['resultado' => ['ok' => $r['errno'] === 0 && $r['code'] > 0 && $r['code'] < 500, 'code' => $r['code'],
                 'mensaje' => $r['errno'] ? $r['error'] : 'HTTP ' . $r['code']]]); exit;

        case 'probar_red':
            $on = red_probar(); red_estado(['ts_prueba' => time()]);
            $ok(['online' => $on, 'cola' => $on && red_cola_pendientes($db) ? red_vaciar_cola($db) : null]); exit;

        case 'cola_decidir':
            $id = (int) ($in['id'] ?? 0); $d = (string) ($in['decision'] ?? '');
            if (!in_array($d, ['reenviar', 'descartar'], true)) $mal('decisión inválida');
            $db->prepare("UPDATE iproy_gas_cola SET estado = ?, proximo = NOW(), intentos = 0 WHERE id = ? AND estado IN ('dudoso','error')")
               ->execute([$d === 'reenviar' ? 'pendiente' : 'descartado', $id]);
            $ok(); exit;

        case 'auth_borrar':
            $n = $db->exec('DELETE FROM iproy_auth_local');
            $ok(['borrados' => $n]); exit;
    }
    $mal('acción desconocida');
}

/** Un nodo de la colmena se reconoce por el protocolo, no por el nombre del software (IPROY, iProy…). */
function es_nodo_colmena($j): bool {
    return is_array($j) && (($j['protocolo'] ?? '') === 'colmena/1' || ($j['servicio'] ?? '') === 'iproy-portable');
}
function probar_par(string $dir): array {
    $u = $dir . '/colmena/ping?id=1';                                  // ruta neutral entre nodos (Paso 105)
    $r = red_http('GET', $u, ['Accept: application/json'], null, 6);
    if ($r['errno'] === 0 && $r['code'] === 404) { $u = $dir . '/_iproy/ping.php?id=1'; $r = red_http('GET', $u, ['Accept: application/json'], null, 6); }   // nodo v0.5.0 o anterior
    $tls = true;
    if ($r['errno'] === 60 || $r['errno'] === 51 || $r['errno'] === 58) {     // responde, pero con una CA que este equipo no conoce
        $tls = false;
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6,
                                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
        $b = curl_exec($ch); $r = ['errno' => curl_errno($ch), 'error' => curl_error($ch), 'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'cuerpo' => (string) $b];
    }
    if ($r['errno'] !== 0) return ['ok' => false, 'mensaje' => 'no responde: ' . $r['error']];
    $j = json_decode($r['cuerpo'], true);
    if (!es_nodo_colmena($j)) return ['ok' => false, 'mensaje' => 'responde, pero no es un nodo de la colmena (HTTP ' . $r['code'] . ')'];
    return ['ok' => true, 'node_id' => $j['node_id'] ?? null, 'nombre' => $j['nombre'] ?? null, 'version' => $j['version'] ?? null,
            'tls_confiado' => $tls,
            'mensaje' => 'responde: nodo ' . ($j['node_id'] ?? '?') . ' «' . ($j['nombre'] ?? '') . '» v' . ($j['version'] ?? '?')
                       . ($tls ? '' : ' · su certificado todavía no es de confianza en este equipo (importá su CA)')];
}

/** Recorre la /24 de cada interfaz buscando nodos (HTTPS al puerto indicado). Solo se ejecuta a pedido. */
function buscar_nodos(int $puerto): array {
    $mh = curl_multi_init(); $hs = []; $yo = red_ips();
    foreach (array_slice($yo, 0, 3) as $ip) {
        $pre = implode('.', array_slice(explode('.', $ip), 0, 3));
        for ($i = 1; $i < 255; $i++) {
            $dst = "$pre.$i"; if (in_array($dst, $yo, true)) continue;
            $ch = curl_init("https://$dst:$puerto/colmena/ping?id=1");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 700, CURLOPT_TIMEOUT_MS => 1500,
                                    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_NOPROXY => '*']);
            curl_multi_add_handle($mh, $ch); $hs[$dst] = $ch;
        }
    }
    do { $s = curl_multi_exec($mh, $act); if ($act) curl_multi_select($mh, 0.2); } while ($act && $s === CURLM_OK);
    $out = [];
    foreach ($hs as $dst => $ch) {
        $j = json_decode((string) curl_multi_getcontent($ch), true);
        if (es_nodo_colmena($j))
            $out[] = ['ip' => $dst, 'direccion' => "https://$dst:$puerto", 'node_id' => $j['node_id'] ?? null, 'nombre' => $j['nombre'] ?? null, 'version' => $j['version'] ?? null];
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/* ================================================================== panel */
$c = nodo_cfg(); $rc = red_cfg(); $e = red_estado(); $ips = red_ips();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$pares = $cola = []; $auth = ['n' => 0, 'pepper' => is_file(red_dir() . '/php/auth_pepper.txt')]; $cache = ['n' => 0, 'mb' => 0]; $cnt = [];
try {
    $db = nodo_pdo('iproy');
    $pares = $db->query("SELECT peer_id, via, direccion, descubierto, ultima_sync_utc, ultimo_error FROM sync_peers WHERE activo = 1 ORDER BY peer_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($db->query('SELECT estado, COUNT(*) n FROM iproy_gas_cola GROUP BY estado') as $f) $cnt[$f['estado']] = (int) $f['n'];
    $cola = $db->query("SELECT id, accion, metodo, estado, intentos, ultimo_error, creado FROM iproy_gas_cola WHERE estado IN ('pendiente','dudoso','error') ORDER BY id LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $auth['n'] = (int) $db->query('SELECT COUNT(*) FROM iproy_auth_local')->fetchColumn();
    $x = $db->query('SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b FROM iproy_gas_cache')->fetch(PDO::FETCH_ASSOC);
    $cache = ['n' => (int) $x['n'], 'mb' => round($x['b'] / 1048576, 1)];
    $dbOk = true;
} catch (Throwable $x) { $dbOk = false; }
$cdnN = count(glob(red_dir() . '/cdn/*/*.bin') ?: []);
$caFp = is_file($caFile) && ($pem = (string) file_get_contents($caFile)) && preg_match('~-----BEGIN CERTIFICATE-----(.+?)-----END~s', $pem, $m)
    ? implode(':', str_split(strtoupper(hash('sha256', base64_decode(preg_replace('/\s+/', '', $m[1])))), 2)) : null;
$modoActivo = trim((string) @file_get_contents(red_dir() . '/run/modo_activo')) ?: ($c['modo'] ?? 'local');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="iproy-panel" content="<?= $h(red_token_panel()) ?>">
<title>Redes · Nodo <?= $h($c['node_id'] ?? '') ?></title>
<style>
  :root { --az1:#0d47a1; --az2:#1976d2; --az3:#42a5f5; --fondo:#f4f8fd; --borde:#d6e4f5; --texto:#1b2b40; --suave:#5a7591; --ok:#2e7d32; --mal:#c62828; --aviso:#b26a00; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:system-ui,"Segoe UI",Roboto,sans-serif; background:var(--fondo); color:var(--texto); }
  header { background:linear-gradient(135deg,var(--az1),var(--az2) 55%,var(--az3)); color:#fff; padding:18px 16px; }
  header h1 { margin:0; font-size:1.25rem; } header p { margin:4px 0 0; opacity:.92; font-size:.9rem; } header a { color:#fff; }
  main { max-width:1040px; margin:0 auto; padding:16px; display:grid; gap:14px; grid-template-columns:repeat(auto-fit,minmax(310px,1fr)); }
  .card { background:#fff; border:1px solid var(--borde); border-radius:12px; padding:14px 16px; box-shadow:0 1px 3px rgba(13,71,161,.06); min-width:0; }
  .ancha { grid-column:1/-1; }
  .card h2 { margin:0 0 8px; font-size:1rem; color:var(--az1); }
  .fila { display:flex; justify-content:space-between; gap:8px; padding:5px 0; border-bottom:1px dashed var(--borde); font-size:.92rem; flex-wrap:wrap; }
  .fila:last-child { border-bottom:0; }
  .ok { color:var(--ok); font-weight:600; } .mal { color:var(--mal); font-weight:600; } .aviso { color:var(--aviso); font-weight:600; }
  p.nota { color:var(--suave); font-size:.85rem; margin:8px 0 0; }
  button, .boton { min-height:40px; padding:0 14px; border:0; border-radius:10px; color:#fff; font-weight:600; font-size:.9rem; cursor:pointer;
           background:linear-gradient(135deg,var(--az1),var(--az2)); text-decoration:none; display:inline-flex; align-items:center; }
  button.sec { background:#eef4fc; color:var(--az1); border:1px solid var(--borde); }
  button:disabled { opacity:.6; cursor:wait; }
  input, select { min-height:40px; border:1px solid var(--borde); border-radius:8px; padding:0 10px; font-size:.92rem; width:100%; background:#fff; color:var(--texto); }
  label { font-size:.85rem; color:var(--suave); display:block; margin:8px 0 3px; }
  .acciones { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
  .dos { display:grid; grid-template-columns:110px 1fr; gap:8px; }
  table { width:100%; border-collapse:collapse; font-size:.86rem; } td,th { text-align:left; padding:5px 3px; border-bottom:1px solid var(--borde); vertical-align:top; }
  code { background:#eef4fc; padding:1px 5px; border-radius:4px; word-break:break-all; }
  .modo { display:flex; gap:8px; } .modo label { flex:1; margin:0; border:1px solid var(--borde); border-radius:10px; padding:10px; cursor:pointer; color:var(--texto); }
  .modo input { width:auto; min-height:0; margin-right:6px; } .modo label:has(input:checked) { border-color:var(--az2); background:#eef4fc; }
  #msg { position:fixed; left:50%; bottom:16px; transform:translateX(-50%); background:#fff; border:1px solid var(--borde); border-left:5px solid var(--az2);
         border-radius:10px; padding:10px 14px; box-shadow:0 4px 16px rgba(13,71,161,.18); font-size:.9rem; display:none; max-width:92vw; }
  .tabla { overflow-x:auto; }
</style>
</head>
<body>
<?php if (!isset($_GET['embebido'])): ?>
<header>
  <h1>Configuración › Redes · Nodo <?= $h($c['node_id'] ?? '—') ?> «<?= $h($c['nombre'] ?? '') ?>»</h1>
  <p>Conexión con los servidores centrales, otros nodos de la colmena y trabajo sin Internet · <a href="/_iproy/">volver al nodo</a></p>
</header>
<?php endif; ?>
<main>

<section class="card">
  <h2>Servidores centrales (Google y iproy.com.ar)</h2>
  <?php $on = $e['online'] ?? null; ?>
  <div class="fila"><span>Estado</span><span class="<?= $on === false ? 'aviso' : ($on ? 'ok' : '') ?>"><?= $on === false ? 'Sin conexión · el nodo trabaja solo' : ($on ? 'En línea' : 'Sin probar todavía') ?></span></div>
  <div class="fila"><span>Último contacto</span><span><?= $h(isset($e['ultimo_ok']) ? date('d/m/Y H:i', strtotime($e['ultimo_ok'])) : '—') ?></span></div>
  <?php if (($e['online'] ?? null) === false && !empty($e['ultimo_error'])): ?>
  <div class="fila"><span>Motivo</span><span><?= $h(mb_substr((string) $e['ultimo_error'], 0, 90)) ?></span></div>
  <?php endif; ?>
  <div class="fila"><span>Cambios por enviar</span><span class="<?= ($cnt['pendiente'] ?? 0) ? 'aviso' : '' ?>"><?= (int) ($cnt['pendiente'] ?? 0) ?></span></div>
  <div class="fila"><span>Para revisar (dudosos / con error)</span><span class="<?= (($cnt['dudoso'] ?? 0) + ($cnt['error'] ?? 0)) ? 'mal' : '' ?>"><?= (int) ($cnt['dudoso'] ?? 0) ?> / <?= (int) ($cnt['error'] ?? 0) ?></span></div>
  <div class="fila"><span>Enviados desde este nodo</span><span><?= (int) ($cnt['enviado'] ?? 0) ?></span></div>
  <div class="fila"><span>Consultas guardadas para usar sin red</span><span><?= $cache['n'] ?> (<?= $cache['mb'] ?> MB)</span></div>
  <div class="fila"><span>Librerías y fuentes externas en el nodo</span><span><?= $cdnN ?></span></div>
  <label for="proxy">Proxy de salida (solo si la red del hospital lo exige)</label>
  <input id="proxy" value="<?= $h($rc['proxy'] ?? '') ?>" placeholder="vacío = automático<?= is_file(red_dir() . '/run/proxy_sistema.txt') ? ' (el sistema usa uno)' : '' ?>">
  <div class="acciones"><button data-a="probar_red">Probar conexión y enviar la cola</button><button class="sec" data-a="guardar_proxy">Guardar proxy</button></div>
  <p class="nota">Sin red, las escrituras quedan en este nodo en el orden en que se hicieron y el worker las envía solo al volver la conexión.
  Si un envío se cortó a mitad de camino queda «dudoso»: no se reenvía solo para no duplicar órdenes.</p>
</section>

<section class="card">
  <h2>Este nodo en la red</h2>
  <div class="modo">
    <label><input type="radio" name="modo" value="local" <?= ($rc['modo'] ?? 'local') === 'local' ? 'checked' : '' ?>><b>Solo este equipo</b><br><small>http://localhost:<?= $puerto ?></small></label>
    <label><input type="radio" name="modo" value="lan" <?= ($rc['modo'] ?? '') === 'lan' ? 'checked' : '' ?>><b>Red local (LAN)</b><br><small>visible para otros nodos y PC</small></label>
  </div>
  <div class="fila"><span>Modo en uso ahora</span><span><?= $modoActivo === 'lan' ? 'Red local (LAN)' : 'Solo este equipo' ?></span></div>
  <div class="fila"><span>Direcciones IP de este equipo</span><span><?= $ips ? $h(implode(' · ', $ips)) : '—' ?></span></div>
  <div class="fila"><span>Puertos</span><span>HTTP <?= $puerto ?> · HTTPS <?= $puertoTls ?></span></div>
  <?php if ($modoActivo === 'lan' && $ips): ?>
  <div class="fila"><span>Dirección para otros nodos</span><span><code>https://<?= $h($ips[0]) ?>:<?= $puertoTls ?></code></span></div>
  <?php endif; ?>
  <div class="fila"><span>CA del nodo (huella SHA-256)</span><span><?= $caFp ? '<code>' . $h(substr($caFp, 0, 47)) . '…</code>' : '—' ?></span></div>
  <div class="acciones"><button data-a="guardar_modo">Guardar modo</button><?php if ($caFp): ?><a class="boton" href="?ca=1">Descargar CA</a><?php endif; ?></div>
  <p class="nota">Cambiar el modo requiere reiniciar el nodo (<code>iProy-Control stop</code> y <code>start</code>). En LAN, Windows puede pedir permiso
  en el firewall para los puertos <?= $puerto ?> y <?= $puertoTls ?>. Las otras PC confían en este nodo importando su CA (botón «Descargar CA» o <code>iProy-Control confiar-ca</code>).</p>
</section>

<section class="card ancha">
  <h2>Nodos de la colmena</h2>
  <div class="tabla"><table id="tpares"><tr><th>Nodo</th><th>Vía</th><th>Dirección</th><th>Última sync</th><th>Estado</th><th></th></tr>
  <?php foreach ($pares as $p): ?>
    <tr><td><b><?= $h($p['peer_id']) ?></b></td><td><?= $h($p['via']) ?></td><td><code><?= $h($p['direccion'] ?? '—') ?></code></td>
        <td><?= $h($p['ultima_sync_utc'] ?? 'nunca') ?></td><td class="<?= $p['ultimo_error'] ? 'mal' : '' ?>"><?= $h($p['ultimo_error'] ?? '—') ?></td>
        <td><button class="sec" data-a="par_probar" data-dir="<?= $h($p['direccion']) ?>" data-id="<?= $h($p['peer_id']) ?>">Probar</button>
            <button class="sec" data-a="par_baja" data-id="<?= $h($p['peer_id']) ?>" data-via="<?= $h($p['via']) ?>">Quitar</button></td></tr>
  <?php endforeach; if (!$pares): ?><tr><td colspan="6">Todavía no hay nodos vinculados.</td></tr><?php endif; ?>
  </table></div>
  <div class="dos" style="margin-top:10px">
    <div><label for="pid">Identificador</label><input id="pid" maxlength="3" placeholder="K7P" autocomplete="off"></div>
    <div><label for="pdir">Dirección</label><input id="pdir" placeholder="https://192.168.1.20:<?= $puertoTls ?>" autocomplete="off"></div>
  </div>
  <div class="acciones">
    <button data-a="par_probar_nuevo" class="sec">Probar dirección</button>
    <button data-a="par_alta">Agregar nodo</button>
    <button data-a="buscar" class="sec">Buscar nodos en mi red</button>
  </div>
  <div id="encontrados"></div>
  <p class="nota">«Buscar» consulta el puerto <?= $puertoTls ?> de las direcciones de tu misma subred (solo cuando lo pedís). El identificador y la dirección de cada
  nodo se ven en su propio panel. ⚠️ En esta versión el vínculo registra y prueba la conexión; el intercambio automático de datos entre nodos
  (replicación con HLC y LWW, ya probada en el arnés) se conecta en la Fase 3/7.</p>
</section>

<section class="card">
  <h2>Hub central</h2>
  <label for="hub">Dirección del hub</label>
  <input id="hub" value="<?= $h($rc['hub_url'] ?? '') ?>" placeholder="https://iproy.com.ar/iProy/api/sync">
  <div class="acciones"><button data-a="guardar_hub">Guardar</button><button class="sec" data-a="hub_probar">Probar</button></div>
  <p class="nota">El hub es el punto de encuentro de los nodos que no comparten red local. 🔲 Su API (<code>/iProy/api/sync/</code>) todavía no está publicada:
  hoy el nodo se sincroniza con producción a través de los Apps Script (cola de escrituras).</p>
</section>

<section class="card">
  <h2>Ingreso sin conexión</h2>
  <div class="fila"><span>Permitir ingresar sin Internet</span><span><input type="checkbox" id="loff" style="width:auto;min-height:0" <?= $rc['login_offline'] ? 'checked' : '' ?>></span></div>
  <label for="ldias">Días de validez desde el último ingreso en línea (0 = sin vencimiento)</label>
  <input id="ldias" type="number" min="0" max="365" value="<?= (int) $rc['login_offline_dias'] ?>">
  <div class="fila"><span>Usuarios habilitados en este nodo</span><span><?= $auth['n'] ?></span></div>
  <div class="fila"><span>Clave de autenticación del nodo</span><span class="<?= $auth['pepper'] ? 'ok' : '' ?>"><?= $auth['pepper'] ? 'cargada' : 'no cargada (opcional)' ?></span></div>
  <div class="acciones"><button data-a="guardar_login">Guardar</button><button class="sec" data-a="auth_borrar">Borrar accesos locales</button></div>
  <p class="nota">Cada usuario que ingresa con Internet queda habilitado para entrar sin red en este nodo: se guarda solo un verificador
  (<?= defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt' ?>), nunca la contraseña. Para que entren usuarios que nunca usaron este nodo,
  el administrador puede cargar la clave de autenticación con <code>iProy-Control clave-auth</code> (la pide oculta; nunca la escribas en un chat).</p>
</section>

<section class="card">
  <h2>Librerías externas</h2>
  <div class="fila"><span>Servir CDN y fuentes desde el nodo</span><span><input type="checkbox" id="cdn" style="width:auto;min-height:0" <?= $rc['cdn_local'] ? 'checked' : '' ?>></span></div>
  <div class="acciones"><button data-a="guardar_cdn">Guardar</button><button class="sec" data-a="precargar">Preparar para trabajar sin Internet</button></div>
  <p class="nota">Font Awesome, SheetJS, fuentes y demás se descargan la primera vez que se usan con Internet y quedan en <code>data/cdn/</code>.
  Conviene abrir la app una vez con conexión después de instalar.</p>
</section>

<?php if ($cola): ?>
<section class="card ancha">
  <h2>Cola de cambios hacia los servidores centrales</h2>
  <div class="tabla"><table><tr><th>#</th><th>Acción</th><th>Estado</th><th>Intentos</th><th>Detalle</th><th>Creado</th><th></th></tr>
  <?php foreach ($cola as $f): ?>
    <tr><td><?= (int) $f['id'] ?></td><td><?= $h($f['accion'] ?: $f['metodo']) ?></td><td class="<?= $f['estado'] === 'pendiente' ? 'aviso' : 'mal' ?>"><?= $h($f['estado']) ?></td>
        <td><?= (int) $f['intentos'] ?></td><td><?= $h($f['ultimo_error'] ?? '') ?></td><td><?= $h($f['creado']) ?></td>
        <td><?php if ($f['estado'] !== 'pendiente'): ?><button class="sec" data-a="cola" data-id="<?= (int) $f['id'] ?>" data-d="reenviar">Reenviar</button>
            <button class="sec" data-a="cola" data-id="<?= (int) $f['id'] ?>" data-d="descartar">Descartar</button><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </table></div>
  <p class="nota">Antes de reenviar un «dudoso», fijate en la planilla o en producción si el cambio ya llegó.</p>
</section>
<?php endif; ?>
<?php if (!$dbOk): ?><section class="card ancha"><h2 class="mal">La base local no responde</h2><p>Revisá <code>iProy-Control status</code>.</p></section><?php endif; ?>
</main>
<div id="msg" role="status" aria-live="polite"></div>
<script>
(function () {
  var TOKEN = document.querySelector('meta[name="iproy-panel"]').content;
  var msg = document.getElementById('msg'), t;
  function aviso(s) { msg.textContent = s; msg.style.display = 'block'; clearTimeout(t); t = setTimeout(function () { msg.style.display = 'none'; }, 7000); }
  function api(o) {
    return fetch('/_iproy/red.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-IPROY-Panel': TOKEN }, body: JSON.stringify(o) })
      .then(function (r) { return r.json(); });
  }
  function v(id) { return document.getElementById(id).value.trim(); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  var recargar = function () { setTimeout(function () { location.reload(); }, 1200); };
  var acciones = {
    probar_red: function () { return api({ accion: 'probar_red' }).then(function (j) { aviso(j.online ? 'En línea' + (j.cola ? ' · cola: ' + j.cola : '') : 'Sin conexión con los servidores centrales'); recargar(); }); },
    guardar_modo: function () {
      var m = document.querySelector('input[name=modo]:checked').value;
      return api({ accion: 'guardar', modo: m }).then(function (j) { aviso(j.ok ? (j.reiniciar ? 'Guardado. Reiniciá el nodo (stop y start) para aplicar el modo.' : 'Guardado.') : j.mensaje); });
    },
    guardar_proxy: function () { return api({ accion: 'guardar', proxy: v('proxy') }).then(function (j) { aviso(j.ok ? 'Proxy guardado.' : j.mensaje); }); },
    guardar_hub: function () { return api({ accion: 'guardar', hub_url: v('hub') }).then(function (j) { aviso(j.ok ? 'Hub guardado.' : j.mensaje); }); },
    hub_probar: function () { return api({ accion: 'hub_probar' }).then(function (j) { aviso(j.ok ? (j.resultado.ok ? 'El hub responde (' + j.resultado.mensaje + ')' : 'El hub no responde: ' + j.resultado.mensaje) : j.mensaje); }); },
    guardar_login: function () {
      return api({ accion: 'guardar', login_offline: document.getElementById('loff').checked, login_offline_dias: +v('ldias') })
        .then(function (j) { aviso(j.ok ? 'Guardado.' : j.mensaje); });
    },
    precargar: function () {
      aviso('Descargando librerías y archivos que usa iProy… (puede tardar un minuto)');
      return fetch('/_iproy/precargar.php', { method: 'POST', headers: { 'X-IPROY-Panel': TOKEN } }).then(function (r) { return r.json(); })
        .then(function (j) { aviso(j.ok ? 'Listo: ' + j.listos + ' de ' + j.total + ' guardados en el nodo' + (j.fallidos.length ? ' · ' + j.fallidos.length + ' sin conexión o inexistentes' : '') : (j.mensaje || 'No se pudo')); });
    },
    guardar_cdn: function () { return api({ accion: 'guardar', cdn_local: document.getElementById('cdn').checked }).then(function (j) { aviso(j.ok ? 'Guardado.' : j.mensaje); }); },
    auth_borrar: function () {
      if (!window.confirm('¿Borrar todos los accesos locales? Cada usuario tendrá que volver a ingresar una vez con Internet.')) return Promise.resolve();
      return api({ accion: 'auth_borrar' }).then(function (j) { aviso(j.ok ? 'Accesos locales borrados: ' + j.borrados : j.mensaje); recargar(); });
    },
    par_probar: function (b) { return api({ accion: 'par_probar', direccion: b.dataset.dir, peer_id: b.dataset.id }).then(function (j) { aviso(j.ok ? j.resultado.mensaje : j.mensaje); }); },
    par_probar_nuevo: function () {
      return api({ accion: 'par_probar', direccion: v('pdir') }).then(function (j) {
        if (j.ok && j.resultado.ok && j.resultado.node_id && !v('pid')) document.getElementById('pid').value = j.resultado.node_id;
        aviso(j.ok ? j.resultado.mensaje : j.mensaje);
      });
    },
    par_alta: function () { return api({ accion: 'par_alta', peer_id: v('pid'), direccion: v('pdir') }).then(function (j) { aviso(j.ok ? 'Nodo agregado.' : j.mensaje); if (j.ok) recargar(); }); },
    par_baja: function (b) { return api({ accion: 'par_baja', peer_id: b.dataset.id, via: b.dataset.via }).then(function (j) { aviso(j.ok ? 'Nodo quitado.' : j.mensaje); if (j.ok) recargar(); }); },
    buscar: function () {
      aviso('Buscando nodos en tu subred… (unos segundos)');
      return api({ accion: 'buscar' }).then(function (j) {
        var c = document.getElementById('encontrados');
        if (!j.ok) { aviso(j.mensaje); return; }
        if (!j.encontrados.length) { c.innerHTML = '<p class="nota">No se encontraron otros nodos en modo LAN en tu subred.</p>'; return; }
        c.innerHTML = '<table><tr><th>Nodo</th><th>Nombre</th><th>Dirección</th><th></th></tr>' + j.encontrados.map(function (n) {
          return '<tr><td><b>' + esc(n.node_id) + '</b></td><td>' + esc(n.nombre) + '</td><td><code>' + esc(n.direccion) + '</code></td>'
            + '<td><button class="sec" data-a="usar" data-id="' + esc(n.node_id) + '" data-dir="' + esc(n.direccion) + '">Usar</button></td></tr>';
        }).join('') + '</table>';
      });
    },
    usar: function (b) { document.getElementById('pid').value = b.dataset.id; document.getElementById('pdir').value = b.dataset.dir; aviso('Revisá los datos y tocá «Agregar nodo».'); return Promise.resolve(); },
    cola: function (b) { return api({ accion: 'cola_decidir', id: +b.dataset.id, decision: b.dataset.d }).then(function (j) { aviso(j.ok ? 'Listo.' : j.mensaje); recargar(); }); }
  };
  document.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-a]'); if (!b || !acciones[b.dataset.a]) return;
    b.disabled = true;
    acciones[b.dataset.a](b).catch(function () { aviso('No se pudo completar la acción.'); }).then(function () { b.disabled = false; });
  });
})();
</script>
</body>
</html>
