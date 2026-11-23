<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

namespace Modulos\Fuentes;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, publicar, asentar, pdo};

/*
 * Reglas (lista JSON). Cada regla toma una parte de la respuesta y dice qué es cada dato:
 *   objeto   {ruta, activo, ts | ts_raiz | (sin ts = ahora), campos: {clave: punto | {punto, unidad, etiqueta, escala}} | "*", excluir, unidades, etiquetas}
 *   lista    {ruta, activo_de, activo_mapa, sin_mapa: omitir|usar, puntos_ruta, punto, etiqueta, valor, unidad, ts_raiz, punto_mapa, solo_mapa}
 *   filas    {ruta, ts, activo, campos}                       una fila por momento (p. ej. registros de un sensor)
 *   series   {ruta, activo, campos}                           {clave: [[ts, valor], …]}
 *   columnas {ruta_ts, ruta, activo, campos}                  {t: [ts…], k: {clave: [valor…]}}
 *   eventos  {ruta, id, ts, fin, mensaje, categoria, alarma_si, activo}
 * Rutas con puntos: «estado.equipos», «0.valores»; vacía = la respuesta entera. Momentos: epoch en ms o s, o texto de fecha.
 * Activos: el código de inventario PIM (con o sin la sigla del establecimiento); «{x}» se reemplaza por variables de la URL.
 */
const TIPOS = ['objeto', 'lista', 'filas', 'series', 'columnas', 'eventos'];

