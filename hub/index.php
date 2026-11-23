<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Hub de relevo de la colmena (colmena/1) · un solo archivo PHP ≥ 8.1 con PDO SQLite y libsodium (cualquier hosting común).
 *
 * Es un buzón «guardar y reenviar»: los nodos (detrás de NAT, sin IP fija ni puertos abiertos) dejan sobres para otros
 * nodos y retiran los suyos. El hub NO puede leerlos (van sellados con X25519 para el destinatario) ni falsificarlos
 * (van firmados con ed25519 por el remitente, y el hub también verifica esa firma). Si el hub cae o miente, lo peor que
 * puede hacer es demorar o descartar sobres: nunca leerlos ni alterarlos sin que el destinatario lo detecte.
 *
 *  GET  ?r=estado                         público: versión y protocolo
 *  POST ?r=registrar  {node_id, firma_pub, caja_pub, pim_jwk, nombre, producto, codigo}   (TOFU: la clave queda fija)
 *  GET  ?r=directorio&id=XYZ              claves públicas de un nodo (para vincularse por Internet)
 *  POST ?r=enviar     {sobre}             deja un sobre para su destinatario; responde «escucha» = segundos desde el último pedido
 *                                         del destinatario (1.2.0): el remitente sabe si lo están esperando o conviene otro camino
 *  GET  ?r=buzon[&espera=N]               retira hasta 100 sobres propios; con espera (1.1.0) mantiene el pedido abierto hasta N s
 *                                         y responde apenas llega un sobre (como AnyDesk en modo relevo: el nodo solo hace salidas HTTPS)
 *  POST ?r=ack        {nros: [...]}        confirma (y borra) los retirados
 * Autenticación de cada pedido: X-Colmena-Nodo, X-Colmena-Ts (±5 min), X-Colmena-Nonce (hex aleatorio)
 * y X-Colmena-Firma = ed25519("MÉTODO\nr\nts\nnonce\nsha256(cuerpo)"); cada firma se acepta una sola vez.
 * Toda respuesta trae la hora del hub (cabecera X-Colmena-Hora y campo «hora»): el nodo corrige con ella un reloj corrido (1.1.0).
 */
declare(strict_types=1);

const VERSION_HUB = '1.2.0';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Colmena-Hora: ' . time());   // se reescribe abajo con la hora efectiva del hub

