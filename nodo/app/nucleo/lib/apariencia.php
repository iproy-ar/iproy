<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Apariencia y perfil (1.2.0): identidad institucional (logo, nombre, lema), fondos del ingreso y del tablero,
 * orden y visibilidad de los widgets, y preferencias de cada usuario (fondo propio, transparencia, recientes).
 *
 *  data/nucleo/apariencia.json            lo que define el administrador para todo el nodo
 *  data/nucleo/perfiles/{usuario}.json    lo que elige cada usuario para sí
 *  data/nucleo/imagenes/{24 hex}.{png|jpg} imágenes subidas, siempre re-codificadas con GD (sin metadatos ni contenido ajeno)
 *
 * Cambiar la apariencia del nodo exige una «sesión de configuración»: el administrador confirma su contraseña y queda
 * habilitado 2 horas (cookie firmada aparte de la sesión). Así una sesión olvidada abierta no basta para cambiar la identidad.
 */
declare(strict_types=1);

namespace Nucleo;

const FONDOS = ['', 'cielo', 'oceano', 'atardecer', 'bosque', 'montana', 'aurora', 'arena', 'grafito', 'hexagonos', 'quirofano'];
const VISTAS = ['widgets', 'menu'];
const APARIENCIA_DEF = ['vista' => 'widgets', 'institucion' => '', 'lema' => '', 'logo' => '', 'fondo_login' => 'cielo', 'fondo_tablero' => '', 'opacidad' => 82,
    'widgets' => ['orden' => [], 'ocultos' => []], 'saludo' => true];
const PERFIL_DEF = ['vista' => null, 'fondo' => null, 'opacidad' => null, 'recientes' => [], 'widgets_ocultos' => []];

function dir_imagenes(): string { return dir_datos('imagenes'); }
function apariencia(?array $cambios = null): array {
    $f = dir_datos() . '/apariencia.json';
    $a = leer_json($f) + APARIENCIA_DEF;
    if ($cambios !== null) { $a = array_replace($a, array_intersect_key($cambios, APARIENCIA_DEF)); escribir_json($f, $a); }
    return $a;
}
/** Lo que necesita la pantalla de ingreso (público: no incluye nada sensible). */
function apariencia_publica(): array {
    $a = apariencia(); $pr = producto();
    return ['producto' => $pr['nombre'], 'version' => $pr['version'], 'institucion' => $a['institucion'], 'lema' => $a['lema'],
        'logo' => imagen_url($a['logo']), 'fondo_login' => fondo_publico($a['fondo_login']), 'nodo' => nodo()['nombre']];
}
function imagen_url(string $v): string { return preg_match('/^[a-f0-9]{24}\.(png|jpg)$/', $v) ? '/api/apariencia/imagen/' . $v : ''; }
/** Un fondo es un tema prediseñado (por nombre) o una imagen subida (archivo). */
function fondo_publico(string $v): array {
    if ($u = imagen_url($v)) return ['tipo' => 'imagen', 'url' => $u];
    return ['tipo' => 'tema', 'tema' => in_array($v, FONDOS, true) ? $v : ''];
}
function validar_fondo(string $v): string {
    if ($v === '' || in_array($v, FONDOS, true) || (imagen_url($v) && is_file(dir_imagenes() . '/' . $v))) return $v;
    throw new Fallo(422, 'Fondo desconocido: elegí un tema o subí una imagen');
}

// ---------------------------------------------------------------- sesión de configuración (2 horas)
function confirmar_config(string $clave): array {
    $u = exigir('sistema.admin');
    $h = valor('SELECT clave_hash FROM nucleo_usuario WHERE usuario = ?', [$u['usuario']]);
    if (!$h || !password_verify($clave, (string) $h)) { usleep(random_int(300000, 600000)); asentar('config.confirmar', ['ok' => false], $u['usuario']); throw new Fallo(403, 'Contraseña incorrecta'); }
    $exp = time() + 7200; $p = b64u(json_encode(['u' => $u['usuario'], 'exp' => $exp, 'n' => bin2hex(random_bytes(6))]));
    $seguro = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    setcookie('nucleo_cfg', $p . '.' . b64u(hash_hmac('sha256', 'cfg|' . $p, clave_sesion(), true)), ['expires' => $exp, 'path' => '/api', 'httponly' => true, 'samesite' => 'Strict', 'secure' => $seguro]);
    asentar('config.confirmar', ['ok' => true], $u['usuario']);
    return ['vence' => date('c', $exp)];
}
function config_confirmada(): bool {
    $u = $GLOBALS['nucleo_usuario'] ?? null; $c = (string) ($_COOKIE['nucleo_cfg'] ?? '');
    if (!$u || ($u['via'] ?? '') === 'token' || !preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $c, $m)) return false;
    if (!hash_equals(b64u(hash_hmac('sha256', 'cfg|' . $m[1], clave_sesion(), true)), $m[2])) return false;
    $p = json_decode(b64u_dec($m[1]), true);
    return is_array($p) && ($p['u'] ?? '') === $u['usuario'] && ($p['exp'] ?? 0) > time();
}
function exigir_config(): array {
    $u = exigir('sistema.admin');
    if (!config_confirmada()) throw new Fallo(428, 'Confirmá tu contraseña para entrar a la configuración', 'config-sin-confirmar');
    return $u;
}

