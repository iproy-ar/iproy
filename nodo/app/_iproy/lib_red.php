<?php
/**
 * iProy Portable · red, conectividad y modo autónomo · v0.4.0 · 29-10-2026
 * - Configuración de red del nodo (data/red.json) y estado de conexión (data/run/red_estado.json).
 * - Cliente HTTP saliente (curl) que distingue «no salió» de «salió y no sé si llegó».
 * - Caché de lecturas, cola de escrituras y verificador local del login.
 * Nunca registra parámetros de las peticiones (pueden traer contraseñas o datos personales).
 */
declare(strict_types=1);
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }
require_once __DIR__ . '/lib_nodo.php';

const RED_DEFECTO = [
    'modo' => 'local',              // local = solo este equipo · lan = visible para otros nodos
    'hub_url' => '',                // servidor central de la colmena (P-4 · Fase 3)
    'login_offline' => true,        // permitir ingresar sin red con el verificador local
    'login_offline_dias' => 30,     // validez del verificador desde el último login en línea
    'cdn_local' => true,            // servir librerías y fuentes externas desde la copia del nodo
    'gas_origen' => 'https://script.google.com',
    'timeout_conexion' => 4,
    'proxy' => '',                  // proxy de salida (ej. http://proxy.hospital:3128); vacío = el del sistema si lo hay
];
const NO_CACHEAR = ['_s', '_o', '_t', '_', 't', 'ts', 'nocache', 'cb', 'callback', 'password', 'pa_ss', 'token', '_dep'];
const SOLO_EN_LINEA = ['authregister', 'authforgot', 'resetpassword', 'confirm', 'resetform'];

function red_dir(): string { return dirname(__DIR__, 2) . '/data'; }

function red_cfg(): array {
    static $c = null;
    if ($c !== null) return $c;
    $f = red_dir() . '/red.json';
    $j = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    return $c = array_merge(RED_DEFECTO, ['modo' => nodo_cfg()['modo'] ?? 'local'], $j);
}
function red_guardar(array $cambios): array {
    $f = red_dir() . '/red.json';
    $j = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $j = array_merge($j, $cambios);
    file_put_contents($f . '.tmp', json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($f . '.tmp', $f);
    return $j;
}
function red_estado(?array $nuevo = null): array {
    $f = red_dir() . '/run/red_estado.json';
    if ($nuevo !== null) {
        $v = red_estado();
        $nuevo = array_merge($v, $nuevo, ['ts' => time()]);
        @file_put_contents($f . '.tmp', json_encode($nuevo)); @rename($f . '.tmp', $f);
        return $nuevo;
    }
    return is_file($f) ? (json_decode((string) @file_get_contents($f), true) ?: []) : [];
}
/** true = hay red (o no se sabe); false = se confirmó sin red hace menos de 30 s */
function red_probablemente_en_linea(): bool {
    $e = red_estado();
    return !(isset($e['online'], $e['ts']) && $e['online'] === false && time() - (int) $e['ts'] < 30);
}

/**
 * Petición saliente. Devuelve ['code','tipo','cuerpo','errno','error','salio'].
 * salio=false → la petición NO llegó a enviarse (DNS, conexión, TLS): es seguro encolarla y reenviarla.
 */
function red_http(string $metodo, string $url, array $cab = [], $cuerpo = null, int $total = 25, ?int $conexion = null): array {
    $c = red_cfg();
    $ch = curl_init($url);
    $o = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => $conexion ?? (int) $c['timeout_conexion'], CURLOPT_TIMEOUT => $total,
        CURLOPT_HTTPHEADER => $cab, CURLOPT_USERAGENT => 'IPROY-Portable/0.4', CURLOPT_ENCODING => '',
        CURLOPT_HEADER => false,
    ];
    $px = red_proxy($url);
    if ($px !== '') $o[CURLOPT_PROXY] = $px;
    if ($metodo === 'POST') { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = $cuerpo ?? ''; }
    elseif ($metodo !== 'GET') { $o[CURLOPT_CUSTOMREQUEST] = $metodo; if ($cuerpo !== null) $o[CURLOPT_POSTFIELDS] = $cuerpo; }
    curl_setopt_array($ch, $o);
    $r = curl_exec($ch);
    $errno = curl_errno($ch);
    $inf = curl_getinfo($ch);
    $res = ['code' => (int) ($inf['http_code'] ?? 0), 'tipo' => (string) ($inf['content_type'] ?? ''),
            'cuerpo' => is_string($r) ? $r : '', 'errno' => $errno, 'error' => $errno ? curl_error($ch) : '', 'salio' => true];
    if ($errno) {
        // 5/6 DNS · 7 conexión · 35 TLS · 28 sin haber empezado la transferencia → no salió
        $res['salio'] = !(in_array($errno, [5, 6, 7, 35, 60], true) || ($errno === 28 && (float) ($inf['pretransfer_time'] ?? 0) == 0.0));
    }
    return $res;
}
/** Proxy de salida: el configurado en el panel o el del sistema (Windows lo deja en data/run/proxy_sistema.txt). Nunca para la LAN. */
function red_proxy(string $url): string {
    $h = (string) parse_url($url, PHP_URL_HOST);
    if ($h === '' || $h === 'localhost' || str_ends_with($h, '.local') || filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($h, FILTER_VALIDATE_IP)) return '';
    $p = trim((string) red_cfg()['proxy']);
    if ($p === '' && is_file($f = red_dir() . '/run/proxy_sistema.txt')) $p = trim((string) file_get_contents($f));
    return preg_match('~^https?://[A-Za-z0-9.\-\[\]:]+/?$~', $p) ? $p : '';
}
function red_sin_red(array $r): bool { return $r['errno'] !== 0 && !$r['salio']; }

