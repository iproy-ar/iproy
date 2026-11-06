<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Recuperación de contraseña (1.6.0). Pensada para nodos que trabajan SIN Internet:
 *
 *  1. Clave temporal del administrador: el administrador (usuarios.admin) genera una clave de un solo uso desde
 *     Usuarios; vence en 72 h y al ingresar el sistema exige cambiarla antes de hacer cualquier otra cosa.
 *  2. Códigos de recuperación: cada usuario genera 8 códigos de un solo uso (Mi perfil) y los imprime o guarda.
 *     Con uno cambia su clave desde «¿Olvidaste tu contraseña?», sin ayuda. Se guardan como HMAC con una clave del
 *     nodo: aunque alguien copie la base, no puede deducirlos.
 *  3. Enlace por correo: solo si el nodo tiene salida a Internet y el administrador configuró SMTP. El enlace vence
 *     en 30 minutos y sirve una vez. La respuesta es siempre la misma (no revela si el usuario existe).
 *  4. Último recurso (el único administrador olvidó su clave): en la PC del nodo, «<Producto>-Control usuario clave admin».
 *
 * Todo queda en el diario firmado. Ningún secreto se escribe en registros: solo el tipo de recuperación.
 */
declare(strict_types=1);

namespace Nucleo;

const CLAVE_TEMPORAL_HORAS = 72;
const CODIGOS_CANTIDAD = 8;
const ENLACE_MINUTOS = 30;
const ALFABETO_LEGIBLE = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // sin 0/O ni 1/I: se dictan y se copian sin errores

function texto_legible(int $n): string { $s = ''; for ($i = 0; $i < $n; $i++) $s .= ALFABETO_LEGIBLE[random_int(0, strlen(ALFABETO_LEGIBLE) - 1)]; return $s; }
/** HMAC con una clave propia del nodo (derivada del secreto de sesión): la base sola no alcanza para verificar códigos. */
function hmac_recuperacion(string $s): string { return hash_hmac('sha256', 'rec|' . $s, hash_hmac('sha256', 'nucleo-recuperacion', (string) (\nodo_cfg()['session_secret'] ?? ''))); }
function normalizar_codigo(string $c): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c)); }

// ---------------------------------------------------------------- 1 · clave temporal (administrador)
function clave_temporal(string $usuario): array {
    $adm = exigir('usuarios.admin');
    $u = fila('SELECT usuario, activo FROM nucleo_usuario WHERE usuario = ?', [$usuario]) ?? throw new Fallo(404, "No existe {$usuario}");
    if (!(int) $u['activo']) throw new Fallo(409, 'El usuario está dado de baja: activalo primero');
    $t = implode('-', str_split(texto_legible(12), 4));
    q('UPDATE nucleo_usuario SET clave_hash = ?, cambiar_clave = 1, clave_vence = DATE_ADD(NOW(), INTERVAL ' . CLAVE_TEMPORAL_HORAS . ' HOUR), intentos = 0, bloqueado_hasta = NULL WHERE usuario = ?',
      [password_hash($t, PASSWORD_ARGON2ID), $usuario]);
    asentar('usuario.clave_temporal', ['por' => $adm['usuario'], 'vence_horas' => CLAVE_TEMPORAL_HORAS], $usuario);
    return ['clave' => $t, 'vence_horas' => CLAVE_TEMPORAL_HORAS];
}

