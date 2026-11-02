<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Colmena: red de nodos (de cualquier producto derivado: iProy, Colmen, …) que se reconocen por el protocolo «colmena/1».
 *
 *  Sobre (mensaje) v1 = {v, id, de, para, ts, tipo, datos | caja, firma}
 *    - firma ed25519 del remitente sobre el JSON canónico (sin «firma»): autenticidad e integridad de extremo a extremo;
 *    - caja: sobre sellado X25519 (libsodium crypto_box_seal) para el destinatario: ni el hub ni la red lo pueden leer;
 *    - anti-repetición: cada id se procesa una sola vez (nucleo_sobre_visto) y el ts no puede tener más de 8 días.
 *  Transporte: directo por la intranet (POST <url>/api/colmena/sobre, respuesta en el acto) o por el HUB de relevo
 *  (buzón «guardar y reenviar» en un hosting PHP común: Internet sin abrir puertos ni IP fija).
 *  Vínculo: A pide → B lo acepta (después de comparar la huella por teléfono) → ambos registran al otro como cliente PIM
 *  con su clave ES384 y el rol acordado. «El vínculo es la autorización»: sin vínculo activo no hay RPC ni eventos.
 *  RPC: A firma una aserción SMART dirigida a B (aud = http://nodo-<b>.colmena/pim/auth/token) y la manda sellada;
 *  B la canjea en SU Servidor PIM: roles, validaciones, Provenance e historial se aplican igual que a cualquier cliente.
 */
declare(strict_types=1);

namespace Nucleo;

const TIPOS_SOBRE = ['vinculo-solicitud', 'vinculo-recibido', 'vinculo-aceptado', 'vinculo-revocado', 'rpc', 'rpc-resp', 'evento', 'ping', 'pong'];

function yo(): string { return nodo()['id']; }
function url_propia(): string {
    $u = (string) (cfg()['url_publica'] ?? '');
    if ($u !== '') return rtrim($u, '/');
    $env = @file_get_contents(raiz() . '/data/iproy.env') ?: '';
    $modo = trim((string) (@file_get_contents(raiz() . '/data/run/modo_activo') ?: nodo()['modo']));
    if ($modo === 'lan') {
        $tls = preg_match('/^IPROY_PUERTO_TLS=(\d+)/m', $env, $m) ? $m[1] : '8443';
        $ip = (leer_json(raiz() . '/data/run/ips.json')['ipv4'][0] ?? '') ?: gethostbyname(gethostname());
        return "https://$ip:$tls";
    }
    return 'http://127.0.0.1:' . puerto_local();
}
function vinculo(string $par): ?array { return fila('SELECT * FROM nucleo_vinculo WHERE par = ?', [strtoupper($par)]); }

// ---------------------------------------------------------------- sobres
function sobre(string $para, string $tipo, array $datos, ?string $caja_pub = null): array {
    $s = ['v' => 1, 'id' => id_nuevo(), 'de' => yo(), 'para' => strtoupper($para), 'ts' => time(), 'tipo' => $tipo];
    if ($caja_pub) $s['caja'] = sellar(json_encode($datos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $caja_pub);
    else $s['datos'] = $datos;
    $s['firma'] = firmar(canonico($s));
    return $s;
}
/** Valida forma, destinatario, vigencia, repetición y firma. Devuelve los datos (abriendo la caja si viene sellada). */
function abrir_sobre(array $s, string $firma_pub): array {
    foreach (['v', 'id', 'de', 'para', 'ts', 'tipo', 'firma'] as $k) if (!isset($s[$k])) throw new Fallo(400, "Sobre incompleto: falta $k");
    if ((int) $s['v'] !== 1 || !in_array($s['tipo'], TIPOS_SOBRE, true)) throw new Fallo(400, 'Sobre de versión o tipo desconocido');
    if (strtoupper((string) $s['para']) !== yo()) throw new Fallo(421, 'El sobre es para otro nodo');
    if ((int) $s['ts'] < time() - 8 * 86400 || (int) $s['ts'] > time() + 600) throw new Fallo(400, 'Sobre vencido o con fecha futura');
    $f = $s['firma']; unset($s['firma']);
    if (!verificar_firma(canonico($s), (string) $f, $firma_pub)) throw new Fallo(401, 'Firma del sobre inválida');
    try { q('INSERT INTO nucleo_sobre_visto (id, de, ts) VALUES (?,?,FROM_UNIXTIME(?))', [substr((string) $s['id'], 0, 26), substr((string) $s['de'], 0, 12), (int) $s['ts']]); }
    catch (\PDOException) { throw new Fallo(409, 'Sobre repetido'); }
    if (isset($s['caja'])) {
        $p = abrir((string) $s['caja']);
        if ($p === null) throw new Fallo(400, 'No se pudo abrir la caja (¿cambió la clave del nodo?)');
        return json_decode($p, true) ?? [];
    }
    return (array) ($s['datos'] ?? []);
}

/** Procesa un sobre recibido (por la intranet o por el hub). Devuelve el sobre de respuesta, si corresponde. */
function recibir_sobre(array $s, string $via): ?array {
    $de = strtoupper((string) ($s['de'] ?? ''));
    if (!preg_match('/^[A-Z0-9]{2,12}$/', $de)) throw new Fallo(400, 'Remitente inválido');
    $v = vinculo($de);
    if (($s['tipo'] ?? '') === 'vinculo-solicitud') {
        $d = (array) ($s['datos'] ?? []);
        if (($d['node_id'] ?? '') !== $de || empty($d['firma_pub'])) throw new Fallo(400, 'Solicitud de vínculo sin identidad');
        if ($v && $v['firma_pub'] !== $d['firma_pub'] && $v['estado'] !== 'revocado')
            throw new Fallo(409, "Ya hay un vínculo con $de y otra clave: debe revocarse antes (posible suplantación)");
        $d = abrir_sobre($s, (string) $d['firma_pub']);
        if (!$v || $v['estado'] === 'revocado') {
            guardar_vinculo($d, 'entrante', $via);
            asentar('colmena.solicitud_recibida', ['huella' => huella($d['firma_pub']), 'nombre' => $d['nombre'] ?? null, 'via' => $via], $de);
            publicar('nucleo/colmena/solicitud', ['par' => $de, 'nombre' => $d['nombre'] ?? null], 'nucleo');
        } elseif ($v['estado'] === 'saliente') {   // los dos se pidieron a la vez: se acepta
            q("UPDATE nucleo_vinculo SET estado = 'activo' WHERE par = ?", [$de]); registrar_cliente_par(vinculo($de));
        }
        return sobre($de, 'vinculo-recibido', ['estado' => vinculo($de)['estado']] + identidad_publica());
    }
    if (!$v) throw new Fallo(403, "No hay vínculo con $de");
    $d = abrir_sobre($s, $v['firma_pub']);
    q('UPDATE nucleo_vinculo SET ultimo_contacto = NOW() WHERE par = ?', [$de]);
    switch ($s['tipo']) {
        case 'vinculo-recibido':
            return null;
        case 'vinculo-aceptado':
            if ($v['estado'] === 'saliente' || $v['estado'] === 'entrante') {
                q("UPDATE nucleo_vinculo SET estado = 'activo', pim_jwk = ?, url = COALESCE(NULLIF(?, ''), url) WHERE par = ?", [json_encode($d['pim_jwk'] ?? json_decode($v['pim_jwk'], true)), (string) ($d['url'] ?? ''), $de]);
                registrar_cliente_par(vinculo($de));
                asentar('colmena.vinculo_activo', ['via' => $via, 'huella' => $v['huella']], $de);
                publicar('nucleo/colmena/vinculo', ['par' => $de, 'estado' => 'activo'], 'nucleo');
            }
            return null;
        case 'vinculo-revocado':
            revocar_local($de, 'revocado por el otro nodo');
            return null;
        case 'ping':
            return sobre($de, 'pong', ['hora' => date('c'), 'nombre' => nodo()['nombre']], $v['caja_pub']);
        case 'pong':
            return null;
        case 'rpc':
            if ($v['estado'] !== 'activo') throw new Fallo(403, "El vínculo con $de no está activo");
            $r = pim_con_asercion((string) ($d['asercion'] ?? ''), (string) ($d['metodo'] ?? 'GET'), (string) ($d['ruta'] ?? ''), $d['cuerpo'] ?? null, $d['if_match'] ?? null);
            return sobre($de, 'rpc-resp', ['rpc' => (string) ($d['rpc'] ?? ''), 'http' => $r['http'], 'cuerpo' => $r['cuerpo'], 'cab' => $r['cab']], $v['caja_pub']);
        case 'rpc-resp':
            q("UPDATE nucleo_rpc SET estado = 'respondido', http = ?, respuesta = ?, respondido = ? WHERE id = ? AND par = ?",
              [(int) ($d['http'] ?? 0), json_encode(['cuerpo' => $d['cuerpo'] ?? null, 'cab' => $d['cab'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ahora(), (string) ($d['rpc'] ?? ''), $de]);
            return null;
        case 'evento':
            if ($v['estado'] !== 'activo') throw new Fallo(403, 'Vínculo no activo');
            $tema = preg_replace('#[^A-Za-z0-9_\-./]#', '', (string) ($d['tema'] ?? 'mensaje'));
            publicar('colmena/' . strtolower($de) . '/' . trim($tema, '/'), (array) ($d['datos'] ?? []), 'colmena:' . $de);
            return null;
    }
    return null;
}

// ---------------------------------------------------------------- vínculos
function guardar_vinculo(array $d, string $estado, string $via): void {
    foreach (['node_id', 'firma_pub', 'caja_pub', 'pim_jwk'] as $k) if (empty($d[$k])) throw new Fallo(422, "Identidad del otro nodo incompleta: falta $k");
    if (strtoupper($d['node_id']) === yo()) throw new Fallo(422, 'No se puede vincular un nodo consigo mismo');
    q('INSERT INTO nucleo_vinculo (par, nombre, producto, url, firma_pub, caja_pub, pim_jwk, huella, estado, via) VALUES (?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), producto = VALUES(producto), url = COALESCE(VALUES(url), url), firma_pub = VALUES(firma_pub),
       caja_pub = VALUES(caja_pub), pim_jwk = VALUES(pim_jwk), huella = VALUES(huella), estado = VALUES(estado), via = VALUES(via)',
      [strtoupper($d['node_id']), mb_substr((string) ($d['nombre'] ?? ''), 0, 120), mb_substr((string) ($d['producto'] ?? ''), 0, 60),
       ($d['url'] ?? '') !== '' ? mb_substr((string) $d['url'], 0, 255) : null, $d['firma_pub'], $d['caja_pub'], json_encode($d['pim_jwk']),
       huella($d['firma_pub']), $estado, $via === 'hub' ? 'hub' : 'auto']);
}
/** El otro nodo pasa a ser un cliente del Servidor PIM de este nodo, con el rol del vínculo. */
function registrar_cliente_par(array $v): void {
    $id = 'colmena-' . strtolower($v['par']);
    escribir_json(raiz() . "/data/pim/clientes/$id.json", ['client_id' => $id, 'nombre' => "Nodo vinculado {$v['par']} · {$v['nombre']}", 'activo' => true,
        'alta' => date('c'), 'rol' => $v['rol_pim'], 'organizacion' => '', 'scopes' => explode(' ', ALCANCES_PIM[$v['rol_pim']] ?? 'system/*.rs'),
        'jwks' => ['keys' => [json_decode($v['pim_jwk'], true)]], 'colmena' => ['huella' => $v['huella']]], true);
}
function baja_cliente_par(string $par): void {
    $f = raiz() . '/data/pim/clientes/colmena-' . strtolower($par) . '.json';
    if (is_file($f)) { @mkdir(dirname($f) . '/_bajas', 0700, true); rename($f, dirname($f) . '/_bajas/' . basename($f, '.json') . '.' . date('Ymd_His') . '.json'); }
}
/** Pide un vínculo: $destino es la URL del otro nodo (intranet) o su identificador (por el hub). */
function vincular(string $destino): array {
    if (preg_match('#^https?://#', $destino)) {
        $url = rtrim($destino, '/');
        $r = http('GET', "$url/api/colmena/identidad", null, ['Accept' => 'application/json'], 8, false);
        $d = json_decode($r['cuerpo'], true);
        if (($d['protocolo'] ?? '') !== PROTOCOLO) throw new Fallo(502, "En $url no responde un nodo colmena/1" . ($r['error'] ? " ({$r['error']})" : " (HTTP {$r['http']})"));
        $d['url'] = $url; $via = 'lan';
    } else {
        $d = hub_directorio(strtoupper($destino)); $via = 'hub';
    }
    $par = strtoupper($d['node_id']);
    $v = vinculo($par);
    if ($v && $v['estado'] === 'activo') return ['par' => $par, 'estado' => 'activo', 'huella' => $v['huella']];
    if ($v && $v['firma_pub'] !== $d['firma_pub'] && $v['estado'] !== 'revocado') throw new Fallo(409, "El nodo $par cambió de clave: revocá el vínculo anterior y verificá la huella");
    guardar_vinculo($d, $v && $v['estado'] === 'entrante' ? 'entrante' : 'saliente', $via);
    asentar('colmena.solicitud_enviada', ['huella' => huella($d['firma_pub']), 'via' => $via, 'url' => $d['url'] ?? null], $par);
    $res = enviar($par, sobre($par, 'vinculo-solicitud', identidad_publica()));
    return ['par' => $par, 'nombre' => $d['nombre'] ?? null, 'producto' => $d['producto'] ?? null, 'huella' => huella($d['firma_pub']),
            'estado' => vinculo($par)['estado'], 'via' => $res['via'], 'aviso' => 'Compará la huella con el administrador del otro nodo antes de que la acepte.'];
}
function aceptar(string $par, string $rol = 'CI'): array {
    $v = vinculo($par) ?? throw new Fallo(404, "No hay solicitud de $par");
    if (!isset(ALCANCES_PIM[$rol])) throw new Fallo(422, 'rol PIM: ' . implode(' | ', array_keys(ALCANCES_PIM)));
    if ($v['estado'] !== 'entrante' && $v['estado'] !== 'activo') throw new Fallo(409, "El vínculo con $par está «{$v['estado']}»");
    q("UPDATE nucleo_vinculo SET estado = 'activo', rol_pim = ? WHERE par = ?", [$rol, $v['par']]);
    $v = vinculo($par); registrar_cliente_par($v);
    asentar('colmena.vinculo_aceptado', ['rol_pim' => $rol, 'huella' => $v['huella']], $v['par']);
    $res = enviar($v['par'], sobre($v['par'], 'vinculo-aceptado', identidad_publica()));
    return ['par' => $v['par'], 'estado' => 'activo', 'rol_pim' => $rol, 'via' => $res['via']];
}
function revocar_local(string $par, string $motivo): void {
    q("UPDATE nucleo_vinculo SET estado = 'revocado' WHERE par = ?", [strtoupper($par)]);
    baja_cliente_par($par);
    asentar('colmena.vinculo_revocado', ['motivo' => $motivo], strtoupper($par));
}
function revocar(string $par): array {
    $v = vinculo($par) ?? throw new Fallo(404, "No hay vínculo con $par");
    $s = sobre($v['par'], 'vinculo-revocado', ['motivo' => 'revocado por el administrador']);
    revocar_local($v['par'], 'revocado por el administrador de este nodo');
    try { $r = enviar($v['par'], $s); } catch (\Throwable $e) { $r = ['via' => 'ninguna', 'error' => $e->getMessage()]; }
    return ['par' => $v['par'], 'estado' => 'revocado', 'aviso' => $r['error'] ?? null];
}
/** Cambia el rol PIM que este nodo le da al otro (p. ej. CI → SO para que pueda pedir trabajos). */
function rol_par(string $par, string $rol): array {
    $v = vinculo($par) ?? throw new Fallo(404, "No hay vínculo con $par");
    if (!isset(ALCANCES_PIM[$rol])) throw new Fallo(422, 'rol PIM: ' . implode(' | ', array_keys(ALCANCES_PIM)));
    q('UPDATE nucleo_vinculo SET rol_pim = ? WHERE par = ?', [$rol, $v['par']]);
    if ($v['estado'] === 'activo') registrar_cliente_par(vinculo($par));
    asentar('colmena.rol', ['rol_pim' => $rol], $v['par']);
    return ['par' => $v['par'], 'rol_pim' => $rol];
}

// ---------------------------------------------------------------- transporte
/** Entrega un sobre: directo por la intranet si el otro nodo tiene URL y responde; si no, por el hub. */
function enviar(string $par, array $s): array {
    $v = vinculo($par);
    $hub = cfg()['hub'];
    $err = null;
    if ($v && $v['url'] && $v['via'] !== 'hub') {
        $r = http('POST', $v['url'] . '/api/colmena/sobre', json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ['Content-Type' => 'application/json'], 35, false);
        if ($r['http'] >= 200 && $r['http'] < 300) {
            $j = json_decode($r['cuerpo'], true) ?? [];
            if (!empty($j['sobre'])) recibir_sobre($j['sobre'], 'lan');
            return ['via' => 'lan', 'respuesta' => $j['sobre']['tipo'] ?? null];
        }
        $err = $r['error'] ?: "HTTP {$r['http']}: " . texto_corto(json_decode($r['cuerpo'], true)['error'] ?? $r['cuerpo'], 200);
        if ($r['http'] >= 400 && $r['http'] < 500) throw new Fallo(502, "El nodo $par rechazó el sobre: $err");
    }
    if (!empty($hub['activo']) && $hub['url']) { hub_enviar($s); return ['via' => 'hub']; }
    throw new Fallo(502, "No se pudo entregar al nodo $par" . ($err ? " por la intranet ($err)" : '') . ' y no hay hub de relevo configurado');
}
/** RPC sobre la API PIM del otro nodo. Por la intranet responde en el acto; por el hub queda «enviado» hasta que llega la respuesta. */
function rpc(string $par, string $metodo, string $ruta, ?array $cuerpo = null, ?string $if_match = null, int $esperar = 0): array {
    $v = vinculo($par) ?? throw new Fallo(404, "No hay vínculo con $par");
    if ($v['estado'] !== 'activo') throw new Fallo(409, "El vínculo con {$v['par']} está «{$v['estado']}»");
    $metodo = strtoupper($metodo);
    if (!in_array($metodo, ['GET', 'POST', 'PUT'], true)) throw new Fallo(422, 'Método: GET | POST | PUT');
    $id = id_nuevo(); $yo = strtolower(yo());
    $as = asercion('colmena', "colmena-$yo", "colmena-$yo", 'http://nodo-' . strtolower($v['par']) . '.colmena/pim/auth/token');
    q('INSERT INTO nucleo_rpc (id, par, metodo, ruta, via, estado, creado) VALUES (?,?,?,?,?,?,?)', [$id, $v['par'], $metodo, mb_substr($ruta, 0, 500), 'auto', 'enviado', ahora()]);
    $r = enviar($v['par'], sobre($v['par'], 'rpc', ['rpc' => $id, 'metodo' => $metodo, 'ruta' => $ruta, 'cuerpo' => $cuerpo, 'if_match' => $if_match, 'asercion' => $as], $v['caja_pub']));
    q('UPDATE nucleo_rpc SET via = ? WHERE id = ?', [$r['via'], $id]);
    $fin = time() + max(0, min(120, $esperar));
    while (($x = rpc_estado($id)) && $x['estado'] === 'enviado' && time() < $fin) { sleep(2); try { hub_buzon(); } catch (\Throwable) {} }
    return rpc_estado($id);
}
function rpc_estado(string $id): ?array {
    $r = fila('SELECT * FROM nucleo_rpc WHERE id = ?', [$id]);
    if (!$r) return null;
    $r['respuesta'] = $r['respuesta'] ? json_decode($r['respuesta'], true) : null;
    return $r;
}
function evento_remoto(string $par, string $tema, array $datos): array {
    $v = vinculo($par) ?? throw new Fallo(404, "No hay vínculo con $par");
    if ($v['estado'] !== 'activo') throw new Fallo(409, 'Vínculo no activo');
    return enviar($v['par'], sobre($v['par'], 'evento', ['tema' => $tema, 'datos' => $datos], $v['caja_pub']));
}
function ping_par(string $par): array {
    $v = vinculo($par) ?? throw new Fallo(404, "No hay vínculo con $par");
    $t = microtime(true);
    $r = enviar($v['par'], sobre($v['par'], 'ping', ['hora' => date('c')], $v['caja_pub']));
    return ['par' => $v['par'], 'via' => $r['via'], 'respuesta' => $r['respuesta'] ?? 'en el hub', 'ms' => (int) ((microtime(true) - $t) * 1000)];
}

// ---------------------------------------------------------------- hub de relevo (Internet)
function hub_pedido(string $r, ?array $cuerpo = null): array {
    $hub = cfg()['hub'];
    if (empty($hub['url'])) throw new Fallo(409, 'No hay hub configurado (colmena hub <url>)');
    $b = $cuerpo === null ? '' : json_encode($cuerpo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ts = (string) time(); $metodo = $cuerpo === null ? 'GET' : 'POST'; $nonce = bin2hex(random_bytes(12));
    $cab = ['X-Colmena-Nodo' => yo(), 'X-Colmena-Ts' => $ts, 'X-Colmena-Nonce' => $nonce, 'X-Colmena-Firma' => firmar("$metodo\n$r\n$ts\n$nonce\n" . hash('sha256', $b)), 'Accept' => 'application/json'];
    if ($cuerpo !== null) $cab['Content-Type'] = 'application/json';
    $url = rtrim($hub['url'], '/') . '/index.php?r=' . rawurlencode($r);
    $x = http($metodo, $url, $cuerpo === null ? null : $b, $cab, 20, !preg_match('#^http://#', $url));
    $j = json_decode($x['cuerpo'], true);
    if ($x['http'] < 200 || $x['http'] >= 300 || !is_array($j)) throw new Fallo(502, 'Hub: ' . ($j['error'] ?? $x['error'] ?? "HTTP {$x['http']}"));
    return $j;
}
function hub_registrar(): array {
    $i = identidad_publica();
    $j = hub_pedido('registrar', ['node_id' => $i['node_id'], 'nombre' => $i['nombre'], 'producto' => $i['producto'], 'firma_pub' => $i['firma_pub'],
                                  'caja_pub' => $i['caja_pub'], 'pim_jwk' => $i['pim_jwk'], 'codigo' => (string) (cfg()['hub']['codigo'] ?? '')]);
    asentar('colmena.hub_registro', ['hub' => cfg()['hub']['url']]);
    return $j;
}
function hub_directorio(string $par): array {
    $j = hub_pedido('directorio&id=' . rawurlencode($par));
    $d = $j['nodo'] ?? throw new Fallo(404, "El nodo $par no está registrado en el hub");
    if (($d['node_id'] ?? '') !== $par) throw new Fallo(502, 'Respuesta del hub inconsistente');
    return $d + ['protocolo' => PROTOCOLO];
}
function hub_enviar(array $s): void { hub_pedido('enviar', ['sobre' => $s]); }
/** Retira el buzón del hub, procesa cada sobre (respondiendo por el hub si corresponde) y confirma su recepción. */
function hub_buzon(): array {
    $j = hub_pedido('buzon');
    $ok = []; $err = [];
    foreach ((array) ($j['sobres'] ?? []) as $x) {
        $s = $x['sobre'] ?? null;
        try {
            if (is_array($s) && ($resp = recibir_sobre($s, 'hub'))) hub_enviar($resp);
        } catch (Fallo $e) { if ($e->http !== 409) $err[] = ($s['id'] ?? '?') . ': ' . $e->getMessage(); }
        catch (\Throwable $e) { $err[] = ($s['id'] ?? '?') . ': ' . $e->getMessage(); }
        $ok[] = $x['nro'];
    }
    if ($ok) hub_pedido('ack', ['nros' => $ok]);
    if ($err) asentar('colmena.hub_errores', ['errores' => array_slice($err, 0, 10)]);
    return ['recibidos' => count($ok), 'errores' => count($err)];
}
function colmena_tick(): string {
    q("UPDATE nucleo_rpc SET estado = 'vencido' WHERE estado = 'enviado' AND creado < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $hub = cfg()['hub'];
    if (empty($hub['activo']) || empty($hub['url'])) return 'sin hub';
    $e = estado('colmena_hub');
    if (empty($e['registrado']) || ($e['url'] ?? '') !== $hub['url']) { hub_registrar(); estado('colmena_hub', ['registrado' => time(), 'url' => $hub['url']]); }
    $r = hub_buzon();
    return "hub: {$r['recibidos']} sobres" . ($r['errores'] ? ", {$r['errores']} con error" : '');
}
