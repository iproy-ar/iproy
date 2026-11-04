<?php
/**
 * iProy · Servidor PIM v1.1 · núcleo · 02-11-2026 (PIM 0.9.2, alineado con IHE)
 * Configuración, conexión a la base propia iproy_pim, respuestas FHIR, OperationOutcome, fechas, ULID,
 * versiones con huella encadenada (Provenance/_history) y auditoría (AuditEvent).
 * Sistema paralelo: no lee ni escribe tablas de iProy (solo la importación lee las vistas iproy_fhir).
 */
declare(strict_types=1);

namespace IproyPim;

use PDO;

const VERSION_API = '1.1.0';
const VERSION_PIM = '0.9.4';
const MDC = 'urn:iso:std:iso:11073:10101';                          // nomenclatura ISO/IEEE 11073-10101 (IHE MEM-DMC)
const UCUM = 'http://unitsofmeasure.org';
const MCSD_TIPOS = 'https://profiles.ihe.net/ITI/mCSD/CodeSystem/IHE.mCSD.Organization.Location.Types';
/** Ids de extensiones y perfiles de versiones anteriores → id vigente (PIM 0.9.2, Anexo J7). Se aplican al recibir. */
const ALIAS_SD = [
    'grupoUsoMedico' => 'pim-grupo-uso-medico', 'ambienteControlado' => 'pim-ambiente-controlado', 'categoriaAlcance' => 'pim-categoria-alcance',
    'claseRiesgoPM' => 'pim-clase-riesgo-pm', 'puestaEnMarcha' => 'pim-puesta-en-marcha', 'pertenencia' => 'pim-pertenencia',
    'categoriaRiesgo' => 'pim-categoria-riesgo', 'redundancia' => 'pim-redundancia', 'tipoMantenimiento' => 'pim-tipo-mantenimiento',
    'modoFalla' => 'pim-modo-falla', 'estadoDeclarado' => 'pim-estado-declarado', 'componentesIRU' => 'pim-iru', 'pim-equipo-medico' => 'pim-pma',
];
const FHIR_VERSION = '4.0.1';
const MIME = 'application/fhir+json; charset=utf-8';

function raiz(): string { return dirname(__DIR__, 3); }
function datos(): string { return raiz() . '/data/pim'; }

/** data/pim/config.json (se crea desde conf/pim-config.json en el primer start). */
function cfg(): array {
    static $c = null;
    if ($c !== null) return $c;
    $def = [
        'ns' => 'https://iproy.com.ar/fhir', 'ns_alias' => ['https://iproy.com.ar/pim'],   // PIM_CANONICAL (docs/dominio-canonico.md); /pim = alias de la v1.0
        'organismo' => ['codigo' => 'ORG', 'nombre' => 'Organismo (configurar en data/pim/config.json)', 'refes' => '', 'refes_system' => ''],
        'empresas_internas' => [],
        'exponer_detalle' => false, 'token_ttl' => 300, 'max_count' => 200, 'count_def' => 50,
        'responsable_defecto' => '', 'regla_telemetria' => true, 'legado_solo_lectura' => true,
    ];
    $f = datos() . '/config.json';
    $u = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    $c = is_array($u) ? array_replace($def, $u) : $def;
    $c['organismo'] = array_replace($def['organismo'], is_array($c['organismo']) ? $c['organismo'] : []);
    $c['ns'] = rtrim((string) $c['ns'], '/');
    $c['ns_alias'] = array_values(array_map(fn($x) => rtrim((string) $x, '/'), (array) $c['ns_alias']));
    if ($c['ns'] === 'https://iproy.com.ar/pim') {                  // nodos creados con la 1.0: el canónico es /fhir y /pim queda como alias
        $c['ns'] = 'https://iproy.com.ar/fhir'; $c['ns_alias'] = array_values(array_unique(array_merge(['https://iproy.com.ar/pim'], $c['ns_alias'])));
    }
    $p = raiz() . '/data/php/pim.php';
    $c['priv'] = is_file($p) ? (require $p) : [];
    return $c;
}

function sid(string $t): string { return cfg()['ns'] . '/sid/' . $t; }
function cs(string $n): string { return cfg()['ns'] . '/CodeSystem/' . $n; }
function sd(string $n): string { return cfg()['ns'] . '/StructureDefinition/' . $n; }
/** Sistema de códigos local del nodo (categorías propias, clases de equipo): no es parte del estándar. */
function cs_local(string $n): string { return 'urn:iproy:' . strtolower(nodo_id()) . ':' . $n; }
function es_sistema(string $s, string $t): bool {
    foreach (array_merge([cfg()['ns']], cfg()['ns_alias']) as $ns) if ($s === $ns . '/sid/' . $t) return true;
    return false;
}