function ahora(): int { return time() + (int) ($GLOBALS['C']['desfase_prueba_s'] ?? 0); }   // desfase_prueba_s: solo para las pruebas de reloj corrido
function fin(int $http, array $d): never { http_response_code($http); $d['hora'] ??= ahora(); echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }
function canonico(mixed $v): string {
    $o = function ($x) use (&$o) { if (!is_array($x)) return $x; if (array_is_list($x)) return array_map($o, $x); ksort($x, SORT_STRING); return array_map($o, $x); };
    return json_encode($o($v), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}
function verifica(string $msg, string $firma, string $pub): bool {
    $f = base64_decode($firma, true); $p = base64_decode($pub, true);
    return $f !== false && $p !== false && strlen($f) === SODIUM_CRYPTO_SIGN_BYTES && strlen($p) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && sodium_crypto_sign_verify_detached($f, $msg, $p);
}

if (!extension_loaded('sodium') || !extension_loaded('pdo_sqlite')) fin(500, ['ok' => false, 'error' => 'El hosting necesita las extensiones sodium y pdo_sqlite']);
$C = (is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : []) + require __DIR__ . '/config.ejemplo.php';
header('X-Colmena-Hora: ' . ahora());
if (!empty($C['mantenimiento'])) fin(503, ['ok' => false, 'servicio' => 'colmena-hub', 'error' => 'Hub en mantenimiento']);   // los nodos siguen por sus otros caminos y, si no hay, por la cola
@mkdir($C['dir_datos'], 0700, true);
$db = new PDO('sqlite:' . $C['dir_datos'] . '/hub.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;
  CREATE TABLE IF NOT EXISTS nodo (id TEXT PRIMARY KEY, nombre TEXT, producto TEXT, firma_pub TEXT NOT NULL, caja_pub TEXT NOT NULL, pim_jwk TEXT, alta INTEGER, visto INTEGER);
  CREATE TABLE IF NOT EXISTS sobre (nro INTEGER PRIMARY KEY AUTOINCREMENT, id TEXT UNIQUE, de TEXT, para TEXT, recibido INTEGER, contenido TEXT);
  CREATE INDEX IF NOT EXISTS k_para ON sobre (para, nro);
  CREATE TABLE IF NOT EXISTS nonce (h TEXT PRIMARY KEY, ts INTEGER);
  CREATE TABLE IF NOT EXISTS tasa (nodo TEXT, minuto INTEGER, n INTEGER, PRIMARY KEY (nodo, minuto));');
if (random_int(1, 50) === 1) {   // mantenimiento liviano
    $db->prepare('DELETE FROM sobre WHERE recibido < ?')->execute([time() - 86400 * (int) $C['ttl_dias']]);
    $db->prepare('DELETE FROM nonce WHERE ts < ?')->execute([time() - 900]);
    $db->prepare('DELETE FROM tasa WHERE minuto < ?')->execute([intdiv(time(), 60) - 5]);
}

$r = (string) ($_GET['r'] ?? 'estado');
$partes = explode('&', $r); $op = $partes[0]; $par = [];
foreach (array_slice($partes, 1) as $x) { [$k, $v] = array_pad(explode('=', $x, 2), 2, ''); $par[$k] = rawurldecode($v); }
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cuerpo = (string) file_get_contents('php://input');
if (strlen($cuerpo) > (int) $C['max_sobre_kb'] * 1024 + 4096) fin(413, ['ok' => false, 'error' => 'Pedido demasiado grande']);
$J = $cuerpo !== '' ? (json_decode($cuerpo, true) ?? fin(400, ['ok' => false, 'error' => 'JSON inválido'])) : [];

if ($op === 'estado') fin(200, ['ok' => true, 'servicio' => 'colmena-hub', 'protocolo' => 'colmena/1', 'version' => VERSION_HUB, 'hora' => ahora(), 'espera_max' => max(0, min(55, (int) ($C['espera_max'] ?? 25)))]);

// ---------------------------------------------------------------- autenticación del pedido
$nodo = strtoupper((string) ($_SERVER['HTTP_X_COLMENA_NODO'] ?? ''));
$ts = (int) ($_SERVER['HTTP_X_COLMENA_TS'] ?? 0);
$firma = (string) ($_SERVER['HTTP_X_COLMENA_FIRMA'] ?? '');
$nonce = (string) ($_SERVER['HTTP_X_COLMENA_NONCE'] ?? '');
if (!preg_match('/^[A-Z0-9]{2,12}$/', $nodo) || abs(ahora() - $ts) > 300 || $firma === '' || !preg_match('/^[a-f0-9]{16,64}$/', $nonce)) fin(401, ['ok' => false, 'error' => 'Pedido sin firma válida o con la hora del equipo desfasada (±5 min)']);
$msg = "$metodo\n$r\n$ts\n$nonce\n" . hash('sha256', $cuerpo);
$q = $db->prepare('SELECT * FROM nodo WHERE id = ?'); $q->execute([$nodo]); $yo = $q->fetch() ?: null;
if ($op === 'registrar') {
    $pub = (string) ($J['firma_pub'] ?? '');
    if (($J['node_id'] ?? '') !== $nodo || !verifica($msg, $firma, $pub)) fin(401, ['ok' => false, 'error' => 'La firma no corresponde a la clave presentada']);
    if ($yo && $yo['firma_pub'] !== $pub) fin(409, ['ok' => false, 'error' => "El nodo $nodo ya está registrado con otra clave (el hub no permite reemplazarla)"]);
    if (!$yo) {
        if ($C['codigo_registro'] !== '' && !hash_equals((string) $C['codigo_registro'], (string) ($J['codigo'] ?? ''))) fin(403, ['ok' => false, 'error' => 'Código de registro incorrecto']);
        if ($C['nodos_permitidos'] && !in_array($nodo, $C['nodos_permitidos'], true)) fin(403, ['ok' => false, 'error' => 'Nodo no habilitado en este hub']);
    }
    if (strlen(base64_decode((string) ($J['caja_pub'] ?? ''), true) ?: '') !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) fin(422, ['ok' => false, 'error' => 'caja_pub inválida']);
    $db->prepare('INSERT INTO nodo (id, nombre, producto, firma_pub, caja_pub, pim_jwk, alta, visto) VALUES (?,?,?,?,?,?,?,?)
                  ON CONFLICT(id) DO UPDATE SET nombre = excluded.nombre, producto = excluded.producto, caja_pub = excluded.caja_pub, pim_jwk = excluded.pim_jwk, visto = excluded.visto')
       ->execute([$nodo, mb_substr((string) ($J['nombre'] ?? ''), 0, 120), mb_substr((string) ($J['producto'] ?? ''), 0, 60), $pub, $J['caja_pub'], json_encode($J['pim_jwk'] ?? null), time(), time()]);
    fin(200, ['ok' => true, 'registrado' => $nodo, 'nuevo' => !$yo]);
}
if (!$yo || !verifica($msg, $firma, $yo['firma_pub'])) fin(401, ['ok' => false, 'error' => 'Nodo no registrado o firma inválida']);
try { $db->prepare('INSERT INTO nonce (h, ts) VALUES (?, ?)')->execute([hash('sha256', $firma), time()]); }
catch (PDOException) { fin(409, ['ok' => false, 'error' => 'Pedido repetido']); }
$min = intdiv(time(), 60);
$db->prepare('INSERT INTO tasa (nodo, minuto, n) VALUES (?, ?, 1) ON CONFLICT(nodo, minuto) DO UPDATE SET n = n + 1')->execute([$nodo, $min]);
$q = $db->prepare('SELECT n FROM tasa WHERE nodo = ? AND minuto = ?'); $q->execute([$nodo, $min]);
if ((int) $q->fetchColumn() > (int) $C['limite_por_minuto']) fin(429, ['ok' => false, 'error' => 'Demasiados pedidos: esperá un minuto']);
$db->prepare('UPDATE nodo SET visto = ? WHERE id = ?')->execute([time(), $nodo]);

switch ($op) {
    case 'directorio':
        $q = $db->prepare('SELECT id, nombre, producto, firma_pub, caja_pub, pim_jwk, visto FROM nodo WHERE id = ?'); $q->execute([strtoupper((string) ($par['id'] ?? ''))]);
        $n = $q->fetch() ?: fin(404, ['ok' => false, 'error' => 'Nodo no registrado en este hub']);
        fin(200, ['ok' => true, 'nodo' => ['node_id' => $n['id'], 'nombre' => $n['nombre'], 'producto' => $n['producto'], 'firma_pub' => $n['firma_pub'],
            'caja_pub' => $n['caja_pub'], 'pim_jwk' => json_decode((string) $n['pim_jwk'], true), 'visto' => (int) $n['visto']]]);
    case 'enviar':
        if (($d = (int) ($C['demora_prueba_ms'] ?? 0)) > 0) usleep(min(5000, $d) * 1000);   // solo para las pruebas del «camino más rápido»
        $s = $J['sobre'] ?? fin(422, ['ok' => false, 'error' => 'Falta el sobre']);
        foreach (['v', 'id', 'de', 'para', 'ts', 'tipo', 'firma'] as $k) if (!isset($s[$k])) fin(422, ['ok' => false, 'error' => "Sobre incompleto: $k"]);
        if (strtoupper((string) $s['de']) !== $nodo) fin(403, ['ok' => false, 'error' => 'Solo podés enviar sobres a tu nombre']);
        $f = $s['firma']; $t = $s; unset($t['firma']);
        if (!verifica(canonico($t), (string) $f, $yo['firma_pub'])) fin(401, ['ok' => false, 'error' => 'Firma del sobre inválida']);
        $para = strtoupper((string) $s['para']);
        $q = $db->prepare('SELECT visto FROM nodo WHERE id = ?'); $q->execute([$para]); $visto = $q->fetchColumn();
        if ($visto === false) fin(404, ['ok' => false, 'error' => "El destinatario $para no está registrado en este hub"]);
        $escucha = $visto ? max(0, time() - (int) $visto) : null;   // 1.2.0: hace cuánto pidió algo el destinatario (con espera larga, menos de 30 s = está escuchando)
        $q = $db->prepare('SELECT COUNT(*) FROM sobre WHERE para = ?'); $q->execute([$para]);
        if ((int) $q->fetchColumn() >= (int) $C['max_pendientes']) fin(507, ['ok' => false, 'error' => 'El buzón del destinatario está lleno']);
        try { $db->prepare('INSERT INTO sobre (id, de, para, recibido, contenido) VALUES (?,?,?,?,?)')->execute([(string) $s['id'], $nodo, $para, time(), json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]); }
        catch (PDOException) { fin(200, ['ok' => true, 'duplicado' => true, 'escucha' => $escucha]); }
        fin(202, ['ok' => true, 'nro' => (int) $db->lastInsertId(), 'escucha' => $escucha]);
    case 'buzon':
        // 1.1.0: espera larga. Con «espera=N» el pedido queda abierto hasta N s (tope espera_max) y responde apenas hay sobres.
        $espera = max(0, min((int) ($par['espera'] ?? 0), max(0, min(55, (int) ($C['espera_max'] ?? 25)))));
        if ($espera > 0) { @set_time_limit($espera + 20); ignore_user_abort(false); }
        $fin = microtime(true) + $espera; $q = $db->prepare('SELECT nro, contenido FROM sobre WHERE para = ? ORDER BY nro LIMIT 100');
        do {
            $q->execute([$nodo]); $l = $q->fetchAll();
            if ($l || microtime(true) >= $fin || connection_aborted()) break;
            usleep(400000);
        } while (true);
        header('X-Colmena-Hora: ' . ahora());
        fin(200, ['ok' => true, 'espera' => $espera, 'sobres' => array_map(fn($x) => ['nro' => (int) $x['nro'], 'sobre' => json_decode($x['contenido'], true)], $l)]);
    case 'ack':
        $nros = array_map('intval', (array) ($J['nros'] ?? []));
        if ($nros) $db->prepare('DELETE FROM sobre WHERE para = ? AND nro IN (' . implode(',', array_fill(0, count($nros), '?')) . ')')->execute([$nodo, ...$nros]);
        fin(200, ['ok' => true, 'borrados' => count($nros)]);
}
fin(404, ['ok' => false, 'error' => "Operación desconocida: $op"]);
