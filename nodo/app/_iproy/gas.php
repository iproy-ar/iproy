<?php
/**
 * iProy Portable · puerta local a los Apps Script · v0.4.0 · 29-10-2026
 *
 * iproy-offline.js redirige aquí TODAS las llamadas de la app a script.google.com:
 *     /_iproy/gas.php?_dep=<id de implementación>&<parámetros originales>
 *
 * Con red      → se reenvía a Google desde el nodo (mismo método, parámetros y cuerpo) y se aprovecha la
 *                respuesta: login correcto = verificador local; lectura = copia en caché.
 * Sin red      → login con el verificador local · lecturas desde la caché · escrituras a la cola
 *                (iproy_gas_cola), que el worker reenvía en orden cuando vuelve la conexión.
 * Las lecturas ya migradas (getDataV2, getStock…) ni siquiera llegan aquí: las sirve MySQL por
 * mysql-bridge.js → iProy/api/gas.php, igual que en producción.
 *
 * Nunca se registran parámetros (pueden traer contraseñas). Auth nunca se cachea ni se encola.
 */
declare(strict_types=1);
require __DIR__ . '/lib_red.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function salir_json(int $code, array $j, string $via): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-IPROY-Via: ' . $via);
    echo json_encode($j, JSON_UNESCAPED_UNICODE);
    exit;
}
function salir_crudo(int $code, string $tipo, string $cuerpo, string $via, array $extra = []): never {
    http_response_code($code ?: 200);
    header('Content-Type: ' . ($tipo ?: 'application/json; charset=utf-8'));
    header('X-IPROY-Via: ' . $via);
    foreach ($extra as $k => $v) header($k . ': ' . $v);
    echo $cuerpo;
    exit;
}