/** Prueba liviana de salida a Internet (la usa el worker cada 30 s). */
function red_probar(): bool {
    $r = red_http('GET', rtrim((string) red_cfg()['gas_origen'], '/') . '/', [], null, 6);
    $ok = $r['errno'] === 0 && $r['code'] > 0;
    $e = ['online' => $ok];
    if ($ok) $e['ultimo_ok'] = date('c'); else $e['ultimo_error'] = $r['error'];
    red_estado($e);
    return $ok;
}

/* ------------------------------------------------------------------ caché de lecturas */
function red_clave(string $dep, array $p): string {
    foreach (NO_CACHEAR as $k) unset($p[$k]);
    ksort($p);
    return sha1($dep . '|' . http_build_query($p));
}
function red_cache_poner(PDO $db, string $dep, array $p, string $tipo, string $cuerpo): void {
    if ($cuerpo === '' || strlen($cuerpo) > 48 * 1024 * 1024) return;
    $db->prepare('REPLACE INTO iproy_gas_cache (clave, dep, accion, tipo, cuerpo, bytes, actualizado) VALUES (?,?,?,?,?,?,NOW())')
       ->execute([red_clave($dep, $p), substr($dep, 0, 90), substr(red_accion($p), 0, 60), substr($tipo ?: 'application/json', 0, 100), $cuerpo, strlen($cuerpo)]);
}
function red_cache_leer(PDO $db, string $dep, array $p): ?array {
    $q = $db->prepare('SELECT tipo, cuerpo, actualizado FROM iproy_gas_cache WHERE clave = ?');
    $q->execute([red_clave($dep, $p)]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
function red_accion(array $p): string { return (string) ($p['action'] ?? $p['accion'] ?? $p['IDform'] ?? (isset($p['envio_fila']) ? 'envio_fila' : '')); }

/* ------------------------------------------------------------------ cola de escrituras */
function red_cola_pendientes(PDO $db): int {
    return (int) $db->query("SELECT COUNT(*) FROM iproy_gas_cola WHERE estado = 'pendiente'")->fetchColumn();
}
function red_encolar(PDO $db, string $dep, string $metodo, string $query, ?string $cuerpo, ?string $tipo, ?string $usuario): int {
    $db->prepare('INSERT INTO iproy_gas_cola (clave_idem, dep, accion, metodo, query, cuerpo, tipo, usuario) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([bin2hex(random_bytes(16)), substr($dep, 0, 90), substr(red_accion(red_parsear($query, $cuerpo, $tipo)), 0, 60),
                  $metodo, $query, $cuerpo, $tipo ? substr($tipo, 0, 150) : null, $usuario ? substr($usuario, 0, 120) : null]);
    return (int) $db->lastInsertId();
}
function red_parsear(string $query, ?string $cuerpo, ?string $tipo): array {
    parse_str($query, $p);
    if ($cuerpo !== null && $cuerpo !== '') {
        $t = strtolower((string) $tipo);
        if (str_contains($t, 'json') || ltrim($cuerpo)[0] === '{') { $j = json_decode($cuerpo, true); if (is_array($j)) $p += $j; }
        elseif (str_contains($t, 'urlencoded') || str_contains($t, 'text/plain') || $t === '') { parse_str($cuerpo, $b); $p += $b; }
    }
    return $p;
}
/** Reenvía la cola a Google en orden; se detiene en el primer corte de red. Devuelve un resumen. */
function red_vaciar_cola(PDO $db, int $max = 20): string {
    $origen = rtrim((string) red_cfg()['gas_origen'], '/');
    $q = $db->query("SELECT * FROM iproy_gas_cola WHERE estado = 'pendiente' AND proximo <= NOW() ORDER BY id LIMIT " . $max);
    $env = 0; $err = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $url = $origen . '/macros/s/' . rawurlencode($f['dep']) . '/exec' . ($f['query'] !== '' && $f['query'] !== null ? '?' . $f['query'] : '');
        $cab = ['X-IPROY-Reenvio: ' . $f['clave_idem']];
        if ($f['tipo']) $cab[] = 'Content-Type: ' . $f['tipo'];
        $r = red_http($f['metodo'], $url, $cab, $f['metodo'] === 'GET' ? null : (string) $f['cuerpo'], 40);
        if (red_sin_red($r)) {
            $db->prepare('UPDATE iproy_gas_cola SET ultimo_error = ? WHERE id = ?')->execute([substr($r['error'], 0, 255), $f['id']]);
            red_estado(['online' => false, 'ultimo_error' => $r['error']]);
            break;                                              // sin red: se conserva el orden
        }
        if ($r['errno'] !== 0) {                                 // salió y se cortó: pudo grabarse
            $db->prepare("UPDATE iproy_gas_cola SET estado = 'dudoso', intentos = intentos + 1, ultimo_error = ? WHERE id = ?")
               ->execute([substr('sin confirmación: ' . $r['error'], 0, 255), $f['id']]);
            $err++; break;
        }
        if ($r['code'] >= 200 && $r['code'] < 400) {
            $db->prepare("UPDATE iproy_gas_cola SET estado = 'enviado', intentos = intentos + 1, enviado = NOW(), respuesta = ?, ultimo_error = NULL WHERE id = ?")
               ->execute([substr($r['cuerpo'], 0, 2000), $f['id']]);
            $env++; continue;
        }
        $n = (int) $f['intentos'] + 1;
        $db->prepare("UPDATE iproy_gas_cola SET estado = ?, intentos = ?, proximo = NOW() + INTERVAL ? MINUTE, ultimo_error = ? WHERE id = ?")
           ->execute([$n >= 10 ? 'error' : 'pendiente', $n, min(60, 2 ** min($n, 6)), 'HTTP ' . $r['code'], $f['id']]);
        $err++; break;
    }
    return "env=$env err=$err";
}

