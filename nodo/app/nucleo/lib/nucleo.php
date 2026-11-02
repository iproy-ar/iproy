<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Núcleo iProy · base común: configuración, base de datos, respuestas, errores e identificadores.
 *
 * El núcleo es pequeño y estable (como un kernel): carga módulos, ofrece un bus de eventos, un planificador
 * de tareas, un diario firmado, usuarios/capacidades, introspección (/api/proc) y la colmena (vínculos entre nodos).
 * Todo lo del dominio (órdenes, preventivos, alarmas, telemetría, protocolos industriales) vive en módulos.
 */
declare(strict_types=1);

namespace Nucleo;

const VERSION = '1.0.0';
const ABI = 1;                    // versión del ABI de módulos: modulo.json declara "abi": 1
const PROTOCOLO = 'colmena/1';
const ROLES = ['AD' => 'Administrador del nodo', 'GM' => 'Gestor de mantenimiento', 'EJ' => 'Ejecutor (técnico o empresa)',
               'SO' => 'Solicitante', 'FT' => 'Telemetría (sistemas y sensores)', 'CI' => 'Consulta'];

require_once dirname(__DIR__, 2) . '/_iproy/lib_nodo.php';

final class Fallo extends \RuntimeException {
    public function __construct(public int $http, string $mensaje, public string $codigo = 'error', public array $extra = []) { parent::__construct($mensaje); }
}

function raiz(): string { return dirname(__DIR__, 3); }
function dir_datos(string $sub = ''): string {
    $d = raiz() . '/data/nucleo' . ($sub !== '' ? "/$sub" : '');
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}
/** Identidad del producto (la incubadora la reescribe al crear un software derivado). */
function producto(): array {
    static $p = null;
    if ($p !== null) return $p;
    $f = raiz() . '/producto.json';
    $p = (is_file($f) ? json_decode((string) file_get_contents($f), true) : null) ?: [];
    return $p += ['nombre' => 'iProy', 'version' => VERSION, 'licencia' => 'AGPL-3.0-or-later', 'fuente' => '', 'base' => null];
}
function nodo(): array {
    $c = \nodo_cfg();
    return ['id' => (string) ($c['node_id'] ?? ''), 'nombre' => (string) ($c['nombre'] ?? ''), 'modo' => (string) ($c['modo'] ?? 'local')];
}
function puerto_local(): int {
    static $p = null;
    if ($p !== null) return $p;
    $env = @file_get_contents(raiz() . '/data/iproy.env') ?: '';
    return $p = preg_match('/^IPROY_PUERTO=(\d+)/m', $env, $m) ? (int) $m[1] : (int) ($_SERVER['SERVER_PORT'] ?? 8080);
}
function pdo(): \PDO {
    static $p = null;
    if ($p === null) { $p = \nodo_pdo('iproy'); $p->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC); }
    return $p;
}
function q(string $sql, array $a = []): \PDOStatement { $s = pdo()->prepare($sql); $s->execute($a); return $s; }
function fila(string $sql, array $a = []): ?array { $r = q($sql, $a)->fetch(); return $r ?: null; }
function filas(string $sql, array $a = []): array { return q($sql, $a)->fetchAll(); }
function valor(string $sql, array $a = []): mixed { $r = q($sql, $a)->fetchColumn(); return $r === false ? null : $r; }

function ahora(int $dec = 3): string {
    $t = microtime(true);
    return date('Y-m-d H:i:s', (int) $t) . ($dec ? '.' . substr(sprintf('%06d', ($t - floor($t)) * 1e6), 0, $dec) : '');
}
/** Identificador ordenable por tiempo (26 caracteres Crockford, compatible ULID). */
function id_nuevo(): string {
    $a = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $ms = (int) (microtime(true) * 1000); $t = '';
    for ($i = 0; $i < 10; $i++) { $t = $a[$ms % 32] . $t; $ms = intdiv($ms, 32); }
    $r = random_bytes(16); $s = '';
    for ($i = 0; $i < 16; $i++) $s .= $a[ord($r[$i]) % 32];
    return $t . $s;
}
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

