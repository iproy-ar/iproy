<?php
/**
 * iProy · Servidor PIM v1.0 · autorización OAuth2 «SMART Backend Services» · 30-10-2026
 * (misma mecánica que la API FHIR v0.3, con registro de clientes, claves y alcances PROPIOS del servidor PIM:
 *  data/pim/clientes/*.json y la clave de tokens de data/php/pim.php)
 * v1.0: alcances de escritura SMART v2 (c = crear, u = actualizar) y ROL PIM del cliente (Tabla 9-1):
 *   GM Gestor de Mantenimiento · EJ Ejecutor (empresa/área, ve y opera SUS órdenes) · SO Solicitante · FT Fuente de telemetría · CI Consumidor de indicadores
 *
 * Flujo (sistema a sistema, sin personas):
 *  1. El organismo externo se registra UNA vez: entrega su clave PÚBLICA (JWKS) y se le asignan alcances.
 *     → data/pim/clientes/<client_id>.json   (lo crea «iProy-Control pim cliente alta»)
 *  2. Para operar, firma con su clave PRIVADA un JWT corto (client_assertion, RS384 o ES384)
 *     y lo canjea en POST /pim/auth/token (grant_type=client_credentials).
 *  3. Recibe un access_token de 5 minutos con los alcances concedidos (system/Device.read, system/*.rs…).
 * No hay secretos compartidos entre organismos: cada nodo firma sus tokens con su propia clave (data/php/pim.php).
 */
declare(strict_types=1);

namespace IproyPim;