/* ------------------------------------------------------------------ login local */
function red_algo(): string|int { return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT; }

/** Tras un login correcto en Google: guarda el verificador local y el perfil devuelto. */
function red_auth_recordar(PDO $db, string $email, string $pass, array $perfil): void {
    if (!red_cfg()['login_offline'] || $email === '' || $pass === '') return;
    $db->prepare("INSERT INTO iproy_auth_local (email, verificador, perfil_json, origen, ultimo_online, fallidos, bloqueado_hasta)
                  VALUES (?,?,?,'google',NOW(),0,NULL)
                  ON DUPLICATE KEY UPDATE verificador=VALUES(verificador), perfil_json=VALUES(perfil_json), origen='google',
                                          ultimo_online=NOW(), fallidos=0, bloqueado_hasta=NULL")
       ->execute([$email, password_hash($pass, red_algo()), json_encode($perfil, JSON_UNESCAPED_UNICODE)]);
}

/** Login sin red. Devuelve la respuesta JSON con el mismo formato que authLogin_ del Apps Script. */
function red_auth_local(PDO $db, string $email, string $pass): array {
    $c = red_cfg();
    $mal = ['ok' => false, 'offline' => true, 'mensaje' => 'Email o contraseña incorrectos.'];
    if (!$c['login_offline']) return ['ok' => false, 'offline' => true, 'mensaje' => 'Sin conexión con el servidor central y el ingreso local está desactivado en este nodo.'];
    if ($email === '' || $pass === '') return $mal;
    $q = $db->prepare('SELECT * FROM iproy_auth_local WHERE email = ?'); $q->execute([$email]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if ($u && $u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time())
        return ['ok' => false, 'offline' => true, 'mensaje' => 'Demasiados intentos. Esperá unos minutos.'];
    if ($u && password_verify($pass, $u['verificador'])) {
        $dias = (int) $c['login_offline_dias'];
        if ($u['origen'] === 'google' && $dias > 0 && $u['ultimo_online'] && strtotime($u['ultimo_online']) < time() - $dias * 86400) {
            return ['ok' => false, 'offline' => true, 'motivo' => 'verificador_vencido',
                    'mensaje' => "Sin conexión. Tu acceso local venció (más de $dias días sin ingresar en línea)."];
        }
        $db->prepare('UPDATE iproy_auth_local SET ultimo_offline = NOW(), fallidos = 0, bloqueado_hasta = NULL WHERE email = ?')->execute([$email]);
        $perfil = json_decode($u['perfil_json'], true) ?: [];
        return ['ok' => true, 'offline' => true, 'perfil' => $perfil];
    }
    // Sin verificador (primer ingreso en este nodo): se intenta con la base replicada si el administrador cargó la clave del servicio
    $r = red_auth_pepper($db, $email, $pass);
    if ($r !== null) return $r;
    if ($u) {
        $n = (int) $u['fallidos'] + 1;
        $db->prepare('UPDATE iproy_auth_local SET fallidos = ?, bloqueado_hasta = ? WHERE email = ?')
           ->execute([$n, $n >= 5 ? date('Y-m-d H:i:s', time() + 300) : null, $email]);
        return $mal;
    }
    return ['ok' => false, 'offline' => true, 'motivo' => 'sin_verificador',
            'mensaje' => 'Sin conexión con el servidor central. Este usuario todavía no ingresó nunca en este nodo: hace falta un primer ingreso con Internet (o que el administrador cargue la clave de autenticación del nodo).'];
}

/** Verificación contra legado_user_gestion.user_mant (replicada) con el pepper cargado localmente. null = no aplicable. */
function red_auth_pepper(PDO $db, string $email, string $pass): ?array {
    $f = red_dir() . '/php/auth_pepper.txt';
    if (!is_file($f)) return null;
    $pepper = rtrim((string) file_get_contents($f), "\r\n");
    if ($pepper === '') return null;
    try {
        $u = nodo_pdo('legado_user_gestion');
        $q = $u->prepare('SELECT * FROM user_mant WHERE LOWER(TRIM(email)) = ? LIMIT 1'); $q->execute([$email]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $x) { return null; }
    if (!$r || empty($r['password_hash'])) return null;
    $calc = base64_encode(hash('sha256', ($r['password_salt'] ?? '') . '|' . $pass . '|' . $pepper, true));
    if (!hash_equals((string) $r['password_hash'], $calc)) return ['ok' => false, 'offline' => true, 'mensaje' => 'Email o contraseña incorrectos.'];
    $est = strtolower((string) ($r['estado'] ?? 'activo'));
    if ($est === 'bloqueado' || in_array(strtoupper((string) ($r['activo'] ?? '')), ['NO', 'FALSE', '0'], true))
        return ['ok' => false, 'offline' => true, 'motivo' => 'bloqueado', 'mensaje' => 'Acceso deshabilitado. Contacte al administrador.'];
    $perfil = red_perfil_desde_base($u, $db, $r);
    red_auth_recordar_pepper($db, $email, $pass, $perfil);
    return ['ok' => true, 'offline' => true, 'perfil' => $perfil];
}
function red_auth_recordar_pepper(PDO $db, string $email, string $pass, array $perfil): void {
    $db->prepare("INSERT INTO iproy_auth_local (email, verificador, perfil_json, origen, ultimo_offline) VALUES (?,?,?,'pepper',NOW())
                  ON DUPLICATE KEY UPDATE verificador=VALUES(verificador), perfil_json=IF(origen='google', perfil_json, VALUES(perfil_json)), ultimo_offline=NOW()")
       ->execute([$email, password_hash($pass, red_algo()), json_encode($perfil, JSON_UNESCAPED_UNICODE)]);
}
/** Arma el perfil como buildPerfil_ del Apps Script, con lo que haya replicado en la base (columnas opcionales). */
function red_perfil_desde_base(PDO $u, PDO $db, array $r): array {
    $lista = fn($v) => array_values(array_filter(array_map('trim', explode(',', (string) $v)), 'strlen'));
    $p = [];
    try {
        if (!empty($r['perfil_id'])) { $q = $u->prepare('SELECT * FROM user_perfiles WHERE id = ?'); $q->execute([$r['perfil_id']]); $p = $q->fetch(PDO::FETCH_ASSOC) ?: []; }
        elseif (!empty($r['perfil'])) { $q = $u->prepare('SELECT * FROM user_perfiles WHERE perfil = ?'); $q->execute([$r['perfil']]); $p = $q->fetch(PDO::FETCH_ASSOC) ?: []; }
    } catch (Throwable $x) { $p = []; }
    $perfilId = (string) ($p['perfil'] ?? $r['perfil'] ?? 'pendiente');
    $mod = $lista($p['modulos'] ?? 'dashboard');
    $mod = array_values(array_unique(array_merge($mod, $lista($r['modulos_extra'] ?? ''))));
    $mod = array_values(array_diff($mod, $lista($r['modulos_bloqueados'] ?? '')));
    if (!in_array('dashboard', $mod, true)) array_unshift($mod, 'dashboard');
    $nombre = trim(($r['nombre'] ?? '') . ' ' . ($r['apellido'] ?? ''));
    $ini = strtoupper(mb_substr((string) ($r['nombre'] ?? ''), 0, 1) . mb_substr((string) ($r['apellido'] ?? ''), 0, 1)) ?: 'US';
    $perfil = ['id' => $r['id_registro'] ?? $r['id'] ?? '', 'nombre' => $nombre ?: $r['email'], 'usuario' => $nombre ?: $r['email'],
               'email' => $r['email'], 'area' => $r['area'] ?? '', 'empresa' => ($r['area'] ?? '') ?: 'IPROY',
               'rol' => ($r['rol'] ?? '') ?: (($p['rol'] ?? '') ?: $perfilId), 'perfil' => $perfilId,
               'color' => ($r['color'] ?? '') ?: (($p['color'] ?? '') ?: 'chip-blue'), 'initials' => $ini, 'modulos' => $mod, 'resumen' => []];
    // El resumen y las URL de los Apps Script se toman de otro usuario del mismo perfil que ya ingresó en línea
    try {
        foreach ($db->query("SELECT perfil_json FROM iproy_auth_local WHERE origen = 'google' ORDER BY ultimo_online DESC LIMIT 200") as $f) {
            $o = json_decode($f['perfil_json'], true);
            if (($o['perfil'] ?? null) === $perfilId) {
                foreach (['resumen', 'scriptUrl', 'scriptUrlPost'] as $k) if (isset($o[$k])) $perfil[$k] = $o[$k];
                break;
            }
        }
    } catch (Throwable $x) {}
    $perfil['origen_local'] = 'base_replicada';
    return $perfil;
}

/* ------------------------------------------------------------------ utilidades del panel */
/** Sesión local de Configuración (sin red): token = nodo.<vence>.<hmac(email|vence)>; 2 h. */
function red_token_config(string $email): string {
    $v = time() + 7200; $s = (string) (nodo_cfg()['session_secret'] ?? '');
    return 'nodo.' . $v . '.' . rtrim(strtr(base64_encode($email), '+/', '-_'), '=') . '.' . hash_hmac('sha256', 'cfg|' . $email . '|' . $v, $s);
}
function red_token_config_ok(string $t): bool {
    $x = explode('.', $t);
    if (count($x) !== 4 || $x[0] !== 'nodo' || (int) $x[1] < time()) return false;
    $email = (string) base64_decode(strtr($x[2], '-_', '+/'));
    $s = (string) (nodo_cfg()['session_secret'] ?? '');
    return $s !== '' && hash_equals(hash_hmac('sha256', 'cfg|' . $email . '|' . $x[1], $s), $x[3]);
}
function red_es_local(): bool { return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true); }
function red_token_panel(): string {
    $s = (string) (nodo_cfg()['session_secret'] ?? '');
    return hash_hmac('sha256', 'panel-red|' . date('Y-m-d'), $s !== '' ? $s : 'sin-secreto');
}
function red_ips(): array {
    $f = red_dir() . '/run/ips.json';
    $j = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    return array_values(array_filter((array) ($j['ipv4'] ?? []), fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        && !str_starts_with($ip, '127.') && !str_starts_with($ip, '169.254.')));
}
