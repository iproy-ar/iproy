<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Malla de la colmena (1.7.0): cada nodo es hub, puente, emisor y receptor.
 *
 *  · HUB PROPIO: cada nodo ofrece en <su dirección>/colmena-hub/ el mismo servicio de buzón que el hub central, pero solo a
 *    los nodos con los que tiene vínculo activo (el vínculo es la autorización; no hay código de registro).
 *  · VARIOS HUBS A LA VEZ: el nodo escucha al mismo tiempo en el hub central y en el hub de cada nodo vinculado que alcanza.
 *  · CAMINOS: hacia cada nodo vinculado se conocen varios caminos —directo, por el hub propio (el otro escucha acá), por el
 *    hub de un tercero, por el hub central, o pidiéndole a un tercero que haga de puente— y se usa el más rápido que anda.
 *    Si uno cae se pasa al siguiente; si no queda ninguno, el envío espera en la cola de salida.
 *  · PUENTE: un nodo reenvía sobres ajenos solo entre dos nodos con los que tiene vínculo activo, y solo por caminos que
 *    conoce de primera mano (directo, su propio buzón o el hub central): un solo puente entre origen y destino, sin vueltas.
 *    El sobre sigue firmado y sellado de extremo a extremo: el puente no puede leerlo ni alterarlo.
 *  · INTERRUPTORES (Colmena › Red): «hub propio» encendido/apagado y modo de red «malla» (todos los caminos),
 *    «central» (solo el hub central: nada entra directo a este equipo) o «aislado» (sin tráfico de colmena; lo que sale espera).
 */
declare(strict_types=1);

namespace Nucleo;

const CENTRAL = '*';
const MALLA_HUB_VERSION = 'nodo-1.0';
const MALLA_OYENTE_S = 60;      // un nodo «escucha» en un hub si pidió su buzón hace menos de esto (el worker vuelve a pedir cada 15 s o menos)
const MALLA_SALTOS = 2;
const MALLA_SONDEO_S = 300;
const MALLA_BUZON_HORAS = 72;
const MALLA_BUZON_MAX = 500;
const MALLA_MODOS = ['malla', 'central', 'aislado'];
/** Demora supuesta (ms) de un camino todavía sin medir: decide el orden hasta que haya mediciones. */
const RUTA_SUPUESTA = ['directo' => 30, 'propio' => 250, 'hub' => 400, 'central' => 900, 'puente' => 1500];

function malla_cfg(): array { return cfg()['malla']; }
function modo_red(): string { $m = (string) (malla_cfg()['modo'] ?? 'malla'); return in_array($m, MALLA_MODOS, true) ? $m : 'malla'; }
function hub_propio_activo(): bool { return !empty(malla_cfg()['hub_propio']) && modo_red() === 'malla'; }
function hub_central(): ?array { $h = cfg()['hub']; return empty($h['activo']) || empty($h['url']) || modo_red() === 'aislado' ? null : ['clave' => CENTRAL, 'url' => rtrim((string) $h['url'], '/')]; }
function mhub(string $clave): ?array { return fila('SELECT * FROM nucleo_malla_hub WHERE clave = ?', [$clave]); }
function malla_pendiente(): void { $e = estado('colmena_malla'); if (empty($e['pendiente'])) estado('colmena_malla', ['pendiente' => 1] + $e); }
function lista_json(?string $j): array { $l = json_decode((string) $j, true); return is_array($l) ? array_values(array_filter($l, 'is_string')) : []; }

/** Direcciones por las que otros nodos pueden encontrar a este (la pública, si hay, y las de la red local). */
function urls_propias(): array {
    $l = [];
    if (($u = (string) (cfg()['url_publica'] ?? '')) !== '') $l[] = rtrim($u, '/');
    $env = @file_get_contents(raiz() . '/data/iproy.env') ?: '';
    $modo = trim((string) (@file_get_contents(raiz() . '/data/run/modo_activo') ?: nodo()['modo']));
    if ($modo === 'lan') {
        $tls = preg_match('/^IPROY_PUERTO_TLS=(\d+)/m', $env, $m) ? $m[1] : '8443';
        foreach (array_slice((array) (leer_json(raiz() . '/data/run/ips.json')['ipv4'] ?? []), 0, 4) as $ip) $l[] = "https://$ip:$tls";
    } else $l[] = 'http://127.0.0.1:' . puerto_local();
    return array_values(array_unique($l));
}
/** Direcciones candidatas de un nodo vinculado: la que respondió la última vez, la del vínculo y las que anunció. */
function urls_de(array $v): array {
    $l = array_merge([(string) ($v['url_ok'] ?? ''), (string) ($v['url'] ?? '')], lista_json($v['urls'] ?? null));
    $l = array_filter(array_map(fn($u) => rtrim($u, '/'), $l), fn($u) => (bool) preg_match('#^https?://[^\s/]+#', $u));
    return array_slice(array_values(array_unique($l)), 0, 6);
}

// ---------------------------------------------------------------- pedidos firmados a un hub (central o de un nodo)
/** Arma un pedido firmado sin enviarlo: [método, url, cuerpo, cabeceras, verificar_tls]. En el hub de un nodo la firma incluye
 *  el identificador del hub: un pedido firmado para un hub no sirve en otro. */