// ---------------------------------------------------------------- 2 · códigos de recuperación
function generar_codigos(string $usuario, string $actual): array {
    $h = valor('SELECT clave_hash FROM nucleo_usuario WHERE usuario = ?', [$usuario]);
    if (!$h || !password_verify($actual, (string) $h)) { usleep(random_int(200000, 400000)); throw new Fallo(403, 'La contraseña actual no es correcta'); }
    q("DELETE FROM nucleo_recuperacion WHERE usuario = ? AND tipo = 'codigo' AND usado IS NULL", [$usuario]);
    $c = [];
    for ($i = 0; $i < CODIGOS_CANTIDAD; $i++) {
        $x = texto_legible(10); $c[] = substr($x, 0, 5) . '-' . substr($x, 5);
        q("INSERT INTO nucleo_recuperacion (usuario, tipo, hash) VALUES (?, 'codigo', ?)", [$usuario, hmac_recuperacion($usuario . '|' . $x)]);
    }
    asentar('usuario.codigos', ['cantidad' => CODIGOS_CANTIDAD], $usuario);
    return $c;
}
function codigos_estado(string $usuario): array {
    $r = fila("SELECT SUM(usado IS NULL) AS libres, COUNT(*) AS total, MAX(creado) AS creado FROM nucleo_recuperacion WHERE usuario = ? AND tipo = 'codigo'", [$usuario]);
    return ['libres' => (int) ($r['libres'] ?? 0), 'total' => (int) ($r['total'] ?? 0), 'creado' => $r['creado'] ?? null];
}
/** Cambio de clave con un código (público). Mensaje único ante cualquier error: no revela si el usuario existe. */
function recuperar_con_codigo(string $usuario, string $codigo, string $nueva): array {
    $mal = new Fallo(401, 'Usuario o código incorrectos (o el código ya se usó)');
    $u = fila('SELECT usuario, activo, bloqueado_hasta FROM nucleo_usuario WHERE usuario = ?', [$usuario]);
    if ($u && $u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time()) throw new Fallo(429, 'Demasiados intentos: esperá unos minutos');
    validar_clave($nueva);
    $id = $u && (int) $u['activo'] ? valor("SELECT id FROM nucleo_recuperacion WHERE usuario = ? AND tipo = 'codigo' AND usado IS NULL AND hash = ?",
                                           [$usuario, hmac_recuperacion($usuario . '|' . normalizar_codigo($codigo))]) : null;
    if (!$id) {
        if ($u) q('UPDATE nucleo_usuario SET intentos = intentos + 1, bloqueado_hasta = IF(intentos + 1 >= 5, DATE_ADD(NOW(), INTERVAL 5 MINUTE), NULL) WHERE usuario = ?', [$usuario]);
        usleep(random_int(300000, 600000)); throw $mal;
    }
    q('UPDATE nucleo_recuperacion SET usado = NOW(), ip = ? WHERE id = ?', [substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), $id]);
    poner_clave($usuario, $nueva);
    asentar('usuario.recuperacion', ['tipo' => 'codigo', 'quedan' => codigos_estado($usuario)['libres']], $usuario, $usuario);
    return ['quedan' => codigos_estado($usuario)['libres']];
}
function poner_clave(string $usuario, string $nueva): void {
    validar_clave($nueva);
    q('UPDATE nucleo_usuario SET clave_hash = ?, cambiar_clave = 0, clave_vence = NULL, intentos = 0, bloqueado_hasta = NULL WHERE usuario = ?', [password_hash($nueva, PASSWORD_ARGON2ID), $usuario]);
}