function nodo_id(): string {
    static $n = null;
    if ($n !== null) return $n;
    $j = raiz() . '/data/node.json';
    $x = is_file($j) ? json_decode((string) file_get_contents($j), true) : null;
    return $n = (is_array($x) && !empty($x['node_id'])) ? (string) $x['node_id'] : 'PIM';
}

function pdo(): PDO {
    static $p = null;
    if ($p) return $p;
    $v = cfg()['priv'];
    if (empty($v['db_pass'])) throw new Fallo(503, 'exception', 'El servidor PIM no está inicializado (falta data/php/pim.php). Reiniciá el nodo con iProy-Control start.');
    $p = new PDO('mysql:host=' . $v['db_host'] . ';dbname=iproy_pim;charset=utf8mb4', $v['db_user'], $v['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]);
    $p->exec("SET time_zone = '-03:00'");
    return $p;
}

final class Fallo extends \RuntimeException {
    public function __construct(public int $http, public string $tipo, string $msg, public ?string $expresion = null) { parent::__construct($msg); }
}

function oo(string $tipo, string $msg, string $sev = 'error', ?string $expr = null): array {
    $i = ['severity' => $sev, 'code' => $tipo, 'diagnostics' => $msg];
    if ($expr) $i['expression'] = [$expr];
    return ['resourceType' => 'OperationOutcome', 'issue' => [$i]];
}

function base(): string {
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off');
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https' : 'http') . '://' . $host . '/pim/r4';
}
function url_token(): string { return substr(base(), 0, -3) . '/auth/token'; }

$GLOBALS['iproy_pim_audit'] = ['cliente' => null, 'n' => null];

function responder(int $http, array $cuerpo, string $mime = MIME, array $cab = []): never {
    http_response_code($http);
    header('Content-Type: ' . $mime);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach ($cab as $k => $v) header("$k: $v");
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    auditar($http);
    exit;
}

/** AuditEvent interno: tabla pim_auditoria. Nunca guarda parámetros ni tokens. */
function auditar(int $http): void {
    try {
        $a = $GLOBALS['iproy_pim_audit'];
        pdo()->prepare('INSERT INTO pim_auditoria (cliente, metodo, ruta, http, n, ip) VALUES (?,?,?,?,?,?)')
             ->execute([$a['cliente'], substr((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 8),
                        substr((string) strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'), 0, 200), $http, $a['n'], $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (\Throwable) { /* la auditoría nunca rompe la respuesta */ }
}

/** ULID nuevo (48 bits de tiempo + 80 aleatorios, Crockford base32). */
function ulid(): string {
    static $alf = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $ms = (int) floor(microtime(true) * 1000);
    $t = '';
    for ($i = 0; $i < 10; $i++) { $t = $alf[$ms % 32] . $t; $ms = intdiv($ms, 32); }
    $r = ''; $b = random_bytes(10); $bits = '';
    for ($i = 0; $i < 10; $i++) $bits .= str_pad(decbin(ord($b[$i])), 8, '0', STR_PAD_LEFT);
    for ($i = 0; $i < 80; $i += 5) $r .= $alf[bindec(substr($bits, $i, 5))];
    return $t . $r;
}
/** Mismo ULID determinístico que la API v0.3 (A-11): los registros importados conservan su ID público. */
function ulid_legado(string $origen, string $clave): string {
    static $alf = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $h = hash('sha256', 'iproy|' . $origen . '|' . $clave, true);
    $bits = '';
    for ($i = 0; $i < 10; $i++) $bits .= str_pad(decbin(ord($h[$i])), 8, '0', STR_PAD_LEFT);
    $r = '';
    for ($i = 0; $i < 80; $i += 5) $r .= $alf[bindec(substr($bits, $i, 5))];
    return '0000000000' . $r;
}

function es_id(string $id): bool { return (bool) preg_match('/^[A-Za-z0-9\-\.]{1,64}$/', $id); }

/** Número correlativo atómico por serie (LAST_INSERT_ID con expresión: seguro ante altas simultáneas). */
function numero_nuevo(string $serie): int {
    $p = pdo();
    $p->prepare('INSERT INTO pim_numerador (serie, ultimo) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE ultimo = LAST_INSERT_ID(ultimo + 1)')->execute([$serie]);
    return (int) $p->query('SELECT LAST_INSERT_ID()')->fetchColumn();
}

/** Guarda una versión (Provenance/_history) con huella SHA-256 encadenada a la anterior. */
function guardar_version(string $recurso, string $id, int $version, string $accion, string $cliente, array $contenido, ?string $motivo = null): void {
    $p = pdo();
    $q = $p->prepare('SELECT huella FROM pim_version WHERE recurso = ? AND id = ? ORDER BY version DESC LIMIT 1'); $q->execute([$recurso, $id]);
    $ant = (string) ($q->fetchColumn() ?: str_repeat('0', 64));
    $json = json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $p->prepare('INSERT INTO pim_version (recurso, id, version, accion, cliente, motivo, contenido, huella) VALUES (?,?,?,?,?,?,?,?)')
      ->execute([$recurso, $id, $version, $accion, $cliente, $motivo === null ? null : mb_substr($motivo, 0, 255), $json, hash('sha256', $ant . '|' . $json)]);
}

function iso(?string $dt): ?string {
    if ($dt === null || $dt === '' || str_starts_with($dt, '0000')) return null;
    $t = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', strlen($dt) > 19 ? $dt : $dt . '.000', new \DateTimeZone('-03:00'));
    if (!$t) return null;
    $ms = (int) $t->format('v');
    return $ms ? $t->format('Y-m-d\TH:i:s.vP') : $t->format('Y-m-d\TH:i:sP');
}
/** dateTime FHIR recibido → 'Y-m-d H:i:s.v' en hora de Argentina. */
function a_local(?string $v, string $campo): ?string {
    if ($v === null || $v === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:\d{2}))?$/', $v))
        throw new Fallo(422, 'invalid', "$campo: fecha FHIR inválida ($v)", $campo);
    try { $t = new \DateTimeImmutable($v, new \DateTimeZone('-03:00')); } catch (\Throwable) { throw new Fallo(422, 'invalid', "$campo: fecha inválida", $campo); }
    return $t->setTimezone(new \DateTimeZone('-03:00'))->format('Y-m-d H:i:s.v');
}
function fecha_param(string $v): array {
    $ops = ['eq' => '=', 'ge' => '>=', 'gt' => '>', 'le' => '<=', 'lt' => '<', 'sa' => '>', 'eb' => '<'];
    $op = '=';
    if (preg_match('/^(eq|ge|gt|le|lt|sa|eb)/', $v, $m)) { $op = $ops[$m[1]]; $v = substr($v, 2); }
    try { $t = new \DateTimeImmutable($v, new \DateTimeZone('-03:00')); } catch (\Throwable) { throw new Fallo(400, 'invalid', "Fecha inválida: $v"); }
    $t = $t->setTimezone(new \DateTimeZone('-03:00'));
    $dia = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
    if ($dia && $op === '=') return ['BETWEEN', $t->format('Y-m-d 00:00:00'), $t->format('Y-m-d 23:59:59.999')];
    if ($dia && ($op === '<=' || $op === '>')) return [$op, $t->format('Y-m-d 23:59:59.999')];
    return [$op, $t->format('Y-m-d H:i:s.v')];
}
function normalizar(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
}
function token(string $v): array { $p = strpos($v, '|'); return $p === false ? [null, $v] : [substr($v, 0, $p), substr($v, $p + 1)]; }
/** «Tipo/id», «id» o URL absoluta → id (valida el tipo). */
function ref_id(string $v, string|array $tipos, string $campo = 'reference'): string {
    $tipos = (array) $tipos;
    $v = rtrim($v, '/');
    if (preg_match('#(?:^|/)([A-Za-z]+)/([A-Za-z0-9\-\.]{1,64})$#', $v, $m)) {
        if (!in_array($m[1], $tipos, true)) throw new Fallo(422, 'invalid', "$campo debe apuntar a " . implode(' o ', $tipos), $campo);
        return $m[2];
    }
    if (es_id($v)) return $v;
    throw new Fallo(422, 'invalid', "$campo: referencia inválida", $campo);
}
function tipo_ref(string $v): ?string { return preg_match('#(?:^|/)([A-Za-z]+)/[A-Za-z0-9\-\.]{1,64}$#', $v, $m) ? $m[1] : null; }
