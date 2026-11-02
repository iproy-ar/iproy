<?php
/**
 * iProy · Servidor PIM v1.1 · operaciones REST (lectura, búsqueda, alta, modificación, transición, historial) · 02-11-2026
 * Perfiles de integración implementados (PIM 0.9.2, Tabla 9-4) y transacciones (Tabla 9-3):
 *   PIM-INV  también ITI-90 (mCSD) para Organization y Location; ITI-91 con _history?_since
 *   PIM-REG  operación Device/$refes y rol RE
 *   PIM-EQM  lo traduce el módulo «mem» (PCD-15 → Device + DeviceMetric + Observation) usando esta API
 *   PIM-INV  Organization, Location, Device: leer, buscar; GM además da de alta y modifica (If-Match)
 *   PIM-SOL  POST ServiceRequest → crea la Task (orden) y la devuelve enlazada
 *   PIM-OT   Task: leer, buscar, PUT con If-Match y operación $transicion con la máquina de estados (Tabla 9-2)
 *   PIM-RSG  RiskAssessment (IRU explicado) y Group; la prioridad de la orden sale del IRU del activo
 *   PIM-TEL  POST Observation (o Bundle batch); fuera de rango → solicitud + orden automáticas
 *   PIM-FED  _history (por recurso y ?_since) y Provenance con huella encadenada
 */
declare(strict_types=1);

namespace IproyPim;

const RECURSOS = [
    'Organization'   => ['tabla' => 'pim_organizacion', 'fhir' => 'IproyPim\org_fhir',  'alta' => ['GM'],       'modif' => ['GM']],
    'Location'       => ['tabla' => 'pim_local',        'fhir' => 'IproyPim\loc_fhir',  'alta' => ['GM'],       'modif' => ['GM']],
    'Device'         => ['tabla' => 'pim_activo',       'fhir' => 'IproyPim\dev_fhir',  'alta' => ['GM'],       'modif' => ['GM']],
    'ServiceRequest' => ['tabla' => 'pim_solicitud',    'fhir' => 'IproyPim\sr_fhir',   'alta' => ['GM', 'SO'], 'modif' => []],
    'Task'           => ['tabla' => 'pim_orden',        'fhir' => 'IproyPim\task_fhir', 'alta' => [],           'modif' => ['GM', 'EJ']],
    'Observation'    => ['tabla' => 'pim_observacion',  'fhir' => 'IproyPim\obs_fhir',  'alta' => ['GM', 'FT'], 'modif' => []],
    'DeviceMetric'   => ['tabla' => 'pim_punto',        'fhir' => 'IproyPim\dm_fhir',   'alta' => [],           'modif' => []],
    'RiskAssessment' => ['tabla' => null, 'fhir' => null, 'alta' => [], 'modif' => []],
    'Group'          => ['tabla' => null, 'fhir' => null, 'alta' => [], 'modif' => []],
    'Provenance'     => ['tabla' => null, 'fhir' => null, 'alta' => [], 'modif' => []],
];

function cuerpo_json(): array {
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 1048576) throw new Fallo(413, 'too-costly', 'El cuerpo supera 1 MB');
    $j = json_decode($raw, true);
    if (!is_array($j)) throw new Fallo(400, 'structure', 'El cuerpo debe ser JSON FHIR válido');
    return normalizar_ns($j);
}
/** Interoperabilidad entre nodos con distinto espacio de nombres (p. ej. iproy.com.ar ↔ iproy.com.ar, Paso 105):
 *  toda URL que empiece con un ns_alias (extensiones, perfiles, CodeSystems, sid) se lee como si fuera del ns propio. */
function normalizar_ns(array $j): array {
    $propio = cfg()['ns']; $alias = array_values(array_filter(cfg()['ns_alias'], fn($a) => $a !== '' && $a !== $propio));
    $pref = $propio . '/StructureDefinition/';
    array_walk_recursive($j, function (&$v) use ($propio, $alias, $pref) {
        if (!is_string($v)) return;
        foreach ($alias as $a) if (str_starts_with($v, $a . '/')) { $v = $propio . substr($v, strlen($a)); break; }
        if (str_starts_with($v, $pref) && isset(ALIAS_SD[$n = substr($v, strlen($pref))])) $v = $pref . ALIAS_SD[$n];   // ids de la 0.9.1 y de la 1.0
    });
    return $j;
}
function exigir_rol(array $cli, array $roles, string $que): void {
    if (!in_array($cli['rol'], $roles, true)) throw new Fallo(403, 'forbidden', "El rol {$cli['rol']} no puede $que (roles permitidos: " . implode(', ', $roles) . ')');
}
/** Organización del cliente Ejecutor (empresa o área): solo ve y opera sus órdenes. */
function org_de_cliente(array $cli): ?string {
    if ($cli['rol'] !== 'EJ') return null;
    $cod = (string) ($cli['organizacion'] ?? '');
    $q = pdo()->prepare("SELECT id FROM pim_organizacion WHERE codigo = ? AND tipo IN ('empresa','area')"); $q->execute([$cod]);
    $id = $q->fetchColumn();
    if (!$id) throw new Fallo(403, 'forbidden', 'El cliente Ejecutor no tiene una organización válida asignada');
    return (string) $id;
}
function fila(string $tabla, string $id, bool $bloquear = false): ?array {
    $q = pdo()->prepare("SELECT * FROM $tabla WHERE id = ?" . ($bloquear ? ' FOR UPDATE' : '')); $q->execute([$id]);
    return $q->fetch() ?: null;
}
function cab_version(array $res): array {
    $c = [];
    if (isset($res['meta']['versionId'])) $c['ETag'] = 'W/"' . $res['meta']['versionId'] . '"';
    if (isset($res['meta']['lastUpdated'])) $c['Last-Modified'] = gmdate('D, d M Y H:i:s', strtotime($res['meta']['lastUpdated'])) . ' GMT';
    return $c;
}
function if_match(): ?int {
    $h = trim((string) ($_SERVER['HTTP_IF_MATCH'] ?? ''));
    if ($h === '') return null;
    if (!preg_match('/^(?:W\/)?"?(\d+)"?$/', $h, $m)) throw new Fallo(400, 'invalid', 'If-Match debe ser W/"<versión>"');
    return (int) $m[1];
}