function obtener(mixed $d, string $ruta): mixed {
    if ($ruta === '') return $d;
    foreach (explode('.', $ruta) as $k) { if (is_array($d) && array_key_exists($k, $d)) $d = $d[$k]; elseif (is_array($d) && ctype_digit($k) && array_key_exists((int) $k, $d)) $d = $d[(int) $k]; else return null; }
    return $d;
}
function momento(mixed $t): ?int {
    if ($t === null || $t === '' || is_bool($t)) return null;
    if (is_int($t) || is_float($t) || (is_string($t) && preg_match('/^\d{9,13}(\.\d+)?$/', $t))) { $n = (float) $t; $n = $n > 1e11 ? $n / 1000 : $n; }
    else $n = strtotime((string) $t) ?: 0;
    return $n > 946684800 && $n <= time() + 300 ? (int) floor($n) : null;
}
/** Código de punto válido para telemetría (A-Z 0-9 -): «v_l1n» → «V-L1N», «ai/1» → «AI-1». */
function punto_id(string $k): string { return mb_substr(trim(preg_replace('/[^A-Z0-9]+/', '-', strtoupper(strtr($k, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']))), '-'), 0, 40); }
function numero(mixed $v): ?float {
    if (is_bool($v)) return $v ? 1.0 : 0.0;
    if (is_int($v) || is_float($v)) return is_finite((float) $v) ? (float) $v : null;
    if (is_string($v) && is_numeric(trim($v))) return (float) trim($v);
    return null;
}
function vars(string $t, array $v, bool $url = false): string { return preg_replace_callback('/\{(\w+)\}/', fn($m) => array_key_exists($m[1], $v) ? ($url ? rawurlencode((string) $v[$m[1]]) : (string) $v[$m[1]]) : $m[0], $t); }
/** Especificación de un campo: «PUNTO» o {punto, unidad, etiqueta, escala}. */
function spec(string $k, mixed $s, array $r): array {
    $s = is_array($s) ? $s : (is_string($s) && $s !== '' ? ['punto' => $s] : []);
    return ['punto' => punto_id((string) ($s['punto'] ?? $k)), 'unidad' => (string) ($s['unidad'] ?? ($r['unidades'][$k] ?? '')), 'etiqueta' => (string) ($s['etiqueta'] ?? ($r['etiquetas'][$k] ?? '')),
            'escala' => is_numeric($s['escala'] ?? null) ? (float) $s['escala'] : (is_numeric($r['escalas'][$k] ?? null) ? (float) $r['escalas'][$k] : 1.0)];
}
/** Campos de un objeto según la regla (mapa explícito o «*» = todos los numéricos). */
function campos(array $o, array $r): array {
    $l = []; $ex = array_flip((array) ($r['excluir'] ?? []));
    if (($r['campos'] ?? '*') === '*') { foreach ($o as $k => $v) if (!isset($ex[$k]) && !is_array($v) && numero($v) !== null && (string) $k !== (string) ($r['ts'] ?? '')) $l[(string) $k] = spec((string) $k, null, $r); }
    else foreach ((array) $r['campos'] as $k => $s) $l[(string) $k] = spec((string) $k, $s, $r);
    return $l;
}
function med(string $activo, array $sp, float $v, int $t): array {
    return ['activo' => $activo, 'punto' => $sp['punto'], 'valor' => round($v * $sp['escala'], 6), 'unidad' => $sp['unidad'], 'etiqueta' => $sp['etiqueta'], 'ts' => $t];
}

/** Aplica las reglas a una respuesta. Devuelve mediciones (con su momento), series históricas por punto y eventos. */
function aplicar(array $reglas, mixed $j, array $v = []): array {
    $out = ['mediciones' => [], 'eventos' => [], 'avisos' => []]; $ahora = time();
    foreach ($reglas as $n => $r) {
        $r = (array) $r; $tipo = (string) ($r['tipo'] ?? '');
        if (!in_array($tipo, TIPOS, true)) { $out['avisos'][] = "Regla $n: tipo «{$tipo}» desconocido"; continue; }
        $d = obtener($j, vars((string) ($r['ruta'] ?? ''), $v));
        $activo = vars((string) ($r['activo'] ?? ''), $v);
        $tsRaiz = isset($r['ts_raiz']) ? momento(obtener($j, (string) $r['ts_raiz'])) : null;
        if ($tipo === 'objeto') {
            if (!is_array($d)) { $out['avisos'][] = "Regla $n: no hay objeto en «" . ($r['ruta'] ?? '') . '»'; continue; }
            $t = isset($r['ts']) ? momento(obtener($d, (string) $r['ts'])) : ($tsRaiz ?? $ahora); if ($t === null) $t = $tsRaiz ?? $ahora;
            foreach (campos($d, $r) as $k => $sp) if (($x = numero($d[$k] ?? null)) !== null) $out['mediciones'][] = med($activo, $sp, $x, $t);
        } elseif ($tipo === 'lista') {
            if (!is_array($d)) { $out['avisos'][] = "Regla $n: no hay lista en «" . ($r['ruta'] ?? '') . '»'; continue; }
            foreach ($d as $it) {
                if (!is_array($it)) continue;
                $orig = (string) obtener($it, (string) ($r['activo_de'] ?? 'activo'));
                $mapa = (array) ($r['activo_mapa'] ?? []);
                if (isset($mapa[$orig])) $act = (string) $mapa[$orig]; elseif (($r['sin_mapa'] ?? 'usar') === 'omitir') continue; else $act = $orig;
                if ($act === '') continue;
                $pts = obtener($it, (string) ($r['puntos_ruta'] ?? 'p')); if (!is_array($pts)) continue;
                $t = isset($r['ts']) ? momento(obtener($it, (string) $r['ts'])) : null; $t = $t ?? $tsRaiz ?? $ahora;
                $pm = (array) ($r['punto_mapa'] ?? []);
                foreach ($pts as $p) {
                    if (!is_array($p)) continue;
                    $id = (string) obtener($p, (string) ($r['punto'] ?? '0'));
                    if ($id === '' || (!empty($r['solo_mapa']) && !isset($pm[$id]))) continue;
                    $x = numero(obtener($p, (string) ($r['valor'] ?? '2'))); if ($x === null) continue;
                    $m = (array) ($pm[$id] ?? []);
                    $sp = ['punto' => punto_id((string) ($m['punto'] ?? $id)), 'unidad' => (string) ($m['unidad'] ?? obtener($p, (string) ($r['unidad'] ?? '3')) ?? ''),
                           'etiqueta' => (string) ($m['etiqueta'] ?? obtener($p, (string) ($r['etiqueta'] ?? '1')) ?? ''), 'escala' => is_numeric($m['escala'] ?? null) ? (float) $m['escala'] : 1.0];
                    if ($sp['punto'] !== '') $out['mediciones'][] = med($act, $sp, $x, $t);
                }
            }
        } elseif ($tipo === 'filas') {
            if (!is_array($d)) { $out['avisos'][] = "Regla $n: no hay filas en «" . ($r['ruta'] ?? '') . '»'; continue; }
            foreach ($d as $f) { if (!is_array($f)) continue; $t = momento(obtener($f, (string) ($r['ts'] ?? 'ts'))); if ($t === null) continue;
                foreach (campos($f, $r) as $k => $sp) if (($x = numero($f[$k] ?? null)) !== null) $out['mediciones'][] = med($activo, $sp, $x, $t); }
        } elseif ($tipo === 'series') {
            if (!is_array($d)) { $out['avisos'][] = "Regla $n: no hay series en «" . ($r['ruta'] ?? '') . '»'; continue; }
            $cs = ($r['campos'] ?? '*') === '*' ? array_map(fn($k) => spec((string) $k, null, $r), array_combine(array_keys($d), array_keys($d))) : array_map(fn($k) => spec((string) $k, $r['campos'][$k], $r), array_combine(array_keys((array) $r['campos']), array_keys((array) $r['campos'])));
            foreach ($cs as $k => $sp) foreach ((array) ($d[$k] ?? []) as $p) if (is_array($p) && count($p) >= 2 && ($t = momento($p[0])) !== null && ($x = numero($p[1])) !== null) $out['mediciones'][] = med($activo, $sp, $x, $t);
        } elseif ($tipo === 'columnas') {
            $ts = obtener($j, (string) ($r['ruta_ts'] ?? 't')); if (!is_array($d) || !is_array($ts)) { $out['avisos'][] = "Regla $n: faltan las columnas"; continue; }
            $cs = ($r['campos'] ?? '*') === '*' ? array_map(fn($k) => spec((string) $k, null, $r), array_combine(array_keys($d), array_keys($d))) : array_map(fn($k) => spec((string) $k, $r['campos'][$k], $r), array_combine(array_keys((array) $r['campos']), array_keys((array) $r['campos'])));
            foreach ($cs as $k => $sp) foreach ((array) ($d[$k] ?? []) as $i => $x) if (($t = momento($ts[$i] ?? null)) !== null && ($x = numero($x)) !== null) $out['mediciones'][] = med($activo, $sp, $x, $t);
        } else {   // eventos
            if (!is_array($d)) { $out['avisos'][] = "Regla $n: no hay eventos en «" . ($r['ruta'] ?? '') . '»'; continue; }
            $al = (array) ($r['alarma_si'] ?? []);
            foreach ($d as $e) {
                if (!is_array($e)) continue;
                $t = momento(obtener($e, (string) ($r['ts'] ?? 'ts'))); $id = (string) obtener($e, (string) ($r['id'] ?? 'id')); if ($t === null || $id === '') continue;
                $cat = obtener($e, (string) ($r['categoria'] ?? 'categoria'));
                $alarma = false; foreach ($al as $k => $val) if ((string) obtener($e, (string) $k) === (string) $val) $alarma = true;
                $out['eventos'][] = ['id' => $id, 'activo' => $activo, 'ts' => $t, 'fin' => momento(obtener($e, (string) ($r['fin'] ?? 'fin'))),
                    'mensaje' => mb_substr(trim((string) obtener($e, (string) ($r['mensaje'] ?? 'mensaje'))), 0, 300), 'categoria' => $cat === null ? null : mb_substr((string) $cat, 0, 40), 'nivel' => $alarma ? 'alarma' : 'evento'];
            }
        }
    }
    return $out;
}

// ------------------------------------------------------------------ lectura por HTTP (solo GET)
function bajar(string $url, array $cfg): mixed {
    if (!preg_match('#^https?://#i', $url)) throw new Fallo(422, 'La dirección tiene que empezar con http:// o https://');
    $url = strtr($url, [' ' => '%20', '|' => '%7C']);                       // libcurl rechaza espacios (p. ej. «Chiller 01») y conviene no mandar «|» crudo
    $ch = curl_init($url);
    $max = max(1, (int) ($cfg['max_mb'] ?? 8)) * 1048576; $buf = '';
    curl_setopt_array($ch, [CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => max(5, (int) ($cfg['tiempo_s'] ?? 20)),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Cache-Control: no-cache'], CURLOPT_USERAGENT => 'iProy-fuentes/1.0',
        CURLOPT_WRITEFUNCTION => function ($c, $s) use (&$buf, $max) { $buf .= $s; return strlen($buf) > $max ? 0 : strlen($s); }]);
    $ok = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
    if ($ok === false) throw new Fallo(502, strlen($buf) > $max ? 'La respuesta supera el máximo de ' . ($cfg['max_mb'] ?? 8) . ' MB' : 'Sin respuesta: ' . $err);
    if ($http >= 400) throw new Fallo(502, "El origen respondió HTTP $http");
    $j = json_decode($buf, true);
    if ($j === null && trim($buf) !== 'null') throw new Fallo(502, 'El origen no devolvió JSON');
    return $j;
}
function reglas_validas(mixed $r, string $que = 'reglas'): array {
    if (is_string($r)) $r = json_decode($r, true);
    if (!is_array($r) || !array_is_list($r) || !$r) throw new Fallo(422, "$que: una lista JSON de reglas, por ejemplo [{\"tipo\":\"objeto\",\"ruta\":\"ultimo\",\"activo\":\"MED-001\",\"campos\":\"*\"}]");
    foreach ($r as $i => $x) if (!is_array($x) || !in_array($x['tipo'] ?? '', TIPOS, true)) throw new Fallo(422, "$que: la regla " . ($i + 1) . ' no tiene un tipo válido (' . implode(', ', TIPOS) . ')');
    return $r;
}
/** Oculta en pantalla el valor de parámetros que parecen secretos (token, clave, key…). */
function url_visible(string $u): string { return preg_replace('/([?&](?:token|clave|key|apikey|api_key|secret|pass|password|pwd)=)[^&]*/i', '$1•••', $u); }

function fuente(int $id): array { return fila('SELECT * FROM fue_fuente WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe esa fuente'); }
function vista(array $f): array {
    return ['id' => (int) $f['id'], 'nombre' => $f['nombre'], 'descripcion' => $f['descripcion'], 'url' => url_visible($f['url']), 'cada_s' => (int) $f['cada_s'], 'activa' => (bool) $f['activa'],
        'reglas' => json_decode((string) $f['reglas'], true), 'historial' => $f['historial'] ? json_decode((string) $f['historial'], true) : null, 'estado' => $f['estado'], 'error' => $f['error'],
        'ultimo_intento' => $f['ultimo_intento'], 'ultimo_ok' => $f['ultimo_ok'], 'lecturas' => (int) $f['lecturas'], 'eventos' => (int) $f['eventos'], 'modificado' => $f['modificado'], 'modificado_por' => $f['modificado_por']];
}
function guardar(?int $id, array $c, array $u): array {
    $nom = trim((string) ($c['nombre'] ?? '')); if ($nom === '' || mb_strlen($nom) > 80) throw new Fallo(422, 'nombre: de 1 a 80 letras');
    $url = trim((string) ($c['url'] ?? ''));
    if ($id && str_contains($url, '•••')) $url = fuente($id)['url'];   // se mostró oculta: queda la guardada
    if (!preg_match('#^https?://\S+$#i', $url) || strlen($url) > 1000) throw new Fallo(422, 'url: http:// o https://, hasta 1000 caracteres');
    $reg = reglas_validas($c['reglas'] ?? null);
    $his = $c['historial'] ?? null; if (is_string($his)) $his = trim($his) === '' ? null : json_decode($his, true);
    if ($his !== null) { if (!is_array($his) || empty($his['url'])) throw new Fallo(422, 'historial: {"url": "…{desde_ms}…{hasta_ms}…", "reglas": [ … ], "tramo_horas": 24}'); $his['reglas'] = reglas_validas($his['reglas'] ?? null, 'historial.reglas'); }
    $cada = max(15, min(86400, (int) ($c['cada_s'] ?? 60)));
    $vals = [$nom, mb_substr(trim((string) ($c['descripcion'] ?? '')), 0, 300) ?: null, $url, $cada, !empty($c['activa']) ? 1 : 0, json_encode($reg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
             $his ? json_encode($his, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, (string) ($u['usuario'] ?? '')];
    try {
        if ($id) { fuente($id); q('UPDATE fue_fuente SET nombre = ?, descripcion = ?, url = ?, cada_s = ?, activa = ?, reglas = ?, historial = ?, modificado = NOW(), modificado_por = ? WHERE id = ?', [...$vals, $id]); }
        else { q('INSERT INTO fue_fuente (nombre, descripcion, url, cada_s, activa, reglas, historial, modificado, modificado_por) VALUES (?,?,?,?,?,?,?,NOW(),?)', $vals); $id = (int) pdo()->lastInsertId(); }
    } catch (\PDOException $e) { if (str_contains($e->getMessage(), 'Duplicate')) throw new Fallo(409, "Ya hay una fuente llamada «{$nom}»"); throw $e; }
    asentar('fuentes.guardada', ['nombre' => $nom, 'activa' => (bool) $vals[4]], (string) $id);
    return vista(fuente($id));
}

/** Publica lo nuevo: mediciones más recientes que la última publicada de cada punto, y eventos nuevos o que cambiaron. */
function publicar_lote(int $fid, array $x, string $origen): array {
    $marcas = []; foreach (filas('SELECT clave, ts FROM fue_marca WHERE fuente_id = ?', [$fid]) as $m) $marcas[$m['clave']] = (int) $m['ts'];
    usort($x['mediciones'], fn($a, $b) => $a['ts'] <=> $b['ts']);
    $n = 0; $nuevas = [];
    foreach ($x['mediciones'] as $m) {
        if ($m['activo'] === '' || $m['punto'] === '') continue;
        $k = mb_substr($m['activo'] . '|' . $m['punto'], 0, 120);
        if (isset($marcas[$k]) && $m['ts'] <= $marcas[$k]) continue;
        publicar('telemetria/medicion', ['activo' => $m['activo'], 'punto' => $m['punto'], 'valor' => $m['valor'], 'unidad' => $m['unidad'] ?: '1', 'ts' => date('Y-m-d H:i:s', $m['ts']), 'origen' => $origen]
            + ($m['etiqueta'] !== '' ? ['etiqueta' => $m['etiqueta']] : []), 'fuentes');
        $marcas[$k] = $m['ts']; $nuevas[$k] = $m['ts']; $n++;
    }
    foreach ($nuevas as $k => $t) q('INSERT INTO fue_marca (fuente_id, clave, ts) VALUES (?,?,?) ON DUPLICATE KEY UPDATE ts = GREATEST(ts, VALUES(ts))', [$fid, $k, $t]);
    $e = publicar_eventos($fid, $x['eventos']);
    if ($n || $e) q('UPDATE fue_fuente SET lecturas = lecturas + ?, eventos = eventos + ? WHERE id = ?', [$n, $e, $fid]);
    return ['mediciones' => $n, 'eventos' => $e];
}
function publicar_eventos(int $fid, array $evs): int {
    if (!$evs) return 0;
    $vistos = []; foreach (filas('SELECT clave, huella FROM fue_visto WHERE fuente_id = ?', [$fid]) as $v) $vistos[$v['clave']] = $v['huella'];
    $n = 0;
    foreach ($evs as $e) {
        $k = mb_substr($fid . ':' . $e['id'], 0, 120); $hu = substr(md5(json_encode([$e['fin'], $e['nivel'], $e['mensaje']])), 0, 8);
        if (($vistos[$k] ?? null) === $hu) continue;
        publicar('equipos/evento', ['clave' => $k, 'activo' => $e['activo'], 'ts' => date('Y-m-d H:i:s', $e['ts']), 'fin' => $e['fin'] ? date('Y-m-d H:i:s', $e['fin']) : null,
            'mensaje' => $e['mensaje'], 'categoria' => $e['categoria'], 'nivel' => $e['nivel'], 'fuente' => $fid], 'fuentes');
        q('INSERT INTO fue_visto (fuente_id, clave, huella) VALUES (?,?,?) ON DUPLICATE KEY UPDATE huella = VALUES(huella)', [$fid, $k, $hu]); $vistos[$k] = $hu; $n++;
    }
    return $n;
}
/** Lee una fuente ahora (la tarea periódica o «Leer ahora»). */
function leer(array $f, array $cfg): array {
    q('UPDATE fue_fuente SET ultimo_intento = NOW() WHERE id = ?', [$f['id']]);
    try {
        $j = bajar($f['url'], $cfg); $x = aplicar(json_decode((string) $f['reglas'], true) ?: [], $j);
        $r = publicar_lote((int) $f['id'], $x, 'fuente:' . $f['id']) + ['avisos' => $x['avisos']];
        q("UPDATE fue_fuente SET ultimo_ok = NOW(), estado = ?, error = ? WHERE id = ?", [$x['avisos'] ? 'aviso' : 'ok', $x['avisos'] ? mb_substr(implode(' · ', $x['avisos']), 0, 500) : null, $f['id']]);
        return $r;
    } catch (\Throwable $e) {
        q("UPDATE fue_fuente SET estado = 'error', error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), $f['id']]);
        throw $e;
    }
}
/** Prueba una dirección y sus reglas sin publicar nada: muestra qué se leería. */
function probar(array $c, array $cfg): array {
    $url = trim((string) ($c['url'] ?? '')); if (!empty($c['id']) && str_contains($url, '•••')) $url = fuente((int) $c['id'])['url'];
    $x = aplicar(reglas_validas($c['reglas'] ?? null), bajar($url, $cfg));
    $act = array_values(array_unique(array_column($x['mediciones'], 'activo')));
    return ['mediciones' => count($x['mediciones']), 'eventos' => count($x['eventos']), 'activos' => $act, 'avisos' => $x['avisos'],
            'muestra' => array_map(fn($m) => $m + ['cuando' => date('Y-m-d H:i:s', $m['ts'])], array_slice($x['mediciones'], 0, 60)),
            'muestra_eventos' => array_map(fn($e) => $e + ['cuando' => date('Y-m-d H:i:s', $e['ts'])], array_slice($x['eventos'], 0, 20))];
}

/** Trae el historial de una fuente: la dirección del historial se arma por tramos ({desde_ms}…{hasta_ms}) y por cada valor de sus variables;
 *  cada punto va en lote a telemetria/historial (no repite lo que ya hay) y los eventos a equipos/evento. */
function traer_historial(int $id, int $dias, array $cfg): array {
    $f = fuente($id); $h = $f['historial'] ? json_decode((string) $f['historial'], true) : null;
    if (!$h) throw new Fallo(422, 'Esta fuente no tiene dirección de historial');
    @set_time_limit(900);
    $dias = max(1, min((int) ($h['max_dias'] ?? 400), $dias)); $fin = time(); $ini = $fin - $dias * 86400;
    $tramo = (int) ($h['tramo_horas'] ?? 0) * 3600; $tramos = [];
    if ($tramo > 0) for ($t = $ini; $t < $fin; $t += $tramo) $tramos[] = [$t, min($fin, $t + $tramo)]; else $tramos[] = [$ini, $fin];
    // variables: {"equipo": ["UTA-01", …]} o {"equipo": [{"valor": "UTA-01", "codigo": "CLIMA-UTA-01"}, …]} → {equipo} y {equipo_codigo}
    $combs = [[]]; foreach ((array) ($h['variables'] ?? []) as $k => $vals) { $nc = []; foreach ($combs as $c) foreach ((array) $vals as $v) {
        if (is_array($v)) { $x = [$k => (string) ($v['valor'] ?? '')]; foreach ($v as $kk => $vv) if ($kk !== 'valor' && !is_array($vv)) $x[$k . '_' . $kk] = (string) $vv; $nc[] = $c + $x; }
        else $nc[] = $c + [$k => (string) $v]; } $combs = $nc; }
    $series = []; $evs = []; $llamadas = 0; $err = [];
    foreach ($combs as $vv) foreach ($tramos as [$a, $b]) {
        $v = $vv + ['desde_ms' => $a * 1000, 'hasta_ms' => $b * 1000, 'desde_s' => $a, 'hasta_s' => $b, 'desde_fecha' => date('Y-m-d', $a), 'hasta_fecha' => date('Y-m-d', $b), 'horas' => (int) ceil(($b - $a) / 3600), 'dias' => $dias];
        try { $x = aplicar($h['reglas'], bajar(vars((string) $h['url'], $v, true), $cfg), $v); $llamadas++; }
        catch (\Throwable $e) { $err[] = mb_substr($e->getMessage(), 0, 160); if (count($err) > 5) break 2; continue; }
        foreach ($x['mediciones'] as $m) { $k = $m['activo'] . '|' . $m['punto']; $series[$k] ??= ['activo' => $m['activo'], 'punto' => $m['punto'], 'unidad' => $m['unidad'] ?: '1', 'etiqueta' => $m['etiqueta'], 'pares' => []]; $series[$k]['pares'][] = [$m['ts'], $m['valor']]; }
        array_push($evs, ...$x['eventos']);
    }
    $n = 0;
    foreach ($series as $s) foreach (array_chunk($s['pares'], 5000) as $b) { publicar('telemetria/historial', ['activo' => $s['activo'], 'punto' => $s['punto'], 'unidad' => $s['unidad'], 'etiqueta' => $s['etiqueta'], 'origen' => 'fuente:' . $id, 'pares' => $b], 'fuentes'); $n += count($b); }
    $ne = publicar_eventos($id, $evs);
    asentar('fuentes.historial', ['fuente' => $id, 'dias' => $dias, 'llamadas' => $llamadas, 'series' => count($series), 'lecturas' => $n, 'eventos' => $ne, 'errores' => count($err)], (string) $id);
    return ['dias' => $dias, 'llamadas' => $llamadas, 'series' => count($series), 'lecturas' => $n, 'eventos' => $ne, 'errores' => $err];
}
function exportar(): array {
    return ['formato' => 'iproy-fuentes/1', 'exportado' => date('c'), 'fuentes' => array_map(fn($f) => ['nombre' => $f['nombre'], 'descripcion' => $f['descripcion'], 'url' => $f['url'], 'cada_s' => (int) $f['cada_s'],
        'activa' => (bool) $f['activa'], 'reglas' => json_decode((string) $f['reglas'], true), 'historial' => $f['historial'] ? json_decode((string) $f['historial'], true) : null], filas('SELECT * FROM fue_fuente ORDER BY nombre'))];
}
function importar(array $c, array $u): array {
    $l = (array) ($c['fuentes'] ?? []); if (!$l) throw new Fallo(422, 'El paquete no trae fuentes (formato iproy-fuentes/1)');
    $r = ['nuevas' => 0, 'actualizadas' => 0];
    foreach ($l as $f) { $ya = fila('SELECT id FROM fue_fuente WHERE nombre = ?', [trim((string) ($f['nombre'] ?? ''))]); guardar($ya ? (int) $ya['id'] : null, (array) $f, $u); $r[$ya ? 'actualizadas' : 'nuevas']++; }
    return $r;
}