// ---------------------------------------------------------------- imágenes
/** Recibe el cuerpo crudo (image/png|jpeg|webp), lo valida y lo re-codifica. logo: PNG ≤ 512 px · fondo: JPEG ≤ 2560 px. */
function guardar_imagen(string $tipo): string {
    if (!function_exists('imagecreatefromstring')) throw new Fallo(501, 'Este PHP no tiene la extensión GD');
    $raw = (string) file_get_contents('php://input', false, null, 0, 6 * 1048576 + 1);
    if ($raw === '') throw new Fallo(422, 'No llegó ninguna imagen');
    if (strlen($raw) > 6 * 1048576) throw new Fallo(413, 'La imagen supera 6 MB');
    $info = @getimagesizefromstring($raw);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) throw new Fallo(415, 'Formato no admitido: usá PNG, JPG o WebP');
    if ($info[0] * $info[1] > 40_000_000) throw new Fallo(413, 'La imagen tiene demasiados píxeles');
    $im = @imagecreatefromstring($raw) ?: throw new Fallo(422, 'No se pudo leer la imagen');
    $logo = $tipo === 'logo'; $max = $logo ? 512 : 2560;
    $w = imagesx($im); $h = imagesy($im); $k = min(1, $max / max($w, $h));
    $nw = max(1, (int) round($w * $k)); $nh = max(1, (int) round($h * $k));
    $out = imagecreatetruecolor($nw, $nh);
    if ($logo) { imagealphablending($out, false); imagesavealpha($out, true); imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127)); }
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $nombre = bin2hex(random_bytes(12)) . ($logo ? '.png' : '.jpg');
    $ok = $logo ? imagepng($out, dir_imagenes() . "/$nombre", 6) : imagejpeg($out, dir_imagenes() . "/$nombre", 84);
    imagedestroy($im); imagedestroy($out);
    if (!$ok) throw new Fallo(500, 'No se pudo guardar la imagen');
    return $nombre;
}
function borrar_imagen(?string $v): void { if ($v && imagen_url($v) && is_file($f = dir_imagenes() . '/' . $v)) @unlink($f); }
function servir_imagen(string $v): never {
    if (!imagen_url($v) || !is_file($f = dir_imagenes() . '/' . $v)) throw new Fallo(404, 'Imagen inexistente');
    header('Content-Type: ' . (str_ends_with($v, '.png') ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: public, max-age=31536000, immutable'); header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($f)); readfile($f); exit;
}

// ---------------------------------------------------------------- perfil de cada usuario
function archivo_perfil(string $u): string { return dir_datos('perfiles') . '/' . preg_replace('/[^a-z0-9._-]/', '_', $u) . '.json'; }
function perfil(string $u, ?array $cambios = null): array {
    $p = leer_json(archivo_perfil($u)) + PERFIL_DEF;
    if ($cambios !== null) {
        if (array_key_exists('fondo', $cambios)) {
            $nuevo = $cambios['fondo'] === null ? null : validar_fondo((string) $cambios['fondo']);
            if ($p['fondo'] !== $nuevo && is_string($p['fondo']) && imagen_url($p['fondo']) && $p['fondo'] !== apariencia()['fondo_tablero']) borrar_imagen($p['fondo']);
            $p['fondo'] = $nuevo;
        }
        if (array_key_exists('vista', $cambios)) $p['vista'] = in_array($cambios['vista'], VISTAS, true) ? $cambios['vista'] : null;
        if (array_key_exists('opacidad', $cambios)) $p['opacidad'] = $cambios['opacidad'] === null ? null : max(40, min(100, (int) $cambios['opacidad']));
        if (isset($cambios['recientes'])) $p['recientes'] = array_slice(array_values(array_unique(array_filter(array_map(fn($x) => preg_replace('/[^a-z0-9_-]/', '', (string) $x), (array) $cambios['recientes'])))), 0, 6);
        if (isset($cambios['widgets_ocultos'])) $p['widgets_ocultos'] = array_slice(array_values(array_unique(array_map(fn($x) => preg_replace('/[^a-z0-9_.-]/', '', (string) $x), (array) $cambios['widgets_ocultos']))), 0, 40);
        escribir_json(archivo_perfil($u), array_intersect_key($p, PERFIL_DEF));
    }
    return $p;
}
/** Lo que la app necesita para pintar el tablero de este usuario: identidad + fondo efectivo + widgets. */
function vista_usuario(string $u): array {
    $a = apariencia(); $p = perfil($u);
    $fondo = $p['fondo'] ?? $a['fondo_tablero'];
    $vn = in_array($a['vista'], VISTAS, true) ? $a['vista'] : 'widgets';
    return ['vista' => $p['vista'] ?? $vn, 'vista_nodo' => $vn, 'vista_propia' => $p['vista'] !== null,
        'institucion' => $a['institucion'], 'lema' => $a['lema'], 'logo' => imagen_url($a['logo']), 'saludo' => (bool) $a['saludo'],
        'fondo' => fondo_publico((string) $fondo), 'fondo_propio' => $p['fondo'] !== null, 'opacidad' => (int) ($p['opacidad'] ?? $a['opacidad']),
        'widgets' => ['orden' => $a['widgets']['orden'] ?? [], 'ocultos_nodo' => $a['widgets']['ocultos'] ?? [], 'ocultos' => $p['widgets_ocultos']],
        'recientes' => $p['recientes'], 'temas' => array_values(array_filter(FONDOS))];
}
