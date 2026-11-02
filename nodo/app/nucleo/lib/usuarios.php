<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Usuarios, sesiones y capacidades. Un rol (AD GM EJ SO FT CI, los mismos de PIM más el administrador del nodo)
 * otorga capacidades; cada módulo declara las suyas en su manifiesto ("capacidades": {"preventivos.editar": ["GM"]}).
 * AD tiene todas. La sesión es una cookie firmada (HMAC-SHA256) HttpOnly + SameSite=Strict; las escrituras exigen
 * además el encabezado X-Nucleo (un sitio ajeno no puede enviarlo sin CORS). Contraseñas: Argon2id.
 */
declare(strict_types=1);

namespace Nucleo;

const CAPACIDADES_NUCLEO = [
    'sistema.ver' => ['GM'], 'sistema.admin' => [], 'modulos.admin' => [], 'usuarios.admin' => [],
    'bus.leer' => ['GM'], 'bus.publicar' => ['FT'], 'colmena.ver' => ['GM'], 'colmena.usar' => ['GM'], 'colmena.admin' => [],
    'pim.usar' => ['GM', 'EJ', 'SO', 'FT', 'CI'],
];
/** Rol PIM con el que la app opera en el Servidor PIM en nombre del usuario. */
function rol_pim(string $rol): string { return $rol === 'AD' ? 'GM' : $rol; }

function capacidades(): array {
    $c = CAPACIDADES_NUCLEO;
    foreach (activos() as $m) foreach ((array) $m['capacidades'] as $cap => $roles) $c[$cap] = array_values(array_unique(array_merge($c[$cap] ?? [], (array) $roles)));
    return $c;
}
function puede(string $cap, ?array $u = null): bool {
    $u ??= $GLOBALS['nucleo_usuario'] ?? null;
    if (!$u) return false;
    if ($u['rol'] === 'AD') return true;
    return in_array($u['rol'], capacidades()[$cap] ?? [], true);
}
function exigir(string $cap): array {
    $u = $GLOBALS['nucleo_usuario'] ?? null;
    if (!$u) throw new Fallo(401, 'Ingresá con tu usuario');
    if (!puede($cap, $u)) throw new Fallo(403, "Tu rol ({$u['rol']}) no tiene la capacidad «{$cap}»");
    return $u;
}

