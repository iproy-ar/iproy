<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Cliente del Servidor PIM (FHIR R4) para el núcleo y los módulos. Los módulos NUNCA leen ni escriben la base PIM:
 * usan la API como cualquier sistema externo (mismos roles, validaciones, Provenance e historial).
 *
 *  - Clientes internos «nucleo-<rol>» con clave ES384 propia (data/nucleo/claves/), registrados en data/pim/clientes/.
 *  - Token SMART en caché (5 min) por cliente; un solo pedido de token a la vez.
 *  - pim()        → llamada HTTP por 127.0.0.1 (tareas del planificador, módulos).
 *  - pim_proxy()  → ejecuta la API en el MISMO proceso (pedidos web de la app: no ocupa dos hilos de PHP, L-321).
 *  - pim_con_asercion() → ejecuta un pedido de un nodo vinculado con SU aserción SMART (la autorización la decide PIM).
 */
declare(strict_types=1);

namespace Nucleo;

const ALCANCES_PIM = ['GM' => 'system/*.cruds', 'EJ' => 'system/*.rs system/Task.u', 'SO' => 'system/*.rs system/ServiceRequest.c',
                      'FT' => 'system/Device.rs system/Observation.crs', 'CI' => 'system/*.rs',
                      'RE' => 'system/Device.rs'];

function base_local(): string { return 'http://127.0.0.1:' . puerto_local(); }