function hub_firmar(string $clave, string $r, ?array $cuerpo = null): array {
    $b = $cuerpo === null ? null : json_encode($cuerpo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $metodo = $cuerpo === null ? 'GET' : 'POST'; $nonce = bin2hex(random_bytes(12));
    if ($clave === CENTRAL) {
        $base = rtrim((string) (cfg()['hub']['url'] ?? ''), '/');
        if ($base === '') throw new Fallo(409, 'No hay hub central configurado', 'sin-transporte');
        $ts = (string) hora_colmena(); $msg = "$metodo\n$r\n$ts\n$nonce\n" . hash('sha256', $b ?? ''); $cab = [];
    } else {
        $h = mhub($clave) ?? throw new Fallo(502, "Hub: no se conoce el hub del nodo $clave", 'sin-transporte');
        $base = $h['url']; $ts = (string) (time() + (int) $h['desfase']);
        $msg = "$metodo\n$r\n$ts\n$nonce\n" . hash('sha256', $b ?? '') . "\n$clave"; $cab = ['X-Colmena-Hub' => $clave];
    }
    $cab += ['X-Colmena-Nodo' => yo(), 'X-Colmena-Ts' => $ts, 'X-Colmena-Nonce' => $nonce, 'X-Colmena-Firma' => firmar($msg), 'Accept' => 'application/json'];
    if ($cuerpo !== null) $cab['Content-Type'] = 'application/json';
    $url = $base . '/index.php?r=' . rawurlencode($r);
    return [$metodo, $url, $b, $cab, $clave === CENTRAL && !preg_match('#^http://#', $url)];
}
/** Interpreta la respuesta de un hub: corrige la hora, anota la salud del hub y devuelve el JSON o lanza un Fallo. */
function hub_respuesta(string $clave, array $x): array {
    $j = json_decode((string) $x['cuerpo'], true);
    $hora = isset($x['cab']['x-colmena-hora']) && ctype_digit((string) $x['cab']['x-colmena-hora']) ? (int) $x['cab']['x-colmena-hora'] : null;
    $bien = $x['http'] >= 200 && $x['http'] < 300 && is_array($j);
    if ($clave === CENTRAL) { if ($hora !== null) reloj_ajustar($hora); }
    else {
        if ($hora !== null && abs($hora - time()) <= RELOJ_TOPE) q('UPDATE nucleo_malla_hub SET desfase = ? WHERE clave = ? AND ABS(desfase - ?) >= 2', [$hora - time(), $clave, $hora - time()]);
        if ($bien) q("UPDATE nucleo_malla_hub SET ok_ts = ?, fallos = 0, error = NULL, estado = 'ok' WHERE clave = ?", [time(), $clave]);
        else {
            $est = $x['http'] === 503 && ($j['activo'] ?? null) === false ? 'apagado' : (in_array($x['http'], [401, 403], true) && !str_contains((string) ($j['error'] ?? ''), 'hora') ? 'rechaza' : null);
            q('UPDATE nucleo_malla_hub SET falla_ts = ?, fallos = fallos + 1, error = ?, estado = COALESCE(?, IF(fallos >= 2, \'caido\', estado)) WHERE clave = ?',
              [time(), mb_substr((string) ($j['error'] ?? $x['error'] ?? "HTTP {$x['http']}"), 0, 200), $est, $clave]);
        }
    }
    if (!$bien) throw new Fallo(502, ($clave === CENTRAL ? 'Hub: ' : "Hub de $clave: ") . ($j['error'] ?? $x['error'] ?? "HTTP {$x['http']}"), $x['http'] >= 400 && $x['http'] < 500 ? 'rechazado' : 'sin-transporte');
    return $j;
}
/** Pedido firmado a un hub. $clave = CENTRAL o el identificador del nodo dueño del hub. */
function hub_llamar(string $clave, string $r, ?array $cuerpo = null, int $timeout = 20): array {
    if ($clave === CENTRAL) return hub_pedido($r, $cuerpo, $timeout);
    [$m, $url, $b, $cab, $tls] = hub_firmar($clave, $r, $cuerpo);
    try { return hub_respuesta($clave, http($m, $url, $b, $cab, $timeout, $tls)); }
    catch (Fallo $e) {
        if (!str_contains($e->getMessage(), 'hora')) throw $e;   // la respuesta trajo la hora del hub y ya se corrigió: se repite una vez
        [$m, $url, $b, $cab, $tls] = hub_firmar($clave, $r, $cuerpo);
        return hub_respuesta($clave, http($m, $url, $b, $cab, $timeout, $tls));
    }
}
/** Varios pedidos HTTP a la vez. $pedidos: clave → [método, url, cuerpo, cabeceras, timeout, verificar_tls]. Devuelve clave → respuesta + ms. */
function http_varios(array $pedidos): array {
    if (!$pedidos) return [];
    $mh = curl_multi_init(); $hs = []; $res = []; $t0 = microtime(true);
    foreach ($pedidos as $k => $p) { $ch = http_ch($p[0], $p[1], $p[2] ?? null, $p[3] ?? [], $p[4] ?? 5, $p[5] ?? false); curl_multi_add_handle($mh, $ch); $hs[spl_object_id($ch)] = [$k, $ch]; }
    do {
        curl_multi_exec($mh, $corriendo);
        while ($i = curl_multi_info_read($mh)) {
            [$k, $ch] = $hs[spl_object_id($i['handle'])];
            $x = http_leer($ch, $i['result'] === CURLE_OK ? (string) curl_multi_getcontent($ch) : false);
            if ($i['result'] !== CURLE_OK) $x['error'] = curl_strerror($i['result']);
            $res[$k] = $x + ['ms' => (int) ((microtime(true) - $t0) * 1000)];
            curl_multi_remove_handle($mh, $ch);
        }
        if ($corriendo && curl_multi_select($mh, 0.5) === -1) usleep(50000);
    } while ($corriendo);
    curl_multi_close($mh);
    return $res;
}

// ---------------------------------------------------------------- caminos hacia cada nodo
/** Anota que un camino anduvo. La demora es un promedio móvil que baja rápido cuando el camino mejora y sube despacio cuando empeora. */
function ruta_ok(string $par, string $camino, ?int $ms = null, bool $eco = false): void {
    q('INSERT INTO nucleo_ruta (par, camino, ms, ok_ts, eco_ts, fallos, usos) VALUES (?,?,?,?,?,0,1)
       ON DUPLICATE KEY UPDATE ms = IF(VALUES(ms) IS NULL, ms, IF(ms IS NULL, VALUES(ms), IF(VALUES(ms) < ms, ROUND(ms * 0.3 + VALUES(ms) * 0.7), ROUND(ms * 0.6 + VALUES(ms) * 0.4)))),
       ok_ts = VALUES(ok_ts), eco_ts = IF(VALUES(eco_ts) > 0, VALUES(eco_ts), eco_ts), fallos = 0, error = NULL, usos = usos + 1',
      [strtoupper($par), $camino, $ms, time(), $eco ? time() : 0]);
}
function ruta_falla(string $par, string $camino, string $error): void {
    q('INSERT INTO nucleo_ruta (par, camino, falla_ts, fallos, error) VALUES (?,?,?,1,?)
       ON DUPLICATE KEY UPDATE falla_ts = VALUES(falla_ts), fallos = fallos + 1, error = VALUES(error)', [strtoupper($par), $camino, time(), mb_substr($error, 0, 200)]);
}
function ruta_en_pausa(?array $r): bool {
    return $r && (int) $r['falla_ts'] > (int) $r['ok_ts'] && time() - (int) $r['falla_ts'] < min(300, 10 * (2 ** min((int) $r['fallos'], 5)));
}
function ruta_tipo(string $camino): string {
    if ($camino === 'directo') return 'directo';
    if ($camino === 'hub:' . CENTRAL) return 'central';
    if ($camino === 'hub:' . yo()) return 'propio';
    return str_starts_with($camino, 'puente:') ? 'puente' : 'hub';
}
function ruta_nombre(string $camino): string {
    $n = substr($camino, (int) strpos($camino, ':') + 1);
    return match (ruta_tipo($camino)) { 'directo' => 'directo', 'central' => 'hub central', 'propio' => 'hub propio', 'puente' => "puente $n", default => "hub de $n" };
}
function oyente_reciente(string $par): bool {
    $o = fila('SELECT visto, espera_hasta FROM nucleo_hub_oyente WHERE nodo = ?', [strtoupper($par)]);
    return $o && (time() - (int) $o['visto'] < MALLA_OYENTE_S || (int) $o['espera_hasta'] >= time() - 5);
}
/** ¿Está escuchando AHORA en este hub? (tiene un pedido de buzón abierto o pidió hace menos de 20 s). Más estricto que oyente_reciente():
 *  decide si un sobre dejado en el buzón se da por entregado o conviene buscarle además otro camino. */
function oyente_ahora(string $par): bool {
    $o = fila('SELECT visto, espera_hasta FROM nucleo_hub_oyente WHERE nodo = ?', [strtoupper($par)]);
    return $o && ((int) $o['espera_hasta'] >= time() - 2 || time() - (int) $o['visto'] < 20);
}
/** Nodos que están escuchando en el hub propio de este nodo. */
function oyentes(): array {
    return array_column(filas('SELECT nodo FROM nucleo_hub_oyente WHERE visto > ? OR espera_hasta >= ? ORDER BY nodo', [time() - MALLA_OYENTE_S, time() - 5]), 'nodo');
}
/** Nodos vinculados a los que este nodo llega de primera mano: directo, porque escuchan en su hub, o por el hub central con eco reciente. */
function alcanzables(): array {
    $a = []; $central = hub_central() !== null;
    foreach (filas("SELECT par, url_ok, via, vence, estado FROM nucleo_vinculo WHERE estado = 'activo'") as $v) {
        if (!vigente($v)) continue;
        if (($v['url_ok'] && $v['via'] !== 'hub' && modo_red() === 'malla') || oyente_reciente($v['par'])) { $a[] = $v['par']; continue; }
        if (!$central) continue;
        $r = fila('SELECT eco_ts, ok_ts, falla_ts FROM nucleo_ruta WHERE par = ? AND camino = ?', [$v['par'], 'hub:' . CENTRAL]);
        if ($r && time() - (int) $r['eco_ts'] < 900 && (int) $r['ok_ts'] >= (int) $r['falla_ts']) $a[] = $v['par'];
    }
    return $a;
}
/** Caminos posibles hacia un nodo, del más rápido al más lento: ['activos' => [...], 'en_pausa' => [...]].
 *  $primera_mano = solo los que este nodo conoce por sí mismo (lo que usa cuando hace de puente). */
function rutas_para(string $par, array $evitar = [], bool $primera_mano = false): array {
    $par = strtoupper($par); $modo = modo_red(); $c = []; $por_puente = [];
    if ($modo === 'aislado') return ['activos' => [], 'en_pausa' => []];
    $v = vinculo($par); $ahora = time();
    $filas = []; foreach (filas('SELECT * FROM nucleo_ruta WHERE par = ?', [$par]) as $r) $filas[$r['camino']] = $r;
    if ($modo === 'malla') {
        // directo: con la dirección que respondió el último sondeo, o con la del vínculo si todavía no falló nunca;
        // no se intenta si el otro nodo avisó que solo usa el hub central
        $d = $filas['directo'] ?? null; $pm = json_decode((string) ($v['malla'] ?? ''), true) ?: [];
        if ($v && $v['via'] !== 'hub' && ($pm['modo'] ?? 'malla') === 'malla' && ($v['url_ok'] || ($v['url'] && !($d && (int) $d['falla_ts'] > (int) $d['ok_ts'])))) $c[] = 'directo';
        if (hub_propio_activo() && oyente_reciente($par)) $c[] = 'hub:' . yo();
        if (!$primera_mano) foreach (filas("SELECT clave, oyentes, alcanza, malla_ts FROM nucleo_malla_hub WHERE estado = 'ok' AND clave <> ?", [$par]) as $h) {
            if (in_array($h['clave'], $evitar, true) || $ahora - (int) $h['malla_ts'] > 180) continue;
            if (in_array($par, lista_json($h['oyentes']), true)) $c[] = 'hub:' . $h['clave'];                                           // el destino escucha en ese hub
            elseif (in_array($par, lista_json($h['alcanza']), true)) { $c[] = 'hub:' . $h['clave']; $por_puente['hub:' . $h['clave']] = true; }   // ese nodo lo reenvía (puente)
        }
    }
    if (!$primera_mano) {   // puentes: el sobre va adentro de un «relevo» hacia el nodo puente, por el camino que haya hasta él (sirve también en modo «central»)
        foreach (filas("SELECT par, malla FROM nucleo_vinculo WHERE estado = 'activo' AND malla IS NOT NULL AND par <> ?", [$par]) as $b) {
            if (in_array($b['par'], $evitar, true) || in_array('hub:' . $b['par'], $c, true)) continue;
            $m = json_decode((string) $b['malla'], true) ?: [];
            if (empty($m['hub']) || $ahora - (int) ($m['ts'] ?? 0) > 2400) continue;   // el anuncio se repite cada 30 min: se le da margen
            if (in_array($par, (array) ($m['alcanza'] ?? []), true) || in_array($par, (array) ($m['oyentes'] ?? []), true)) $c[] = 'puente:' . $b['par'];
        }
        // puentes aprendidos: por ahí llegó hace poco un sobre de ese nodo
        foreach (filas("SELECT camino FROM nucleo_ruta WHERE par = ? AND camino LIKE 'puente:%' AND ok_ts > ?", [$par, $ahora - 1800]) as $r) {
            $b = substr($r['camino'], 7);
            if (in_array($r['camino'], $c, true) || in_array('hub:' . $b, $c, true) || in_array($b, $evitar, true) || !($vb = vinculo($b)) || !vigente($vb)) continue;
            $c[] = $r['camino'];
        }
    }
    if (hub_central() && !in_array(CENTRAL, $evitar, true)) $c[] = 'hub:' . CENTRAL;
    $act = []; $pau = [];
    // un camino con puente todavía sin medir no puede suponerse más rápido que el hub central que ese puente seguramente usa
    $central_ms = (int) ($filas['hub:' . CENTRAL]['ms'] ?? 0);
    foreach ($c as $k) {
        $r = $filas[$k] ?? null;
        if (ruta_en_pausa($r)) $pau[$k] = (int) $r['falla_ts'];
        elseif (!isset($r['ms']) && (ruta_tipo($k) === 'puente' || isset($por_puente[$k]))) $act[$k] = RUTA_SUPUESTA['puente'] + $central_ms;
        // el directo, si anda, va siempre primero: no tiene intermediarios y responde en el acto. El resto, por demora medida
        // (o la supuesta para su tipo mientras no haya medición)
        else $act[$k] = $k === 'directo' ? 0 : max(1, (int) ($r['ms'] ?? RUTA_SUPUESTA[ruta_tipo($k)]));
    }
    asort($act); asort($pau);
    return ['activos' => array_keys($act), 'en_pausa' => array_keys($pau)];
}
/** Entrega por un camino concreto. «firme» = el destino lo recibió o está escuchando ahí (no quedó esperando a que aparezca). */
function entregar_por(string $camino, string $par, array $s): array {
    $par = strtoupper($par); $json = json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($camino === 'directo') {
        $v = vinculo($par); $url = (string) (($v['url_ok'] ?? '') ?: ($v['url'] ?? ''));
        if ($url === '') throw new Fallo(502, "No se conoce la dirección del nodo $par", 'sin-transporte');
        $r = http('POST', $url . '/api/colmena/sobre', $json, ['Content-Type' => 'application/json'], 35, false);
        if ($r['http'] >= 200 && $r['http'] < 300) {
            $j = json_decode($r['cuerpo'], true) ?? [];
            if (!empty($j['sobre'])) recibir_sobre($j['sobre'], 'lan');
            return ['via' => 'lan', 'ruta' => 'directo', 'respuesta' => $j['sobre']['tipo'] ?? null, 'firme' => true];
        }
        $err = $r['error'] ?: "HTTP {$r['http']}: " . texto_corto(json_decode($r['cuerpo'], true)['error'] ?? $r['cuerpo'], 200);
        if ($r['http'] >= 400 && $r['http'] < 500) throw new Fallo(502, "El nodo $par rechazó el sobre: $err", 'rechazado');
        if ($r['http'] === 0) { q('UPDATE nucleo_vinculo SET url_ok = NULL WHERE par = ?', [$par]); malla_pendiente(); }   // no conecta: el próximo sondeo lo vuelve a buscar
        throw new Fallo(502, $err, 'sin-transporte');
    }
    [$t, $h] = explode(':', $camino, 2);
    if ($t === 'hub' && $h === yo()) { hubp_guardar($s, yo(), 0); return ['via' => 'hub', 'ruta' => $camino, 'firme' => oyente_ahora($par)]; }
    if ($t === 'hub') {
        $j = hub_llamar($h, 'enviar', ['sobre' => $s]);
        if ($h !== CENTRAL) $firme = ($j['entrega'] ?? '') !== 'espera';
        // hub central 1.2.0: dice hace cuánto pidió algo el destino (con espera larga, menos de 45 s = está escuchando)
        elseif (array_key_exists('escucha', $j)) $firme = $j['escucha'] !== null && (int) $j['escucha'] < 45;
        else {   // hub central anterior: no lo dice. Se lo da por bueno salvo que el último ping por ahí lleve más de un minuto sin eco
            $r = fila('SELECT ping_id, ping_ts FROM nucleo_ruta WHERE par = ? AND camino = ?', [$par, $camino]);
            $firme = !($r && $r['ping_id'] !== null && ($s['tipo'] ?? '') !== 'ping' && microtime(true) - (float) $r['ping_ts'] > 60);
        }
        return ['via' => 'hub', 'ruta' => $camino, 'firme' => $firme, 'entrega' => $j['entrega'] ?? null];
    }
    if ($t === 'puente') {
        $r = entregar($h, sobre($h, 'relevo', ['sobre' => $s, 'saltos' => 0]), true);
        return ['via' => 'hub', 'ruta' => $camino, 'firme' => !empty($r['firme'])];
    }
    throw new Fallo(500, "Camino desconocido: $camino");
}

// ---------------------------------------------------------------- hub propio: el buzón que este nodo ofrece a sus vinculados
function hubp_guardar(array $s, string $por, int $saltos): string {
    $para = strtoupper((string) $s['para']);
    if ((int) valor('SELECT COUNT(*) FROM nucleo_hub_buzon WHERE para = ?', [$para]) >= MALLA_BUZON_MAX) throw new Fallo(507, "El buzón de $para en este hub está lleno", 'sin-transporte');
    try { q('INSERT INTO nucleo_hub_buzon (id, de, para, por, saltos, recibido, contenido) VALUES (?,?,?,?,?,NOW(),?)',
            [substr((string) $s['id'], 0, 40), substr(strtoupper((string) $s['de']), 0, 12), $para, $por, $saltos, json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]); }
    catch (\PDOException) { return 'repetido'; }
    return 'buzon';
}
/** Forma mínima de un sobre ajeno que se va a guardar o reenviar. */
function sobre_formado(mixed $s): bool {
    if (!is_array($s)) return false;
    foreach (['v', 'id', 'de', 'para', 'ts', 'tipo', 'firma'] as $k) if (!isset($s[$k]) || is_array($s[$k])) return false;
    return (bool) preg_match('/^[A-Z0-9]{2,12}$/', strtoupper((string) $s['de'])) && (bool) preg_match('/^[A-Z0-9]{2,12}$/', strtoupper((string) $s['para']))
        && strlen((string) $s['id']) <= 40 && in_array($s['tipo'], TIPOS_SOBRE, true);
}
/** Este nodo como PUENTE: hace llegar un sobre ajeno a su destino. Reglas: origen y destino con vínculo activo acá, firma del origen
 *  verificada, un sobre se encamina una sola vez y solo por caminos de primera mano. Devuelve cómo quedó:
 *  «buzon» (el destino escucha en este hub), «puente» (reenviado), «espera» (guardado hasta que el destino aparezca) o «repetido». */
function puente_encaminar(array $s, string $por, int $saltos): string {
    if (!sobre_formado($s)) throw new Fallo(422, 'Sobre incompleto', 'rechazado');
    $de = strtoupper((string) $s['de']); $para = strtoupper((string) $s['para']);
    if ($saltos > MALLA_SALTOS) throw new Fallo(508, 'El sobre ya pasó por demasiados puentes', 'rechazado');
    $vo = vinculo($de); $vd = vinculo($para);
    if (!$vd || !vigente($vd)) throw new Fallo(404, 'Este nodo (' . yo() . ") no tiene vínculo activo con $para: no puede hacer de puente", 'rechazado');
    if (!$vo || !vigente($vo)) throw new Fallo(403, 'Este nodo (' . yo() . ") no tiene vínculo activo con $de: no reenvía sus sobres", 'rechazado');
    $t = $s; $f = (string) $t['firma']; unset($t['firma']);
    if (!verificar_firma(canonico($t), $f, $vo['firma_pub'])) throw new Fallo(401, 'Firma del sobre inválida', 'rechazado');
    try { q('INSERT INTO nucleo_puente_visto (id, ts) VALUES (?,?)', [substr((string) $s['id'], 0, 40) . '>' . $para, time()]); }
    catch (\PDOException) { return 'repetido'; }
    if (oyente_ahora($para)) { puente_cuenta('buzon'); return hubp_guardar($s, $por, $saltos); }
    $r = puente_reenviar($s, $de, $para, $por, $saltos);
    if ($r !== null) { puente_cuenta('reenviados'); return $r; }
    hubp_guardar($s, $por, $saltos);   // nadie lo espera todavía: queda en el buzón; el tick lo vuelve a intentar
    puente_cuenta('espera');
    return 'espera';
}
/** Cuenta lo que este nodo hizo como hub y como puente (para la pantalla; no va al diario: serían miles de asientos). */
function puente_cuenta(string $que): void {
    $e = estado('colmena_puente'); $e[$que] = (int) ($e[$que] ?? 0) + 1; $e['ultimo'] = time(); $e['desde'] ??= time();
    estado('colmena_puente', $e);
}
/** Reenvío por los caminos de primera mano de este nodo (sin pasar por el que lo trajo ni por el origen). null = no hubo camino. */
function puente_reenviar(array $s, string $de, string $para, string $por, int $saltos): ?string {
    $rutas = rutas_para($para, [$por, $de], true);
    foreach ($rutas['activos'] as $c) {
        if ($c === 'hub:' . yo()) continue;
        try {
            if ($c === 'directo') {
                $v = vinculo($para); $url = (string) ($v['url_ok'] ?: $v['url']);
                $x = http('POST', $url . '/api/colmena/sobre', json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ['Content-Type' => 'application/json', 'X-Colmena-Puente' => yo()], 35, false);
                if ($x['http'] === 409) return 'puente';   // el destino ya lo tenía
                if ($x['http'] < 200 || $x['http'] >= 300) throw new Fallo(502, $x['error'] ?: "HTTP {$x['http']}", 'sin-transporte');
                ruta_ok($para, 'directo');
                $resp = (json_decode($x['cuerpo'], true) ?? [])['sobre'] ?? null;
                if (is_array($resp) && sobre_formado($resp)) {   // la respuesta vuelve hacia el origen (que puede ser este mismo nodo, si el sobre era suyo y esperaba en su buzón)
                    try { if (strtoupper((string) $resp['para']) === yo()) recibir_sobre($resp, 'lan'); else puente_encaminar($resp, $para, $saltos); } catch (\Throwable) {}
                }
                return 'puente';
            }
            $r = entregar_por($c, $para, sobre($para, 'relevo', ['sobre' => $s, 'saltos' => $saltos + 1]));
            if (!empty($r['firme'])) { ruta_ok($para, $c); return 'puente'; }
            ruta_falla($para, $c, 'el destino no está escuchando en ese hub');
        } catch (\Throwable $e) { ruta_falla($para, $c, $e->getMessage()); }
    }
    return null;
}
/** Tick: vuelve a intentar lo que quedó en el buzón para nodos que no escuchan acá, y limpia lo vencido. */
function puente_repasar(): int {
    q('DELETE FROM nucleo_hub_buzon WHERE recibido < DATE_SUB(NOW(), INTERVAL ' . MALLA_BUZON_HORAS . ' HOUR)');
    if (random_int(1, 20) === 1) { q('DELETE FROM nucleo_hub_nonce WHERE ts < ?', [time() - 900]); q('DELETE FROM nucleo_puente_visto WHERE ts < ?', [time() - 8 * 86400]); q('DELETE FROM nucleo_hub_oyente WHERE visto < ?', [time() - 86400]); }
    if (!hub_propio_activo()) return 0;
    $n = 0;
    foreach (filas('SELECT * FROM nucleo_hub_buzon WHERE (intento IS NULL OR intento < DATE_SUB(NOW(), INTERVAL 30 SECOND)) AND recibido < DATE_SUB(NOW(), INTERVAL 3 SECOND) ORDER BY nro LIMIT 50') as $f) {
        if (oyente_ahora($f['para'])) continue;
        $s = json_decode($f['contenido'], true);
        $vd = vinculo($f['para']);
        if (!is_array($s) || !$vd || !vigente($vd)) { q('DELETE FROM nucleo_hub_buzon WHERE nro = ?', [$f['nro']]); continue; }
        q('UPDATE nucleo_hub_buzon SET intento = NOW() WHERE nro = ?', [$f['nro']]);
        if (puente_reenviar($s, $f['de'], $f['para'], $f['por'], (int) $f['saltos']) !== null) { q('DELETE FROM nucleo_hub_buzon WHERE nro = ?', [$f['nro']]); puente_cuenta('reenviados'); $n++; }
    }
    return $n;
}
/** Un sobre «relevo» que llegó a este nodo: o trae un sobre para mí (lo proceso y respondo por donde vino), o me piden que haga de puente. */
function malla_relevo(string $por, array $d, string $via): void {
    $s = $d['sobre'] ?? null; $saltos = (int) ($d['saltos'] ?? 0);
    if (!sobre_formado($s)) throw new Fallo(422, 'Relevo sin sobre válido');
    $para = strtoupper((string) $s['para']); $de = strtoupper((string) $s['de']);
    if ($para !== yo()) {
        if (!hub_propio_activo()) throw new Fallo(503, 'Este nodo no hace de puente (su hub propio está apagado)');
        puente_encaminar($s, $por, $saltos + 1);
        return;
    }
    if ($de !== $por) ruta_ok($de, 'puente:' . $por);   // aprendido: por ese nodo se llega al origen
    try { $resp = recibir_sobre($s, 'puente'); }
    catch (Fallo $e) { if ($e->http === 409) return; throw $e; }
    if ($resp) entregar($de, $resp, false, $de === $por ? null : 'puente:' . $por);
}

/** Atiende /colmena-hub/index.php?r=… con el mismo protocolo que el hub central. */
function hubp_atender(): never {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    $fin = function (int $http, array $d): never {
        header('X-Colmena-Hora: ' . hora_colmena()); http_response_code($http); $d['hora'] ??= hora_colmena();
        echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit;
    };
    $yo = yo();
    if (!hub_propio_activo()) $fin(503, ['ok' => false, 'servicio' => 'colmena-hub', 'nodo' => $yo, 'activo' => false, 'error' => 'El hub de este nodo está apagado']);
    $r = (string) ($_GET['r'] ?? 'estado'); $partes = explode('&', $r); $op = $partes[0]; $par = [];
    foreach (array_slice($partes, 1) as $x) { [$k, $val] = array_pad(explode('=', $x, 2), 2, ''); $par[$k] = rawurldecode($val); }
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET'; $cuerpo = (string) file_get_contents('php://input');
    if (strlen($cuerpo) > 1024 * 1024 + 4096) $fin(413, ['ok' => false, 'error' => 'Pedido demasiado grande']);
    $J = $cuerpo !== '' ? json_decode($cuerpo, true) : [];
    if (!is_array($J)) $fin(400, ['ok' => false, 'error' => 'JSON inválido']);
    if ($op === 'estado') $fin(200, ['ok' => true, 'servicio' => 'colmena-hub', 'protocolo' => PROTOCOLO, 'version' => MALLA_HUB_VERSION, 'nodo' => $yo, 'activo' => true, 'espera_max' => 25]);

    $nodo = strtoupper((string) ($_SERVER['HTTP_X_COLMENA_NODO'] ?? '')); $ts = (int) ($_SERVER['HTTP_X_COLMENA_TS'] ?? 0);
    $firma = (string) ($_SERVER['HTTP_X_COLMENA_FIRMA'] ?? ''); $nonce = (string) ($_SERVER['HTTP_X_COLMENA_NONCE'] ?? '');
    if (!preg_match('/^[A-Z0-9]{2,12}$/', $nodo) || $firma === '' || !preg_match('/^[a-f0-9]{16,64}$/', $nonce)) $fin(401, ['ok' => false, 'error' => 'Pedido sin firma válida']);
    if (abs(hora_colmena() - $ts) > 300) $fin(401, ['ok' => false, 'error' => 'Pedido con la hora del equipo desfasada (±5 min)']);
    $v = vinculo($nodo);
    if (!$v || !vigente($v)) $fin(403, ['ok' => false, 'error' => "El hub de $yo solo atiende a los nodos con vínculo activo"]);
    $msg = "$metodo\n$r\n$ts\n$nonce\n" . hash('sha256', $cuerpo) . "\n$yo";
    if (strtoupper((string) ($_SERVER['HTTP_X_COLMENA_HUB'] ?? '')) !== $yo || !verificar_firma($msg, $firma, $v['firma_pub'])) $fin(401, ['ok' => false, 'error' => 'Firma inválida o pedido firmado para otro hub']);
    try { q('INSERT INTO nucleo_hub_nonce (h, nodo, ts) VALUES (?,?,?)', [hash('sha256', $firma), $nodo, time()]); }
    catch (\PDOException) { $fin(409, ['ok' => false, 'error' => 'Pedido repetido']); }
    if ((int) valor('SELECT COUNT(*) FROM nucleo_hub_nonce WHERE nodo = ? AND ts > ?', [$nodo, time() - 60]) > 300) $fin(429, ['ok' => false, 'error' => 'Demasiados pedidos: esperá un minuto']);

    try {
        switch ($op) {
            case 'registrar':
                $fin(200, ['ok' => true, 'registrado' => $nodo, 'nuevo' => false]);
            case 'directorio':
                $id = strtoupper((string) ($par['id'] ?? ''));
                if ($id === $yo) { $i = identidad_publica(); $fin(200, ['ok' => true, 'nodo' => array_intersect_key($i, array_flip(['node_id', 'nombre', 'producto', 'firma_pub', 'caja_pub', 'pim_jwk'])) + ['visto' => time()]]); }
                $x = vinculo($id);
                if (!$x || !vigente($x)) $fin(404, ['ok' => false, 'error' => 'Nodo no vinculado con este hub']);
                $o = fila('SELECT visto FROM nucleo_hub_oyente WHERE nodo = ?', [$id]);
                $fin(200, ['ok' => true, 'nodo' => ['node_id' => $x['par'], 'nombre' => $x['nombre'], 'producto' => $x['producto'], 'firma_pub' => $x['firma_pub'], 'caja_pub' => $x['caja_pub'],
                    'pim_jwk' => json_decode((string) $x['pim_jwk'], true), 'visto' => (int) ($o['visto'] ?? 0)]]);
            case 'enviar':
                $s = $J['sobre'] ?? null;
                if (!sobre_formado($s)) $fin(422, ['ok' => false, 'error' => 'Falta el sobre o está incompleto']);
                if (strtoupper((string) $s['de']) !== $nodo) $fin(403, ['ok' => false, 'error' => 'Solo podés enviar sobres a tu nombre']);
                if (strtoupper((string) $s['para']) === $yo) {   // era para el dueño del hub: se atiende como una entrega directa
                    try { $resp = recibir_sobre($s, 'hub'); } catch (Fallo $e) { if ($e->http === 409) $fin(200, ['ok' => true, 'duplicado' => true, 'entrega' => 'directo']); throw $e; }
                    if ($resp) { try { entregar($nodo, $resp, false, 'hub:' . $yo); } catch (\Throwable) {} }
                    $fin(202, ['ok' => true, 'entrega' => 'directo']);
                }
                $e = puente_encaminar($s, $nodo, 0);
                $fin($e === 'repetido' ? 200 : 202, ['ok' => true, 'entrega' => $e] + ($e === 'repetido' ? ['duplicado' => true] : []));
            case 'buzon':
                $pide = max(0, min(25, (int) ($par['espera'] ?? 0)));
                $otros = (int) valor('SELECT COUNT(*) FROM nucleo_hub_oyente WHERE nodo <> ? AND espera_hasta > ?', [$nodo, time()]);
                $espera = $otros >= max(1, (int) (malla_cfg()['cupo_espera'] ?? 4)) ? 0 : $pide;   // cupo de esperas largas (cada una ocupa un hilo de PHP): el resto consulta cada 3 s
                q('INSERT INTO nucleo_hub_oyente (nodo, visto, espera_hasta) VALUES (?,?,?) ON DUPLICATE KEY UPDATE visto = VALUES(visto), espera_hasta = VALUES(espera_hasta)', [$nodo, time(), $espera ? time() + $espera : 0]);
                if ($espera > 0) @set_time_limit($espera + 20);
                $hasta = microtime(true) + $espera; $n = 0;
                do {
                    $l = filas('SELECT nro, contenido FROM nucleo_hub_buzon WHERE para = ? ORDER BY nro LIMIT 100', [$nodo]);
                    if ($l || microtime(true) >= $hasta) break;
                    if (++$n % 8 === 0 && !hub_propio_activo()) break;   // lo apagaron mientras esperaba
                    usleep(400000);
                } while (true);
                q('UPDATE nucleo_hub_oyente SET visto = ?, espera_hasta = 0 WHERE nodo = ?', [time(), $nodo]);
                $fin(200, ['ok' => true, 'espera' => $espera, 'sobres' => array_map(fn($x) => ['nro' => (int) $x['nro'], 'sobre' => json_decode($x['contenido'], true)], $l),
                           'malla' => ['oyentes' => oyentes(), 'alcanza' => alcanzables()]] + ($espera === 0 && $pide > 0 ? ['reintentar_en' => 3] : []));
            case 'ack':
                $nros = array_slice(array_map('intval', (array) ($J['nros'] ?? [])), 0, 200);
                if ($nros) q('DELETE FROM nucleo_hub_buzon WHERE para = ? AND nro IN (' . implode(',', array_fill(0, count($nros), '?')) . ')', [$nodo, ...$nros]);
                $fin(200, ['ok' => true, 'borrados' => count($nros)]);
        }
    } catch (Fallo $e) { $fin($e->http, ['ok' => false, 'error' => $e->getMessage()]); }
    $fin(404, ['ok' => false, 'error' => "Operación desconocida: $op"]);
}

// ---------------------------------------------------------------- escucha en varios hubs a la vez
/** Procesa lo que devolvió el buzón de un hub: responde por donde vino cada sobre y confirma la recepción. */
function malla_procesar_buzon(string $clave, array $j): array {
    if ($clave !== CENTRAL && isset($j['malla']) && is_array($j['malla'])) {
        $o = json_encode(array_values((array) ($j['malla']['oyentes'] ?? []))); $a = json_encode(array_values((array) ($j['malla']['alcanza'] ?? [])));
        $h = mhub($clave);
        if ($h && ($h['oyentes'] !== $o || $h['alcanza'] !== $a)) malla_medir_pronto();   // por ese hub ahora se llega a otros nodos: se miden esos caminos en el próximo tick
        q('UPDATE nucleo_malla_hub SET oyentes = ?, alcanza = ?, malla_ts = ? WHERE clave = ?', [$o, $a, time(), $clave]);
    }
    $ok = []; $err = [];
    foreach ((array) ($j['sobres'] ?? []) as $x) {
        $s = $x['sobre'] ?? null;
        try {
            if (is_array($s) && ($resp = recibir_sobre($s, 'hub'))) entregar((string) $resp['para'], $resp, false, 'hub:' . $clave);
        } catch (Fallo $e) { if ($e->http !== 409) $err[] = ($s['id'] ?? '?') . ': ' . $e->getMessage(); }
        catch (\Throwable $e) { $err[] = ($s['id'] ?? '?') . ': ' . $e->getMessage(); }
        $ok[] = (int) $x['nro'];
    }
    if ($ok) { try { hub_llamar($clave, 'ack', ['nros' => $ok]); } catch (\Throwable $e) { $err[] = 'ack: ' . $e->getMessage(); } }
    if ($err) asentar('colmena.hub_errores', ['hub' => $clave === CENTRAL ? 'central' : $clave, 'errores' => array_slice($err, 0, 10)]);
    return ['recibidos' => count($ok), 'errores' => count($err)];
}
/** Hubs en los que este nodo escucha: clave → espera máxima. El central solo si está registrado. */
function hubs_de_escucha(?bool &$sin_registro = null, ?array &$sin_datos = null): array {
    $hubs = []; $sin_registro = false; $sin_datos = [];
    if (hub_central()) {
        $e = estado('colmena_hub');
        if (!empty($e['registrado']) && ($e['url'] ?? '') === cfg()['hub']['url']) $hubs[CENTRAL] = (int) (hub_caps()['espera_max'] ?? 0);
        else $sin_registro = true;
    }
    if (modo_red() === 'malla') foreach (filas("SELECT h.clave, h.espera_max, h.malla_ts FROM nucleo_malla_hub h JOIN nucleo_vinculo v ON v.par = h.clave WHERE h.estado = 'ok' AND v.estado = 'activo'") as $h) {
        $hubs[$h['clave']] = (int) $h['espera_max'];
        if (time() - (int) $h['malla_ts'] > 60) $sin_datos[$h['clave']] = true;
    }
    return $hubs;
}
/** Escucha del worker: un pedido abierto con cada hub (espera larga), todos a la vez. Lo que llega se procesa en el acto. */
function malla_escuchar(int $max = 25): array {
    if (modo_red() === 'aislado') { estado('colmena_escucha', ['ultimo' => 0, 'hubs' => [], 'modo' => 'aislado']); return ['modo' => 'aislado']; }
    $todos = hubs_de_escucha($sin_registro, $sin_datos);
    $hubs = array_filter($todos, fn($e) => $e >= 2);   // un hub sin espera larga (central 1.0.0) lo mira el tick cada 15 s
    if (!$hubs) {
        estado('colmena_escucha', ['ultimo' => 0, 'hubs' => []]);
        return $todos ? ['modo' => 'hub-viejo', 'version' => hub_caps()['version'] ?? '?'] : ['modo' => $sin_registro ? 'sin-registro' : 'sin-hub'];
    }
    $nombres = array_map(fn($k) => $k === CENTRAL ? 'central' : $k, array_keys($hubs));
    estado('colmena_escucha', ['ultimo' => time(), 'espera' => $max, 'hubs' => $nombres]);
    $t0 = microtime(true); $fin = $t0 + $max; $mh = curl_multi_init(); $act = []; $prox = []; $rec = 0; $fallas = [];
    $lanzar = function (string $clave) use (&$act, $mh, $hubs, $fin, &$fallas, &$sin_datos): void {
        $esp = max(1, min((int) floor($fin - microtime(true)), $hubs[$clave]));
        if (isset($sin_datos[$clave])) { $esp = 1; unset($sin_datos[$clave]); }   // hub recién alcanzado: un pedido corto trae enseguida a quiénes llega
        try { [$m, $url, $b, $cab, $tls] = hub_firmar($clave, "buzon&espera=$esp", null); }
        catch (\Throwable $e) { $fallas[$clave] = $e->getMessage(); return; }
        $ch = http_ch($m, $url, $b, $cab, $esp + 15, $tls); curl_multi_add_handle($mh, $ch); $act[spl_object_id($ch)] = [$clave, $ch, microtime(true)];
    };
    foreach (array_keys($hubs) as $k) $lanzar((string) $k);
    $modo = modo_red(); $visto = microtime(true); $cortada = false;
    while ($act || $prox) {
        // si el administrador cambió el modo de red (aislar, solo central) la escucha se corta ya: no se vuelve a pedir nada a un hub que dejó de usarse
        if (microtime(true) - $visto >= 1.0) { $visto = microtime(true); if (modo_red() !== $modo) { $cortada = true; break; } }
        if ($act) { curl_multi_exec($mh, $corriendo); if (curl_multi_select($mh, 0.5) === -1) usleep(100000); curl_multi_exec($mh, $corriendo); }
        else usleep(200000);
        while ($i = curl_multi_info_read($mh)) {
            $id = spl_object_id($i['handle']); [$clave, $ch, $ti] = $act[$id]; unset($act[$id]);
            $x = http_leer($ch, $i['result'] === CURLE_OK ? (string) curl_multi_getcontent($ch) : false);
            if ($i['result'] !== CURLE_OK) $x['error'] = curl_strerror($i['result']);
            curl_multi_remove_handle($mh, $ch);
            try {
                $j = hub_respuesta($clave, $x); $p = malla_procesar_buzon($clave, $j); $rec += $p['recibidos'];
                $prox[$clave] = microtime(true) + (isset($j['reintentar_en']) ? max(2, (int) $j['reintentar_en']) : (!$p['recibidos'] && microtime(true) - $ti < 1.0 ? 2 : 0));
            } catch (\Throwable $e) { $fallas[$clave] = $e->getMessage(); }   // ese hub no se reintenta en esta vuelta
        }
        $ahora = microtime(true);
        foreach ($prox as $clave => $t) if ($t <= $ahora) { unset($prox[$clave]); if ($fin - $ahora >= 1.5) $lanzar((string) $clave); }
        if (!$act && (!$prox || min($prox) >= $fin - 1.5 || $ahora >= $fin)) break;
        if ($ahora >= $fin + 20) break;
    }
    foreach ($act as [, $ch]) curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
    estado('colmena_escucha', ['ultimo' => time(), 'espera' => $max, 'hubs' => $nombres, 'recibidos' => $rec, 'fallas' => array_map(fn($m) => mb_substr($m, 0, 160), $fallas)]);
    if (!$cortada && microtime(true) - $t0 < 1.0) sleep(2);   // todos contestaron (o fallaron) al instante: no volverse un bucle de pedidos
    return ['modo' => 'espera', 'espera' => $max, 'hubs' => count($hubs), 'recibidos' => $rec, 'errores' => count($fallas)];
}

// ---------------------------------------------------------------- sondeo, medición y anuncio (los llama el tick)
/** Busca a cada nodo vinculado en sus direcciones conocidas y mira si su hub propio está encendido. */
function malla_sondear(bool $forzar = false): array {
    $e = estado('colmena_malla');
    if (!$forzar && empty($e['pendiente']) && time() - (int) ($e['sondeo'] ?? 0) < MALLA_SONDEO_S) return ['salteado' => true];
    estado('colmena_malla', ['sondeo' => time()] + array_diff_key($e, ['pendiente' => 1]));
    $pares = filas("SELECT par, url, url_ok, urls, firma_pub, via FROM nucleo_vinculo WHERE estado = 'activo'");
    $activos = array_column($pares, 'par');
    if ($activos) { $in = implode(',', array_fill(0, count($activos), '?')); q("DELETE FROM nucleo_malla_hub WHERE clave NOT IN ($in)", $activos); q("DELETE FROM nucleo_ruta WHERE par NOT IN ($in)", $activos); }
    else { q('DELETE FROM nucleo_malla_hub'); q('DELETE FROM nucleo_ruta'); }
    if (modo_red() !== 'malla') return ['modo' => modo_red()];
    $ped = []; $rutas = []; $sond = [];
    foreach (filas("SELECT par, fallos, falla_ts, ok_ts FROM nucleo_ruta WHERE camino = 'directo'") as $r) $rutas[$r['par']] = $r;
    foreach ($pares as $v) {
        // vía «hub» (lo fuerza el administrador): a ese nodo solo por terceros; no se toca su dirección ni se usa su hub propio
        if ($v['via'] === 'hub') { q("DELETE FROM nucleo_ruta WHERE par = ? AND camino = 'directo'", [$v['par']]); q('DELETE FROM nucleo_malla_hub WHERE clave = ?', [$v['par']]); if ($v['url_ok']) q('UPDATE nucleo_vinculo SET url_ok = NULL WHERE par = ?', [$v['par']]); continue; }
        // una dirección que nunca responde (otra red) se prueba cada vez menos: no hace falta golpear cada 5 min una IP ajena
        $r = $rutas[$v['par']] ?? null; $f = (int) ($r['fallos'] ?? 0);
        if (!$forzar && $r && (int) $r['falla_ts'] > (int) $r['ok_ts'] && $f >= 3 && time() - (int) $r['falla_ts'] < ($f >= 10 ? 21600 : 1800)) continue;
        $sond[$v['par']] = true;
        foreach (urls_de($v) as $u) $ped[$v['par'] . '|' . $u] = ['GET', "$u/api/colmena/identidad", null, ['Accept' => 'application/json'], 4, false];
    }
    $res = http_varios($ped); $ok = [];
    foreach ($pares as $v) foreach (urls_de($v) as $u) {
        if (isset($ok[$v['par']])) break;
        $x = $res[$v['par'] . '|' . $u] ?? null; $j = $x ? json_decode($x['cuerpo'], true) : null;
        if ($x && $x['http'] === 200 && strtoupper((string) ($j['node_id'] ?? '')) === $v['par'] && ($j['firma_pub'] ?? '') === $v['firma_pub']) $ok[$v['par']] = [$u, $x['ms'], $j];
    }
    $ped = []; foreach ($ok as $par => [$u]) $ped[$par] = ['GET', "$u/colmena-hub/index.php?r=estado", null, ['Accept' => 'application/json'], 4, false];
    $res = http_varios($ped); $n = ['directos' => 0, 'hubs' => 0];
    foreach ($pares as $v) {
        $par = $v['par'];
        if (!isset($sond[$par])) continue;   // no le tocaba: queda como estaba
        if (!isset($ok[$par])) {
            if (!urls_de($v)) { q("DELETE FROM nucleo_ruta WHERE par = ? AND camino = 'directo'", [$par]); q('DELETE FROM nucleo_malla_hub WHERE clave = ?', [$par]); continue; }
            if ($v['url_ok']) q('UPDATE nucleo_vinculo SET url_ok = NULL WHERE par = ?', [$par]);
            ruta_falla($par, 'directo', 'no responde en ninguna de sus direcciones');
            q("UPDATE nucleo_malla_hub SET estado = 'caido', falla_ts = ?, error = 'el nodo no responde' WHERE clave = ?", [time(), $par]);
            continue;
        }
        [$u, $ms, $j] = $ok[$par]; $n['directos']++;
        $urls = array_values(array_unique(array_merge(lista_json($v['urls']), array_filter((array) ($j['urls'] ?? []), 'is_string'))));
        q('UPDATE nucleo_vinculo SET url_ok = ?, urls = ? WHERE par = ?', [$u, json_encode(array_slice($urls, 0, 6), JSON_UNESCAPED_SLASHES), $par]);
        ruta_ok($par, 'directo', $ms);
        $x = $res[$par] ?? null; $h = $x ? json_decode($x['cuerpo'], true) : null;
        if ($x && $x['http'] === 200 && ($h['servicio'] ?? '') === 'colmena-hub' && strtoupper((string) ($h['nodo'] ?? '')) === $par && !empty($h['activo'])) {
            q("INSERT INTO nucleo_malla_hub (clave, url, estado, version, espera_max, desfase, ms, probado, ok_ts) VALUES (?,?,'ok',?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE url = VALUES(url), estado = 'ok', version = VALUES(version), espera_max = VALUES(espera_max), desfase = VALUES(desfase), ms = VALUES(ms), probado = VALUES(probado), ok_ts = VALUES(ok_ts), fallos = 0, error = NULL",
              [$par, "$u/colmena-hub", mb_substr((string) ($h['version'] ?? '?'), 0, 24), max(0, min(55, (int) ($h['espera_max'] ?? 0))), isset($h['hora']) ? max(-RELOJ_TOPE, min(RELOJ_TOPE, (int) $h['hora'] - time())) : 0, $x['ms'], time(), time()]);
            $n['hubs']++;
        } else {
            $est = $x && $x['http'] === 503 && ($h['activo'] ?? null) === false ? 'apagado' : 'sin-hub';
            q("INSERT INTO nucleo_malla_hub (clave, url, estado, probado) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE url = VALUES(url), estado = VALUES(estado), probado = VALUES(probado), oyentes = NULL, alcanza = NULL",
              [$par, "$u/colmena-hub", $est, time()]);
        }
    }
    return $n;
}
/** Mide cada camino con un ping: el eco (pong) vuelve con el identificador del ping y deja la demora de ida y vuelta. */
function malla_medir(bool $forzar = false): int {
    $e = estado('colmena_malla');
    if (!$forzar && time() - (int) ($e['medicion'] ?? 0) < MALLA_SONDEO_S) return 0;
    estado('colmena_malla', ['medicion' => time()] + $e);
    if (modo_red() === 'aislado') return 0;
    $n = 0; $caidos = [];
    foreach (filas("SELECT * FROM nucleo_vinculo WHERE estado = 'activo'") as $v) {
        if (!vigente($v)) continue;
        $rutas = rutas_para($v['par']);
        foreach (array_slice(array_merge($rutas['activos'], $rutas['en_pausa']), 0, 5) as $c) {   // los que están en pausa también: así se nota cuando vuelven
            if (($c === 'directo' && !$v['url_ok']) || isset($caidos[$c])) continue;
            $id = id_nuevo();
            q('INSERT INTO nucleo_ruta (par, camino, ping_id, ping_ts) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE ping_id = VALUES(ping_id), ping_ts = VALUES(ping_ts)', [$v['par'], $c, $id, microtime(true)]);
            try { $r = entregar_por($c, $v['par'], sobre($v['par'], 'ping', ['hora' => date('c')], $v['caja_pub'], $id)); if (empty($r['firme'])) ruta_falla($v['par'], $c, 'el destino no está escuchando en ese hub'); $n++; }
            catch (\Throwable $x) { ruta_falla($v['par'], $c, $x->getMessage()); if ($c !== 'directo' && !($x instanceof Fallo && $x->codigo === 'rechazado')) $caidos[$c] = true; }   // ese hub no responde: no se insiste con los demás nodos en esta vuelta
        }
    }
    return $n;
}
/** Hay caminos nuevos: la próxima medición se hace en el tick que viene (no más de una vez por minuto). */
function malla_medir_pronto(): void {
    $e = estado('colmena_malla'); $m = (int) ($e['medicion'] ?? 0);
    if ($m > 0 && time() - $m >= 60) estado('colmena_malla', ['medicion' => 0] + $e);
}
/** Llegó algo de un nodo cuyo camino directo estaba dado por caído: está vivo, se lo vuelve a buscar en el próximo tick. */
function malla_vio(string $par): void {
    if (q("UPDATE nucleo_ruta SET fallos = 0 WHERE par = ? AND camino = 'directo' AND falla_ts > ok_ts AND fallos >= 3", [strtoupper($par)])->rowCount() > 0) malla_pendiente();
}
/** Llegó el eco de un ping: demora de ida y vuelta de ese camino. */
function ruta_eco(string $par, string $ping_id): void {
    if ($ping_id === '') return;
    $r = fila('SELECT camino, ping_ts FROM nucleo_ruta WHERE par = ? AND ping_id = ?', [strtoupper($par), $ping_id]);
    if (!$r) return;
    q('UPDATE nucleo_ruta SET ping_id = NULL WHERE par = ? AND camino = ?', [strtoupper($par), $r['camino']]);
    ruta_ok($par, $r['camino'], max(1, min(60000, (int) ((microtime(true) - (float) $r['ping_ts']) * 1000))), true);   // un eco que tardó más de un minuto cuenta como un minuto
}
/** Lo que este nodo le cuenta a sus vinculados: dónde encontrarlo, si su hub está encendido y a quiénes llega. */
function malla_anuncio(): array {
    $hub = hub_propio_activo();
    return ['urls' => modo_red() === 'malla' ? urls_propias() : [], 'hub' => $hub, 'modo' => modo_red(), 'alcanza' => $hub ? alcanzables() : [], 'oyentes' => $hub ? oyentes() : []];
}
function malla_anunciar(bool $forzar = false): int {
    if (modo_red() === 'aislado') return 0;
    $a = malla_anuncio(); $h = hash('sha256', json_encode($a)); $e = estado('colmena_malla'); $t = time() - (int) ($e['anuncio'] ?? 0);
    if (!$forzar && (($h === ($e['anuncio_h'] ?? '') && $t < 1800) || $t < 60)) return 0;
    estado('colmena_malla', ['anuncio' => time(), 'anuncio_h' => $h] + $e);
    $n = 0;
    foreach (filas("SELECT * FROM nucleo_vinculo WHERE estado = 'activo'") as $v) {
        if (!vigente($v)) continue;
        try { entregar($v['par'], sobre($v['par'], 'anuncio', $a, $v['caja_pub']), true); $n++; } catch (\Throwable) {}   // un nodo anterior a la malla no lo entiende: no importa
    }
    return $n;
}
function malla_recibir_anuncio(string $de, array $d): void {
    $v = vinculo($de); if (!$v) return;
    $urls = array_slice(array_values(array_filter((array) ($d['urls'] ?? []), fn($u) => is_string($u) && preg_match('#^https?://[^\s/]+#', $u) && strlen($u) <= 200)), 0, 6);
    $m = ['hub' => !empty($d['hub']), 'modo' => in_array($d['modo'] ?? '', MALLA_MODOS, true) ? $d['modo'] : 'malla', 'ts' => time(),
          'alcanza' => array_slice(array_values(array_filter((array) ($d['alcanza'] ?? []), fn($x) => is_string($x) && preg_match('/^[A-Z0-9]{2,12}$/', $x))), 0, 200),
          'oyentes' => array_slice(array_values(array_filter((array) ($d['oyentes'] ?? []), fn($x) => is_string($x) && preg_match('/^[A-Z0-9]{2,12}$/', $x))), 0, 200)];
    $antes = lista_json($v['urls']); $viejo = json_decode((string) $v['malla'], true) ?: [];
    q('UPDATE nucleo_vinculo SET urls = ?, malla = ? WHERE par = ?', [json_encode($urls, JSON_UNESCAPED_SLASHES), json_encode($m), $v['par']]);
    if ($m['modo'] !== 'malla') q('DELETE FROM nucleo_hub_oyente WHERE nodo = ?', [$v['par']]);   // dejó la malla: ya no escucha en este hub
    if (($viejo['alcanza'] ?? null) != $m['alcanza'] || ($viejo['oyentes'] ?? null) != $m['oyentes']) malla_medir_pronto();
    if ($urls != $antes || ($viejo['hub'] ?? null) !== $m['hub'] || ($viejo['modo'] ?? null) !== $m['modo']) {
        q("UPDATE nucleo_ruta SET fallos = 0, falla_ts = 0 WHERE par = ? AND camino = 'directo'", [$v['par']]);   // cambió algo: se vuelve a probar ya
        malla_pendiente();
    }
}
/** Trabajo de la malla en cada tick. */
function malla_tick(): string {
    if (modo_red() === 'aislado') return 'aislado';
    $s = malla_sondear(); $m = malla_medir(); $p = puente_repasar(); $a = malla_anunciar();
    $t = [];
    if (empty($s['salteado']) && isset($s['directos'])) $t[] = "sondeo: {$s['directos']} directos, {$s['hubs']} hubs";
    if ($m) $t[] = "$m caminos medidos"; if ($p) $t[] = "$p reenviados como puente"; if ($a) $t[] = "anuncio a $a";
    return $t ? ' · malla: ' . implode(', ', $t) : '';
}

// ---------------------------------------------------------------- estado para la pantalla y el diagnóstico
function malla_estado(): array {
    $modo = modo_red(); $esc = estado('colmena_escucha'); $central = cfg()['hub'];
    $hubs = [];
    if (!empty($central['url'])) {
        $caps = estado('colmena_hub_caps'); $reg = estado('colmena_hub');
        $hubs[] = ['clave' => 'central', 'nombre' => 'Hub central', 'url' => $central['url'], 'estado' => empty($central['activo']) ? 'apagado' : ($modo === 'aislado' ? 'sin uso' : (!empty($reg['registrado']) ? 'ok' : 'sin registro')),
                   'version' => $caps['version'] ?? null, 'espera_max' => (int) ($caps['espera_max'] ?? 0), 'escucha' => in_array('central', (array) ($esc['hubs'] ?? []), true) && escucha_activa()];
    }
    foreach (filas('SELECT h.*, v.nombre FROM nucleo_malla_hub h LEFT JOIN nucleo_vinculo v ON v.par = h.clave ORDER BY h.clave') as $h)
        $hubs[] = ['clave' => $h['clave'], 'nombre' => 'Hub de ' . ($h['nombre'] ?: $h['clave']), 'url' => $h['url'], 'estado' => $modo === 'malla' ? $h['estado'] : 'sin uso', 'version' => $h['version'], 'espera_max' => (int) $h['espera_max'],
                   'ms' => $h['ms'] === null ? null : (int) $h['ms'], 'error' => $h['error'], 'escucha' => in_array($h['clave'], (array) ($esc['hubs'] ?? []), true) && escucha_activa() && $h['estado'] === 'ok',
                   'oyentes' => lista_json($h['oyentes']), 'alcanza' => lista_json($h['alcanza'])];
    $caminos = [];
    foreach (filas("SELECT par, nombre, via, url_ok FROM nucleo_vinculo WHERE estado = 'activo' ORDER BY par") as $v) {
        $r = rutas_para($v['par']); $filas = []; foreach (filas('SELECT * FROM nucleo_ruta WHERE par = ?', [$v['par']]) as $x) $filas[$x['camino']] = $x;
        $l = [];
        foreach (array_merge($r['activos'], $r['en_pausa']) as $i => $c) {
            $x = $filas[$c] ?? [];
            $l[] = ['camino' => $c, 'nombre' => ruta_nombre($c), 'tipo' => ruta_tipo($c), 'ms' => isset($x['ms']) ? (int) $x['ms'] : null, 'en_pausa' => in_array($c, $r['en_pausa'], true),
                    'elegido' => $i === 0 && $r['activos'], 'error' => in_array($c, $r['en_pausa'], true) ? ($x['error'] ?? null) : null, 'eco' => (int) ($x['eco_ts'] ?? 0) ?: null];
        }
        $caminos[] = ['par' => $v['par'], 'nombre' => $v['nombre'], 'caminos' => $l, 'en_cola' => cola_cuenta($v['par'])];
    }
    return ['modo' => $modo, 'hub_propio' => ['encendido' => !empty(malla_cfg()['hub_propio']), 'activo' => hub_propio_activo(), 'urls' => array_map(fn($u) => "$u/colmena-hub/", urls_propias()), 'oyentes' => hub_propio_activo() ? oyentes() : [],
                'en_buzon' => (int) valor('SELECT COUNT(*) FROM nucleo_hub_buzon'), 'trabajo' => array_intersect_key(estado('colmena_puente'), ['buzon' => 1, 'reenviados' => 1, 'espera' => 1, 'ultimo' => 1, 'desde' => 1])],
            'hubs' => $hubs, 'caminos' => $caminos, 'escucha' => ['activa' => escucha_activa(), 'hubs' => (array) ($esc['hubs'] ?? []), 'fallas' => (array) ($esc['fallas'] ?? [])]];
}
/** Cambia los interruptores de la malla. Apagar el hub propio o salir de «malla» corta en el acto a los que estaban esperando. */
function malla_configurar(array $c): array {
    $n = [];
    if (array_key_exists('hub_propio', $c)) $n['hub_propio'] = (bool) $c['hub_propio'];
    if (isset($c['modo'])) { if (!in_array($c['modo'], MALLA_MODOS, true)) throw new Fallo(422, 'modo: ' . implode(' | ', MALLA_MODOS)); $n['modo'] = $c['modo']; }
    if (isset($c['cupo_espera'])) $n['cupo_espera'] = max(1, min(64, (int) $c['cupo_espera']));
    if (!$n) return malla_cfg();
    $antes = malla_cfg();
    if (($n['modo'] ?? '') === 'aislado' && modo_red() !== 'aislado') {   // último aviso antes de cortar: los demás dejan de contar con este nodo en el acto
        $a = ['urls' => [], 'hub' => false, 'modo' => 'aislado', 'alcanza' => [], 'oyentes' => []];
        foreach (filas("SELECT * FROM nucleo_vinculo WHERE estado = 'activo'") as $v) { try { if (vigente($v)) entregar($v['par'], sobre($v['par'], 'anuncio', $a, $v['caja_pub']), true); } catch (\Throwable) {} }
    }
    cfg(['malla' => $n]);
    asentar('colmena.malla', ['antes' => array_intersect_key($antes, $n), 'ahora' => $n]);
    publicar('nucleo/colmena/malla', ['modo' => modo_red(), 'hub_propio' => hub_propio_activo()], 'nucleo');
    if (!hub_propio_activo()) q('UPDATE nucleo_hub_oyente SET espera_hasta = 0');
    if (modo_red() !== 'aislado') { try { malla_anunciar(true); } catch (\Throwable) {} }
    malla_pendiente();
    return malla_cfg();
}
