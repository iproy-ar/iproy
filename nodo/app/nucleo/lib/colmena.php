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
 *  Transporte (1.7.0, malla · lib/malla.php): directo (POST <url>/api/colmena/sobre, respuesta en el acto), por el buzón de un HUB
 *  —el central (hosting PHP común: Internet sin abrir puertos ni IP fija) o el hub propio de cualquier nodo vinculado— o pidiéndole
 *  a un nodo vinculado que haga de puente. Se usa el camino más rápido que anda; si no hay ninguno, la cola de salida.
 *  Vínculo: A pide → B lo acepta (después de comparar la huella por teléfono) → ambos registran al otro como cliente PIM
 *  con su clave ES384 y el rol acordado. «El vínculo es la autorización»: sin vínculo activo no hay RPC ni eventos.
 *  RPC: A firma una aserción SMART dirigida a B (aud = http://nodo-<b>.colmena/pim/auth/token) y la manda sellada;
 *  B la canjea en SU Servidor PIM: roles, validaciones, Provenance e historial se aplican igual que a cualquier cliente.
 */
declare(strict_types=1);

namespace Nucleo;

/* 1.7.0 (malla): «relevo» lleva adentro un sobre ajeno que un nodo reenvía como puente; «anuncio» cuenta dónde encontrar al nodo,
 * si su hub propio está encendido y a quiénes llega. Un nodo anterior los rechaza por tipo desconocido, sin otro efecto. */
const TIPOS_SOBRE = ['vinculo-solicitud', 'vinculo-recibido', 'vinculo-aceptado', 'vinculo-revocado', 'rpc', 'rpc-resp', 'evento', 'ping', 'pong', 'relevo', 'anuncio'];
/** Cola de salida (1.6.3): qué se guarda cuando no hay camino, por cuánto tiempo y hasta cuántos por nodo. */
const COLA_TIPOS = ['rpc', 'evento', 'vinculo-aceptado', 'vinculo-revocado'];
const COLA_HORAS = 72;
const COLA_MAX = 200;

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
const TIPOS_VINCULO = ['local', 'institucional', 'central'];
/** Un vínculo autoriza solo si está activo y vigente (docs/vinculos.md: al vencer deja de autorizar). */
function vigente(array $v): bool { return $v['estado'] === 'activo' && (empty($v['vence']) || $v['vence'] >= date('Y-m-d')); }

// ---------------------------------------------------------------- sobres
function sobre(string $para, string $tipo, array $datos, ?string $caja_pub = null, ?string $id = null): array {
    $s = ['v' => 1, 'id' => $id ?? id_nuevo(), 'de' => yo(), 'para' => strtoupper($para), 'ts' => hora_colmena(), 'tipo' => $tipo];
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
    if ((int) $s['ts'] < hora_colmena() - 8 * 86400 || (int) $s['ts'] > hora_colmena() + 600) throw new Fallo(400, 'Sobre vencido o con fecha futura');
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
    if (modo_red() === 'aislado') throw new Fallo(503, 'Este nodo está aislado de la colmena');
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
            q("UPDATE nucleo_vinculo SET estado = 'activo' WHERE par = ?", [$de]); registrar_cliente_par(vinculo($de)); malla_pendiente();
        }
        return sobre($de, 'vinculo-recibido', ['estado' => vinculo($de)['estado']] + identidad_publica());
    }
    if (!$v) throw new Fallo(403, "No hay vínculo con $de");
    $d = abrir_sobre($s, $v['firma_pub']);
    q('UPDATE nucleo_vinculo SET ultimo_contacto = NOW() WHERE par = ?', [$de]);
    malla_vio($de);
    switch ($s['tipo']) {
        case 'vinculo-recibido':
            return null;
        case 'vinculo-aceptado':
            if ($v['estado'] === 'saliente' || $v['estado'] === 'entrante') {
                q("UPDATE nucleo_vinculo SET estado = 'activo', pim_jwk = ?, url = COALESCE(NULLIF(?, ''), url), vence = ?, acta_sha = ? WHERE par = ?",
                  [json_encode($d['pim_jwk'] ?? json_decode($v['pim_jwk'], true)), (string) ($d['url'] ?? ''), ($d['vence'] ?? null) ?: null, ($d['acta_sha'] ?? null) ?: null, $de]);
                if (!empty($d['urls']) && is_array($d['urls'])) q('UPDATE nucleo_vinculo SET urls = ? WHERE par = ?', [json_encode(array_slice(array_values(array_filter($d['urls'], 'is_string')), 0, 6), JSON_UNESCAPED_SLASHES), $de]);
                registrar_cliente_par(vinculo($de)); malla_pendiente();
                asentar('colmena.vinculo_activo', ['via' => $via, 'huella' => $v['huella']], $de);
                publicar('nucleo/colmena/vinculo', ['par' => $de, 'estado' => 'activo'], 'nucleo');
            }
            return null;
        case 'vinculo-revocado':
            revocar_local($de, 'revocado por el otro nodo');
            return null;
        case 'ping':
            return sobre($de, 'pong', ['hora' => date('c'), 'nombre' => nodo()['nombre'], 'ping' => (string) $s['id']], $v['caja_pub']);
        case 'pong':
            ruta_eco($de, (string) ($d['ping'] ?? ''));   // mide el camino por el que salió ese ping
            return null;
        case 'relevo':
            if (!vigente($v)) throw new Fallo(403, 'Vínculo no activo o vencido');
            malla_relevo($de, $d, $via);
            return null;
        case 'anuncio':
            if (vigente($v)) malla_recibir_anuncio($de, $d);
            return null;
        case 'rpc':
            if (!vigente($v)) {
                // por el hub no hay respuesta HTTP: sin esto, el que pidió se queda en «enviado» hasta vencer (1.6.3)
                if ($via !== 'lan') return sobre($de, 'rpc-resp', ['rpc' => (string) ($d['rpc'] ?? ''), 'http' => 403, 'cab' => [],
                    'cuerpo' => ['error' => 'vinculo-no-activo', 'mensaje' => 'El vínculo con ' . yo() . " está «{$v['estado']}»: este nodo no atiende el pedido"]], $v['caja_pub']);
                throw new Fallo(403, "El vínculo con $de no está activo o venció");
            }
            $r = pim_con_asercion((string) ($d['asercion'] ?? ''), (string) ($d['metodo'] ?? 'GET'), (string) ($d['ruta'] ?? ''), $d['cuerpo'] ?? null, $d['if_match'] ?? null);
            return sobre($de, 'rpc-resp', ['rpc' => (string) ($d['rpc'] ?? ''), 'http' => $r['http'], 'cuerpo' => $r['cuerpo'], 'cab' => $r['cab']], $v['caja_pub']);
        case 'rpc-resp':
            q("UPDATE nucleo_rpc SET estado = 'respondido', http = ?, respuesta = ?, respondido = ? WHERE id = ? AND par = ?",
              [(int) ($d['http'] ?? 0), json_encode(['cuerpo' => $d['cuerpo'] ?? null, 'cab' => $d['cab'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ahora(), (string) ($d['rpc'] ?? ''), $de]);
            return null;
        case 'evento':
            if (!vigente($v)) throw new Fallo(403, 'Vínculo no activo o vencido');
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
       huella($d['firma_pub']), $estado, 'auto']);   // 1.7.0 (malla): el camino se elige midiendo; «hub» queda solo para cuando lo fuerza el administrador
    if (!empty($d['urls']) && is_array($d['urls'])) q('UPDATE nucleo_vinculo SET urls = ? WHERE par = ?', [json_encode(array_slice(array_values(array_filter($d['urls'], 'is_string')), 0, 6), JSON_UNESCAPED_SLASHES), strtoupper($d['node_id'])]);
    if (in_array($d['tipo_vinculo'] ?? '', TIPOS_VINCULO, true)) q('UPDATE nucleo_vinculo SET tipo = ? WHERE par = ?', [$d['tipo_vinculo'], strtoupper($d['node_id'])]);
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
/** Busca la identidad de un nodo por su identificador: primero en el hub central y después en el hub propio de cada nodo vinculado. */
function directorio_malla(string $par): array {
    $err = null;
    if (hub_central()) { try { return hub_directorio($par); } catch (Fallo $e) { $err = $e; } }
    if (modo_red() === 'malla') foreach (filas("SELECT clave FROM nucleo_malla_hub WHERE estado = 'ok' ORDER BY ms") as $h) {
        try {
            $d = hub_llamar($h['clave'], 'directorio&id=' . rawurlencode($par))['nodo'] ?? null;
            if (is_array($d) && ($d['node_id'] ?? '') === $par) return $d + ['protocolo' => PROTOCOLO];
        } catch (Fallo $e) { $err ??= $e; }
    }
    throw $err ?? new Fallo(409, 'Para vincular por identificador hace falta un hub: el central (Colmena › Hub) o el de un nodo ya vinculado que conozca a ' . $par);
}
/** Pide un vínculo: $destino es la URL del otro nodo (intranet) o su identificador (por un hub). */
function vincular(string $destino, string $tipo = 'institucional'): array {
    if (!in_array($tipo, TIPOS_VINCULO, true)) throw new Fallo(422, 'tipo: ' . implode(' | ', TIPOS_VINCULO));
    if (preg_match('#^https?://#', $destino)) {
        $url = rtrim($destino, '/');
        $r = http('GET', "$url/api/colmena/identidad", null, ['Accept' => 'application/json'], 8, false);
        $d = json_decode($r['cuerpo'], true);
        if (($d['protocolo'] ?? '') !== PROTOCOLO) throw new Fallo(502, "En $url no responde un nodo colmena/1" . ($r['error'] ? " ({$r['error']})" : " (HTTP {$r['http']})"));
        $d['url'] = $url; $via = 'lan';
    } else {
        $d = directorio_malla(strtoupper($destino)); $via = 'hub';
    }
    $par = strtoupper($d['node_id']);
    $v = vinculo($par);
    if ($v && $v['estado'] === 'activo') return ['par' => $par, 'estado' => 'activo', 'huella' => $v['huella']];
    if ($v && $v['firma_pub'] !== $d['firma_pub'] && $v['estado'] !== 'revocado') throw new Fallo(409, "El nodo $par cambió de clave: revocá el vínculo anterior y verificá la huella");
    guardar_vinculo($d + ['tipo_vinculo' => $tipo], $v && $v['estado'] === 'entrante' ? 'entrante' : 'saliente', $via);
    asentar('colmena.solicitud_enviada', ['huella' => huella($d['firma_pub']), 'via' => $via, 'url' => $d['url'] ?? null, 'tipo' => $tipo], $par);
    $res = entregar($par, sobre($par, 'vinculo-solicitud', identidad_publica() + ['tipo_vinculo' => $tipo]));
    return ['par' => $par, 'nombre' => $d['nombre'] ?? null, 'producto' => $d['producto'] ?? null, 'huella' => huella($d['firma_pub']),
            'estado' => vinculo($par)['estado'], 'via' => $res['via'], 'aviso' => 'Compará la huella con el administrador del otro nodo antes de que la acepte.'];
}
/** Acepta un vínculo. $vence (AAAA-MM-DD) = vigencia; $acta_sha = sha256 del acta o convenio firmado (Ley 25.506), si existe. */
function aceptar(string $par, string $rol = 'CI', ?string $vence = null, ?string $acta_sha = null): array {
    $v = vinculo($par) ?? throw new Fallo(404, "No hay solicitud de $par");
    if (!isset(ALCANCES_PIM[$rol])) throw new Fallo(422, 'rol PIM: ' . implode(' | ', array_keys(ALCANCES_PIM)));
    if ($v['estado'] !== 'entrante' && $v['estado'] !== 'activo') throw new Fallo(409, "El vínculo con $par está «{$v['estado']}»");
    if ($vence !== null && $vence !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vence) || $vence < date('Y-m-d'))) throw new Fallo(422, 'vence: fecha futura AAAA-MM-DD');
    if ($acta_sha !== null && $acta_sha !== '' && !preg_match('/^[a-f0-9]{64}$/', $acta_sha)) throw new Fallo(422, 'acta_sha: sha256 en hexadecimal (64 caracteres)');
    q("UPDATE nucleo_vinculo SET estado = 'activo', rol_pim = ?, vence = ?, acta_sha = ? WHERE par = ?", [$rol, $vence ?: null, $acta_sha ?: null, $v['par']]);
    $v = vinculo($par); registrar_cliente_par($v); malla_pendiente();
    asentar('colmena.vinculo_aceptado', ['rol_pim' => $rol, 'huella' => $v['huella'], 'tipo' => $v['tipo'], 'vence' => $v['vence'], 'acta_sha' => $v['acta_sha']], $v['par']);
    $res = remitir($v['par'], 'vinculo-aceptado', identidad_publica() + ['vence' => $v['vence'], 'acta_sha' => $v['acta_sha']], false);
    return ['par' => $v['par'], 'estado' => 'activo', 'rol_pim' => $rol, 'tipo' => $v['tipo'], 'vence' => $v['vence'], 'via' => $res['via']]
         + ($res['via'] === 'cola' ? ['aviso' => 'No hay camino hasta el otro nodo: el aviso de aceptación quedó en la cola de salida y sale solo cuando vuelva.'] : []);
}
function revocar_local(string $par, string $motivo): void {
    q("UPDATE nucleo_vinculo SET estado = 'revocado', url_ok = NULL, malla = NULL WHERE par = ?", [strtoupper($par)]);
    foreach (['nucleo_ruta' => 'par', 'nucleo_malla_hub' => 'clave', 'nucleo_hub_oyente' => 'nodo', 'nucleo_hub_buzon' => 'para'] as $t => $k) q("DELETE FROM $t WHERE $k = ?", [strtoupper($par)]);   // deja de usarlo y de servirle como hub
    baja_cliente_par($par);
    asentar('colmena.vinculo_revocado', ['motivo' => $motivo], strtoupper($par));
    cola_anular($par, 'El vínculo se revocó antes de poder entregar el pedido');
}
function revocar(string $par): array {
    $v = vinculo($par) ?? throw new Fallo(404, "No hay vínculo con $par");
    revocar_local($v['par'], 'revocado por el administrador de este nodo');
    try { $r = remitir($v['par'], 'vinculo-revocado', ['motivo' => 'revocado por el administrador'], false); }
    catch (\Throwable $e) { $r = ['via' => 'ninguna', 'error' => $e->getMessage()]; }
    return ['par' => $v['par'], 'estado' => 'revocado', 'via' => $r['via'],
            'aviso' => $r['error'] ?? ($r['via'] === 'cola' ? 'El otro nodo no está al alcance: el aviso de revocación quedó en la cola de salida.' : null)];
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
/** Entrega un sobre ya armado por el camino más rápido que ande (lib/malla.php): directo, por el hub propio (si el otro escucha acá),
 *  por el hub de otro nodo, por el hub central o con otro nodo de puente. Si un camino falla se pasa al siguiente.
 *  Si falla, el código del Fallo dice por qué: «rechazado» (el otro nodo contestó que no: reintentar no sirve)
 *  o «sin-transporte» (no hubo camino: se puede reintentar más tarde).
 *  $sin_puente: no pedir puentes (lo usa el propio puente). $primero: camino a probar antes que los demás (por donde llegó el pedido). */
function entregar(string $par, array $s, bool $sin_puente = false, ?string $primero = null): array {
    $par = strtoupper($par);
    if (modo_red() === 'aislado') throw new Fallo(502, 'Este nodo está aislado de la colmena (Colmena › Red): lo que sale espera en la cola hasta que el administrador lo reconecte', 'sin-transporte');
    $r = rutas_para($par);
    $l = $r['activos']; if ($r['en_pausa']) $l[] = $r['en_pausa'][0];   // de los caminos en pausa se prueba uno solo, el que hace más que falló
    if ($primero !== null) $l = array_values(array_unique(array_merge([$primero], $l)));
    if ($sin_puente) $l = array_values(array_filter($l, fn($c) => !str_starts_with($c, 'puente:')));
    $errs = []; $debil = null; $en_buzon_propio = false;
    foreach ($l as $c) {
        try { $x = entregar_por($c, $par, $s); }
        catch (Fallo $e) {
            if ($c === 'directo' && $e->codigo === 'rechazado') throw $e;
            ruta_falla($par, $c, $e->getMessage()); $errs[] = ruta_nombre($c) . ': ' . texto_corto(preg_replace('/^Hub( de [A-Z0-9]+)?: /', '', $e->getMessage()), 140); continue;
        }
        if (!empty($x['firme'])) {
            ruta_ok($par, $c);
            if ($en_buzon_propio) q('DELETE FROM nucleo_hub_buzon WHERE id = ? AND para = ?', [substr((string) $s['id'], 0, 40), $par]);   // ya llegó por otro lado: no hace falta la copia que esperaba acá
            return $x;
        }
        ruta_falla($par, $c, 'el destino no está escuchando en ese hub'); $debil ??= $x;   // quedó en un buzón, pero se sigue buscando un camino mejor
        if ($c === 'hub:' . yo()) $en_buzon_propio = true;
    }
    if ($debil) return $debil;
    throw new Fallo(502, "No se pudo entregar al nodo $par" . ($errs ? ' (' . implode(' · ', $errs) . ')' : ': no hay dirección directa, ni hub de otro nodo, ni hub central configurado'), 'sin-transporte');
}
/** Compatibilidad con módulos escritos antes de la 1.6.3. */
function enviar(string $par, array $s): array { return entregar($par, $s); }

// ---------------------------------------------------------------- cola de salida (1.6.3)
/** Arma el sobre de un envío. La aserción de un RPC se firma acá, en el momento de salir, porque dura 4 minutos. */
function armar(string $par, string $tipo, array $datos, bool $sellado, string $id): array {
    $v = vinculo($par) ?? throw new Fallo(404, "No hay vínculo con $par", 'rechazado');
    if ($tipo === 'rpc') {
        $yo = strtolower(yo());
        $datos['asercion'] = asercion('colmena', "colmena-$yo", "colmena-$yo", 'http://nodo-' . strtolower($v['par']) . '.colmena/pim/auth/token');
    }
    return sobre($v['par'], $tipo, $datos, $sellado ? $v['caja_pub'] : null, $id);
}
/** Envía; si no hay camino y el tipo lo admite, lo deja en la cola de salida y devuelve via = «cola».
 *  Mientras un nodo tenga envíos en cola, los nuevos van detrás: se entregan en el orden en que se pidieron. */
function remitir(string $par, string $tipo, array $datos, bool $sellado = true, ?string $rpc = null): array {
    $par = strtoupper($par); $id = id_nuevo();
    $encolable = in_array($tipo, COLA_TIPOS, true);
    if ($encolable && cola_cuenta($par) > 0) {
        cola_drenar($par, true);
        if (cola_cuenta($par) > 0) return cola_guardar($id, $par, $tipo, $datos, $sellado, $rpc, 'detrás de envíos anteriores en cola');
    }
    try { return entregar($par, armar($par, $tipo, $datos, $sellado, $id)); }
    catch (Fallo $e) {
        if (!$encolable || $e->codigo !== 'sin-transporte') throw $e;
        return cola_guardar($id, $par, $tipo, $datos, $sellado, $rpc, $e->getMessage());
    }
}
function cola_cuenta(?string $par = null): int {
    return (int) (fila('SELECT COUNT(*) n FROM nucleo_salida' . ($par ? ' WHERE par = ?' : ''), $par ? [strtoupper($par)] : [])['n'] ?? 0);
}
function cola_guardar(string $id, string $par, string $tipo, array $datos, bool $sellado, ?string $rpc, string $motivo): array {
    if (cola_cuenta($par) >= COLA_MAX) throw new Fallo(503, "La cola de salida hacia $par está llena (" . COLA_MAX . ' envíos sin entregar): revisá la conexión antes de seguir', 'cola-llena');
    unset($datos['asercion']);
    q('INSERT INTO nucleo_salida (id, par, tipo, sellado, datos, rpc, intentos, ultimo_error, creado, proximo, vence) VALUES (?,?,?,?,?,?,0,?,?,NOW(),DATE_ADD(NOW(), INTERVAL ' . COLA_HORAS . ' HOUR))',
      [$id, $par, $tipo, $sellado ? 1 : 0, json_encode($datos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $rpc, mb_substr($motivo, 0, 300), ahora()]);
    if ($rpc) q("UPDATE nucleo_rpc SET estado = 'en-cola', via = 'cola' WHERE id = ?", [$rpc]);
    asentar('colmena.cola_alta', ['id' => $id, 'tipo' => $tipo, 'rpc' => $rpc, 'motivo' => texto_corto($motivo, 160)], $par);
    publicar('nucleo/colmena/cola', ['par' => $par, 'pendientes' => cola_cuenta($par)], 'nucleo');
    return ['via' => 'cola', 'cola' => $id, 'vence_en_horas' => COLA_HORAS];
}
/** Saca un envío de la cola sin entregarlo y, si era un RPC, lo cierra con el motivo. */
function cola_cerrar(array $f, string $estado, string $motivo, string $asiento): void {
    q('DELETE FROM nucleo_salida WHERE id = ?', [$f['id']]);
    if ($f['rpc']) q("UPDATE nucleo_rpc SET estado = ?, respuesta = ?, respondido = ? WHERE id = ? AND estado = 'en-cola'",
        [$estado, json_encode(['cuerpo' => ['error' => $estado, 'mensaje' => $motivo], 'cab' => []], JSON_UNESCAPED_UNICODE), ahora(), $f['rpc']]);
    asentar($asiento, ['id' => $f['id'], 'tipo' => $f['tipo'], 'rpc' => $f['rpc'], 'intentos' => (int) $f['intentos'], 'motivo' => texto_corto($motivo, 160)], $f['par']);
}
/** Al revocarse un vínculo, lo que esperaba salir hacia ese nodo ya no corresponde (salvo el propio aviso de revocación). */
function cola_anular(string $par, string $motivo): void {
    foreach (filas("SELECT * FROM nucleo_salida WHERE par = ? AND tipo <> 'vinculo-revocado'", [strtoupper($par)]) as $f) cola_cerrar($f, 'rechazado', $motivo, 'colmena.cola_anulado');
}
function cola_lista(): array {
    return filas('SELECT id, par, tipo, rpc, intentos, ultimo_error, creado, proximo, vence FROM nucleo_salida ORDER BY creado, id LIMIT 500');
}
function cola_descartar(string $id): array {
    $f = fila('SELECT * FROM nucleo_salida WHERE id = ?', [$id]) ?? throw new Fallo(404, 'Ese envío no está en la cola de salida');
    cola_cerrar($f, 'rechazado', 'Descartado por el administrador de este nodo', 'colmena.cola_descartado');
    return ['descartado' => $f['id'], 'par' => $f['par'], 'tipo' => $f['tipo']];
}
/** Reintenta lo que está en cola, en orden por nodo. Si un nodo no responde, lo suyo espera (cada vez un poco más,
 *  hasta 5 minutos entre intentos) y se sigue con los demás. $ya = true ignora la espera (reintento a pedido). */
function cola_drenar(?string $par = null, bool $ya = false, float $presupuesto = 40.0): array {
    $t0 = microtime(true); $res = ['entregados' => 0, 'vencidos' => 0, 'rechazados' => 0, 'pendientes' => 0];
    foreach (filas('SELECT * FROM nucleo_salida WHERE vence < NOW()' . ($par ? ' AND par = ?' : ''), $par ? [strtoupper($par)] : []) as $f) {
        cola_cerrar($f, 'vencido', 'Pasaron ' . COLA_HORAS . ' horas sin poder entregarlo', 'colmena.cola_vencido'); $res['vencidos']++;
    }
    $saltear = [];
    if (modo_red() === 'aislado') { $res['pendientes'] = cola_cuenta($par); return $res; }   // aislado: nada sale y la espera no cuenta como intento fallido
    foreach (filas('SELECT * FROM nucleo_salida' . ($par ? ' WHERE par = ?' : '') . ' ORDER BY creado, id LIMIT 500', $par ? [strtoupper($par)] : []) as $f) {
        if (isset($saltear[$f['par']])) continue;
        if (microtime(true) - $t0 > $presupuesto) break;
        if (!$ya && strtotime($f['proximo']) > time()) { $saltear[$f['par']] = 1; continue; }   // se respeta el orden: lo de atrás espera
        $v = vinculo($f['par']);
        if (!$v || (in_array($f['tipo'], ['rpc', 'evento'], true) && !vigente($v))) {
            cola_cerrar($f, 'rechazado', 'El vínculo ya no está activo', 'colmena.cola_anulado'); $res['rechazados']++; continue;
        }
        try {
            $r = entregar($f['par'], armar($f['par'], $f['tipo'], json_decode($f['datos'], true) ?? [], (bool) $f['sellado'], $f['id']));
            q('DELETE FROM nucleo_salida WHERE id = ?', [$f['id']]);
            if ($f['rpc']) q("UPDATE nucleo_rpc SET via = ?, enviado = ?, estado = IF(estado = 'en-cola', 'enviado', estado) WHERE id = ?", [$r['via'], ahora(), $f['rpc']]);
            asentar('colmena.cola_entregado', ['id' => $f['id'], 'tipo' => $f['tipo'], 'rpc' => $f['rpc'], 'via' => $r['via'], 'intentos' => $f['intentos'] + 1,
                                               'espera_s' => max(0, time() - strtotime($f['creado']))], $f['par']);
            $res['entregados']++;
        } catch (\Throwable $e) {
            if ($e instanceof Fallo && $e->codigo === 'rechazado' && str_contains($e->getMessage(), 'Sobre repetido')) {
                // un intento anterior había llegado y se perdió la confirmación: el otro nodo ya lo tiene, no se entrega dos veces
                q('DELETE FROM nucleo_salida WHERE id = ?', [$f['id']]);
                if ($f['rpc']) q("UPDATE nucleo_rpc SET enviado = ?, estado = IF(estado = 'en-cola', 'enviado', estado) WHERE id = ?", [ahora(), $f['rpc']]);
                asentar('colmena.cola_entregado', ['id' => $f['id'], 'tipo' => $f['tipo'], 'rpc' => $f['rpc'], 'via' => 'lan', 'intentos' => $f['intentos'] + 1, 'nota' => 'ya recibido en un intento anterior'], $f['par']);
                $res['entregados']++; continue;
            }
            if ($e instanceof Fallo && $e->codigo === 'rechazado') { cola_cerrar($f, 'rechazado', $e->getMessage(), 'colmena.cola_rechazado'); $res['rechazados']++; continue; }
            $n = $f['intentos'] + 1; $espera = min(300, 15 * (2 ** min($n, 5)));
            q('UPDATE nucleo_salida SET intentos = ?, ultimo_error = ?, proximo = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?', [$n, mb_substr($e->getMessage(), 0, 300), $espera, $f['id']]);
            $saltear[$f['par']] = 1;
        }
    }
    $res['pendientes'] = cola_cuenta($par);
    if ($res['entregados'] || $res['vencidos'] || $res['rechazados']) publicar('nucleo/colmena/cola', ['pendientes' => cola_cuenta()] + $res, 'nucleo');
    return $res;
}

/** RPC sobre la API PIM del otro nodo. Por la intranet responde en el acto; por el hub queda «enviado» hasta que llega la respuesta.
 *  Si no hay camino (el otro nodo apagado, el hub caído, este equipo sin red) queda «en-cola» y sale solo cuando el camino vuelve. */
function rpc(string $par, string $metodo, string $ruta, ?array $cuerpo = null, ?string $if_match = null, int $esperar = 0): array {
    $v = vinculo($par) ?? throw new Fallo(404, "No hay vínculo con $par");
    if (!vigente($v)) throw new Fallo(409, "El vínculo con {$v['par']} está «{$v['estado']}»" . (!empty($v['vence']) && $v['vence'] < date('Y-m-d') ? " y venció el {$v['vence']}" : ''));
    $metodo = strtoupper($metodo);
    if (!in_array($metodo, ['GET', 'POST', 'PUT'], true)) throw new Fallo(422, 'Método: GET | POST | PUT');
    $id = id_nuevo(); $yo = strtolower(yo());
    q('INSERT INTO nucleo_rpc (id, par, metodo, ruta, via, estado, creado) VALUES (?,?,?,?,?,?,?)', [$id, $v['par'], $metodo, mb_substr($ruta, 0, 500), 'auto', 'enviado', ahora()]);
    try { $r = remitir($v['par'], 'rpc', ['rpc' => $id, 'metodo' => $metodo, 'ruta' => $ruta, 'cuerpo' => $cuerpo, 'if_match' => $if_match], true, $id); }
    catch (\Throwable $e) { q('DELETE FROM nucleo_rpc WHERE id = ? AND estado = ?', [$id, 'enviado']); throw $e; }   // no salió ni quedó en cola: no deja un «enviado» huérfano
    if ($r['via'] === 'cola') return rpc_estado($id);
    q('UPDATE nucleo_rpc SET via = ?, enviado = ? WHERE id = ?', [$r['via'], ahora(), $id]);
    $fin = time() + max(0, min(120, $esperar));
    // con la escucha del worker activa (espera larga) la respuesta la trae ella; si no, se mira el buzón cada 2 s
    while (($x = rpc_estado($id)) && $x['estado'] === 'enviado' && time() < $fin) { if (escucha_central() || !hub_central()) { usleep(300000); continue; } sleep(2); try { hub_buzon(); } catch (\Throwable) {} }
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
    if (!vigente($v)) throw new Fallo(409, 'Vínculo no activo o vencido');
    return remitir($v['par'], 'evento', ['tema' => $tema, 'datos' => $datos]);
}
function ping_par(string $par): array {
    $v = vinculo($par) ?? throw new Fallo(404, "No hay vínculo con $par");
    $t = microtime(true);
    $r = entregar($v['par'], sobre($v['par'], 'ping', ['hora' => date('c')], $v['caja_pub']));
    return ['par' => $v['par'], 'via' => $r['via'], 'ruta' => ruta_nombre($r['ruta'] ?? 'directo'), 'respuesta' => $r['respuesta'] ?? 'en el hub', 'ms' => (int) ((microtime(true) - $t) * 1000)];
}

// ---------------------------------------------------------------- reloj de la colmena (corregido contra el hub)
/* Los pedidos al hub y los sobres llevan la hora del equipo y se rechazan con más de 5 min (hub) o 10 min (sobre) de diferencia.
 * Una PC con el reloj corrido (por ejemplo, una PC de taller) quedaba afuera. El nodo mide su diferencia con la hora que devuelve el hub
 * y firma con la hora corregida. La corrección tiene un tope (RELOJ_TOPE): más allá, el reloj se arregla a mano y el diagnóstico lo dice.
 * Seguridad: un hub que mienta con la hora solo puede demorar la entrega (como ya podía); los nonces de un solo uso evitan repeticiones. */
const RELOJ_TOPE = 900;
function reloj(): array { return estado('colmena_reloj'); }
function reloj_desfase(): int { $d = (int) (reloj()['desfase'] ?? 0); return abs($d) <= RELOJ_TOPE ? $d : 0; }
function hora_colmena(): int { return time() + reloj_desfase(); }
function reloj_ajustar(int $hora_hub): void {
    $d = $hora_hub - time(); $r = reloj();
    if (isset($r['desfase']) && abs($d - (int) $r['desfase']) < 2 && time() - (int) ($r['medido'] ?? 0) < 600) return;   // sin cambios: no escribe en cada pedido
    estado('colmena_reloj', ['desfase' => $d, 'medido' => time(), 'fuera_de_tope' => abs($d) > RELOJ_TOPE]);
}
/** Mide la hora del hub con el pedido público «estado» (sirve también con un hub 1.0.0). Devuelve el estado del hub o null. */
function hub_estado(): ?array {
    $hub = cfg()['hub']; if (empty($hub['url'])) return null;
    $x = http('GET', rtrim($hub['url'], '/') . '/index.php?r=estado', null, ['Accept' => 'application/json'], 10, !preg_match('#^http://#', $hub['url']));
    $j = json_decode($x['cuerpo'], true);
    if ($x['http'] !== 200 || !is_array($j) || ($j['servicio'] ?? '') !== 'colmena-hub') return null;
    if (isset($j['hora'])) reloj_ajustar((int) $j['hora']);
    $caps = ['url' => $hub['url'], 'version' => (string) ($j['version'] ?? '?'), 'espera_max' => (int) ($j['espera_max'] ?? 0), 'medido' => time()];
    estado('colmena_hub_caps', $caps);
    return $j + ['caps' => $caps];
}
/** Lo que sabe hacer el hub (versión, espera larga). Se vuelve a preguntar cada 6 h o si cambió la dirección. */
function hub_caps(): array {
    $c = estado('colmena_hub_caps'); $url = (string) (cfg()['hub']['url'] ?? '');
    if (($c['url'] ?? '') !== $url || time() - (int) ($c['medido'] ?? 0) > 21600) { hub_estado(); $c = estado('colmena_hub_caps'); }
    return $c;
}

// ---------------------------------------------------------------- hub de relevo (Internet)
function hub_pedido(string $r, ?array $cuerpo = null, int $timeout = 20, bool $reintentar = true): array {
    $hub = cfg()['hub'];
    if (empty($hub['url'])) throw new Fallo(409, 'No hay hub configurado (colmena hub <url>)');
    $b = $cuerpo === null ? '' : json_encode($cuerpo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ts = (string) hora_colmena(); $metodo = $cuerpo === null ? 'GET' : 'POST'; $nonce = bin2hex(random_bytes(12));
    $cab = ['X-Colmena-Nodo' => yo(), 'X-Colmena-Ts' => $ts, 'X-Colmena-Nonce' => $nonce, 'X-Colmena-Firma' => firmar("$metodo\n$r\n$ts\n$nonce\n" . hash('sha256', $b)), 'Accept' => 'application/json'];
    if ($cuerpo !== null) $cab['Content-Type'] = 'application/json';
    $url = rtrim($hub['url'], '/') . '/index.php?r=' . rawurlencode($r);
    $x = http($metodo, $url, $cuerpo === null ? null : $b, $cab, $timeout, !preg_match('#^http://#', $url));
    $j = json_decode($x['cuerpo'], true);
    if (isset($x['cab']['x-colmena-hora']) && ctype_digit($x['cab']['x-colmena-hora'])) reloj_ajustar((int) $x['cab']['x-colmena-hora']);
    // rechazo por hora: se mide la hora del hub y se repite una sola vez con la hora corregida
    if ($x['http'] === 401 && $reintentar && str_contains((string) ($j['error'] ?? ''), 'hora') && hub_estado() !== null) return hub_pedido($r, $cuerpo, $timeout, false);
    if ($x['http'] < 200 || $x['http'] >= 300 || !is_array($j))
        throw new Fallo(502, 'Hub: ' . ($j['error'] ?? $x['error'] ?? "HTTP {$x['http']}"), $x['http'] >= 400 && $x['http'] < 500 ? 'rechazado' : 'sin-transporte');
    return $j;
}
function hub_registrar(): array {
    $i = identidad_publica();
    $j = hub_pedido('registrar', ['node_id' => $i['node_id'], 'nombre' => $i['nombre'], 'producto' => $i['producto'], 'firma_pub' => $i['firma_pub'],
                                  'caja_pub' => $i['caja_pub'], 'pim_jwk' => $i['pim_jwk'], 'codigo' => (string) (cfg()['hub']['codigo'] ?? '')]);
    asentar('colmena.hub_registro', ['hub' => cfg()['hub']['url']]);
    estado('colmena_hub', ['registrado' => time(), 'url' => cfg()['hub']['url']]);   // la escucha y el tick saben que ya está registrado
    return $j;
}
function hub_directorio(string $par): array {
    $j = hub_pedido('directorio&id=' . rawurlencode($par));
    $d = $j['nodo'] ?? throw new Fallo(404, "El nodo $par no está registrado en el hub");
    if (($d['node_id'] ?? '') !== $par) throw new Fallo(502, 'Respuesta del hub inconsistente');
    return $d + ['protocolo' => PROTOCOLO];
}
function hub_enviar(array $s): void { hub_pedido('enviar', ['sobre' => $s]); }
/** Retira el buzón del hub, procesa cada sobre (respondiendo por el hub si corresponde) y confirma su recepción.
 *  Con $espera > 0 (hub 1.1.0) el pedido queda abierto hasta que llega un sobre o pasan $espera segundos. */
function hub_buzon(int $espera = 0): array {
    return malla_procesar_buzon(CENTRAL, hub_pedido($espera > 0 ? "buzon&espera=$espera" : 'buzon', null, $espera + 20));
}
/** Escucha del worker (como AnyDesk en modo relevo): un pedido saliente que el hub mantiene abierto hasta que hay sobres.
 *  Devuelve el modo: «espera» (entrega en 1-2 s), «hub-viejo» (el hub no espera: el worker mira cada 15 s), «sin-hub», «sin-registro» o «aislado». */
function hub_escuchar(int $max = 25): array { return malla_escuchar($max); }   // 1.7.0 (malla): escucha en el hub central y en el de cada nodo vinculado a la vez
/** ¿La escucha del worker anduvo hace poco? (cada vuelta dura como mucho 25 s y anota al empezar y al terminar) */
function escucha_activa(int $s = 60): bool { return time() - (int) (estado('colmena_escucha')['ultimo'] ?? 0) < $s; }
/** ¿La escucha está atendiendo al hub central? (si no, la respuesta de un pedido hay que ir a buscarla al buzón) */
function escucha_central(): bool { return escucha_activa() && in_array('central', (array) (estado('colmena_escucha')['hubs'] ?? []), true); }

function colmena_tick(): string {
    q("UPDATE nucleo_rpc SET estado = 'vencido' WHERE estado = 'enviado' AND COALESCE(enviado, creado) < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    foreach (filas("SELECT par FROM nucleo_vinculo WHERE estado = 'activo' AND vence IS NOT NULL AND vence < CURDATE()") as $x) {
        q("UPDATE nucleo_vinculo SET estado = 'vencido' WHERE par = ?", [$x['par']]); baja_cliente_par($x['par']);
        asentar('colmena.vinculo_vencido', [], $x['par']);
    }
    $malla = '';   // primero la malla: si apareció un camino nuevo, lo que espera en la cola ya sale por ahí en este mismo tick
    if (modo_red() !== 'aislado') { try { $malla = malla_tick(); } catch (\Throwable $e) { $malla = ' · malla: ' . texto_corto($e->getMessage(), 120); } }
    $cola = '';
    if (cola_cuenta() > 0) {   // después lo pendiente de salir: si el camino volvió, sale antes de pedir el buzón
        $c = cola_drenar();
        $cola = " · cola: {$c['entregados']} entregados, {$c['pendientes']} pendientes" . ($c['vencidos'] + $c['rechazados'] ? ', ' . ($c['vencidos'] + $c['rechazados']) . ' cerrados sin entregar' : '');
    }
    if (modo_red() === 'aislado') return 'aislado: sin tráfico de colmena' . $cola;
    $hub = cfg()['hub'];
    if (empty($hub['activo']) || empty($hub['url'])) return 'sin hub central' . $cola . $malla;
    $e = estado('colmena_hub');
    if (empty($e['registrado']) || ($e['url'] ?? '') !== $hub['url']) { hub_registrar(); estado('colmena_hub', ['registrado' => time(), 'url' => $hub['url']]); }
    // si el worker ya tiene un pedido abierto con el hub central (espera larga), no hace falta mirar el buzón; si se cortó, el tick vuelve a mirar
    if (escucha_activa(40) && in_array('central', (array) (estado('colmena_escucha')['hubs'] ?? []), true)) return 'hub: lo atiende la escucha (espera larga)' . $cola . $malla;
    $r = hub_buzon();
    return "hub: {$r['recibidos']} sobres" . ($r['errores'] ? ", {$r['errores']} con error" : '') . $cola . $malla;
}

// ---------------------------------------------------------------- «Probar camino» (1.7.0): qué anda, qué no y cómo se arregla
const CA_PUBLICAS = ['let\'s encrypt', 'isrg', 'zerossl', 'sectigo', 'comodo', 'digicert', 'globalsign', 'godaddy', 'starfield', 'google trust', 'amazon', 'cloudflare',
    'entrust', 'certum', 'actalis', 'buypass', 'ssl.com', 'rapidssl', 'thawte', 'geotrust', 'microsoft', 'cpanel', 'encryption everywhere', 'harica', 'telia', 'usertrust'];
/** Diagnóstico del camino hacia los otros nodos: modo de red, hub central (proxy, nombre, certificado, hora, registro), escucha,
 *  hub propio, hubs de los otros nodos y, si se pide, un pedido de prueba a cada vínculo con el camino que usó. */
function colmena_diagnostico(bool $vinculos = false): array {
    $l = []; $ag = function (string $k, string $t, string $e, string $d, string $rem = '') use (&$l) { $l[] = ['clave' => $k, 'titulo' => $t, 'estado' => $e, 'detalle' => $d, 'remedio' => $rem]; };
    $modo = modo_red(); $fin = function (string $camino) use (&$l, $modo): array { return ['camino' => $camino, 'modo' => $modo, 'pruebas' => $l, 'hora' => date('c'), 'desfase' => reloj_desfase()]; };
    $ag('modo', 'Red de la colmena', $modo === 'malla' ? 'ok' : ($modo === 'central' ? 'info' : 'aviso'), match ($modo) {
        'malla' => 'Malla: este nodo usa todos los caminos (directo, su hub, el de otros nodos y el hub central) y elige el más rápido.',
        'central' => 'Solo hub central: nada entra directo a este equipo y no usa ni ofrece hubs de nodos.',
        default => 'Aislado: este nodo no manda ni recibe nada de la colmena. Lo que sale espera en la cola.' },
        $modo === 'aislado' ? 'Para volver a conectarlo: Colmena › Red › Malla o Solo hub central.' : '');
    if ($modo === 'aislado') return $fin('aislado');
    $otros = (int) valor("SELECT COUNT(*) FROM nucleo_vinculo WHERE estado = 'activo' AND url_ok IS NOT NULL") + ($modo === 'malla' ? count(oyentes()) : 0);   // nodos a los que se llega sin el hub central
    $hub = cfg()['hub']; $url = rtrim((string) ($hub['url'] ?? ''), '/');
    $px = proxy_salida();
    $central = function () use ($ag, $hub, $url, $px, $otros, $modo): string {
    if ($url === '') $ag('hub', 'Hub central', $otros && $modo === 'malla' ? 'aviso' : 'mal', 'No hay hub central configurado: los nodos de otra red no tienen por dónde llegar' . ($otros && $modo === 'malla' ? ' (los de esta red siguen andando entre ellos).' : '.'), 'En Colmena › Hub poné la dirección del hub (por ejemplo https://iproy.com.ar/colmena-hub/) y el código de registro.');
    else $ag('hub', 'Hub central', empty($hub['activo']) ? 'aviso' : 'ok', $url . (empty($hub['activo']) ? ' · apagado' : ' · activo'), empty($hub['activo']) ? 'Activalo en Colmena › Hub.' : '');
    $ag('proxy', 'Proxy de salida', 'info', $px === '' ? 'Sin proxy: sale directo a Internet.' : 'Por el proxy ' . preg_replace('#//[^@/]+@#', '//•••@', $px) . ($url !== '' && host_privado((string) parse_url($url, PHP_URL_HOST)) ? ' (el hub está en la red local: a ese se llega sin proxy).' : '.'));
    if ($url === '') return 'sin-hub';
    $host = (string) parse_url($url, PHP_URL_HOST); $tls = str_starts_with($url, 'https://');
    $t = microtime(true); $ip = gethostbyname($host); $ms = (int) ((microtime(true) - $t) * 1000);
    if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) $ag('dns', 'Nombre del hub', $px === '' ? 'mal' : 'aviso', "Este equipo no resuelve «{$host}»" . ($px === '' ? '.' : ' (el proxy puede resolverlo por su cuenta).'), $px === '' ? 'Revisá que la PC tenga Internet y DNS (abrí cualquier página en el navegador).' : '');
    else $ag('dns', 'Nombre del hub', 'ok', "{$host} → {$ip} ({$ms} ms)");
    // conexión, certificado y estado del hub (con el mismo proxy y la misma CA que el resto del nodo)
    $ch = curl_init($url . '/index.php?r=estado');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CERTINFO => $tls, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    if (host_privado($host)) curl_setopt($ch, CURLOPT_PROXY, '');   // igual que el resto del nodo: la red local y el propio equipo no van por el proxy
    elseif ($px !== '') curl_setopt($ch, CURLOPT_PROXY, $px);
    foreach (['SSL_CERT_FILE', 'CURL_CA_BUNDLE'] as $e) if (($ca = getenv($e)) && is_file($ca)) { curl_setopt($ch, CURLOPT_CAINFO, $ca); break; }
    $t = microtime(true); $cuerpo = curl_exec($ch); $ms = (int) ((microtime(true) - $t) * 1000); $err = curl_error($ch); $cod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $cert = $tls ? (curl_getinfo($ch, CURLINFO_CERTINFO)[0] ?? []) : [];
    if ($cuerpo === false) {
        $low = strtolower($err);
        [$d, $rem] = match (true) {
            str_contains($low, 'ssl') || str_contains($low, 'certificate') => ['El certificado del hub no es de confianza para este equipo: es lo que pasa cuando un proxy inspecciona HTTPS.', 'Pedí a Informática que no inspeccione ' . $host . ', o cargá la CA del proxy en el archivo que indica SSL_CERT_FILE.'],
            str_contains($low, 'resolve') => ['No se pudo resolver el nombre del hub.', 'Revisá DNS o configurá el proxy de la institución en el panel de Redes.'],
            str_contains($low, '407') || str_contains($low, 'proxy') => ['El proxy rechazó la conexión (puede pedir usuario y clave).', 'Configurá el proxy con usuario en el panel de Redes (http://usuario:clave@proxy:puerto).'],
            default => ['No hay salida HTTPS hacia el hub: ' . $err . '.', 'Si en esta PC el navegador abre páginas, configurá el mismo proxy en el panel de Redes; si no, la red bloquea la salida y hay que pedir a Informática que permita ' . $host . '.'],
        };
        $ag('https', 'Salida HTTPS al hub', 'mal', $d, $rem);
        return 'sin-salida';
    }
    if ($tls) {
        $emisor = (string) ($cert['Issuer'] ?? ''); $conocida = false; foreach (CA_PUBLICAS as $x) if (str_contains(strtolower($emisor), $x)) $conocida = true;
        $o = preg_match('/O\s*=\s*([^,\n]+)/', $emisor, $m) ? trim($m[1]) : ($emisor ?: '?');
        $ag('certificado', 'Certificado del hub', $conocida ? 'ok' : 'aviso', "Lo emite «{$o}»." . ($conocida ? '' : ' No es una autoridad pública conocida: puede haber un proxy que inspecciona HTTPS.'),
            $conocida ? '' : 'Funciona igual (los sobres van firmados y sellados), pero conviene pedir a Informática que no inspeccione ' . $host . '.');
    }
    $j = json_decode((string) $cuerpo, true);
    if ($cod !== 200 || ($j['servicio'] ?? '') !== 'colmena-hub') { $ag('estado', 'Respuesta del hub', 'mal', "HTTP {$cod}: la dirección no responde como un hub de la colmena.", 'Revisá la dirección del hub (termina en /colmena-hub/).'); return 'hub-invalido'; }
    $esp = (int) ($j['espera_max'] ?? 0);
    estado('colmena_hub_caps', ['url' => (string) ($hub['url'] ?? ''), 'version' => (string) ($j['version'] ?? '?'), 'espera_max' => $esp, 'medido' => time()]);   // la escucha usa lo recién medido
    $ag('estado', 'Hub', $esp >= 2 ? 'ok' : 'aviso', "Versión {$j['version']} · respondió en {$ms} ms." . ($esp >= 2 ? " Espera larga: los sobres llegan en 1-2 s." : ' Sin espera larga: los sobres llegan cada 15 s.'),
        $esp >= 2 ? '' : 'Actualizá el hub a la 1.1.0 (archivo hub/index.php) para la entrega en segundos.');
    if (isset($j['hora'])) {
        $d = (int) $j['hora'] - time(); reloj_ajustar((int) $j['hora']); $abs = abs($d);
        $txt = $abs <= 2 ? 'El reloj de este equipo coincide con el del hub.' : 'El reloj de este equipo está ' . ($d > 0 ? 'atrasado' : 'adelantado') . " {$abs} s respecto del hub.";
        $ag('hora', 'Hora', $abs <= 60 ? 'ok' : ($abs <= RELOJ_TOPE ? 'aviso' : 'mal'), $txt . ($abs > 60 && $abs <= RELOJ_TOPE ? ' Se corrige solo: el nodo firma con la hora del hub.' : ''),
            $abs > RELOJ_TOPE ? 'Poné en hora el equipo (Windows: Configuración › Hora › Sincronizar ahora; Linux: timedatectl set-ntp true). Más de 15 min no se corrige solo.' : ($abs > 60 ? 'Conviene sincronizar la hora del equipo igual.' : ''));
    }
    // registro: un pedido firmado que no consume el buzón (el propio registro en el directorio)
    try { hub_pedido('directorio&id=' . rawurlencode(yo())); $ag('registro', 'Registro en el hub', 'ok', 'Este nodo (' . yo() . ') está registrado y sus pedidos firmados se aceptan.'); }
    catch (Fallo $e) { $ag('registro', 'Registro en el hub', 'mal', 'El hub no acepta a este nodo: ' . $e->getMessage(), 'En Colmena › Hub tocá «Registrar» con el código de registro (lo da el administrador del hub).'); }
    if ($esp >= 2) {
        $u = (int) (estado('colmena_escucha')['ultimo'] ?? 0);
        $ag('escucha', 'Escucha (túnel)', escucha_central() ? 'ok' : 'aviso', escucha_central() ? 'El worker mantiene un pedido abierto con el hub: los sobres entran apenas llegan.' : ($u ? 'La escucha del hub central no corre desde ' . date('d/m H:i', $u) . '.' : 'La escucha todavía no corrió.'),
            escucha_central() ? '' : 'Esperá unos segundos y volvé a probar; si sigue igual, reiniciá el nodo (iProy-Control stop y start) para que el worker arranque la escucha.');
    }
    return 'ok';
    };
    $c = $central();
    if ($modo === 'malla') {
        try { malla_sondear(true); } catch (\Throwable) {}
        $m = malla_estado(); $hp = $m['hub_propio'];
        $ag('hub-propio', 'Hub propio de este nodo', $hp['activo'] ? 'ok' : 'info', $hp['activo']
            ? 'Encendido en ' . implode(' y ', $hp['urls']) . ($hp['oyentes'] ? ' · escuchan acá: ' . implode(', ', $hp['oyentes']) . '.' : ' · todavía no escucha ningún nodo.')
            : 'Apagado: este nodo no hace de hub ni de puente para los demás (sigue usando los caminos de los otros).', $hp['activo'] ? '' : 'Se enciende en Colmena › Red.');
        foreach ($m['hubs'] as $h) if ($h['clave'] !== 'central') $ag('hub-' . $h['clave'], $h['nombre'], match ($h['estado']) { 'ok' => 'ok', 'apagado', 'sin-hub' => 'info', default => 'aviso' }, match ($h['estado']) {
            'ok' => 'Se llega' . ($h['ms'] !== null ? " en {$h['ms']} ms" : '') . ($h['escucha'] ? ' y este nodo escucha ahí.' : ' (la escucha todavía no lo tomó).') . ($h['oyentes'] || $h['alcanza'] ? ' Por ese hub se llega a: ' . (implode(', ', array_diff(array_unique(array_merge($h['oyentes'], $h['alcanza'])), [yo()])) ?: 'nadie más por ahora') . '.' : ''),
            'apagado' => 'El otro nodo tiene su hub propio apagado.', 'sin-hub' => 'El otro nodo responde pero no ofrece hub (versión anterior a la malla).',
            'rechaza' => 'El otro nodo no acepta a este en su hub: ' . ($h['error'] ?? '') . '.', default => 'No responde desde esta red' . ($h['error'] ? ' (' . $h['error'] . ')' : '') . '.' });
    }
    if ($vinculos) foreach (array_slice(filas("SELECT par, nombre, url, via FROM nucleo_vinculo WHERE estado = 'activo' ORDER BY par"), 0, 5) as $v) {
        $t = microtime(true);
        try { $r = rpc($v['par'], 'GET', 'metadata', null, null, 25); } catch (\Throwable $e) { $ag('vinculo-' . $v['par'], 'Vínculo con ' . ($v['nombre'] ?: $v['par']), 'mal', $e->getMessage()); continue; }
        $s = round(microtime(true) - $t, 1); $via = $r['via'] === 'hub' ? 'el hub' : ($r['via'] === 'cola' ? 'la cola' : 'la red local');
        $ru = rutas_para($v['par']); $mejor = $ru['activos'] ? ' Camino preferido: ' . ruta_nombre($ru['activos'][0]) . (count($ru['activos']) > 1 ? ' (alternativas: ' . implode(', ', array_map(fn($x) => ruta_nombre($x), array_slice($ru['activos'], 1))) . ').' : '.') : '';
        match ($r['estado']) {
            'respondido' => $ag('vinculo-' . $v['par'], 'Vínculo con ' . ($v['nombre'] ?: $v['par']), 'ok', "Pedido de prueba respondido por {$via} en {$s} s.{$mejor}"),
            'enviado' => $ag('vinculo-' . $v['par'], 'Vínculo con ' . ($v['nombre'] ?: $v['par']), 'aviso', "El pedido de prueba salió por {$via} pero no hubo respuesta en 25 s.", 'Revisá que el otro nodo esté encendido y con su propio «Probar camino» en verde.'),
            default => $ag('vinculo-' . $v['par'], 'Vínculo con ' . ($v['nombre'] ?: $v['par']), 'mal', 'No hay camino: el pedido quedó ' . $r['estado'] . '.', 'Si el otro nodo está en otra red, los dos tienen que tener el hub central activo y registrado, o un nodo en común con su hub propio encendido.'),
        };
    }
    $malos = count(array_filter($l, fn($x) => $x['estado'] === 'mal'));
    if ($c !== 'ok' && !($modo === 'malla' && $otros)) return $fin($c);   // sin hub central y sin otro camino: ese es el problema
    return $fin($malos ? 'con-problemas' : 'ok');
}