function b64u_dec(string $s): string|false { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }
function b64u_enc(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

// ------------------------------------------------------------------ JWK → PEM (DER escrito a mano)
function der_len(int $n): string {
    if ($n < 0x80) return chr($n);
    $b = ''; while ($n > 0) { $b = chr($n & 0xff) . $b; $n >>= 8; }
    return chr(0x80 | strlen($b)) . $b;
}
function der(int $tag, string $v): string { return chr($tag) . der_len(strlen($v)) . $v; }
function der_int(string $bytes): string {
    $bytes = ltrim($bytes, "\0");
    if ($bytes === '' || (ord($bytes[0]) & 0x80)) $bytes = "\0" . $bytes;
    return der(0x02, $bytes);
}
function pem(string $der): string { return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n"; }

/** Devuelve [pem, alg] o null si la clave no es RSA ni EC P-384. */
function jwk_pem(array $k): ?array {
    if (($k['kty'] ?? '') === 'RSA' && isset($k['n'], $k['e'])) {
        $n = b64u_dec($k['n']); $e = b64u_dec($k['e']);
        if ($n === false || $e === false || strlen($n) < 256) return null;          // ≥ 2048 bits
        $alg = der(0x30, "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01" . "\x05\x00");
        $pub = der(0x30, der_int($n) . der_int($e));
        return [pem(der(0x30, $alg . der(0x03, "\0" . $pub))), 'RS384'];
    }
    if (($k['kty'] ?? '') === 'EC' && ($k['crv'] ?? '') === 'P-384' && isset($k['x'], $k['y'])) {
        $x = b64u_dec($k['x']); $y = b64u_dec($k['y']);
        if ($x === false || $y === false || strlen($x) !== 48 || strlen($y) !== 48) return null;
        $alg = der(0x30, "\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01" . "\x06\x05\x2B\x81\x04\x00\x22");
        return [pem(der(0x30, $alg . der(0x03, "\0\x04" . $x . $y))), 'ES384'];
    }
    return null;
}

/** Firma JOSE ES384 (r‖s, 96 bytes) → DER, que es lo que entiende openssl_verify. */
function es384_der(string $raw): ?string {
    if (strlen($raw) !== 96) return null;
    return der(0x30, der_int(substr($raw, 0, 48)) . der_int(substr($raw, 48)));
}

function verificar_firma(string $datos, string $firma, array $jwk, string $alg): bool {
    $r = jwk_pem($jwk);
    if (!$r || $r[1] !== $alg) return false;
    if ($alg === 'ES384') { $firma = es384_der($firma); if ($firma === null) return false; }
    return openssl_verify($datos, $firma, $r[0], OPENSSL_ALGO_SHA384) === 1;
}

// ------------------------------------------------------------------ clientes registrados
function cliente(string $id): ?array {
    if (!preg_match('/^[A-Za-z0-9._\-]{3,64}$/', $id)) return null;
    $f = datos() . '/clientes/' . $id . '.json';
    if (!is_file($f)) return null;
    $c = json_decode((string) file_get_contents($f), true);
    return (is_array($c) && ($c['activo'] ?? false) === true) ? $c : null;
}

function oauth_error(int $http, string $err, string $desc): never {
    responder($http, ['error' => $err, 'error_description' => $desc], 'application/json; charset=utf-8');
}

/** Un jti no puede reutilizarse mientras la aserción esté vigente (anti-repetición). */
function jti_nuevo(string $cli, string $jti, int $exp): bool {
    $d = datos() . '/run/jti';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    $f = $d . '/' . hash('sha256', $cli . '|' . $jti);
    if (is_file($f)) return false;
    @file_put_contents($f, (string) $exp, LOCK_EX);
    if (random_int(1, 50) === 1) {                       // limpieza ocasional
        foreach (glob($d . '/*') ?: [] as $g) if ((int) @file_get_contents($g) < time() - 60) @unlink($g);
    }
    return true;
}

/** Alcances: «system/Task.read», «system/*.write», «system/Task.cruds» (SMART v1 y v2). ¿$pedido está cubierto por $permitido? */
function alcance_ops(string $x): array {
    return match ($x) { 'read' => ['r', 's'], 'write' => ['c', 'u', 'd'], '*' => ['c', 'r', 'u', 'd', 's'],
        default => preg_match('/^c?r?u?d?s?$/', $x) ? str_split($x) : [] };
}
function alcance_cubre(string $permitido, string $pedido): bool {
    $re = '#^system/(\*|[A-Za-z]+)\.(read|write|\*|c?r?u?d?s?)$#';
    if (!preg_match($re, $permitido, $a) || !preg_match($re, $pedido, $b) || $b[2] === '') return false;
    if ($a[1] !== '*' && $a[1] !== $b[1]) return false;
    return !array_diff(alcance_ops($b[2]), alcance_ops($a[2]));
}

// ------------------------------------------------------------------ POST /pim/auth/token
function emitir_token(): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') oauth_error(405, 'invalid_request', 'Usar POST');
    $f = $_POST;
    if (($f['grant_type'] ?? '') !== 'client_credentials') oauth_error(400, 'unsupported_grant_type', 'Solo client_credentials');
    if (($f['client_assertion_type'] ?? '') !== 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer')
        oauth_error(400, 'invalid_request', 'client_assertion_type debe ser jwt-bearer');
    $partes = explode('.', (string) ($f['client_assertion'] ?? ''));
    if (count($partes) !== 3) oauth_error(400, 'invalid_request', 'client_assertion no es un JWT');
    $cab = json_decode((string) b64u_dec($partes[0]), true);
    $pl = json_decode((string) b64u_dec($partes[1]), true);
    $firma = b64u_dec($partes[2]);
    if (!is_array($cab) || !is_array($pl) || $firma === false) oauth_error(400, 'invalid_request', 'JWT mal formado');
    $alg = (string) ($cab['alg'] ?? '');
    if (!in_array($alg, ['RS384', 'ES384'], true)) oauth_error(400, 'invalid_client', 'alg debe ser RS384 o ES384');

    $id = (string) ($pl['iss'] ?? '');
    $GLOBALS['iproy_pim_audit']['cliente'] = $id;
    if ($id === '' || ($pl['sub'] ?? '') !== $id) oauth_error(401, 'invalid_client', 'iss y sub deben ser el client_id');
    $cli = cliente($id);
    if (!$cli) oauth_error(401, 'invalid_client', 'Cliente no registrado o dado de baja');

    $aud = (string) ($pl['aud'] ?? '');
    $hostOk = parse_url($aud, PHP_URL_HOST) === parse_url(base(), PHP_URL_HOST);
    if (!$hostOk || rtrim((string) parse_url($aud, PHP_URL_PATH), '/') !== '/pim/auth/token')
        oauth_error(401, 'invalid_client', 'aud debe ser la URL del token de este nodo');
    $ahora = time(); $exp = (int) ($pl['exp'] ?? 0);
    if ($exp < $ahora - 30 || $exp > $ahora + 330) oauth_error(401, 'invalid_client', 'exp vencido o mayor a 5 minutos');
    $jti = (string) ($pl['jti'] ?? '');
    if ($jti === '') oauth_error(401, 'invalid_client', 'Falta jti');

    $ok = false;
    foreach (($cli['jwks']['keys'] ?? []) as $k) {
        if (isset($cab['kid'], $k['kid']) && $cab['kid'] !== $k['kid']) continue;
        if (verificar_firma($partes[0] . '.' . $partes[1], $firma, $k, $alg)) { $ok = true; break; }
    }
    if (!$ok) oauth_error(401, 'invalid_client', 'Firma inválida para las claves registradas');
    if (!jti_nuevo($id, $jti, $exp)) oauth_error(401, 'invalid_client', 'jti repetido');

    $pedidos = preg_split('/\s+/', trim((string) ($f['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    $dados = [];
    foreach ($pedidos as $p) {                       // se concede la intersección: lo pedido que está permitido, o lo permitido que cabe en lo pedido
        foreach ((array) ($cli['scopes'] ?? []) as $perm) {
            if (alcance_cubre($perm, $p)) { $dados[] = $p; break; }
            if (alcance_cubre($p, $perm)) $dados[] = $perm;
        }
    }
    $dados = array_values(array_unique($dados));
    if (!$dados) oauth_error(400, 'invalid_scope', 'Ninguno de los alcances pedidos está autorizado para este cliente');

    $ttl = (int) cfg()['token_ttl'];
    $tok = jwt_hs256(['iss' => base(), 'sub' => $id, 'scope' => implode(' ', $dados), 'iat' => $ahora,
                      'exp' => $ahora + $ttl, 'jti' => b64u_enc(random_bytes(12))]);
    responder(200, ['access_token' => $tok, 'token_type' => 'bearer', 'expires_in' => $ttl, 'scope' => implode(' ', $dados)],
              'application/json; charset=utf-8');
}

function clave_tokens(): string {
    $k = (string) (cfg()['priv']['token_key'] ?? '');
    if (strlen($k) < 64) throw new Fallo(503, 'exception', 'Falta la clave de tokens del nodo (data/php/pim.php)');
    return hex2bin($k);
}
function jwt_hs256(array $pl): string {
    $a = b64u_enc(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])) . '.' . b64u_enc(json_encode($pl, JSON_UNESCAPED_SLASHES));
    return $a . '.' . b64u_enc(hash_hmac('sha256', $a, clave_tokens(), true));
}

/** Valida el Bearer y el alcance para $tipo/$op (r leer · s buscar · c crear · u actualizar). Devuelve el cliente (con rol). */
function exigir(string $tipo, string $op): array {
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+([A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+)$/', $h, $m))
        responder(401, oo('login', 'Falta el token (Authorization: Bearer …). Ver /pim/r4/.well-known/smart-configuration'), MIME,
                  ['WWW-Authenticate' => 'Bearer realm="iproy-pim"']);
    [$a, $b, $s] = explode('.', $m[1]);
    $esperada = b64u_enc(hash_hmac('sha256', "$a.$b", clave_tokens(), true));
    $pl = json_decode((string) b64u_dec($b), true);
    if (!hash_equals($esperada, $s) || !is_array($pl) || ($pl['exp'] ?? 0) < time())
        responder(401, oo('login', 'Token inválido o vencido'), MIME, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
    $GLOBALS['iproy_pim_audit']['cliente'] = $pl['sub'] ?? null;
    $cli = cliente((string) ($pl['sub'] ?? ''));
    if (!$cli) responder(401, oo('login', 'Cliente dado de baja'), MIME, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
    foreach (explode(' ', (string) ($pl['scope'] ?? '')) as $sc) {
        if (alcance_cubre($sc, "system/$tipo.$op")) {
            $cli['id'] = (string) $pl['sub']; $cli['rol'] = strtoupper((string) ($cli['rol'] ?? 'GM'));
            if ($cli['rol'] === 'RE' && ($tipo !== 'Device' || !in_array($op, ['r', 's'], true)))      // Registro Estatal: solo PMA (PIM-REG)
                responder(403, oo('forbidden', 'El rol RE (registro estatal) solo lee Device y usa Device/$refes'));
            return $cli;
        }
    }
    responder(403, oo('forbidden', "El token no incluye el alcance system/$tipo.$op"));
}
