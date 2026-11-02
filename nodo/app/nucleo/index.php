<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Núcleo iProy · API del nodo (/api/*). Toda la API es JSON. Mapa:
 *
 *  públicas   GET  /api                       producto, versión, protocolo, módulos
 *             GET  /api/fuente                dónde obtener el código fuente (AGPL-3.0 §13)
 *             GET  /api/colmena/identidad     identidad pública del nodo (claves y huella)
 *             POST /api/colmena/sobre         entrada de sobres de otros nodos (autenticados por firma)
 *  sesión     POST /api/sesion {usuario, clave} · GET /api/sesion · DELETE /api/sesion · POST /api/sesion/clave
 *  proc       GET  /api/proc/{sistema|modulos|tareas|bus|diario|vinculos|capacidades|rpc} · POST /api/proc/diario/verificar
 *  módulos    POST /api/modulos/{id}/{activar|desactivar} · GET|PUT /api/modulos/{id}/config · GET /api/modulos/{id}/ui.js
 *             POST /api/tareas/{modulo:tarea}/ejecutar
 *  bus        GET  /api/bus/eventos?desde=&tema= · POST /api/bus/publicar {tema, datos}
 *  usuarios   GET|POST /api/usuarios · PUT /api/usuarios/{u} · POST /api/usuarios/{u}/token {dias}
 *  PIM        /api/pim/{ruta FHIR}            la API PIM con el rol del usuario (en el mismo proceso)
 *  colmena    GET /api/colmena/vinculos · POST /api/colmena/vincular {destino} · POST /api/colmena/vinculos/{par}/{aceptar|revocar|rol|ping}
 *             POST /api/colmena/rpc {par, metodo, ruta, cuerpo, esperar} · GET /api/colmena/rpc/{id} · POST /api/colmena/evento {par, tema, datos}
 *             GET|PUT /api/colmena/config {hub:{url,activo,codigo}, url_publica} · POST /api/colmena/hub/{registrar|buzon}
 *  módulos    /api/m/{modulo}/{ruta}          rutas propias de cada módulo (rutas.php)
 *  internas   POST /api/nucleo/{arranque|tick|usuario}   solo el lanzador (127.0.0.1 + token del worker)
 */
declare(strict_types=1);

namespace Nucleo;

require __DIR__ . '/lib/nucleo.php';
cargar();

header('X-Content-Type-Options: nosniff');
try {
    $ruta = rtrim(rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)), '/');
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $p = array_values(array_filter(explode('/', substr($ruta, strlen('/api'))), 'strlen'));
    $u = autenticar();
    $c = fn(string $k, $d = null) => cuerpo()[$k] ?? $d;
    $es = fn(string $metodo, string ...$partes) => $m === $metodo && count($p) === count($partes) && !array_filter($partes, fn($x, $i) => $x !== '*' && $x !== $p[$i], ARRAY_FILTER_USE_BOTH);

    // ---------------------------------------------------------------- públicas
    if ($p === [] && $m === 'GET') {
        $pr = producto();
        responder(200, ['producto' => $pr['nombre'], 'version' => $pr['version'], 'nucleo' => VERSION, 'abi' => ABI, 'protocolo' => PROTOCOLO,
            'nodo' => nodo()['id'], 'nombre' => nodo()['nombre'], 'licencia' => $pr['licencia'], 'fuente' => $pr['fuente'],
            'modulos' => array_map(fn($x) => ['id' => $x['id'], 'version' => $x['version'], 'estabilidad' => $x['estabilidad']], array_values(activos())),
            'contaminado' => (bool) contaminado()]);
    }
    if ($es('GET', 'fuente')) {
        $pr = producto();
        responder(200, ['licencia' => $pr['licencia'], 'fuente' => $pr['fuente'], 'version' => $pr['version'], 'base' => $pr['base'],
            'aviso' => 'Este programa es software libre (AGPL-3.0-or-later). Quien lo use por red tiene derecho a recibir el código fuente correspondiente, incluidas las modificaciones.']);
    }
    if ($es('GET', 'colmena', 'identidad')) responder(200, identidad_publica());
    if ($es('POST', 'colmena', 'sobre')) {
        $r = recibir_sobre(cuerpo(), 'lan');
        responder(200, ['ok' => true, 'sobre' => $r]);
    }

    // ---------------------------------------------------------------- internas (lanzador)
    if (($p[0] ?? '') === 'nucleo') {
        if (!es_tick()) throw new Fallo(403, 'Solo el lanzador del nodo');
        if ($es('POST', 'nucleo', 'arranque')) responder(200, ['ok' => true] + arrancar());
        if ($es('POST', 'nucleo', 'tick')) responder(200, ['ok' => true, 'hecho' => tick()]);
        if ($es('POST', 'nucleo', 'usuario')) responder(200, ['ok' => true, 'usuario' => guardar_usuario(cuerpo(), !fila('SELECT 1 FROM nucleo_usuario WHERE usuario = ?', [(string) $c('usuario', '')]))]);
    }

    // ---------------------------------------------------------------- sesión
    if ($es('POST', 'sesion')) responder(200, ['ok' => true] + ingresar((string) $c('usuario', ''), (string) $c('clave', '')));
    if ($es('DELETE', 'sesion')) { salir(); responder(200, ['ok' => true]); }
    if ($es('GET', 'sesion')) {
        if (!$u) responder(200, ['ok' => false, 'producto' => producto()['nombre'], 'nodo' => nodo()]);
        $caps = array_keys(array_filter(capacidades(), fn($roles, $cap) => puede($cap, $u), ARRAY_FILTER_USE_BOTH));
        $ui = [];
        foreach (activos() as $x) if (!empty($x['ui']['script'])) $ui[] = ['modulo' => $x['id'], 'version' => $x['version'], 'orden' => (int) ($x['ui']['orden'] ?? 50)];
        usort($ui, fn($a, $b) => $a['orden'] <=> $b['orden']);
        responder(200, ['ok' => true, 'usuario' => array_intersect_key($u, array_flip(['usuario', 'nombre', 'rol', 'organizacion'])), 'rol_pim' => rol_pim($u['rol']),
            'capacidades' => $caps, 'ui' => $ui, 'producto' => producto(), 'nodo' => nodo(), 'roles' => ROLES]);
    }
    if ($es('POST', 'sesion', 'clave')) {
        $yo = exigir('pim.usar') + ['usuario' => ''];
        $h = valor('SELECT clave_hash FROM nucleo_usuario WHERE usuario = ?', [$yo['usuario']]);
        if (!$h || !password_verify((string) $c('actual', ''), (string) $h)) throw new Fallo(403, 'La contraseña actual no es correcta');
        guardar_usuario(['usuario' => $yo['usuario'], 'clave' => (string) $c('nueva', '')], false);
        responder(200, ['ok' => true]);
    }
    if (!$u) throw new Fallo(401, 'Ingresá con tu usuario');

    // ---------------------------------------------------------------- PIM con el rol del usuario
    if (($p[0] ?? '') === 'pim') { exigir('pim.usar'); pim_proxy(implode('/', array_slice($p, 1)), $u); }

    // ---------------------------------------------------------------- proc
    if ($es('GET', 'proc', '*')) { exigir('sistema.ver'); responder(200, ['ok' => true, $p[1] => proc($p[1])]); }
    if ($es('POST', 'proc', 'diario', 'verificar')) { exigir('sistema.ver'); responder(200, verificar_diario()); }

    // ---------------------------------------------------------------- módulos y tareas
    if ($es('POST', 'modulos', '*', 'activar') || $es('POST', 'modulos', '*', 'desactivar')) {
        exigir('modulos.admin'); activar_modulo($p[1], $p[2] === 'activar');
        responder(200, ['ok' => true] + arrancar());
    }
    if ($es('GET', 'modulos', '*', 'config')) { exigir('modulos.admin'); responder(200, ['ok' => true, 'config' => cfg_modulo($p[1])]); }
    if ($es('PUT', 'modulos', '*', 'config')) {
        exigir('modulos.admin'); $cf = cuerpo()['config'] ?? throw new Fallo(422, 'Falta «config»');
        $r = cfg_modulo($p[1], (array) $cf); asentar('modulo.config', ['claves' => array_keys((array) $cf)], $p[1]);
        responder(200, ['ok' => true, 'config' => $r]);
    }
    if ($es('GET', 'modulos', '*', 'ui.js')) {
        $x = modulo($p[1]) ?? throw new Fallo(404, 'Módulo no cargado');
        $f = $x['dir'] . '/' . ($x['ui']['script'] ?? '-');
        if (empty($x['ui']['script']) || !is_file($f)) throw new Fallo(404, 'El módulo no tiene interfaz');
        header('Content-Type: text/javascript; charset=utf-8'); header('Cache-Control: no-cache');
        readfile($f); exit;
    }
    if ($es('POST', 'tareas', '*', 'ejecutar')) { exigir('sistema.admin'); responder(200, ['ok' => true, 'resultado' => ejecutar_ahora($p[1])]); }

    // ---------------------------------------------------------------- bus
    if ($es('GET', 'bus', 'eventos')) { exigir('bus.leer'); responder(200, ['ok' => true, 'eventos' => eventos((int) ($_GET['desde'] ?? 0), ($_GET['tema'] ?? '') ?: null, (int) ($_GET['n'] ?? 200))]); }
    if ($es('POST', 'bus', 'publicar')) {
        exigir('bus.publicar');
        $tema = (string) $c('tema', '');
        if (preg_match('#^(nucleo|colmena|pim)/#', $tema)) throw new Fallo(403, 'Los temas nucleo/, colmena/ y pim/ los publica solo el nodo');
        responder(201, ['ok' => true, 'id' => publicar($tema, (array) $c('datos', []), 'api:' . $u['usuario'])]);
    }

    // ---------------------------------------------------------------- usuarios
    if ($es('GET', 'usuarios')) { exigir('usuarios.admin'); responder(200, ['ok' => true, 'usuarios' => filas('SELECT usuario, nombre, rol, organizacion, activo, ultimo_ingreso, creado FROM nucleo_usuario ORDER BY usuario'), 'roles' => ROLES]); }
    if ($es('POST', 'usuarios')) { exigir('usuarios.admin'); responder(201, ['ok' => true, 'usuario' => guardar_usuario(cuerpo(), true)]); }
    if ($es('PUT', 'usuarios', '*')) { exigir('usuarios.admin'); responder(200, ['ok' => true, 'usuario' => guardar_usuario(['usuario' => $p[1]] + cuerpo(), false)]); }
    if ($es('POST', 'usuarios', '*', 'token')) {
        exigir('usuarios.admin');
        $x = fila('SELECT * FROM nucleo_usuario WHERE usuario = ? AND activo = 1', [$p[1]]) ?? throw new Fallo(404, 'Usuario inexistente o inactivo');
        $dias = max(1, min(365, (int) $c('dias', 90)));
        asentar('usuario.token', ['dias' => $dias], $p[1]);
        responder(201, ['ok' => true, 'token' => emitir_token($x, $dias * 86400), 'vence' => date('c', time() + $dias * 86400),
            'aviso' => 'Guardalo ahora: no se vuelve a mostrar. Si cambia el rol del usuario, el token deja de valer.']);
    }

    // ---------------------------------------------------------------- colmena
    if ($es('GET', 'colmena', 'vinculos')) { exigir('colmena.ver'); responder(200, ['ok' => true, 'yo' => array_diff_key(identidad_publica(), ['pim_jwk' => 1]) + ['url' => url_propia()], 'vinculos' => proc('vinculos'), 'hub' => array_diff_key(cfg()['hub'], ['codigo' => 1])]); }
    if ($es('POST', 'colmena', 'vincular')) { exigir('colmena.admin'); responder(200, ['ok' => true] + vincular((string) $c('destino', ''), (string) $c('tipo', 'institucional'))); }
    if ($es('POST', 'colmena', 'vinculos', '*', 'aceptar')) { exigir('colmena.admin'); responder(200, ['ok' => true] + aceptar($p[2], (string) $c('rol', 'CI'), $c('vence'), $c('acta_sha'))); }
    if ($es('POST', 'colmena', 'vinculos', '*', 'revocar')) { exigir('colmena.admin'); responder(200, ['ok' => true] + revocar($p[2])); }
    if ($es('POST', 'colmena', 'vinculos', '*', 'rol')) { exigir('colmena.admin'); responder(200, ['ok' => true] + rol_par($p[2], (string) $c('rol', 'CI'))); }
    if ($es('POST', 'colmena', 'vinculos', '*', 'via')) {
        exigir('colmena.admin'); $via = (string) $c('via', 'auto');
        if (!in_array($via, ['auto', 'hub'], true)) throw new Fallo(422, 'via: auto (intranet y, si falla, hub) | hub (siempre por el hub)');
        q('UPDATE nucleo_vinculo SET via = ? WHERE par = ?', [$via, strtoupper($p[2])]); asentar('colmena.via', ['via' => $via], strtoupper($p[2]));
        responder(200, ['ok' => true, 'par' => strtoupper($p[2]), 'via' => $via]);
    }
    if ($es('POST', 'colmena', 'vinculos', '*', 'ping')) { exigir('colmena.ver'); responder(200, ['ok' => true] + ping_par($p[2])); }
    if ($es('POST', 'colmena', 'rpc')) {
        exigir('colmena.usar');
        $r = rpc((string) $c('par', ''), (string) $c('metodo', 'GET'), (string) $c('ruta', ''), $c('cuerpo'), $c('if_match'), (int) $c('esperar', 0));
        asentar('colmena.rpc', ['metodo' => $r['metodo'], 'ruta' => texto_corto($r['ruta'], 120), 'estado' => $r['estado'], 'http' => $r['http']], $r['par']);
        responder(200, ['ok' => true, 'rpc' => $r]);
    }
    if ($es('GET', 'colmena', 'rpc', '*')) { exigir('colmena.usar'); responder(200, ['ok' => true, 'rpc' => rpc_estado($p[2]) ?? throw new Fallo(404, 'RPC inexistente')]); }
    if ($es('POST', 'colmena', 'evento')) { exigir('colmena.usar'); responder(200, ['ok' => true] + evento_remoto((string) $c('par', ''), (string) $c('tema', ''), (array) $c('datos', []))); }
    if ($es('GET', 'colmena', 'config')) { exigir('colmena.admin'); $x = cfg(); responder(200, ['ok' => true, 'hub' => ['url' => $x['hub']['url'], 'activo' => $x['hub']['activo'], 'codigo' => $x['hub']['codigo'] !== '' ? '••••' : ''], 'url_publica' => $x['url_publica'], 'url_efectiva' => url_propia()]); }
    if ($es('PUT', 'colmena', 'config')) {
        exigir('colmena.admin');
        $cambio = [];
        if (is_array($h = $c('hub'))) {
            if (isset($h['url']) && $h['url'] !== '' && !preg_match('#^https?://[^\s]+$#', (string) $h['url'])) throw new Fallo(422, 'URL de hub inválida');
            $cambio['hub'] = array_intersect_key($h, ['url' => 1, 'activo' => 1, 'codigo' => 1]);
            if (isset($cambio['hub']['activo'])) $cambio['hub']['activo'] = (bool) $cambio['hub']['activo'];
        }
        if (($x = $c('url_publica')) !== null) $cambio['url_publica'] = rtrim((string) $x, '/');
        cfg($cambio); estado('colmena_hub', []);
        asentar('colmena.config', ['hub' => cfg()['hub']['url'], 'activo' => cfg()['hub']['activo'], 'url_publica' => cfg()['url_publica']]);
        responder(200, ['ok' => true]);
    }
    if ($es('POST', 'colmena', 'hub', 'registrar')) { exigir('colmena.admin'); responder(200, ['ok' => true, 'hub' => hub_registrar()]); }
    if ($es('POST', 'colmena', 'hub', 'buzon')) { exigir('colmena.usar'); responder(200, ['ok' => true] + hub_buzon()); }

    // ---------------------------------------------------------------- rutas de los módulos
    if (($p[0] ?? '') === 'm' && isset($p[1])) {
        $mod = modulo($p[1]) ?? throw new Fallo(404, "El módulo {$p[1]} no está cargado");
        if (empty($mod['rutas'])) throw new Fallo(404, "El módulo {$p[1]} no tiene API");
        incluir($mod['id']);
        $rutas = require $mod['dir'] . '/' . $mod['rutas'];
        $sub = '/' . implode('/', array_slice($p, 2));
        foreach ($rutas as [$rm, $patron, $cap, $fn]) {
            if ($rm !== $m) continue;
            $rx = '#^' . preg_replace('#\\\{([a-z_]+)\\\}#', '(?P<$1>[^/]+)', preg_quote($patron, '#')) . '$#';
            if (!preg_match($rx, $sub, $x)) continue;
            if ($cap) exigir($cap);
            $GLOBALS['nucleo_modulo_actual'] = $mod['id'];
            $r = $fn(ctx($mod['id'], ['params' => array_filter($x, 'is_string', ARRAY_FILTER_USE_KEY), 'cuerpo' => in_array($m, ['POST', 'PUT'], true) ? cuerpo() : [], 'query' => $_GET]));
            $http = (int) ($r['_http'] ?? 200); unset($r['_http']);
            responder($http, ['ok' => true] + $r);
        }
        throw new Fallo(404, "El módulo {$p[1]} no tiene $m $sub");
    }
    throw new Fallo(404, "No existe $m $ruta");
} catch (Fallo $e) {
    responder($e->http, ['ok' => false, 'error' => $e->getMessage(), 'codigo' => $e->codigo] + $e->extra);
} catch (\Throwable $e) {
    error_log('[nucleo] ' . $e);
    responder(500, ['ok' => false, 'error' => 'Error interno del núcleo: ' . $e->getMessage()]);
}