function pim_cliente(string $rol, ?string $org = null): string {
    if (!isset(ALCANCES_PIM[$rol])) throw new Fallo(500, "Rol PIM desconocido: $rol");
    if ($rol === 'EJ' && !$org) throw new Fallo(403, 'El usuario Ejecutor no tiene organización asignada');
    $id = 'nucleo-' . strtolower($rol) . ($rol === 'EJ' ? '-' . substr(preg_replace('/[^a-z0-9]/', '', strtolower((string) $org)), 0, 20) . '-' . substr(md5((string) $org), 0, 6) : '');
    $kid = $id . '-1';
    $jwk = jwk_publico('pim-interno', $kid);
    $f = raiz() . "/data/pim/clientes/$id.json";
    $reg = leer_json($f);
    if (($reg['jwks']['keys'][0]['x'] ?? '') !== $jwk['x'] || ($reg['rol'] ?? '') !== $rol || !($reg['activo'] ?? false)) {
        escribir_json($f, ['client_id' => $id, 'nombre' => "Núcleo del nodo (rol $rol)", 'activo' => true, 'alta' => date('c'), 'rol' => $rol,
            'organizacion' => (string) $org, 'scopes' => explode(' ', ALCANCES_PIM[$rol]), 'jwks' => ['keys' => [$jwk]]], true);
        @unlink(dir_datos('run') . "/tok-$id.json");
    }
    return $id;
}
function pim_token(string $cli, bool $nuevo = false): string {
    $tf = dir_datos('run') . "/tok-$cli.json";
    $lee = function () use ($tf) { $t = leer_json($tf); return (($t['exp'] ?? 0) > time() + 20) ? (string) $t['tok'] : null; };
    if (!$nuevo && ($t = $lee())) return $t;
    $lk = fopen(dir_datos('run') . "/tok-$cli.lock", 'c');
    flock($lk, LOCK_EX);
    try {
        if (!$nuevo && ($t = $lee())) return $t;
        $reg = leer_json(raiz() . "/data/pim/clientes/$cli.json");
        $aud = base_local() . '/pim/auth/token';
        $r = http('POST', $aud, http_build_query(['grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => asercion('pim-interno', $cli . '-1', $cli, $aud), 'scope' => implode(' ', (array) ($reg['scopes'] ?? []))]),
            ['Content-Type' => 'application/x-www-form-urlencoded'], 10);
        $j = json_decode($r['cuerpo'], true);
        if (empty($j['access_token'])) throw new Fallo(502, 'El Servidor PIM no entregó token al núcleo: ' . ($j['error_description'] ?? $r['error'] ?? "HTTP {$r['http']}"));
        escribir_json($tf, ['tok' => $j['access_token'], 'exp' => time() + (int) $j['expires_in']], true);
        return (string) $j['access_token'];
    } finally { flock($lk, LOCK_UN); fclose($lk); }
}
/** Llamada a la API PIM. $ruta relativa a /pim/r4 (p. ej. "Task?status=requested"). */
function pim(string $metodo, string $ruta, ?array $cuerpo = null, array $o = []): array {
    $cli = pim_cliente($o['rol'] ?? 'GM', $o['org'] ?? null);
    for ($i = 0; $i < 2; $i++) {
        $cab = ['Accept' => 'application/fhir+json', 'Authorization' => 'Bearer ' . pim_token($cli, $i > 0)];
        if ($cuerpo !== null) $cab['Content-Type'] = 'application/fhir+json';
        if (!empty($o['if_match'])) $cab['If-Match'] = $o['if_match'];
        $r = http($metodo, base_local() . '/pim/r4' . ($ruta !== '' ? '/' . ltrim($ruta, '/') : ''),
                  $cuerpo !== null ? json_encode($cuerpo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, $cab, (int) ($o['timeout'] ?? 30));
        if ($r['http'] !== 401) break;
    }
    if ($r['http'] === 0) throw new Fallo(502, 'Servidor PIM sin respuesta: ' . $r['error']);
    $j = json_decode($r['cuerpo'], true) ?? [];
    if (!empty($o['exigir']) && $r['http'] >= 400) throw new Fallo($r['http'], 'PIM: ' . ($j['issue'][0]['diagnostics'] ?? "HTTP {$r['http']}"));
    return ['http' => $r['http'], 'cuerpo' => $j, 'cab' => $r['cab']];
}
/** Recursos de un Bundle de búsqueda (sigue la paginación hasta $max). */
function pim_lista(string $ruta, array $o = [], int $max = 1000): array {
    $l = []; $r = pim('GET', $ruta, null, $o + ['exigir' => true]);
    while (true) {
        foreach ((array) ($r['cuerpo']['entry'] ?? []) as $e) { $l[] = $e['resource']; if (count($l) >= $max) return $l; }
        $sig = null; foreach ((array) ($r['cuerpo']['link'] ?? []) as $k) if (($k['relation'] ?? '') === 'next') $sig = $k['url'];
        if (!$sig) return $l;
        $r = pim('GET', substr((string) parse_url($sig, PHP_URL_PATH), strlen('/pim/r4/')) . '?' . parse_url($sig, PHP_URL_QUERY), null, $o + ['exigir' => true]);
    }
}
/** Pedido web de la app → API PIM en el mismo proceso, con el rol del usuario. No vuelve. */
function pim_proxy(string $ruta, array $u): never {
    if (!preg_match('#^[A-Za-z][A-Za-z0-9\-\.\$_/]*$#', $ruta) || str_contains($ruta, '..')) throw new Fallo(400, 'Ruta PIM inválida');
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($m, ['GET', 'POST', 'PUT'], true)) throw new Fallo(405, 'Método no permitido');
    $cli = pim_cliente(rol_pim($u['rol']), $u['organizacion'] ?? null);
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . pim_token($cli);
    $_SERVER['REQUEST_URI'] = '/pim/r4/' . $ruta . ($qs !== '' ? '?' . $qs : '');
    $GLOBALS['nucleo_pim_usuario'] = $u['usuario'];
    header('X-Nucleo-Usuario: ' . $u['usuario']);
    require raiz() . '/app/pim/index.php';
    exit;
}
/** Ejecuta en ESTE nodo un pedido PIM de un nodo vinculado, autenticado con la aserción SMART que firmó el otro nodo. */
function pim_con_asercion(string $asercion, string $metodo, string $ruta, ?array $cuerpo, ?string $if_match): array {
    $host = 'nodo-' . strtolower(nodo()['id']) . '.colmena';            // la aserción debe estar dirigida a ESTE nodo (aud)
    $pl = json_decode(b64u_dec(explode('.', $asercion . '..')[1]), true);
    if (parse_url((string) ($pl['aud'] ?? ''), PHP_URL_HOST) !== $host) return ['http' => 401, 'cuerpo' => ['error' => 'aud no corresponde a este nodo'], 'cab' => []];
    if (!preg_match('#^[A-Za-z][A-Za-z0-9\-\.\$_/]*(\?[^\s]*)?$#', $ruta) || str_contains($ruta, '..')) return ['http' => 400, 'cuerpo' => ['error' => 'ruta inválida'], 'cab' => []];
    $t = http('POST', base_local() . '/pim/auth/token', http_build_query(['grant_type' => 'client_credentials',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => $asercion, 'scope' => 'system/*.cruds']),
        ['Content-Type' => 'application/x-www-form-urlencoded', 'Host' => $host], 10);
    $j = json_decode($t['cuerpo'], true);
    if (empty($j['access_token'])) {   // el alcance pedido puede exceder el rol: se reintenta con el máximo del rol
        $t = http('POST', base_local() . '/pim/auth/token', http_build_query(['grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => $asercion, 'scope' => 'system/*.rs']),
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Host' => $host], 10);
        $j = json_decode($t['cuerpo'], true);
    }
    if (empty($j['access_token'])) return ['http' => 401, 'cuerpo' => $j ?: ['error' => 'sin token'], 'cab' => []];
    $cab = ['Accept' => 'application/fhir+json', 'Authorization' => 'Bearer ' . $j['access_token'], 'Host' => $host];
    if ($cuerpo !== null) $cab['Content-Type'] = 'application/fhir+json';
    if ($if_match) $cab['If-Match'] = $if_match;
    $r = http($metodo, base_local() . '/pim/r4/' . ltrim($ruta, '/'), $cuerpo !== null ? json_encode($cuerpo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, $cab, 30);
    return ['http' => $r['http'], 'cuerpo' => json_decode($r['cuerpo'], true) ?? [],
            'cab' => array_intersect_key($r['cab'], array_flip(['etag', 'location', 'x-pim-task', 'last-modified']))];
}