// ================================================================== lectura
function leer(string $tipo, string $id, ?string $vid = null): never {
    $cli = exigir($tipo, 'r');
    if (!es_id($id)) throw new Fallo(400, 'invalid', 'ID con formato inválido');
    if ($vid !== null) {                                                       // vread
        $q = pdo()->prepare('SELECT contenido FROM pim_version WHERE recurso = ? AND id = ? AND version = ?'); $q->execute([$tipo, $id, (int) $vid]);
        $c = $q->fetchColumn(); if (!$c) throw new Fallo(404, 'not-found', "$tipo/$id/_history/$vid no existe");
        $GLOBALS['iproy_pim_audit']['n'] = 1;
        responder(200, json_decode($c, true));
    }
    $res = match ($tipo) {
        'RiskAssessment' => ($a = fila('pim_activo', preg_replace('/-iru$/', '', $id))) ? riesgo_fhir($a) : null,
        'Group' => ($a = fila('pim_activo', preg_replace('/-g$/', '', $id))) ? grupo_fhir($a) : null,
        'Provenance' => null,
        'Location' => $id === estab_id() ? estab_fhir() : (($r = fila('pim_local', $id)) ? loc_fhir($r) : null),
        'DeviceMetric' => (function () use ($id) { completar_ids_punto(); return ($r = fila('pim_punto', $id)) ? dm_fhir($r) : null; })(),
        default => ($r = fila(RECURSOS[$tipo]['tabla'], $id)) ? (RECURSOS[$tipo]['fhir'])($r) : null,
    };
    if (!$res) throw new Fallo(404, 'not-found', "$tipo/$id no existe");
    restringir_ej($cli, $tipo, $res);
    $GLOBALS['iproy_pim_audit']['n'] = 1;
    responder(200, $res, MIME, cab_version($res));
}
/** El Ejecutor solo lee órdenes de su organización. */
function restringir_ej(array $cli, string $tipo, array $res): void {
    if ($cli['rol'] !== 'EJ' || $tipo !== 'Task') return;
    if (($res['owner']['reference'] ?? '') !== 'Organization/' . org_de_cliente($cli)) throw new Fallo(404, 'not-found', "Task/{$res['id']} no existe");
}

// ================================================================== búsqueda
const PARAMS = [
    'Organization'   => ['_id' => 'id', 'identifier' => 'codigo', 'name' => 'nombre', 'type' => 'tipo', 'partof' => 'parte_de', 'active' => 'activo'],
    'Location'       => ['_id' => 'id', 'identifier' => 'codigo', 'name' => 'nombre', 'status' => 'estado', 'partof' => 'parte_de',
                         'organization' => 'organization', 'type' => 'loc_tipo'],
    'Device'         => ['_id' => 'id', 'identifier' => 'codigo', 'device-name' => 'nombre', 'location' => 'local_id', 'type' => 'tipo_codigo',
                         'status' => 'estado', 'parent' => 'padre_id', 'manufacturer' => 'fabricante', 'categoria-alcance' => 'alcance'],
    'ServiceRequest' => ['_id' => 'id', 'identifier' => 'numero', 'status' => 'estado', 'priority' => 'prioridad', 'subject' => 'subject', 'authored' => 'autoria'],
    'Task'           => ['_id' => 'id', 'identifier' => 'numero', 'status' => 'estado', 'business-status' => 'estado_negocio', 'owner' => 'responsable_id',
                         'priority' => 'prioridad', 'location' => 'local_id', 'focus' => 'activo_id', 'based-on' => 'solicitud_id',
                         'authored-on' => 'autoria', 'modified' => 'actualizado', '_lastUpdated' => 'actualizado', 'code' => 'categoria_codigo'],
    'Observation'    => ['_id' => 'id', 'device' => 'activo_id', 'code' => 'punto', 'date' => 'instante', 'interpretation' => 'interpretacion'],
    'DeviceMetric'   => ['_id' => 'id'],
];
const FECHAS = ['autoria', 'actualizado', 'instante'];
const REFERENCIAS = ['parte_de' => ['Organization', 'Location'], 'organization' => 'Organization', 'local_id' => 'Location', 'padre_id' => 'Device', 'responsable_id' => 'Organization',
                     'activo_id' => 'Device', 'solicitud_id' => 'ServiceRequest'];

function params(): array {
    $q = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) file_get_contents('php://input') : (string) ($_SERVER['QUERY_STRING'] ?? '');
    $l = [];
    foreach (explode('&', $q) as $par) { if ($par === '') continue; [$k, $v] = array_pad(explode('=', $par, 2), 2, ''); $l[] = [urldecode($k), urldecode($v)]; }
    return $l;
}