/** JSON canónico (claves ordenadas): lo que se firma y se encadena siempre se serializa igual. */
function canonico(mixed $v): string {
    $ord = function ($x) use (&$ord) {
        if (!is_array($x)) return $x;
        if (array_is_list($x)) return array_map($ord, $x);
        ksort($x, SORT_STRING); return array_map($ord, $x);
    };
    return json_encode($ord($v), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}

function leer_json(string $archivo, array $defecto = []): array {
    return is_file($archivo) ? (json_decode((string) file_get_contents($archivo), true) ?: $defecto) : $defecto;
}
function escribir_json(string $archivo, array $datos, bool $secreto = false): void {
    @mkdir(dirname($archivo), 0700, true);
    $tmp = $archivo . '.tmp' . bin2hex(random_bytes(3));
    file_put_contents($tmp, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);
    if ($secreto) @chmod($tmp, 0600);
    rename($tmp, $archivo);
}
/** Configuración del núcleo (data/nucleo/config.json): hub de relevo, límites, etc. */
function cfg(?array $cambios = null): array {
    $f = dir_datos() . '/config.json';
    $c = leer_json($f) + ['hub' => ['url' => '', 'activo' => false, 'codigo' => ''], 'url_publica' => '', 'retencion_eventos_dias' => 7];
    if ($cambios !== null) { $c = array_replace_recursive($c, $cambios); escribir_json($f, $c, true); }
    return $c;
}
/** Estado persistente pequeño de un módulo (cursores, marcas de tiempo). */
function estado(string $clave, ?array $nuevo = null): array {
    $f = dir_datos('estado') . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $clave) . '.json';
    if ($nuevo !== null) { escribir_json($f, $nuevo); return $nuevo; }
    return leer_json($f);
}

function es_local(): bool { return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true); }
/** Pedido interno del lanzador (CLI): solo desde este equipo y con el token del worker. */
function es_tick(): bool {
    $t = (string) (\nodo_cfg()['tick_token'] ?? '');
    return es_local() && $t !== '' && hash_equals($t, (string) ($_SERVER['HTTP_X_IPROY_TICK'] ?? ''));
}

function cuerpo(): array {
    static $c = null;
    if ($c !== null) return $c;
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') return $c = [];
    if (strlen($raw) > 2 * 1048576) throw new Fallo(413, 'El cuerpo supera 2 MB');
    $j = json_decode($raw, true);
    if (!is_array($j)) throw new Fallo(400, 'El cuerpo debe ser JSON');
    return $c = $j;
}
function responder(int $http, array $datos, array $cab = []): never {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($cab as $k => $v) header("$k: $v");
    echo json_encode($datos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}
function texto_corto(mixed $v, int $n = 200): string { $s = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE); return mb_substr((string) $s, 0, $n); }

/** HTTP saliente simple (curl). TLS: la colmena no depende de él (sobres firmados y sellados de extremo a extremo). */
function http(string $metodo, string $url, ?string $cuerpo = null, array $cab = [], int $timeout = 10, bool $verificar_tls = true): array {
    $ch = curl_init($url);
    $h = [];
    foreach ($cab as $k => $v) $h[] = "$k: $v";
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_HTTPHEADER => $h, CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => $verificar_tls, CURLOPT_SSL_VERIFYHOST => $verificar_tls ? 2 : 0, CURLOPT_FOLLOWLOCATION => false]);
    if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
    // Proxy de salida (redes hospitalarias, L-291): solo hacia Internet; la LAN y el propio equipo van directo.
    $host = (string) parse_url($url, PHP_URL_HOST);
    $privado = $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.colmena')
        || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
    if (!$privado && ($px = proxy_salida())) curl_setopt($ch, CURLOPT_PROXY, $px);
    foreach (['SSL_CERT_FILE', 'CURL_CA_BUNDLE'] as $e) if (($ca = getenv($e)) && is_file($ca)) { curl_setopt($ch, CURLOPT_CAINFO, $ca); break; }
    $r = curl_exec($ch);
    if ($r === false) { $e = curl_error($ch); return ['http' => 0, 'cuerpo' => '', 'cab' => [], 'error' => $e]; }
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $cabs = [];
    foreach (explode("\r\n", substr($r, 0, $hs)) as $l) if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $cabs[strtolower(trim($k))] = trim($v); }
    return ['http' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'cuerpo' => substr($r, $hs), 'cab' => $cabs, 'error' => null];
}

/** Proxy de salida: data/nucleo/config.json «proxy», o el del panel de Redes (data/red.json), o HTTPS_PROXY del sistema. */
function proxy_salida(): string {
    $p = (string) (cfg()['proxy'] ?? '');
    if ($p === '') $p = (string) (leer_json(raiz() . '/data/red.json')['proxy'] ?? '');
    if ($p === '') $p = (string) (getenv('HTTPS_PROXY') ?: getenv('https_proxy') ?: '');
    return $p;
}
/** Carga todas las bibliotecas del núcleo. */
function cargar(): void {
    static $ok = false;
    if ($ok) return;
    foreach (['diario', 'identidad', 'modulos', 'bus', 'tareas', 'usuarios', 'pimcli', 'colmena', 'proc'] as $l) require_once __DIR__ . "/$l.php";
    $ok = true;
}