$dep = (string) ($_GET['_dep'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{10,100}$/', $dep)) salir_json(400, ['ok' => false, 'mensaje' => 'implementación inválida'], 'nodo');

$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($metodo, ['GET', 'POST'], true)) salir_json(405, ['ok' => false], 'nodo');
$query = ltrim(preg_replace('/(^|&)_dep=[^&]*/', '', (string) ($_SERVER['QUERY_STRING'] ?? '')), '&');
$tipo = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
$multipart = $metodo === 'POST' && stripos($tipo, 'multipart/form-data') !== false;
$archivos = $multipart && !empty($_FILES);
if ($metodo === 'POST') {
    if ($multipart) {                                           // PHP ya consumió el cuerpo: se rearma para curl
        $cuerpo = $_POST;
        foreach ($_FILES as $k => $f) if (is_string($f['tmp_name'] ?? null) && is_uploaded_file($f['tmp_name']))
            $cuerpo[$k] = new CURLFile($f['tmp_name'], $f['type'] ?: 'application/octet-stream', $f['name']);
        $crudo = http_build_query($_POST); $tipoCola = 'application/x-www-form-urlencoded';
    } else { $cuerpo = $crudo = (string) file_get_contents('php://input'); $tipoCola = $tipo; }
} else { $cuerpo = null; $crudo = null; $tipoCola = null; }

$p = red_parsear($query, $metodo === 'POST' ? $crudo : null, $tipoCola);
$accion = strtolower(red_accion($p));
$esAuth = str_starts_with($accion, 'auth') || str_starts_with($accion, 'config') || in_array($accion, SOLO_EN_LINEA, true) || $accion === 'validar';
$esLectura = $metodo === 'GET' && !isset($p['envio_fila'])
    && !preg_match('/^(set|save|guardar|update|actualiz|ingreso|egreso|alta|baja|borrar|elimin|delete|registr|envi|crear|nuevo|cerrar|asignar|mover|marcar|cambiar|aprobar)/', $accion);

try { $db = nodo_pdo('iproy'); } catch (Throwable $x) { $db = null; }
$pend = ($db && !$esLectura && !$esAuth) ? red_cola_pendientes($db) : 0;

/* ------------------------------------------------------------ 1) con red: a Google */
// Si hace poco se comprobó que no hay red, se prueba igual pero con espera corta (2 s): así el nodo
// vuelve a usar Google apenas regresa la conexión, sin esperar al worker.
$rapido = !red_probablemente_en_linea();
if ($pend === 0) {
    $url = rtrim((string) red_cfg()['gas_origen'], '/') . '/macros/s/' . rawurlencode($dep) . '/exec' . ($query !== '' ? '?' . $query : '');
    $cab = ['Accept: application/json, text/plain, */*'];
    if ($metodo === 'POST' && !$multipart && $tipo !== '') $cab[] = 'Content-Type: ' . $tipo;
    $r = red_http($metodo, $url, $cab, $cuerpo, $esLectura ? 45 : 60, $rapido ? 2 : null);
    if ($r['errno'] === 0) {
        red_estado(['online' => true, 'ultimo_ok' => date('c')]);
        if ($db && $r['code'] === 200) {
            try {
                if ($accion === 'authlogin') {
                    $j = json_decode($r['cuerpo'], true);
                    if (is_array($j) && ($j['ok'] ?? false) === true && is_array($j['perfil'] ?? null))
                        red_auth_recordar($db, strtolower(trim((string) ($p['email'] ?? ''))), (string) ($p['password'] ?? ''), $j['perfil']);
                } elseif ($esLectura && !$esAuth) {
                    red_cache_poner($db, $dep, $p, $r['tipo'], $r['cuerpo']);
                } elseif (in_array($accion, ['configget', 'configgrupos'], true)) {
                    // v0.4.5: copia de la configuración para verla sin red (clave sin token; solo se entrega con sesión local de admin)
                    $j = json_decode($r['cuerpo'], true);
                    if (is_array($j) && ($j['ok'] ?? false) === true) red_cache_poner($db, $dep, ['action' => $accion], $r['tipo'], $r['cuerpo']);
                }
            } catch (Throwable $x) { /* la caché nunca rompe la respuesta */ }
        }
        salir_crudo($r['code'], $r['tipo'], $r['cuerpo'], 'google');
    }
    if (!red_sin_red($r)) {
        // Salió y se cortó: una lectura se intenta desde la caché; una escritura PUDO grabarse → no se reenvía sola.
        if (!$esLectura && !$esAuth && $db && !$archivos) {
            $id = red_encolar($db, $dep, $metodo, $query, $crudo, $tipoCola, (string) ($p['email'] ?? $p['usuario'] ?? ''));
            $db->prepare("UPDATE iproy_gas_cola SET estado = 'dudoso', ultimo_error = 'sin confirmación (corte durante el envío)' WHERE id = ?")->execute([$id]);
            salir_json(504, ['ok' => false, 'success' => false, 'offline' => true, 'dudoso' => true, 'cola_id' => $id,
                'mensaje' => 'La conexión se cortó durante el envío y no se sabe si llegó. Quedó registrado para revisarlo en Configuración › Redes antes de reenviar.'], 'nodo');
        }
        if (!$esLectura || $esAuth) salir_json(504, ['ok' => false, 'offline' => true, 'mensaje' => 'El servidor central no respondió a tiempo. Probá de nuevo.'], 'nodo');
    } else {
        red_estado(['online' => false, 'ultimo_error' => $r['error']]);
    }
}

/* ------------------------------------------------------------ 2) sin red (o con cola pendiente): el nodo responde */
if (!$db) salir_json(503, ['ok' => false, 'offline' => true, 'mensaje' => 'Sin conexión y la base local no está disponible.'], 'nodo');

if ($accion === 'authlogin') {
    $j = red_auth_local($db, strtolower(trim((string) ($p['email'] ?? ''))), (string) ($p['password'] ?? ''));
    salir_json(200, $j, 'nodo-auth');
}
if ($accion === 'authping') salir_json(200, ['ok' => true, 'offline' => true, 'mensaje' => 'Auth IPROY activo (modo local del nodo).'], 'nodo-auth');
// v0.4.5: Configuración de admin sin red → solo lectura, con una sesión local (token firmado por el nodo, 2 h)
if ($accion === 'configlogin') {
    $email = strtolower(trim((string) ($p['email'] ?? '')));
    $j = red_auth_local($db, $email, (string) ($p['password'] ?? ''));
    if (($j['ok'] ?? false) !== true) salir_json(200, $j, 'nodo-auth');
    if (($j['perfil']['perfil'] ?? '') !== 'admin') salir_json(200, ['ok' => false, 'offline' => true, 'mensaje' => 'Solo administradores.'], 'nodo-auth');
    salir_json(200, ['ok' => true, 'offline' => true, 'token' => red_token_config($email), 'expira_s' => 7200,
                     'mensaje' => 'Sin conexión: configuración en modo lectura (los cambios necesitan Internet).'], 'nodo-auth');
}
if (in_array($accion, ['configget', 'configgrupos'], true)) {
    if ($accion === 'configget' && !red_token_config_ok((string) ($p['token'] ?? '')))
        salir_json(200, ['ok' => false, 'offline' => true, 'mensaje' => 'Sin conexión: volvé a ingresar tu contraseña para ver la configuración del nodo.'], 'nodo-auth');
    $c = red_cache_leer($db, $dep, ['action' => $accion]);
    if ($c) salir_crudo(200, $c['tipo'], $c['cuerpo'], 'cache', ['X-IPROY-Cache-Fecha' => $c['actualizado']]);
    salir_json(200, ['ok' => false, 'offline' => true, 'mensaje' => 'Sin conexión y el nodo todavía no tiene copia de la configuración: abrila una vez con Internet. Las Redes del nodo están arriba y funcionan igual.'], 'nodo');
}
if (str_starts_with($accion, 'config')) salir_json(200, ['ok' => false, 'offline' => true, 'mensaje' => 'Sin conexión: la configuración se puede ver, pero los cambios necesitan Internet.'], 'nodo');
if ($esAuth) salir_json(503, ['ok' => false, 'offline' => true, 'mensaje' => 'Esta acción necesita conexión con los servidores centrales.'], 'nodo');

if ($esLectura) {
    $c = red_cache_leer($db, $dep, $p);
    if ($c) salir_crudo(200, $c['tipo'], $c['cuerpo'], 'cache', ['X-IPROY-Cache-Fecha' => $c['actualizado']]);
    salir_json(503, ['ok' => false, 'success' => false, 'offline' => true,
        'mensaje' => 'Sin conexión y este nodo todavía no tiene una copia de esta consulta. Se completa sola la próxima vez que se use con Internet.'], 'nodo');
}

if ($archivos) salir_json(503, ['ok' => false, 'success' => false, 'offline' => true,
    'mensaje' => 'Sin conexión: los envíos con archivos adjuntos todavía no se pueden dejar en cola. Probá cuando vuelva la red.'], 'nodo');
$id = red_encolar($db, $dep, $metodo, $query, $crudo, $tipoCola, (string) ($p['email'] ?? $p['usuario'] ?? $p['nombreUsuario'] ?? ''));
salir_json(200, ['ok' => true, 'success' => true, 'result' => 'success', 'status' => 'success', 'offline' => true, 'encolado' => true, 'cola_id' => $id,
    'mensaje' => $pend > 0 ? 'Quedó en la cola detrás de ' . $pend . ' cambio(s) pendiente(s); se enviará en orden.'
                           : 'Sin conexión: quedó guardado en este nodo y se enviará a los servidores centrales cuando vuelva la red.'], 'cola');