function buscar(string $tipo): never {
    $cli = exigir($tipo, 's');
    $ps = params();
    $max = (int) cfg()['max_count']; $count = (int) cfg()['count_def']; $offset = 0; $soloTotal = false; $sinTotal = false; $sort = null; $usados = [];
    $w = []; $a = [];
    if ($tipo === 'RiskAssessment' || $tipo === 'Provenance' || $tipo === 'Group') { buscar_especial($tipo, $ps, $cli); }
    if ($tipo === 'DeviceMetric') buscar_puntos($ps);
    $estab = $tipo === 'Location' ? incluir_establecimiento($ps) : false;
    foreach ($ps as [$k, $v]) {
        [$nombre, $mod] = array_pad(explode(':', $k, 2), 2, null);
        switch ($nombre) {
            case '_count': $count = max(0, min($max, (int) $v)); $usados[] = [$k, $v]; continue 2;
            case '_offset': $offset = max(0, (int) $v); continue 2;
            case '_summary': if ($v === 'count') { $soloTotal = true; $usados[] = [$k, $v]; } continue 2;
            case '_total': if ($v === 'none') { $sinTotal = true; $usados[] = [$k, $v]; } continue 2;
            case '_sort': $sort = $v; $usados[] = [$k, $v]; continue 2;
            case '_format': continue 2;
        }
        $col = PARAMS[$tipo][$nombre] ?? null;
        if ($col === null) continue;                                           // parámetro no soportado: se ignora (lenient)
        if ($mod !== null && !in_array($mod, ['exact', 'contains'], true)) throw new Fallo(400, 'not-supported', "Modificador no soportado: $k");
        $usados[] = [$k, $v];
        $c = [];
        foreach (explode(',', $v) as $uno) {
            if (in_array($col, FECHAS, true)) {
                $f = fecha_param($uno);
                if ($f[0] === 'BETWEEN') { $c[] = "$col BETWEEN ? AND ?"; $a[] = $f[1]; $a[] = $f[2]; } else { $c[] = "$col {$f[0]} ?"; $a[] = $f[1]; }
            } elseif ($col === 'subject') {
                $t = tipo_ref($uno); $id = ref_id($uno, ['Location', 'Device'], 'subject');
                $c[] = $t === 'Device' ? 'activo_id = ?' : 'local_id = ?'; $a[] = $id;
            } elseif ($tipo === 'Observation' && $nombre === 'device' && tipo_ref($uno) === 'DeviceMetric') {
                completar_ids_punto(); $q0 = pdo()->prepare('SELECT activo_id, punto FROM pim_punto WHERE id = ?'); $q0->execute([ref_id($uno, 'DeviceMetric', 'device')]);
                $pp = $q0->fetch(); $c[] = $pp ? '(activo_id = ? AND punto = ?)' : '1 = 0'; if ($pp) { $a[] = $pp['activo_id']; $a[] = $pp['punto']; }
            } elseif ($col === 'organization') {                                // todos los locales son del organismo del nodo
                $c[] = ref_id($uno, 'Organization', $nombre) === org_organismo_id() ? '1 = 1' : '1 = 0';
            } elseif ($col === 'parte_de' && $tipo === 'Location' && ref_id($uno, 'Location', $nombre) === estab_id()) {
                $c[] = 'parte_de IS NULL';                                          // hijos directos del establecimiento
            } elseif (isset(REFERENCIAS[$col])) {
                $c[] = "$col = ?"; $a[] = ref_id($uno, REFERENCIAS[$col], $nombre);
            } elseif ($col === 'loc_tipo') {                                      // type: facility → solo el establecimiento; texto → tipo físico
                [, $val] = token($uno); $tf = array_search(mb_strtolower($val), array_map('mb_strtolower', TIPO_FISICO_ES), true);
                $c[] = $tf !== false ? 'tipo_fisico = ?' : '1 = 0'; if ($tf !== false) $a[] = $tf;
            } elseif ($col === 'alcance') {
                $x = strtoupper($uno); $cods = array_keys(SISTEMA_ALCANCE, $x, true);
                $c[] = '(categoria_alcance = ?' . ($x === 'A' ? " OR (categoria_alcance IS NULL AND clase = 'equipo-medico')" : '')
                     . ($cods ? " OR (categoria_alcance IS NULL AND clase = 'sistema' AND tipo_codigo IN (" . implode(',', array_fill(0, count($cods), '?')) . '))' : '') . ')';
                $a[] = $x; array_push($a, ...$cods);
            } elseif ($col === 'activo') {
                $c[] = 'activo = ?'; $a[] = $uno === 'false' ? 0 : 1;
            } elseif ($nombre === 'identifier') {
                [$sys, $val] = token($uno);
                $colId = $tipo === 'Device' && $sys !== null ? (es_sistema($sys, 'eui64') ? 'eui64' : (es_sistema($sys, 'anmat-pm') ? 'anmat_pm' : $col)) : $col;
                $c[] = "$colId = ?"; $a[] = $colId === 'eui64' ? strtoupper($val) : $val;
            } elseif (in_array($nombre, ['business-status', 'code', 'type', 'interpretation'], true) && !in_array($tipo, ['Organization', 'Location'], true)) {
                [, $val] = token($uno); $c[] = "$col = ?"; $a[] = $val;
            } elseif (in_array($nombre, ['name', 'device-name', 'manufacturer'], true)) {
                if ($mod === 'exact') { $c[] = "$col = ?"; $a[] = $uno; }
                else { $c[] = "$col LIKE ?"; $a[] = ($mod === 'contains' ? '%' : '') . addcslashes($uno, '%_') . '%'; }
            } elseif ($nombre === 'type' && $tipo === 'Organization') {
                [, $val] = token($uno); $c[] = 'tipo = ?'; $a[] = ['prov' => 'organismo', 'facility' => 'organismo', 'dept' => 'area', 'bus' => 'empresa'][$val] ?? $val;
            } else { $c[] = "$col = ?"; $a[] = $uno; }
        }
        if ($c) $w[] = '(' . implode(' OR ', $c) . ')';
    }
    if ($tipo === 'Task' && ($o = org_de_cliente($cli))) { $w[] = 'responsable_id = ?'; $a[] = $o; }
    $tabla = RECURSOS[$tipo]['tabla'];
    $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
    $q = pdo()->prepare("SELECT COUNT(*) FROM $tabla$where"); $q->execute($a);
    $total = (int) $q->fetchColumn();
    $lista = [];
    if (!$soloTotal && $count > 0) {
        $colFecha = ['Task' => 'autoria', 'ServiceRequest' => 'autoria', 'Observation' => 'instante'][$tipo] ?? 'actualizado';
        $orden = match ($sort) { '_lastUpdated' => 'actualizado ASC, id ASC', '-_lastUpdated' => 'actualizado DESC, id DESC',
            'date', 'authored-on', 'authored' => "$colFecha ASC, id ASC", default => ($colFecha === 'actualizado' ? 'id ASC' : "$colFecha DESC, id DESC") };
        if ($tipo === 'Observation' && $sort === null) $orden = 'instante DESC, id DESC';
        $q = pdo()->prepare("SELECT * FROM $tabla$where ORDER BY $orden LIMIT $count OFFSET $offset"); $q->execute($a);
        $lista = array_map(RECURSOS[$tipo]['fhir'], $q->fetchAll());
    }
    if ($estab) { $total++; if (!$soloTotal && $offset === 0 && $count > 0) array_unshift($lista, estab_fhir()); }
    bundle_busqueda($tipo, $lista, $total, $usados, $count, $offset, $soloTotal, $sinTotal);
}
function bundle_busqueda(string $tipo, array $lista, int $total, array $usados, int $count, int $offset, bool $soloTotal, bool $sinTotal): never {
    $GLOBALS['iproy_pim_audit']['n'] = count($lista);
    $url = base() . '/' . $tipo;
    $qs = fn(array $x) => $url . '?' . implode('&', array_map(fn($p) => rawurlencode($p[0]) . '=' . rawurlencode($p[1]), array_merge($usados, $x)));
    $links = [['relation' => 'self', 'url' => $qs($offset ? [['_offset', (string) $offset]] : [])]];
    if (!$soloTotal && $offset + $count < $total) $links[] = ['relation' => 'next', 'url' => $qs([['_offset', (string) ($offset + $count)]])];
    if (!$soloTotal && $offset > 0) $links[] = ['relation' => 'previous', 'url' => $qs([['_offset', (string) max(0, $offset - $count)]])];
    $b = ['resourceType' => 'Bundle', 'id' => bin2hex(random_bytes(8)), 'meta' => ['lastUpdated' => date('c')], 'type' => 'searchset'];
    if (!$sinTotal || $soloTotal) $b['total'] = $total;
    $b['link'] = $links;
    if (!$soloTotal) $b['entry'] = array_map(fn($r) => ['fullUrl' => base() . '/' . $r['resourceType'] . '/' . $r['id'], 'resource' => $r, 'search' => ['mode' => 'match']], $lista);
    responder(200, $b);
}
/** ¿La búsqueda de Location alcanza al establecimiento (pim-establecimiento, mCSD FacilityLocation)? Sí si no filtra o si filtra por él. */
function incluir_establecimiento(array $ps): bool {
    $o = cfg()['organismo']; $ok = true;
    foreach ($ps as [$k, $v]) {
        [$n, $m] = array_pad(explode(':', $k, 2), 2, null);
        $ok = $ok && match ($n) {
            '_count', '_offset', '_summary', '_total', '_sort', '_format' => true,
            '_id' => in_array(estab_id(), explode(',', $v), true),
            'identifier' => in_array($o['codigo'], array_map(fn($x) => token($x)[1], explode(',', $v)), true),
            'type' => in_array('facility', array_map(fn($x) => token($x)[1], explode(',', $v)), true),
            'status' => in_array('active', explode(',', $v), true),
            'organization' => ref_id($v, 'Organization', 'organization') === org_organismo_id(),
            'name' => $m === 'exact' ? $v === $o['nombre'] : str_contains(normalizar($o['nombre']), normalizar($v)),
            default => false };
    }
    return $ok;
}
/** DeviceMetric?source=Device/x · type=<código> · identifier=<activo>/<punto> · _id */
function buscar_puntos(array $ps): never {
    completar_ids_punto();
    $w = []; $a = []; $count = (int) cfg()['count_def']; $offset = 0; $usados = [];
    foreach ($ps as [$k, $v]) {
        $n = explode(':', $k)[0];
        match ($n) {
            '_count' => $count = max(0, min((int) cfg()['max_count'], (int) $v)), '_offset' => $offset = max(0, (int) $v),
            '_id' => [$w[] = 'id = ?', $a[] = $v],
            'source' => [$w[] = 'activo_id = ?', $a[] = ref_id($v, 'Device', 'source')],
            'type' => [$w[] = 'punto = ?', $a[] = token($v)[1]],
            'identifier' => (function () use ($v, &$w, &$a) { [$cod, $pt] = array_pad(explode('/', token($v)[1], 2), 2, '');
                $w[] = 'punto = ? AND activo_id IN (SELECT id FROM pim_activo WHERE codigo = ?)'; $a[] = $pt; $a[] = $cod; })(),
            default => null };
        if (!str_starts_with($n, '_') || $n === '_id') $usados[] = [$k, $v];
    }
    $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
    $q = pdo()->prepare("SELECT COUNT(*) FROM pim_punto$where"); $q->execute($a); $total = (int) $q->fetchColumn();
    $q = pdo()->prepare("SELECT * FROM pim_punto$where ORDER BY activo_id, punto LIMIT $count OFFSET $offset"); $q->execute($a);
    bundle_busqueda('DeviceMetric', array_map('IproyPim\dm_fhir', $q->fetchAll()), $total, $usados, $count, $offset, false, false);
}
/** PIM-REG (PIM-9): Device/$refes — los cinco datos mínimos del REFES (Res. SGS 532/2026) de cada PMA y lo que falta. */
function op_refes(?string $id): never {
    $cli = exigir('Device', $id ? 'r' : 's');
    exigir_rol($cli, ['GM', 'RE', 'CI'], 'consultar los datos del REFES');
    $completos = $_GET['completos'] ?? null;
    $w = ["clase = 'equipo-medico'"]; $a = [];
    if ($id) { $w[] = 'id = ?'; $a[] = $id; }
    $q = pdo()->prepare('SELECT * FROM pim_activo WHERE ' . implode(' AND ', $w) . ' ORDER BY codigo LIMIT 5000'); $q->execute($a);
    $par = []; $n = 0; $nc = 0;
    foreach ($q->fetchAll() as $r) {
        $falta = [];
        if (!$r['fabricante']) $falta[] = 'marca';
        if (!$r['modelo']) $falta[] = 'modelo';
        if (!$r['fecha_fabricacion'] && !$r['puesta_en_marcha']) $falta[] = 'fecha de fabricación o de puesta en marcha';
        if (!$r['pertenencia']) $falta[] = 'pertenencia';
        if (!$r['anmat_pm']) $falta[] = 'n.º de producto ANMAT';
        $ok = !$falta; $n++; if ($ok) $nc++;
        if ($completos === 'true' && !$ok || $completos === 'false' && $ok) continue;
        $par[] = ['name' => 'pma', 'part' => array_values(quitar_nulos([
            ['name' => 'device', 'valueReference' => dev_ref($r['id'], $r['nombre'])],
            ['name' => 'codigo', 'valueString' => $r['codigo']],
            $r['fabricante'] ? ['name' => 'marca', 'valueString' => $r['fabricante']] : null,
            $r['modelo'] ? ['name' => 'modelo', 'valueString' => $r['modelo']] : null,
            $r['fecha_fabricacion'] ? ['name' => 'fechaFabricacion', 'valueDate' => $r['fecha_fabricacion']] : null,
            $r['puesta_en_marcha'] ? ['name' => 'puestaEnMarcha', 'valueDate' => $r['puesta_en_marcha']] : null,
            $r['pertenencia'] ? ['name' => 'pertenencia', 'valueCoding' => coding(cs('pim-valores'), $r['pertenencia'])] : null,
            $r['anmat_pm'] ? ['name' => 'anmatPM', 'valueString' => $r['anmat_pm']] : null,
            ['name' => 'completo', 'valueBoolean' => $ok],
            ...array_map(fn($x) => ['name' => 'falta', 'valueString' => $x], $falta)]))];
    }
    if ($id && !$n) throw new Fallo(404, 'not-found', "Device/$id no es un producto médico activo");
    $GLOBALS['iproy_pim_audit']['n'] = count($par);
    responder(200, ['resourceType' => 'Parameters', 'parameter' => array_merge([
        ['name' => 'total', 'valueInteger' => $n], ['name' => 'completos', 'valueInteger' => $nc],
        ['name' => 'norma', 'valueString' => 'Res. SGS 532/2026: marca, modelo, fecha de fabricación o puesta en marcha, pertenencia y n.º de producto ANMAT']], $par)]);
}
/** RiskAssessment?subject=Group/x-g · RiskAssessment?banda=A · Provenance?target=Task/x · Group?member=Device/x */
function buscar_especial(string $tipo, array $ps, array $cli): never {
    $f = []; foreach ($ps as [$k, $v]) $f[explode(':', $k)[0]] = $v;
    $count = max(0, min((int) cfg()['max_count'], (int) ($f['_count'] ?? cfg()['count_def']))); $offset = max(0, (int) ($f['_offset'] ?? 0));
    $lista = [];
    if ($tipo === 'Provenance') {
        if (empty($f['target'])) throw new Fallo(400, 'required', 'Provenance exige el parámetro target=<Tipo>/<id>');
        $t = tipo_ref($f['target']) ?? 'Task'; $id = ref_id($f['target'], array_keys(RECURSOS), 'target');
        if ($t === 'Task' && $cli['rol'] === 'EJ') { $r = fila('pim_orden', $id); if (!$r || $r['responsable_id'] !== org_de_cliente($cli)) throw new Fallo(404, 'not-found', 'No existe'); }
        $q = pdo()->prepare('SELECT recurso, id, version, accion, cliente, ts, motivo, huella FROM pim_version WHERE recurso = ? AND id = ? ORDER BY version'); $q->execute([$t, $id]);
        $lista = array_map(fn($v) => quitar_nulos(prov_fhir($v)), $q->fetchAll());
    } else {
        $w = ["(iru_f IS NOT NULL AND iru_m IS NOT NULL AND (iru_r IS NOT NULL OR categoria_riesgo IS NOT NULL))"]; $a = [];
        $suj = $f['subject'] ?? ($f['member'] ?? null);
        if ($suj) { $w[] = 'id = ?'; $a[] = preg_replace('/-(g|iru)$/', '', ref_id($suj, ['Group', 'Device'], 'subject')); }
        if (!empty($f['_id'])) { $w[] = 'id = ?'; $a[] = preg_replace('/-(g|iru)$/', '', $f['_id']); }
        $q = pdo()->prepare('SELECT * FROM pim_activo WHERE ' . implode(' AND ', $w) . ' ORDER BY codigo'); $q->execute($a);
        foreach ($q->fetchAll() as $r) {
            $x = $tipo === 'Group' ? grupo_fhir($r) : riesgo_fhir($r);
            if ($x && (empty($f['banda']) || ($tipo === 'RiskAssessment' && iru($r)['banda'] === $f['banda']))) $lista[] = $x;
        }
    }
    $total = count($lista);
    bundle_busqueda($tipo, array_slice($lista, $offset, $count), $total, [], $count, $offset, false, false);
}