// ---------------------------------------------------------------- 3 · enlace por correo (SMTP, solo con Internet)
function correo_cfg(?array $cambios = null): array {
    $f = dir_datos() . '/correo.json';
    $c = leer_json($f) + ['activo' => false, 'host' => '', 'puerto' => 587, 'seguridad' => 'tls', 'usuario' => '', 'clave' => '', 'remitente' => '', 'nombre' => '', 'url_app' => ''];
    if ($cambios !== null) {
        foreach (['activo', 'host', 'puerto', 'seguridad', 'usuario', 'remitente', 'nombre', 'url_app'] as $k) if (array_key_exists($k, $cambios)) $c[$k] = $cambios[$k];
        if (isset($cambios['clave']) && $cambios['clave'] !== '') $c['clave'] = (string) $cambios['clave'];     // vacía = no cambia
        if (!empty($cambios['borrar_clave'])) $c['clave'] = '';
        $c['activo'] = (bool) $c['activo']; $c['puerto'] = (int) $c['puerto'];
        if (!in_array($c['seguridad'], ['ssl', 'tls', 'ninguna'], true)) throw new Fallo(422, 'Seguridad: ssl, tls o ninguna');
        if ($c['host'] !== '' && !preg_match('/^[A-Za-z0-9.-]{3,253}$/', (string) $c['host'])) throw new Fallo(422, 'Servidor SMTP inválido');
        if ($c['remitente'] !== '' && !filter_var($c['remitente'], FILTER_VALIDATE_EMAIL)) throw new Fallo(422, 'Correo del remitente inválido');
        if ($c['url_app'] !== '' && !preg_match('#^https?://[^\s/]+(:\d+)?/?$#', (string) $c['url_app'])) throw new Fallo(422, 'Dirección de la aplicación: http(s)://servidor[:puerto]');
        if ($c['activo'] && ($c['host'] === '' || $c['remitente'] === '' || $c['url_app'] === '')) throw new Fallo(422, 'Para activar el correo completá servidor, remitente y dirección de la aplicación');
        escribir_json($f, $c, true);
        asentar('config.correo', array_diff_key($c, ['clave' => 1]) + ['clave_cargada' => $c['clave'] !== '']);
    }
    return $c;
}
function correo_publico(): array { $c = correo_cfg(); return array_diff_key($c, ['clave' => 1]) + ['clave_cargada' => $c['clave'] !== '']; }

