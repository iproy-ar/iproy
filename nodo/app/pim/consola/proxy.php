<?php
/**
 * iProy · Consola PIM · pasarela hacia la API PIM · 30-10-2026
 *
 * La Consola es un CLIENTE más de la API: no lee la base. Esta pasarela firma con su propia clave ES384
 * (cliente «consola-local», rol GM, creada sola la primera vez), obtiene el token SMART en /pim/auth/token y ejecuta cada
 * pedido en el controlador de la API (/pim/index.php) con ese token: mismas validaciones, roles, auditoría e historial.
 * Se ejecuta en el mismo proceso (no por HTTP) para no ocupar dos hilos de PHP por pedido (equipos de 2 núcleos, L-321).
 * Por defecto solo responde en el propio equipo (127.0.0.1). Para usarla desde la LAN: "consola_lan": true en data/pim/config.json.
 * Uso: /pim/consola/proxy.php?r=Task%3Fstatus%3Drequested  (método, cuerpo e If-Match se reenvían)
 */
declare(strict_types=1);

$RAIZ = dirname(__DIR__, 3);
$D = $RAIZ . '/data/pim';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function salir(int $h, string $msg): never {
    http_response_code($h); header('Content-Type: application/fhir+json; charset=utf-8');
    echo json_encode(['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'code' => 'forbidden', 'diagnostics' => $msg]]], JSON_UNESCAPED_UNICODE); exit;
}
$cfg = is_file("$D/config.json") ? (json_decode((string) file_get_contents("$D/config.json"), true) ?: []) : [];
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$local && empty($cfg['consola_lan'])) salir(403, 'La Consola PIM está habilitada solo en este equipo (consola_lan = false).');
if (($_SERVER['HTTP_X_PIM_CONSOLA'] ?? '') !== '1') salir(403, 'Pedido sin el encabezado de la Consola');           // impide CSRF entre sitios
$org = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($org !== '' && parse_url($org, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) salir(403, 'Origen no permitido');

// ---------------------------------------------------------------- puerto HTTP local del nodo
$env = @file_get_contents($RAIZ . '/data/iproy.env') ?: '';
$puerto = preg_match('/^IPROY_PUERTO=(\d+)/m', $env, $m) ? (int) $m[1] : (int) ($_SERVER['SERVER_PORT'] ?? 8080);
$base = "http://127.0.0.1:$puerto";

// ---------------------------------------------------------------- clave y registro del cliente «consola-local»
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
$id = 'consola-local';
$kf = "$D/consola/clave.pem";
if (!is_file($kf)) {
    @mkdir("$D/consola", 0700, true);
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']) ?: salir(500, 'No se pudo crear la clave de la Consola (openssl)');
    openssl_pkey_export($k, $pem); file_put_contents($kf, $pem, LOCK_EX); @chmod($kf, 0600);
}
$priv = openssl_pkey_get_private((string) file_get_contents($kf)) ?: salir(500, 'Clave de la Consola ilegible');
$det = openssl_pkey_get_details($priv);
$jwk = ['kty' => 'EC', 'crv' => 'P-384', 'kid' => 'consola-1', 'alg' => 'ES384', 'use' => 'sig',
        'x' => b64u(str_pad($det['ec']['x'], 48, "\0", STR_PAD_LEFT)), 'y' => b64u(str_pad($det['ec']['y'], 48, "\0", STR_PAD_LEFT))];
$cf = "$D/clientes/$id.json";
$reg = is_file($cf) ? json_decode((string) file_get_contents($cf), true) : null;
if (!is_array($reg) || ($reg['jwks']['keys'][0]['x'] ?? '') !== $jwk['x']) {
    @mkdir("$D/clientes", 0700, true);
    file_put_contents($cf, json_encode(['client_id' => $id, 'nombre' => 'Consola PIM del nodo', 'activo' => true, 'alta' => date('c'), 'rol' => 'GM',
        'organizacion' => '', 'scopes' => ['system/*.cruds'], 'jwks' => ['keys' => [$jwk]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ---------------------------------------------------------------- token (se reutiliza mientras dure)
function token(string $base, string $id, $priv, string $D, bool $nuevo = false): string {
    $tf = "$D/run/consola_token.json";
    @mkdir("$D/run", 0700, true);
    $lee = function () use ($tf) { $t = is_file($tf) ? json_decode((string) file_get_contents($tf), true) : null; return (is_array($t) && ($t['exp'] ?? 0) > time() + 20) ? (string) $t['tok'] : null; };
    if (!$nuevo && ($t = $lee())) return $t;
    $lk = fopen("$D/run/consola_token.lock", 'c');                     // un solo pedido de token a la vez
    if ($lk && !flock($lk, LOCK_EX | LOCK_NB)) {
        for ($i = 0; $i < 30; $i++) { usleep(100000); if ($t = $lee()) return $t; }
        flock($lk, LOCK_EX);
    }
    if (!$nuevo && ($t = $lee())) return $t;
    $aud = "$base/pim/auth/token";
    $h = b64u(json_encode(['alg' => 'ES384', 'typ' => 'JWT', 'kid' => 'consola-1']));
    $p = b64u(json_encode(['iss' => $id, 'sub' => $id, 'aud' => $aud, 'exp' => time() + 240, 'jti' => bin2hex(random_bytes(16))], JSON_UNESCAPED_SLASHES));
    openssl_sign("$h.$p", $der, $priv, OPENSSL_ALGO_SHA384);
    // DER (SEQUENCE{INTEGER r, INTEGER s}) → JOSE r‖s de 48 + 48 bytes
    $o = 2; if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
    $lr = ord($der[$o + 1]); $r = substr($der, $o + 2, $lr); $o += 2 + $lr;
    $ls = ord($der[$o + 1]); $s = substr($der, $o + 2, $ls);
    $firma = str_pad(ltrim($r, "\0"), 48, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 48, "\0", STR_PAD_LEFT);
    $ch = curl_init($aud);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'client_credentials', 'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion' => "$h.$p." . b64u($firma), 'scope' => 'system/*.cruds'])]);
    $j = json_decode((string) curl_exec($ch), true);
    if (empty($j['access_token'])) salir(502, 'El servidor PIM no entregó token a la Consola: ' . ($j['error_description'] ?? 'sin respuesta'));
    @mkdir("$D/run", 0700, true);
    file_put_contents($tf, json_encode(['tok' => $j['access_token'], 'exp' => time() + (int) $j['expires_in']]), LOCK_EX); @chmod($tf, 0600);
    return (string) $j['access_token'];
}

// ---------------------------------------------------------------- ejecución en el controlador de la API
$r = (string) ($_GET['r'] ?? 'metadata');
if (!preg_match('#^[A-Za-z][A-Za-z0-9\-\.\$_/]*(\?[^\s]*)?$#', $r) || str_contains($r, '..')) salir(400, 'Ruta inválida');
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($metodo, ['GET', 'POST', 'PUT'], true)) salir(405, 'Método no permitido');
[$ruta, $qs] = array_pad(explode('?', $r, 2), 2, '');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . token($base, $id, $priv, $D);
$_SERVER['REQUEST_URI'] = '/pim/r4/' . $ruta . ($qs !== '' ? '?' . $qs : '');
$_SERVER['QUERY_STRING'] = $qs;
parse_str($qs, $_GET);
unset($_SERVER['HTTP_X_PIM_CONSOLA']);
require dirname(__DIR__) . '/index.php';