// ================================================================== alta (POST)
function crear(string $tipo, ?array $j = null, bool $responder = true): array {
    $cli = exigir($tipo, 'c');
    exigir_rol($cli, RECURSOS[$tipo]['alta'] ?: ['-'], "dar de alta $tipo");
    $j ??= cuerpo_json();
    $p = pdo();
    $p->beginTransaction();
    try {
        $extra = [];
        switch ($tipo) {
            case 'Organization': $f = org_desde($j); $id = ulid(); insertar('pim_organizacion', ['id' => $id] + $f); $res = org_fhir(fila('pim_organizacion', $id)); break;
            case 'Location':     $f = loc_desde($j); $id = ulid(); insertar('pim_local', ['id' => $id] + $f); $res = loc_fhir(fila('pim_local', $id)); break;
            case 'Device':       $f = dev_desde($j); $pts = $f['_puntos'] ?? []; if (isset($f['_origen'])) $f['origen'] = $f['_origen']; unset($f['_puntos'], $f['_origen']);
                                 $id = ulid(); insertar('pim_activo', ['id' => $id] + $f);
                                 guardar_puntos($id, $pts); $res = dev_fhir(fila('pim_activo', $id)); break;
            case 'ServiceRequest': [$res, $task] = alta_solicitud(sr_desde($j), $cli); $extra = ['X-PIM-Task' => 'Task/' . $task['id']]; break;
            case 'Observation':  $res = alta_observacion(obs_desde($j), $cli); break;
            default: throw new Fallo(405, 'not-supported', "No se da de alta $tipo por la API");
        }
        if (in_array($tipo, ['Organization', 'Location', 'Device'], true)) guardar_version($tipo, $res['id'], 1, 'create', $cli['id'], $res);
        $p->commit();
    } catch (\PDOException $e) {
        $p->rollBack();
        if (($e->errorInfo[1] ?? 0) === 1062) throw new Fallo(409, 'duplicate', 'Ya existe un recurso con ese identificador');
        throw $e;
    } catch (\Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
    if (!$responder) return $res;
    responder(201, $res, MIME, ['Location' => base() . "/$tipo/{$res['id']}" . (isset($res['meta']['versionId']) ? "/_history/{$res['meta']['versionId']}" : '')] + cab_version($res) + $extra);
}
/** Puntos de medición del activo (rango aceptable para PIM-TEL). Reemplaza la lista; devuelve true si cambió. */
function guardar_puntos(string $activo, array $pts): bool {
    $q = pdo()->prepare("SELECT punto, unidad, minimo, maximo, genera_orden FROM pim_punto WHERE activo_id = ? AND origen = 'pim' ORDER BY punto"); $q->execute([$activo]);
    $norm = fn(array $l) => json_encode(array_map(fn($p) => [$p['punto'], $p['unidad'], $p['minimo'] === null ? null : (float) $p['minimo'], $p['maximo'] === null ? null : (float) $p['maximo'], (int) $p['genera_orden']],
        (function ($l) { usort($l, fn($a, $b) => strcmp($a['punto'], $b['punto'])); return $l; })($l)));
    if ($norm($q->fetchAll()) === $norm($pts)) return false;
    pdo()->prepare("DELETE FROM pim_punto WHERE activo_id = ? AND origen = 'pim'")->execute([$activo]);
    $i = pdo()->prepare("INSERT INTO pim_punto (id, activo_id, punto, unidad, minimo, maximo, genera_orden, origen) VALUES (?,?,?,?,?,?,?,'pim')
        ON DUPLICATE KEY UPDATE unidad = VALUES(unidad), minimo = VALUES(minimo), maximo = VALUES(maximo), genera_orden = VALUES(genera_orden), origen = 'pim'");
    foreach ($pts as $p) $i->execute([dm_id($activo, $p['punto']), $activo, $p['punto'], $p['unidad'], $p['minimo'], $p['maximo'], $p['genera_orden']]);
    return true;
}
function insertar(string $tabla, array $f): void {
    $c = array_keys($f);
    pdo()->prepare("INSERT INTO $tabla (`" . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($f));
}

/** PIM-SOL: la solicitud crea su orden. Prioridad: la pedida o, si no, la banda IRU del activo. */
function alta_solicitud(array $f, array $cli): array {
    $nodo = nodo_id();
    $idS = ulid();
    $numS = $nodo . '-SOL-' . str_pad((string) numero_nuevo('sol-' . $nodo), 6, '0', STR_PAD_LEFT);
    $prio = $f['prioridad'];
    $motivoPrio = null;
    if ($prio === null && $f['activo_id'] && ($a = fila('pim_activo', $f['activo_id'])) && ($i = iru($a, $f['estado_declarado']))) {
        $prio = BANDA_PRIORIDAD[$i['banda']]; $motivoPrio = 'Prioridad por IRU: banda ' . $i['banda'] . ' (' . $i['total'] . ')';
    }
    $prio ??= 'routine';
    insertar('pim_solicitud', ['id' => $idS, 'numero' => $numS, 'prioridad' => $prio, 'categoria_codigo' => $f['categoria_codigo'], 'categoria_texto' => $f['categoria_texto'],
        'descripcion' => $f['descripcion'], 'local_id' => $f['local_id'], 'activo_id' => $f['activo_id'], 'estado_declarado' => $f['estado_declarado'],
        'solicitante' => $f['solicitante'], 'cliente' => $cli['id'], 'observacion_id' => $f['observacion_id'] ?? null]);
    $sr = sr_fhir(fila('pim_solicitud', $idS));
    guardar_version('ServiceRequest', $idS, 1, 'create', $cli['id'], $sr);
    $resp = null;
    if (($d = (string) cfg()['responsable_defecto']) !== '') { $q = pdo()->prepare("SELECT id FROM pim_organizacion WHERE codigo = ?"); $q->execute([$d]); $resp = $q->fetchColumn() ?: null; }
    $idT = ulid();
    insertar('pim_orden', ['id' => $idT, 'numero' => $nodo . '-' . str_pad((string) numero_nuevo('ot-' . $nodo), 6, '0', STR_PAD_LEFT),
        'solicitud_id' => $idS, 'activo_id' => $f['activo_id'], 'local_id' => $f['local_id'], 'responsable_id' => $resp,
        'estado' => 'requested', 'estado_negocio' => 'solicitada', 'motivo_estado' => $motivoPrio, 'prioridad' => $prio,
        'categoria_codigo' => $f['categoria_codigo'], 'categoria_texto' => $f['categoria_texto'], 'descripcion' => $f['descripcion']]);
    $task = task_fhir(fila('pim_orden', $idT));
    if (!empty($f['observacion_id'])) {                                        // Task.reasonReference → la medición que la originó
        pdo()->prepare('UPDATE pim_observacion SET orden_id = ? WHERE id = ? AND orden_id IS NULL')->execute([$idT, $f['observacion_id']]);
        $task = task_fhir(fila('pim_orden', $idT));
    }
    guardar_version('Task', $idT, 1, 'create', $cli['id'], $task, 'Alta desde ServiceRequest/' . $idS);
    $sr = sr_fhir(fila('pim_solicitud', $idS));                                // ahora con la extensión pim-orden-generada
    return [$sr, $task];
}

/** PIM-TEL: guarda la medición; si sale del rango (del punto configurado o del referenceRange) genera solicitud + orden. */
function alta_observacion(array $f, array $cli): array {
    $q = pdo()->prepare('SELECT * FROM pim_punto WHERE activo_id = ? AND punto = ?'); $q->execute([$f['activo_id'], $f['punto']]);
    $pt = $q->fetch() ?: null;
    if (!$pt) {                                                                // el punto aparece con su primera medición (DeviceMetric automático)
        $mdc = ctype_digit($f['punto']) || str_starts_with($f['punto'], 'MDCX');
        pdo()->prepare('INSERT IGNORE INTO pim_punto (id, activo_id, punto, unidad, genera_orden, origen) VALUES (?,?,?,?,?,?)')
            ->execute([dm_id($f['activo_id'], $f['punto']), $f['activo_id'], $f['punto'], $f['unidad'] ?: '1', $mdc ? 0 : 1, $f['origen'] === 'mem-dmc' ? 'mem-dmc' : 'auto']);
    }
    $interp = null; $min = null; $max = null;
    if ($f['valor_tipo'] === 'q') {
        $min = $f['minimo'] ?? ($pt['minimo'] ?? null); $max = $f['maximo'] ?? ($pt['maximo'] ?? null);
        if ($min !== null || $max !== null) $interp = ($min !== null && $f['valor'] < (float) $min) ? 'L' : (($max !== null && $f['valor'] > (float) $max) ? 'H' : 'N');
    }
    $id = ulid();
    insertar('pim_observacion', ['id' => $id, 'activo_id' => $f['activo_id'], 'punto' => $f['punto'], 'valor' => $f['valor'], 'unidad' => $f['unidad'],
        'valor_tipo' => $f['valor_tipo'], 'valor_texto' => $f['valor_texto'], 'origen' => $f['origen'],
        'instante' => $f['instante'], 'interpretacion' => $interp, 'minimo' => $min, 'maximo' => $max, 'cliente' => $cli['id']]);
    if (in_array($interp, ['H', 'L'], true) && cfg()['regla_telemetria'] && (int) ($pt['genera_orden'] ?? 1) === 1) {
        $cat = 'TEL-' . $f['punto'];
        $q = pdo()->prepare("SELECT id FROM pim_orden WHERE activo_id = ? AND categoria_codigo = ? AND estado NOT IN ('completed','rejected','cancelled') LIMIT 1");
        $q->execute([$f['activo_id'], $cat]);
        $abierta = $q->fetchColumn();
        if (!$abierta) {
            $a = fila('pim_activo', $f['activo_id']);
            [, $task] = alta_solicitud(['prioridad' => null, 'categoria_codigo' => $cat, 'categoria_texto' => 'Medición fuera de rango · ' . $f['punto'],
                'descripcion' => sprintf('Medición fuera de rango en %s: %s = %s %s (rango %s–%s) el %s', $a['nombre'], $f['punto'], rtrim(rtrim(number_format($f['valor'], 4, '.', ''), '0'), '.'),
                    $f['unidad'], $min ?? '—', $max ?? '—', $f['instante']),
                'local_id' => $a['local_id'], 'activo_id' => $a['id'], 'estado_declarado' => 'degradado', 'solicitante' => 'Telemetría (' . $cli['id'] . ')',
                'observacion_id' => $id], $cli);
            $abierta = $task['id'];
        }
        pdo()->prepare('UPDATE pim_observacion SET orden_id = ? WHERE id = ?')->execute([$abierta, $id]);
    }
    return obs_fhir(fila('pim_observacion', $id));
}

/** Bundle batch (POST /pim/r4): ServiceRequest y Observation. Cada entrada es independiente. */
function lote(): never {
    $j = cuerpo_json();
    if (($j['resourceType'] ?? '') !== 'Bundle' || ($j['type'] ?? '') !== 'batch') throw new Fallo(422, 'invalid', 'Se esperaba un Bundle de tipo batch');
    $ent = (array) ($j['entry'] ?? []);
    if (count($ent) > 500) throw new Fallo(413, 'too-costly', 'Máximo 500 entradas por lote');
    $out = [];
    foreach ($ent as $e) {
        $r = $e['resource'] ?? null; $t = $r['resourceType'] ?? '';
        try {
            if (($e['request']['method'] ?? '') !== 'POST' || !in_array($t, ['Observation', 'ServiceRequest'], true))
                throw new Fallo(422, 'not-supported', 'El lote admite solo POST de Observation y ServiceRequest');
            $res = crear($t, $r, false);
            $out[] = ['resource' => $res, 'response' => ['status' => '201 Created', 'location' => "$t/{$res['id']}"]];
        } catch (Fallo $f) {
            $out[] = ['response' => ['status' => $f->http . ' ' . $f->tipo, 'outcome' => oo($f->tipo, $f->getMessage(), 'error', $f->expresion)]];
        }
    }
    $GLOBALS['iproy_pim_audit']['n'] = count($out);
    responder(200, ['resourceType' => 'Bundle', 'id' => bin2hex(random_bytes(8)), 'type' => 'batch-response', 'entry' => $out]);
}

// ================================================================== modificación (PUT con If-Match)
function actualizar(string $tipo, string $id): never {
    $cli = exigir($tipo, 'u');
    exigir_rol($cli, RECURSOS[$tipo]['modif'] ?: ['-'], "modificar $tipo");
    $j = cuerpo_json();
    if (($j['resourceType'] ?? '') !== $tipo || ($j['id'] ?? '') !== $id) throw new Fallo(400, 'invalid', 'resourceType e id del cuerpo deben coincidir con la URL');
    $ver = if_match() ?? throw new Fallo(428, 'required', 'PIM exige If-Match: W/"<versión>" para modificar (control de concurrencia)');
    $p = pdo(); $p->beginTransaction();
    try {
        $tabla = RECURSOS[$tipo]['tabla'];
        $r = fila($tabla, $id, true) ?? throw new Fallo(404, 'not-found', "$tipo/$id no existe");
        solo_lectura_legado($tipo, $r);
        if ((int) $r['version'] !== $ver) throw new Fallo(412, 'conflict', "La versión actual es {$r['version']}: releé el recurso antes de modificarlo");
        $motivo = null;
        if ($tipo === 'Task') {
            [$set, $motivo] = task_cambios($j, $r, $cli);
        } else {
            $f = match ($tipo) { 'Organization' => org_desde($j), 'Location' => loc_desde($j), 'Device' => dev_desde($j) };
            $pts = $f['_puntos'] ?? null; unset($f['_puntos'], $f['_origen']);
            if ($tipo === 'Device' && $pts !== null && guardar_puntos($id, $pts)) $f['actualizado'] = (new \DateTimeImmutable('now', new \DateTimeZone('-03:00')))->format('Y-m-d H:i:s.v');
            $set = array_diff_assoc(array_map(fn($x) => $x === null ? null : (string) $x, $f), array_map(fn($x) => $x === null ? null : (string) $x, array_intersect_key($r, $f)));
            $set = array_intersect_key($f, $set);
        }
        if (!$set) { $p->rollBack(); $res = (RECURSOS[$tipo]['fhir'])($r); responder(200, $res, MIME, cab_version($res)); }
        aplicar_cambios($tabla, $id, $set);
        $res = (RECURSOS[$tipo]['fhir'])(fila($tabla, $id));
        guardar_version($tipo, $id, (int) $res['meta']['versionId'], 'update', $cli['id'], $res, $motivo);
        $p->commit();
    } catch (\Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
    responder(200, $res, MIME, cab_version($res));
}
/** Los registros importados de iProy se gestionan en iProy (fuente de verdad, D-M3): en PIM son de solo lectura. */
function solo_lectura_legado(string $tipo, array $r): void {
    if (($r['origen'] ?? 'pim') === 'legado' && cfg()['legado_solo_lectura'])
        throw new Fallo(409, 'business-rule', "$tipo/{$r['id']} viene de iProy y se gestiona allí (el servidor PIM lo refleja en solo lectura). Las altas nuevas por PIM sí se modifican aquí.");
}
function aplicar_cambios(string $tabla, string $id, array $set): void {
    $s = []; $v = [];
    foreach ($set as $c => $x) { $s[] = "`$c` = ?"; $v[] = $x; }
    $v[] = $id;
    pdo()->prepare("UPDATE $tabla SET " . implode(', ', $s) . ', version = version + 1 WHERE id = ?')->execute($v);
}

/** Qué cambió en una Task enviada por PUT: estado (con la máquina de estados), responsable, prioridad, tipo, falla, notas. */
function task_cambios(array $j, array $r, array $cli): array {
    sin_pacientes($j);
    foreach (['basedOn', 'focus', 'authoredOn', 'location'] as $inm) {
        $antes = task_fhir($r)[$inm] ?? null; $ahora = $j[$inm] ?? null;
        if (json_encode($antes['reference'] ?? $antes) !== json_encode($ahora['reference'] ?? ($ahora[0]['reference'] ?? $ahora)) && !($inm === 'basedOn' && json_encode($antes) === json_encode($ahora)))
            if ($ahora !== null && $antes !== null && json_encode($antes) !== json_encode($ahora)) throw new Fallo(422, 'business-rule', "Task.$inm no se puede modificar", "Task.$inm");
    }
    $nuevoEstado = (string) ($j['businessStatus']['coding'][0]['code'] ?? $r['estado_negocio']);
    $params = ['estado' => $nuevoEstado, 'motivo' => $j['statusReason']['text'] ?? null,
        'responsable' => $j['owner']['reference'] ?? null, 'prioridad' => $j['priority'] ?? null,
        'tipoMantenimiento' => null, 'modoFalla' => null, 'nota' => null];
    foreach ((array) ($j['extension'] ?? []) as $e) {
        if (($e['url'] ?? '') === sd('pim-tipo-mantenimiento')) $params['tipoMantenimiento'] = $e['valueCoding']['code'] ?? null;
        if (($e['url'] ?? '') === sd('pim-modo-falla')) $params['modoFalla'] = $e['valueCoding']['code'] ?? null;
    }
    $notasAntes = count(task_fhir($r)['note'] ?? []);
    $notas = array_slice((array) ($j['note'] ?? []), $notasAntes);         // solo se agregan notas (nunca se borran)
    return transicion_set($r, $params, $cli, array_map(fn($n) => (string) ($n['text'] ?? ''), $notas));
}

/** Núcleo de PIM-OT: valida la transición (Tabla 9-2), el rol y los datos obligatorios; devuelve [set, motivo]. */
function transicion_set(array $r, array $p, array $cli, array $notas = []): array {
    $set = []; $motivo = null;
    $ej = $cli['rol'] === 'EJ';
    if ($ej && $r['responsable_id'] !== org_de_cliente($cli)) throw new Fallo(404, 'not-found', "Task/{$r['id']} no existe");
    $de = $r['estado_negocio']; $a = (string) ($p['estado'] ?? $de);
    if ($a !== $de) {
        if (!isset(ESTADOS_ORDEN[$a])) throw new Fallo(422, 'code-invalid', "Estado desconocido: $a. Válidos: " . implode(', ', array_keys(ESTADOS_ORDEN)), 'Task.businessStatus');
        $quien = TRANSICIONES[$de][$a] ?? null;
        if ($quien === null) throw new Fallo(422, 'business-rule', "Transición no permitida: $de → $a. Desde «{$de}» se puede pasar a: " . (implode(', ', array_keys(TRANSICIONES[$de])) ?: 'ningún estado (terminal)'), 'Task.businessStatus');
        if ($quien === 'GM' && $cli['rol'] !== 'GM') throw new Fallo(403, 'forbidden', "Solo el Gestor de Mantenimiento puede pasar la orden a «{$a}»");
        if ($quien === 'EJ' && !in_array($cli['rol'], ['EJ', 'GM'], true)) throw new Fallo(403, 'forbidden', "Solo el Ejecutor o el Gestor pueden pasar la orden a «{$a}»");
        $motivo = trim((string) ($p['motivo'] ?? ''));
        if (in_array($a, CON_MOTIVO, true) && $motivo === '') throw new Fallo(422, 'required', "Pasar a «{$a}» exige un motivo (statusReason)", 'Task.statusReason');
        if ($a === 'asignada' && !$r['responsable_id'] && empty($p['responsable'])) throw new Fallo(422, 'required', 'Para asignar hace falta el responsable (owner = Organization)', 'Task.owner');
        $tipoM = $p['tipoMantenimiento'] ?? $r['tipo_mantenimiento'];
        if ($a === 'finalizada' && str_starts_with((string) $tipoM, 'correctivo') && !($p['modoFalla'] ?? $r['modo_falla']))
            throw new Fallo(422, 'required', 'Una orden correctiva se finaliza con el modo de falla (extensión pim-modo-falla, ISO 14224)', 'Task.extension');
        $set['estado_negocio'] = $a; $set['estado'] = ESTADOS_ORDEN[$a]; $set['motivo_estado'] = $motivo !== '' ? mb_substr($motivo, 0, 255) : null;
        if ($a === 'en-ejecucion' && !$r['inicio']) $set['inicio'] = (new \DateTimeImmutable('now', new \DateTimeZone('-03:00')))->format('Y-m-d H:i:s.v');
        if ($a === 'finalizada') $set['fin'] = (new \DateTimeImmutable('now', new \DateTimeZone('-03:00')))->format('Y-m-d H:i:s.v');
        if ($a === 'en-ejecucion' && $de === 'finalizada') $set['fin'] = null;
    }
    if (!empty($p['responsable'])) {
        $o = ref_id((string) $p['responsable'], 'Organization', 'Task.owner');
        if ($o !== $r['responsable_id']) {
            if ($cli['rol'] !== 'GM') throw new Fallo(403, 'forbidden', 'Solo el Gestor de Mantenimiento cambia el responsable');
            if (!existe('pim_organizacion', $o)) throw new Fallo(422, 'not-found', 'Organization inexistente', 'Task.owner');
            $set['responsable_id'] = $o;
        }
    }
    if (!empty($p['prioridad']) && $p['prioridad'] !== $r['prioridad']) {
        if (!in_array($p['prioridad'], PRIORIDADES, true)) throw new Fallo(422, 'code-invalid', 'priority: routine | urgent | asap | stat', 'Task.priority');
        if ($cli['rol'] !== 'GM') throw new Fallo(403, 'forbidden', 'Solo el Gestor de Mantenimiento cambia la prioridad');
        $set['prioridad'] = $p['prioridad'];
    }
    if (!empty($p['tipoMantenimiento']) && $p['tipoMantenimiento'] !== $r['tipo_mantenimiento']) {
        if (!in_array($p['tipoMantenimiento'], TIPOS_MANT, true)) throw new Fallo(422, 'code-invalid', 'Tipo de mantenimiento: ' . implode(' | ', TIPOS_MANT));
        $set['tipo_mantenimiento'] = $p['tipoMantenimiento'];
    }
    if (!empty($p['modoFalla']) && $p['modoFalla'] !== $r['modo_falla']) {
        if (!preg_match('/^[A-Z]{3}$/', (string) $p['modoFalla'])) throw new Fallo(422, 'code-invalid', 'Modo de falla: código de 3 letras de ISO 14224 (p. ej. FTS, ELP, VIB, OHE)');
        $set['modo_falla'] = $p['modoFalla'];
    }
    foreach ($notas as $n) {
        $n = trim($n); if ($n === '') continue;
        pdo()->prepare('INSERT INTO pim_nota (orden_id, texto, autor) VALUES (?,?,?)')->execute([$r['id'], mb_substr($n, 0, 1000), $cli['id']]);
        $set['actualizado'] = (new \DateTimeImmutable('now', new \DateTimeZone('-03:00')))->format('Y-m-d H:i:s.v');   // la nota también es un cambio (nueva versión)
    }
    return [$set, $motivo ?: ($notas ? 'Nota agregada' : null)];
}

/** POST /Task/{id}/$transicion con Parameters (estado, motivo, responsable, prioridad, tipoMantenimiento, modoFalla, nota). */
function op_transicion(string $id): never {
    $cli = exigir('Task', 'u');
    exigir_rol($cli, ['GM', 'EJ'], 'cambiar el estado de una orden');
    $j = cuerpo_json();
    if (($j['resourceType'] ?? '') !== 'Parameters') throw new Fallo(422, 'invalid', 'Se esperaba un recurso Parameters');
    $p = []; $notas = [];
    foreach ((array) ($j['parameter'] ?? []) as $x) {
        $n = (string) ($x['name'] ?? '');
        $v = $x['valueCode'] ?? $x['valueString'] ?? ($x['valueReference']['reference'] ?? null);
        if ($n === 'nota') $notas[] = (string) $v; elseif ($n !== '') $p[$n] = $v;
    }
    $ver = if_match();
    $db = pdo(); $db->beginTransaction();
    try {
        $r = fila('pim_orden', $id, true) ?? throw new Fallo(404, 'not-found', "Task/$id no existe");
        solo_lectura_legado('Task', $r);
        if ($ver !== null && (int) $r['version'] !== $ver) throw new Fallo(412, 'conflict', "La versión actual es {$r['version']}");
        [$set, $motivo] = transicion_set($r, $p, $cli, $notas);
        if ($set) {
            aplicar_cambios('pim_orden', $id, $set);
            $res = task_fhir(fila('pim_orden', $id));
            guardar_version('Task', $id, (int) $res['meta']['versionId'], 'update', $cli['id'], $res, $motivo);
            if (in_array($set['estado_negocio'] ?? '', ['conformada', 'rechazada', 'anulada'], true) && $r['solicitud_id']) {
                $nuevo = ($set['estado_negocio'] === 'conformada') ? 'completed' : 'revoked';
                $db->prepare('UPDATE pim_solicitud SET estado = ?, version = version + 1 WHERE id = ?')->execute([$nuevo, $r['solicitud_id']]);
                $s = fila('pim_solicitud', $r['solicitud_id']);
                guardar_version('ServiceRequest', $s['id'], (int) $s['version'], 'update', $cli['id'], sr_fhir($s), 'Orden ' . $set['estado_negocio']);
            }
        } else $res = task_fhir($r);
        $db->commit();
    } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    responder(200, $res, MIME, cab_version($res));
}

// ================================================================== historial (PIM-FED)
function historial(string $tipo, ?string $id): never {
    $cli = exigir($tipo, 'r');
    $w = ['recurso = ?']; $a = [$tipo];
    if ($id !== null) { $w[] = 'id = ?'; $a[] = $id; }
    if (!empty($_GET['_since'])) { $f = fecha_param('ge' . preg_replace('/^(ge|gt)/', '', (string) $_GET['_since'])); $w[] = 'ts >= ?'; $a[] = $f[1]; }
    $count = max(1, min((int) cfg()['max_count'], (int) ($_GET['_count'] ?? cfg()['count_def'])));
    if ($tipo === 'Task' && ($o = org_de_cliente($cli))) { $w[] = 'id IN (SELECT id FROM pim_orden WHERE responsable_id = ?)'; $a[] = $o; }
    $q = pdo()->prepare('SELECT * FROM pim_version WHERE ' . implode(' AND ', $w) . " ORDER BY ts DESC, version DESC LIMIT $count"); $q->execute($a);
    $e = [];
    foreach ($q->fetchAll() as $v) $e[] = ['fullUrl' => base() . "/$tipo/{$v['id']}", 'resource' => json_decode($v['contenido'], true),
        'request' => ['method' => $v['accion'] === 'create' ? 'POST' : 'PUT', 'url' => "$tipo/{$v['id']}"],
        'response' => ['status' => $v['accion'] === 'create' ? '201 Created' : '200 OK', 'lastModified' => iso($v['ts'])]];
    $GLOBALS['iproy_pim_audit']['n'] = count($e);
    responder(200, ['resourceType' => 'Bundle', 'id' => bin2hex(random_bytes(8)), 'type' => 'history', 'total' => count($e), 'entry' => $e]);
}