/** Cliente SMTP mínimo (sin dependencias): SSL implícito (465), STARTTLS (587) o sin cifrar (solo intranet). */
function enviar_correo(string $para, string $asunto, string $texto): void {
    $c = correo_cfg();
    if (!$c['activo']) throw new Fallo(409, 'El correo saliente no está configurado');
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $dir = ($c['seguridad'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . (int) $c['puerto'];
    $s = @stream_socket_client($dir, $en, $es, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new Fallo(502, "No se pudo conectar al servidor de correo ({$es}). ¿El nodo tiene salida a Internet?");
    stream_set_timeout($s, 15);
    $leer = function () use ($s): string { $r = ''; while (($l = fgets($s, 2048)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
    $cmd = function (?string $linea, array $ok) use ($s, $leer): string {
        if ($linea !== null) fwrite($s, $linea . "\r\n");
        $r = $leer(); $cod = (int) substr($r, 0, 3);
        if (!in_array($cod, $ok, true)) throw new Fallo(502, 'El servidor de correo respondió: ' . trim(substr($r, 0, 160)));
        return $r;
    };
    try {
        $yo = preg_replace('/[^A-Za-z0-9.-]/', '', gethostname() ?: 'nodo');
        $cmd(null, [220]); $cmd("EHLO $yo", [250]);
        if ($c['seguridad'] === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new Fallo(502, 'No se pudo negociar TLS con el servidor de correo');
            $cmd("EHLO $yo", [250]);
        }
        if ($c['usuario'] !== '') { $cmd('AUTH LOGIN', [334]); $cmd(base64_encode((string) $c['usuario']), [334]); $cmd(base64_encode((string) $c['clave']), [235]); }
        $cmd('MAIL FROM:<' . $c['remitente'] . '>', [250]); $cmd('RCPT TO:<' . $para . '>', [250, 251]); $cmd('DATA', [354]);
        $enc = fn($t) => '=?UTF-8?B?' . base64_encode($t) . '?=';
        $msg = 'From: ' . ($c['nombre'] !== '' ? $enc((string) $c['nombre']) . ' ' : '') . '<' . $c['remitente'] . ">\r\nTo: <$para>\r\nSubject: " . $enc($asunto)
             . "\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . '@' . $yo . ">\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($texto), 76, "\r\n");
        fwrite($s, $msg . "\r\n.\r\n"); $cmd(null, [250]);
        fwrite($s, "QUIT\r\n");
    } finally { fclose($s); }
}
/** Pedido de enlace (público). Respuesta siempre igual; límite de 3 enlaces por hora por usuario. */
function pedir_enlace(string $usuario): array {
    $generico = ['mensaje' => 'Si el usuario existe y tiene un correo cargado, en unos minutos le llega un enlace que vence en ' . ENLACE_MINUTOS . ' minutos. Revisá también la carpeta de spam.'];
    $c = correo_cfg(); if (!$c['activo']) throw new Fallo(409, 'Este nodo no tiene el correo configurado: usá un código de recuperación o pedile una clave temporal al administrador');
    usleep(random_int(150000, 350000));
    $u = fila('SELECT usuario, nombre, email, activo FROM nucleo_usuario WHERE usuario = ?', [$usuario]);
    if (!$u || !(int) $u['activo'] || !$u['email']) return $generico;
    if ((int) valor("SELECT COUNT(*) FROM nucleo_recuperacion WHERE usuario = ? AND tipo = 'email' AND creado > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$usuario]) >= 3) return $generico;
    $t = b64u(random_bytes(32));
    q("INSERT INTO nucleo_recuperacion (usuario, tipo, hash, vence, ip) VALUES (?, 'email', ?, DATE_ADD(NOW(), INTERVAL " . ENLACE_MINUTOS . ' MINUTE), ?)',
      [$usuario, hmac_recuperacion('email|' . $t), substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    $p = producto()['nombre']; $url = rtrim((string) $c['url_app'], '/') . '/#recuperar=' . $t;
    try {
        enviar_correo((string) $u['email'], "$p · cambiar la contraseña",
            "Hola, {$u['nombre']}:\n\nAlguien pidió cambiar la contraseña del usuario «{$u['usuario']}» en $p (nodo " . nodo()['id'] . ").\n\n"
            . "Para elegir una nueva, abrí este enlace dentro de los próximos " . ENLACE_MINUTOS . " minutos (sirve una sola vez):\n\n$url\n\n"
            . "Si no fuiste vos, ignorá este mensaje: tu contraseña no cambia.\n");
        asentar('usuario.recuperacion_pedida', ['tipo' => 'email'], $usuario, $usuario);
    } catch (Fallo $e) {
        asentar('usuario.recuperacion_pedida', ['tipo' => 'email', 'error' => texto_corto($e->getMessage(), 160)], $usuario, $usuario);
        throw new Fallo(502, 'No se pudo enviar el correo ahora (¿el nodo está sin Internet?). Usá un código de recuperación o pedile una clave temporal al administrador.');
    }
    return $generico;
}
function recuperar_con_enlace(string $token, string $nueva): array {
    validar_clave($nueva);
    $r = fila("SELECT id, usuario FROM nucleo_recuperacion WHERE tipo = 'email' AND hash = ? AND usado IS NULL AND vence > NOW()", [hmac_recuperacion('email|' . $token)]);
    if (!$r) { usleep(random_int(300000, 600000)); throw new Fallo(401, 'El enlace venció o ya se usó: pedí otro'); }
    q('UPDATE nucleo_recuperacion SET usado = NOW() WHERE id = ?', [$r['id']]);
    q("UPDATE nucleo_recuperacion SET usado = NOW() WHERE usuario = ? AND tipo = 'email' AND usado IS NULL", [$r['usuario']]);   // los demás enlaces pendientes ya no sirven
    poner_clave($r['usuario'], $nueva);
    asentar('usuario.recuperacion', ['tipo' => 'email'], $r['usuario'], $r['usuario']);
    return ['usuario' => $r['usuario']];
}
/** Qué formas de recuperar ofrece este nodo (pública, para la pantalla de ingreso). */
function recuperacion_opciones(): array {
    $p = producto();
    return ['codigo' => true, 'email' => (bool) correo_cfg()['activo'], 'lanzador' => $p['nombre'] . '-Control'];
}