// ---------------------------------------------------------------- sesión
function clave_sesion(): string { return hash_hmac('sha256', 'nucleo-sesion', (string) (\nodo_cfg()['session_secret'] ?? '')); }
function emitir_token(array $u, int $segundos): string {
    $p = b64u(json_encode(['u' => $u['usuario'], 'r' => $u['rol'], 'exp' => time() + $segundos, 'n' => bin2hex(random_bytes(6))]));
    return $p . '.' . b64u(hash_hmac('sha256', $p, clave_sesion(), true));
}
function leer_token(string $t): ?array {
    if (!preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $t, $m)) return null;
    if (!hash_equals(b64u(hash_hmac('sha256', $m[1], clave_sesion(), true)), $m[2])) return null;
    $p = json_decode(b64u_dec($m[1]), true);
    if (!is_array($p) || ($p['exp'] ?? 0) < time()) return null;
    $u = fila('SELECT usuario, nombre, rol, organizacion FROM nucleo_usuario WHERE usuario = ? AND activo = 1', [$p['u'] ?? '']);
    return ($u && $u['rol'] === $p['r']) ? $u : null;     // si le cambiaron el rol, la sesión vieja ya no vale
}
/** Identifica al que llama: lanzador (tick), token Bearer de integración o cookie de sesión. */
function autenticar(): ?array {
    if (es_tick()) return $GLOBALS['nucleo_usuario'] = ['usuario' => 'sistema', 'nombre' => 'Lanzador del nodo', 'rol' => 'AD', 'organizacion' => null];
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (str_starts_with($h, 'Bearer ') && ($u = leer_token(substr($h, 7)))) return $GLOBALS['nucleo_usuario'] = $u + ['via' => 'token'];
    $c = (string) ($_COOKIE['nucleo_s'] ?? '');
    if ($c !== '' && ($u = leer_token($c))) {
        $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($m !== 'GET' && $m !== 'HEAD' && ($_SERVER['HTTP_X_NUCLEO'] ?? '') !== '1') throw new Fallo(403, 'Pedido sin el encabezado X-Nucleo (protección CSRF)');
        return $GLOBALS['nucleo_usuario'] = $u + ['via' => 'sesion'];
    }
    return null;
}
function ingresar(string $usuario, string $clave): array {
    $u = fila('SELECT * FROM nucleo_usuario WHERE usuario = ?', [$usuario]);
    if ($u && $u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time()) throw new Fallo(429, 'Usuario bloqueado unos minutos por intentos fallidos');
    if (!$u || !$u['activo'] || !password_verify($clave, $u['clave_hash'])) {
        if ($u) q('UPDATE nucleo_usuario SET intentos = intentos + 1, bloqueado_hasta = IF(intentos + 1 >= 5, DATE_ADD(NOW(), INTERVAL 5 MINUTE), NULL) WHERE usuario = ?', [$usuario]);
        usleep(random_int(200000, 400000));
        throw new Fallo(401, 'Usuario o contraseña incorrectos');
    }
    q('UPDATE nucleo_usuario SET intentos = 0, bloqueado_hasta = NULL, ultimo_ingreso = NOW() WHERE usuario = ?', [$usuario]);
    if (password_needs_rehash($u['clave_hash'], PASSWORD_ARGON2ID)) q('UPDATE nucleo_usuario SET clave_hash = ? WHERE usuario = ?', [password_hash($clave, PASSWORD_ARGON2ID), $usuario]);
    $seguro = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    setcookie('nucleo_s', emitir_token($u, 8 * 3600), ['expires' => time() + 8 * 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => $seguro]);
    $GLOBALS['nucleo_usuario'] = $u;
    asentar('usuario.ingreso', [], $usuario, $usuario);
    return ['usuario' => $u['usuario'], 'nombre' => $u['nombre'], 'rol' => $u['rol']];
}
function salir(): void { setcookie('nucleo_s', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']); }

// ---------------------------------------------------------------- alta y cambios
function validar_clave(string $c): void {
    if (mb_strlen($c) < 10) throw new Fallo(422, 'La contraseña debe tener al menos 10 caracteres');
}
function guardar_usuario(array $d, bool $nuevo): array {
    $u = (string) ($d['usuario'] ?? '');
    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $u)) throw new Fallo(422, 'usuario: 3-60 caracteres (minúsculas, números, . _ -)');
    $rol = (string) ($d['rol'] ?? '');
    if ($nuevo || isset($d['rol'])) if (!isset(ROLES[$rol])) throw new Fallo(422, 'rol: ' . implode(' | ', array_keys(ROLES)));
    if ($rol === 'EJ' && trim((string) ($d['organizacion'] ?? '')) === '') throw new Fallo(422, 'Un Ejecutor necesita su organización (código PIM de la empresa o área)');
    if ($nuevo) {
        if (fila('SELECT 1 FROM nucleo_usuario WHERE usuario = ?', [$u])) throw new Fallo(409, "Ya existe el usuario $u");
        validar_clave((string) ($d['clave'] ?? ''));
        q('INSERT INTO nucleo_usuario (usuario, nombre, rol, organizacion, clave_hash) VALUES (?,?,?,?,?)',
          [$u, mb_substr(trim((string) ($d['nombre'] ?? $u)), 0, 120), $rol, ($d['organizacion'] ?? '') ?: null, password_hash((string) $d['clave'], PASSWORD_ARGON2ID)]);
        asentar('usuario.alta', ['rol' => $rol], $u);
    } else {
        $act = fila('SELECT * FROM nucleo_usuario WHERE usuario = ?', [$u]) ?? throw new Fallo(404, "No existe $u");
        $set = []; $a = [];
        foreach (['nombre', 'rol', 'organizacion'] as $k) if (array_key_exists($k, $d)) { $set[] = "$k = ?"; $a[] = $d[$k] === '' ? null : $d[$k]; }
        if (array_key_exists('activo', $d)) { $set[] = 'activo = ?'; $a[] = $d['activo'] ? 1 : 0; }
        if (!empty($d['clave'])) { validar_clave((string) $d['clave']); $set[] = 'clave_hash = ?'; $a[] = password_hash((string) $d['clave'], PASSWORD_ARGON2ID); $set[] = 'intentos = 0, bloqueado_hasta = NULL'; }
        if ($set) { $a[] = $u; q('UPDATE nucleo_usuario SET ' . implode(', ', $set) . ' WHERE usuario = ?', $a); }
        asentar('usuario.cambio', array_diff_key($d, ['clave' => 1]) + (!empty($d['clave']) ? ['clave' => 'cambiada'] : []), $u);
    }
    return fila('SELECT usuario, nombre, rol, organizacion, activo, ultimo_ingreso, creado FROM nucleo_usuario WHERE usuario = ?', [$u]);
}
