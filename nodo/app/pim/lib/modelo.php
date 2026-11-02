<?php
/**
 * iProy · Servidor PIM v1.0 · modelo: filas de iproy_pim ⇄ recursos FHIR R4 con perfiles PIM v0.9 · 30-10-2026
 *   Organization (pim-organismo, pim-empresa) · Location (pim-local) · Device (pim-sistema, pim-activo, equipo médico)
 *   ServiceRequest (pim-solicitud) · Task (pim-orden) · Observation (pim-medicion)
 *   RiskAssessment (pim-riesgo) + Group · Provenance (pim-procedencia)
 * Regla PIM (Tabla 10-1): ningún recurso contiene datos de pacientes; las referencias a Patient se rechazan.
 */
declare(strict_types=1);

namespace IproyPim;

// ================================================================== catálogos (CodeSystems PIM)
const ESTADOS_ORDEN = [   // estado de negocio → Task.status (Tabla 9-2)
    'solicitada' => 'requested', 'en-revision' => 'requested', 'asignada' => 'received', 'aceptada' => 'accepted',
    'lista' => 'ready', 'en-ejecucion' => 'in-progress', 'en-espera' => 'on-hold', 'finalizada' => 'completed',
    'conformada' => 'completed', 'rechazada' => 'rejected', 'anulada' => 'cancelled',
];
/** Transiciones permitidas y quién puede hacerlas (GM siempre; EJ solo en órdenes de su organización). */
const TRANSICIONES = [
    'solicitada'   => ['en-revision' => 'GM', 'asignada' => 'GM', 'rechazada' => 'GM', 'anulada' => 'GM'],
    'en-revision'  => ['asignada' => 'GM', 'rechazada' => 'GM', 'anulada' => 'GM'],
    'asignada'     => ['aceptada' => 'EJ', 'en-revision' => 'EJ', 'anulada' => 'GM'],
    'aceptada'     => ['lista' => 'EJ', 'en-ejecucion' => 'EJ', 'en-espera' => 'EJ', 'anulada' => 'GM'],
    'lista'        => ['en-ejecucion' => 'EJ', 'en-espera' => 'EJ', 'anulada' => 'GM'],
    'en-ejecucion' => ['en-espera' => 'EJ', 'finalizada' => 'EJ'],
    'en-espera'    => ['en-ejecucion' => 'EJ', 'aceptada' => 'EJ', 'anulada' => 'GM'],
    'finalizada'   => ['conformada' => 'GM', 'en-ejecucion' => 'GM'],
    'conformada'   => [], 'rechazada' => [], 'anulada' => [],
];
const CON_MOTIVO = ['en-espera', 'rechazada', 'anulada', 'en-revision'];
const PRIORIDADES = ['routine', 'urgent', 'asap', 'stat'];
const BANDA_PRIORIDAD = ['A' => 'stat', 'B' => 'asap', 'C' => 'urgent', 'D' => 'routine'];
const TIPOS_MANT = ['correctivo-inmediato', 'correctivo-diferido', 'preventivo-sistematico', 'preventivo-condicional', 'mejora', 'ensayo-reglamentario'];
const SISTEMAS = ['GAS-O2' => 'Oxígeno medicinal', 'GAS-AIRE' => 'Aire medicinal', 'GAS-VAC' => 'Vacío medicinal', 'GAS-N2O' => 'Óxido nitroso',
    'GAS-CO2' => 'Dióxido de carbono', 'GAS-AGSS' => 'Evacuación de gases anestésicos', 'ELE-EMER' => 'Energía de emergencia', 'ELE-UPS' => 'Sistemas de alimentación ininterrumpida',
    'ELE-ITM' => 'Red IT médica', 'ELE-NORM' => 'Distribución eléctrica normal e iluminación', 'HVAC-QX' => 'Climatización de quirófanos', 'HVAC-AIS' => 'Salas de aislamiento',
    'HVAC-GRAL' => 'Climatización general', 'FIL-HEPA' => 'Filtración absoluta', 'EST-VAP' => 'Esterilización y vapor', 'AGU-DIAL' => 'Agua para hemodiálisis',
    'AGU-SAN' => 'Agua sanitaria', 'FRIO-CONS' => 'Frío de conservación', 'INC-DET' => 'Detección de incendio', 'INC-EXT' => 'Extinción de incendio',
    'TRV-ASC' => 'Transporte vertical', 'COM-LLAM' => 'Llamado de enfermería', 'GAS-NAT' => 'Gas natural', 'EDI-GRAL' => 'Edificio y obra civil'];
const ESTADO_DECLARADO = ['operativo-con-falla', 'degradado', 'fuera-de-servicio'];
const GRUPOS_LOCAL = ['0', '1', '2a', '2b'];
const TIPO_FISICO = ['bu' => 'Building', 'wi' => 'Wing', 'lvl' => 'Level', 'ro' => 'Room', 'bd' => 'Bed', 'area' => 'Area'];

function coding(string $sys, string $code, ?string $disp = null): array { return array_filter(['system' => $sys, 'code' => $code, 'display' => $disp], fn($x) => $x !== null); }
function meta(array $r, string $perfil): array {
    return array_filter(['versionId' => isset($r['version']) ? (string) $r['version'] : null,
        'lastUpdated' => iso($r['actualizado'] ?? null), 'profile' => [sd($perfil)],
        'tag' => ($r['origen'] ?? '') === 'legado' ? [coding(cs('pim-origen'), 'legado', 'Reflejado de iProy (solo lectura en PIM)')] : null], fn($x) => $x !== null);
}
function quitar_nulos(array $a): array { return array_filter($a, fn($x) => $x !== null && $x !== [] && $x !== ''); }
function org_ref(?string $id, ?string $disp = null): ?array { return $id ? quitar_nulos(['reference' => 'Organization/' . $id, 'display' => $disp]) : null; }
function loc_ref(?string $id, ?string $disp = null): ?array { return $id ? quitar_nulos(['reference' => 'Location/' . $id, 'display' => $disp]) : null; }
function dev_ref(?string $id, ?string $disp = null): ?array { return $id ? quitar_nulos(['reference' => 'Device/' . $id, 'display' => $disp]) : null; }

/** Recorre todo el recurso y rechaza cualquier referencia a Patient (PIM, Tabla 10-1). */
function sin_pacientes(array $r): void {
    array_walk_recursive($r, function ($v, $k) {
        if ($k === 'reference' && is_string($v) && preg_match('#(^|/)(Patient|RelatedPerson|Person)/#', $v))
            throw new Fallo(422, 'business-rule', 'PIM no admite datos de pacientes ni de personas: use Location o Device', 'reference');
    });
}
function nombre_de(string $tabla, ?string $id): ?string {
    if (!$id) return null;
    static $cache = [];
    $k = "$tabla|$id";
    if (!array_key_exists($k, $cache)) { $q = pdo()->prepare("SELECT nombre FROM $tabla WHERE id = ?"); $q->execute([$id]); $cache[$k] = $q->fetchColumn() ?: null; }
    return $cache[$k];
}
function existe(string $tabla, string $id): bool { $q = pdo()->prepare("SELECT 1 FROM $tabla WHERE id = ?"); $q->execute([$id]); return (bool) $q->fetchColumn(); }

// ================================================================== Organization
function org_fhir(array $r): array {
    $t = match ($r['tipo']) { 'organismo' => ['prov', 'Healthcare Provider'], 'area' => ['dept', 'Hospital Department'], default => ['bus', 'Non-Healthcare Business or Corporation'] };
    $ids = [['use' => 'official', 'system' => sid($r['tipo'] === 'organismo' ? 'organismo' : 'empresa'), 'value' => $r['codigo']]];
    if (!empty($r['refes']) && cfg()['organismo']['refes_system'] !== '') $ids[] = ['system' => cfg()['organismo']['refes_system'], 'value' => $r['refes']];
    return quitar_nulos(['resourceType' => 'Organization', 'id' => $r['id'], 'meta' => meta($r, $r['tipo'] === 'organismo' ? 'pim-organismo' : 'pim-empresa'),
        'identifier' => $ids, 'active' => (bool) $r['activo'],
        'type' => [['coding' => [coding('http://terminology.hl7.org/CodeSystem/organization-type', $t[0], $t[1])]]],
        'name' => $r['nombre'], 'partOf' => org_ref($r['parte_de'])]);
}
function org_desde(array $j): array {
    if (($j['resourceType'] ?? '') !== 'Organization') throw new Fallo(422, 'invalid', 'Se esperaba Organization');
    $cod = null; foreach ((array) ($j['identifier'] ?? []) as $i) if (es_sistema((string) ($i['system'] ?? ''), 'empresa')) $cod = trim((string) ($i['value'] ?? ''));
    if (!$cod) throw new Fallo(422, 'required', 'identifier con system ' . sid('empresa') . ' es obligatorio', 'Organization.identifier');
    $nombre = trim((string) ($j['name'] ?? '')); if ($nombre === '') throw new Fallo(422, 'required', 'name es obligatorio', 'Organization.name');
    $tipo = (($j['type'][0]['coding'][0]['code'] ?? 'bus') === 'dept') ? 'area' : 'empresa';
    return ['tipo' => $tipo, 'codigo' => mb_substr($cod, 0, 60), 'nombre' => mb_substr($nombre, 0, 160), 'activo' => ($j['active'] ?? true) ? 1 : 0,
            'parte_de' => isset($j['partOf']['reference']) ? ref_id($j['partOf']['reference'], 'Organization', 'Organization.partOf') : null];
}

// ================================================================== Location (pim-local)
function loc_fhir(array $r): array {
    $ext = [];
    if ($r['grupo_uso_medico'] !== null) $ext[] = ['url' => sd('pim-grupo-uso-medico'), 'valueCoding' => coding(cs('pim-grupo-local'), $r['grupo_uso_medico'])];
    $amb = quitar_nulos([
        $r['clase_iso14644'] !== null ? ['url' => 'claseISO14644', 'valueString' => $r['clase_iso14644']] : null,
        $r['presion_relativa'] !== null ? ['url' => 'presionRelativa', 'valueCode' => $r['presion_relativa']] : null,
        $r['renovaciones_h'] !== null ? ['url' => 'renovacionesHora', 'valueDecimal' => (float) $r['renovaciones_h']] : null]);
    if ($amb) $ext[] = ['url' => sd('pim-ambiente-controlado'), 'extension' => array_values($amb)];
    return quitar_nulos(['resourceType' => 'Location', 'id' => $r['id'], 'meta' => meta($r, 'pim-local'), 'extension' => $ext ?: null,
        'identifier' => [['use' => 'official', 'system' => sid('local'), 'value' => $r['codigo']]],
        'status' => $r['estado'], 'name' => $r['nombre'], 'mode' => 'instance',
        'physicalType' => ['coding' => [coding('http://terminology.hl7.org/CodeSystem/location-physical-type', $r['tipo_fisico'], TIPO_FISICO[$r['tipo_fisico']] ?? null)]],
        'managingOrganization' => org_ref(org_organismo_id()), 'partOf' => loc_ref($r['parte_de'])]);
}
function loc_desde(array $j): array {
    if (($j['resourceType'] ?? '') !== 'Location') throw new Fallo(422, 'invalid', 'Se esperaba Location');
    $cod = null; foreach ((array) ($j['identifier'] ?? []) as $i) if (es_sistema((string) ($i['system'] ?? ''), 'local')) $cod = trim((string) ($i['value'] ?? ''));
    if (!$cod) throw new Fallo(422, 'required', 'identifier con system ' . sid('local') . ' es obligatorio', 'Location.identifier');
    $nombre = trim((string) ($j['name'] ?? '')); if ($nombre === '') throw new Fallo(422, 'required', 'name es obligatorio', 'Location.name');
    $tf = (string) ($j['physicalType']['coding'][0]['code'] ?? 'area'); if (!isset(TIPO_FISICO[$tf])) throw new Fallo(422, 'code-invalid', "physicalType inválido: $tf", 'Location.physicalType');
    $f = ['codigo' => mb_substr($cod, 0, 60), 'nombre' => mb_substr($nombre, 0, 160), 'tipo_fisico' => $tf,
          'estado' => in_array($j['status'] ?? 'active', ['active', 'inactive', 'suspended'], true) ? ($j['status'] ?? 'active') : 'active',
          'parte_de' => isset($j['partOf']['reference']) ? ref_id($j['partOf']['reference'], 'Location', 'Location.partOf') : null,
          'grupo_uso_medico' => null, 'clase_iso14644' => null, 'presion_relativa' => null, 'renovaciones_h' => null];
    foreach ((array) ($j['extension'] ?? []) as $e) {
        if (($e['url'] ?? '') === sd('pim-grupo-uso-medico')) {
            $g = (string) ($e['valueCoding']['code'] ?? ''); if (!in_array($g, GRUPOS_LOCAL, true)) throw new Fallo(422, 'code-invalid', "Grupo de uso médico inválido: $g (0, 1, 2a, 2b)");
            $f['grupo_uso_medico'] = $g;
        }
        if (($e['url'] ?? '') === sd('pim-ambiente-controlado')) foreach ((array) ($e['extension'] ?? []) as $x) {
            if (($x['url'] ?? '') === 'claseISO14644') $f['clase_iso14644'] = mb_substr((string) $x['valueString'], 0, 10);
            if (($x['url'] ?? '') === 'presionRelativa' && in_array($x['valueCode'] ?? '', ['+', '-', '0'], true)) $f['presion_relativa'] = $x['valueCode'];
            if (($x['url'] ?? '') === 'renovacionesHora') $f['renovaciones_h'] = (float) $x['valueDecimal'];
        }
    }
    return $f;
}

// ================================================================== Device (pim-sistema · pim-activo · equipo médico)
/** IRU = F + R + M + A; R se deriva de la categoría NFPA 99 si no se cargó (1→5, 2→4, 3→2, 4→1). */
function iru(array $r, ?string $estadoDeclarado = null): ?array {
    $R = $r['iru_r'] !== null ? (int) $r['iru_r'] : ([1 => 5, 2 => 4, 3 => 2, 4 => 1][(int) ($r['categoria_riesgo'] ?? 0)] ?? null);
    if ($r['iru_f'] === null || $R === null || $r['iru_m'] === null) return null;
    $A = (int) ($r['iru_a'] ?? 0);
    $total = (int) $r['iru_f'] + $R + (int) $r['iru_m'] + $A;
    $bandas = ['A', 'B', 'C', 'D'];
    $i = $total >= 18 ? 0 : ($total >= 14 ? 1 : ($total >= 10 ? 2 : 3));
    $ajuste = null;
    if ($estadoDeclarado === 'fuera-de-servicio' && in_array($r['redundancia'] ?? null, [null, 'N'], true)) { $i = max(0, $i - 1); $ajuste = 'sube: fuera de servicio sin contingencia'; }
    elseif (in_array($r['redundancia'] ?? null, ['N+1', '2N'], true)) { $i = min(3, $i + 1); $ajuste = 'baja: redundancia ' . $r['redundancia']; }
    return ['F' => (int) $r['iru_f'], 'R' => $R, 'M' => (int) $r['iru_m'], 'A' => $A, 'total' => $total, 'banda' => $bandas[$i], 'ajuste' => $ajuste];
}

function dev_fhir(array $r): array {
    $perfil = ['sistema' => 'pim-sistema', 'activo' => 'pim-activo', 'equipo-medico' => 'pim-equipo-medico'][$r['clase']];
    $ext = [];
    if ($r['categoria_riesgo'] !== null) $ext[] = ['url' => sd('pim-categoria-riesgo'), 'valueCoding' => coding(cs('pim-categoria-riesgo'), (string) $r['categoria_riesgo'])];
    if ($r['redundancia'] !== null) $ext[] = ['url' => sd('pim-redundancia'), 'valueCode' => $r['redundancia']];
    if ($i = iru($r)) $ext[] = ['url' => sd('pim-iru'), 'extension' => [
        ['url' => 'F', 'valueInteger' => $i['F']], ['url' => 'R', 'valueInteger' => $i['R']], ['url' => 'M', 'valueInteger' => $i['M']],
        ['url' => 'A', 'valueInteger' => $i['A']], ['url' => 'total', 'valueInteger' => $i['total']], ['url' => 'banda', 'valueCode' => $i['banda']]]];
    $q = pdo()->prepare('SELECT punto, unidad, minimo, maximo, genera_orden FROM pim_punto WHERE activo_id = ? ORDER BY punto'); $q->execute([$r['id']]);
    foreach ($q->fetchAll() as $pt) $ext[] = ['url' => sd('pim-punto-medicion'), 'extension' => array_values(quitar_nulos([
        ['url' => 'punto', 'valueCode' => $pt['punto']], ['url' => 'unidad', 'valueCode' => $pt['unidad']],
        $pt['minimo'] !== null ? ['url' => 'minimo', 'valueDecimal' => (float) $pt['minimo']] : null,
        $pt['maximo'] !== null ? ['url' => 'maximo', 'valueDecimal' => (float) $pt['maximo']] : null,
        ['url' => 'generaOrden', 'valueBoolean' => (bool) $pt['genera_orden']]]))];
    $tipo = null;
    if ($r['tipo_codigo']) $tipo = ['coding' => [coding($r['clase'] === 'equipo-medico' ? cs('clase-equipo') : cs('pim-sistema'), $r['tipo_codigo'], SISTEMAS[$r['tipo_codigo']] ?? null)], 'text' => $r['tipo_texto']];
    return quitar_nulos(['resourceType' => 'Device', 'id' => $r['id'], 'meta' => meta($r, $perfil), 'extension' => $ext ?: null,
        'identifier' => [['use' => 'official', 'system' => sid('activo'), 'value' => $r['codigo']]],
        'status' => $r['estado'], 'manufacturer' => $r['fabricante'], 'serialNumber' => $r['serie'], 'modelNumber' => $r['modelo'],
        'deviceName' => [['name' => $r['nombre'], 'type' => 'user-friendly-name']], 'type' => $tipo,
        'owner' => org_ref($r['propietario_id'] ?: org_organismo_id()), 'location' => loc_ref($r['local_id'], nombre_de('pim_local', $r['local_id'])),
        'parent' => dev_ref($r['padre_id'], nombre_de('pim_activo', $r['padre_id']))]);
}
function dev_desde(array $j): array {
    if (($j['resourceType'] ?? '') !== 'Device') throw new Fallo(422, 'invalid', 'Se esperaba Device');
    $perfil = (string) ($j['meta']['profile'][0] ?? sd('pim-activo'));
    $clase = str_ends_with($perfil, 'pim-sistema') ? 'sistema' : (str_ends_with($perfil, 'pim-equipo-medico') ? 'equipo-medico' : 'activo');
    $cod = null; foreach ((array) ($j['identifier'] ?? []) as $i) if (es_sistema((string) ($i['system'] ?? ''), 'activo')) $cod = trim((string) ($i['value'] ?? ''));
    if (!$cod) throw new Fallo(422, 'required', 'identifier con system ' . sid('activo') . ' es obligatorio', 'Device.identifier');
    $nombre = trim((string) ($j['deviceName'][0]['name'] ?? '')); if ($nombre === '') throw new Fallo(422, 'required', 'deviceName.name es obligatorio', 'Device.deviceName');
    $tc = $j['type']['coding'][0]['code'] ?? null;
    if ($clase === 'sistema' && (!$tc || !isset(SISTEMAS[$tc]))) throw new Fallo(422, 'code-invalid', 'pim-sistema exige type con un código de ' . cs('pim-sistema') . ' (Anexo B)', 'Device.type');
    $f = ['clase' => $clase, 'codigo' => mb_substr($cod, 0, 60), 'nombre' => mb_substr($nombre, 0, 200), 'tipo_codigo' => $tc ? mb_substr((string) $tc, 0, 40) : null,
          'tipo_texto' => isset($j['type']['text']) ? mb_substr((string) $j['type']['text'], 0, 160) : null,
          'estado' => in_array($j['status'] ?? 'active', ['active', 'inactive', 'entered-in-error'], true) ? ($j['status'] ?? 'active') : 'active',
          'fabricante' => $j['manufacturer'] ?? null, 'modelo' => $j['modelNumber'] ?? null, 'serie' => $j['serialNumber'] ?? null,
          'local_id' => isset($j['location']['reference']) ? ref_id($j['location']['reference'], 'Location', 'Device.location') : null,
          'padre_id' => isset($j['parent']['reference']) ? ref_id($j['parent']['reference'], 'Device', 'Device.parent') : null,
          'propietario_id' => isset($j['owner']['reference']) ? ref_id($j['owner']['reference'], 'Organization', 'Device.owner') : null,
          'categoria_riesgo' => null, 'redundancia' => null, 'iru_f' => null, 'iru_r' => null, 'iru_m' => null, 'iru_a' => null];
    foreach ((array) ($j['extension'] ?? []) as $e) {
        $u = $e['url'] ?? '';
        if ($u === sd('pim-categoria-riesgo')) { $c = (int) ($e['valueCoding']['code'] ?? 0); if ($c < 1 || $c > 4) throw new Fallo(422, 'code-invalid', 'Categoría NFPA 99: 1 a 4'); $f['categoria_riesgo'] = $c; }
        if ($u === sd('pim-redundancia')) { if (!in_array($e['valueCode'] ?? '', ['N', 'N+1', '2N'], true)) throw new Fallo(422, 'code-invalid', 'Redundancia: N, N+1 o 2N'); $f['redundancia'] = $e['valueCode']; }
        if ($u === sd('pim-punto-medicion')) {
            $pt = ['punto' => null, 'unidad' => null, 'minimo' => null, 'maximo' => null, 'genera_orden' => 1];
            foreach ((array) ($e['extension'] ?? []) as $x) match ($x['url'] ?? '') {
                'punto' => $pt['punto'] = (string) ($x['valueCode'] ?? ''), 'unidad' => $pt['unidad'] = (string) ($x['valueCode'] ?? ''),
                'minimo' => $pt['minimo'] = is_numeric($x['valueDecimal'] ?? null) ? (float) $x['valueDecimal'] : null,
                'maximo' => $pt['maximo'] = is_numeric($x['valueDecimal'] ?? null) ? (float) $x['valueDecimal'] : null,
                'generaOrden' => $pt['genera_orden'] = ($x['valueBoolean'] ?? true) ? 1 : 0, default => null };
            if (!preg_match('/^[A-Z0-9\-]{2,40}$/', (string) $pt['punto']) || $pt['unidad'] === '' || $pt['unidad'] === null || mb_strlen($pt['unidad']) > 20)
                throw new Fallo(422, 'invalid', 'pim-punto-medicion: punto (A-Z, 0-9, -) y unidad UCUM son obligatorios', 'Device.extension');
            $f['_puntos'][] = $pt;
        }
        if ($u === sd('pim-iru')) foreach ((array) ($e['extension'] ?? []) as $x) {
            $v = isset($x['valueInteger']) ? (int) $x['valueInteger'] : null;
            match ($x['url'] ?? '') {
                'F' => $f['iru_f'] = rango($v, 1, 10, 'IRU F'), 'R' => $f['iru_r'] = rango($v, 1, 5, 'IRU R'),
                'M' => $f['iru_m'] = rango($v, 1, 5, 'IRU M'), 'A' => $f['iru_a'] = rango($v, -2, 2, 'IRU A'), default => null };
        }
    }
    if ($f['propietario_id'] === org_organismo_id()) $f['propietario_id'] = null;   // el organismo es el dueño por defecto (aunque aún no se haya importado)
    foreach (['local_id' => 'pim_local', 'padre_id' => 'pim_activo', 'propietario_id' => 'pim_organizacion'] as $k => $t)
        if ($f[$k] !== null && !existe($t, $f[$k])) throw new Fallo(422, 'not-found', "Referencia inexistente en $k");
    return $f;
}
function rango(?int $v, int $min, int $max, string $n): ?int {
    if ($v === null) return null;
    if ($v < $min || $v > $max) throw new Fallo(422, 'value', "$n fuera de rango ($min a $max)");
    return $v;
}

// ================================================================== ServiceRequest (pim-solicitud)
function sr_fhir(array $r): array {
    $ext = [];
    if ($r['estado_declarado']) $ext[] = ['url' => sd('pim-estado-declarado'), 'valueCode' => $r['estado_declarado']];
    $q = pdo()->prepare('SELECT id FROM pim_orden WHERE solicitud_id = ? ORDER BY autoria LIMIT 1'); $q->execute([$r['id']]);
    if ($o = $q->fetchColumn()) $ext[] = ['url' => sd('pim-orden-generada'), 'valueReference' => ['reference' => 'Task/' . $o]];
    $subj = $r['activo_id'] ? dev_ref($r['activo_id'], nombre_de('pim_activo', $r['activo_id'])) : loc_ref($r['local_id'], nombre_de('pim_local', $r['local_id']));
    return quitar_nulos(['resourceType' => 'ServiceRequest', 'id' => $r['id'], 'meta' => meta($r, 'pim-solicitud'), 'extension' => $ext ?: null,
        'identifier' => [['use' => 'official', 'system' => sid('solicitud'), 'value' => $r['numero']]],
        'status' => $r['estado'], 'intent' => 'order', 'priority' => $r['prioridad'],
        'code' => $r['categoria_codigo'] || $r['categoria_texto'] ? quitar_nulos(['coding' => $r['categoria_codigo'] ? [coding(cs('pim-categoria'), $r['categoria_codigo'])] : null, 'text' => $r['categoria_texto']]) : null,
        'subject' => $subj, 'locationReference' => $r['local_id'] ? [loc_ref($r['local_id'], nombre_de('pim_local', $r['local_id']))] : null,
        'authoredOn' => iso($r['autoria']), 'requester' => org_ref(org_organismo_id()),
        'note' => $r['descripcion'] && cfg()['exponer_detalle'] ? [['text' => $r['descripcion']]] : null,
        'reasonCode' => $r['solicitante'] ? [['text' => 'Solicitado por: ' . $r['solicitante']]] : null]);
}
function sr_desde(array $j): array {
    if (($j['resourceType'] ?? '') !== 'ServiceRequest') throw new Fallo(422, 'invalid', 'Se esperaba ServiceRequest');
    sin_pacientes($j);
    if (($j['intent'] ?? '') !== 'order') throw new Fallo(422, 'value', 'intent debe ser «order»', 'ServiceRequest.intent');
    $st = $j['status'] ?? 'active'; if ($st !== 'active') throw new Fallo(422, 'value', 'Una solicitud nueva debe tener status «active»', 'ServiceRequest.status');
    $pr = $j['priority'] ?? null; if ($pr !== null && !in_array($pr, PRIORIDADES, true)) throw new Fallo(422, 'code-invalid', 'priority: routine | urgent | asap | stat', 'ServiceRequest.priority');
    $sref = (string) ($j['subject']['reference'] ?? '');
    if ($sref === '') throw new Fallo(422, 'required', 'subject es obligatorio (Location o Device; nunca Patient)', 'ServiceRequest.subject');
    $local = null; $activo = null;
    if (tipo_ref($sref) === 'Device') $activo = ref_id($sref, 'Device', 'ServiceRequest.subject'); else $local = ref_id($sref, 'Location', 'ServiceRequest.subject');
    if (!empty($j['locationReference'][0]['reference'])) $local = ref_id($j['locationReference'][0]['reference'], 'Location', 'ServiceRequest.locationReference');
    if ($activo && !existe('pim_activo', $activo)) throw new Fallo(422, 'not-found', 'El Device indicado no existe', 'ServiceRequest.subject');
    if ($local && !existe('pim_local', $local)) throw new Fallo(422, 'not-found', 'La Location indicada no existe', 'ServiceRequest.locationReference');
    if ($activo && !$local) { $q = pdo()->prepare('SELECT local_id FROM pim_activo WHERE id = ?'); $q->execute([$activo]); $local = $q->fetchColumn() ?: null; }
    $cat = $j['code']['coding'][0]['code'] ?? null; $catTxt = $j['code']['text'] ?? ($j['code']['coding'][0]['display'] ?? null);
    if (!$cat && !$catTxt) throw new Fallo(422, 'required', 'code (categoría del pedido) es obligatorio', 'ServiceRequest.code');
    $desc = trim(implode("\n", array_map(fn($n) => (string) ($n['text'] ?? ''), (array) ($j['note'] ?? []))));
    if ($desc === '') throw new Fallo(422, 'required', 'note[0].text (descripción del problema) es obligatorio', 'ServiceRequest.note');
    $ed = null;
    foreach ((array) ($j['extension'] ?? []) as $e) if (($e['url'] ?? '') === sd('pim-estado-declarado')) {
        $ed = (string) ($e['valueCode'] ?? ''); if (!in_array($ed, ESTADO_DECLARADO, true)) throw new Fallo(422, 'code-invalid', 'Estado declarado: ' . implode(' | ', ESTADO_DECLARADO));
    }
    return ['prioridad' => $pr, 'categoria_codigo' => $cat ? mb_substr((string) $cat, 0, 40) : null, 'categoria_texto' => $catTxt ? mb_substr((string) $catTxt, 0, 160) : null,
            'descripcion' => mb_substr($desc, 0, 5000), 'local_id' => $local, 'activo_id' => $activo, 'estado_declarado' => $ed,
            'solicitante' => isset($j['requester']['display']) ? mb_substr((string) $j['requester']['display'], 0, 160) : null];
}

// ================================================================== Task (pim-orden)
function task_fhir(array $r): array {
    $ext = [];
    if ($r['tipo_mantenimiento']) $ext[] = ['url' => sd('pim-tipo-mantenimiento'), 'valueCoding' => coding(cs('pim-tipo-mantenimiento'), $r['tipo_mantenimiento'])];
    if ($r['modo_falla']) $ext[] = ['url' => sd('pim-modo-falla'), 'valueCoding' => coding(cs('pim-modo-falla'), $r['modo_falla'])];
    $ids = [['use' => 'official', 'system' => sid('orden'), 'value' => $r['numero']]];
    if ($r['codigo_legado']) $ids[] = ['use' => 'secondary', 'system' => sid('orden-codigo'), 'value' => $r['codigo_legado']];
    $notas = [];
    $q = pdo()->prepare('SELECT texto, autor, ts FROM pim_nota WHERE orden_id = ? ORDER BY ts, id'); $q->execute([$r['id']]);
    foreach ($q->fetchAll() as $n) $notas[] = ['authorString' => $n['autor'], 'time' => iso($n['ts']), 'text' => $n['texto']];
    return quitar_nulos(['resourceType' => 'Task', 'id' => $r['id'], 'meta' => meta($r, 'pim-orden'), 'extension' => $ext ?: null,
        'identifier' => $ids,
        'basedOn' => $r['solicitud_id'] ? [['reference' => 'ServiceRequest/' . $r['solicitud_id']]] : null,
        'status' => $r['estado'], 'statusReason' => $r['motivo_estado'] ? ['text' => $r['motivo_estado']] : null,
        'businessStatus' => ['coding' => [coding(cs('pim-estado-orden'), $r['estado_negocio'])], 'text' => $r['estado_negocio']],
        'intent' => 'order', 'priority' => $r['prioridad'],
        'code' => $r['categoria_codigo'] || $r['categoria_texto'] ? quitar_nulos(['coding' => $r['categoria_codigo'] ? [coding(cs('pim-categoria'), $r['categoria_codigo'])] : null, 'text' => $r['categoria_texto']]) : null,
        'description' => cfg()['exponer_detalle'] ? $r['descripcion'] : null,
        'focus' => dev_ref($r['activo_id'], nombre_de('pim_activo', $r['activo_id'])),
        'executionPeriod' => quitar_nulos(['start' => iso($r['inicio']), 'end' => iso($r['fin'])]) ?: null,
        'authoredOn' => iso($r['autoria']), 'lastModified' => iso($r['actualizado']),
        'requester' => org_ref(org_organismo_id()), 'owner' => org_ref($r['responsable_id'], nombre_de('pim_organizacion', $r['responsable_id'])),
        'location' => loc_ref($r['local_id'], nombre_de('pim_local', $r['local_id'])), 'note' => $notas ?: null]);
}

// ================================================================== Observation (pim-medicion)
function obs_fhir(array $r): array {
    $o = ['resourceType' => 'Observation', 'id' => $r['id'], 'meta' => ['lastUpdated' => iso($r['creado']), 'profile' => [sd('pim-medicion')]],
        'status' => 'final', 'category' => [['coding' => [coding('http://terminology.hl7.org/CodeSystem/observation-category', 'survey', 'Survey')], 'text' => 'Medición de instalación']],
        'code' => ['coding' => [coding(cs('pim-punto'), $r['punto'])]],
        'device' => dev_ref($r['activo_id'], nombre_de('pim_activo', $r['activo_id'])),
        'effectiveDateTime' => iso($r['instante']),
        'valueQuantity' => ['value' => (float) $r['valor'], 'unit' => $r['unidad'], 'system' => 'http://unitsofmeasure.org', 'code' => $r['unidad']]];
    if ($r['interpretacion']) $o['interpretation'] = [['coding' => [coding('http://terminology.hl7.org/CodeSystem/v3-ObservationInterpretation', $r['interpretacion'])]]];
    $rr = quitar_nulos(['low' => $r['minimo'] !== null ? ['value' => (float) $r['minimo'], 'unit' => $r['unidad']] : null,
                        'high' => $r['maximo'] !== null ? ['value' => (float) $r['maximo'], 'unit' => $r['unidad']] : null]);
    if ($rr) $o['referenceRange'] = [$rr];
    if ($r['orden_id']) $o['extension'] = [['url' => sd('pim-orden-generada'), 'valueReference' => ['reference' => 'Task/' . $r['orden_id']]]];
    return $o;
}
function obs_desde(array $j): array {
    if (($j['resourceType'] ?? '') !== 'Observation') throw new Fallo(422, 'invalid', 'Se esperaba Observation');
    sin_pacientes($j);
    if (isset($j['subject'])) throw new Fallo(422, 'business-rule', 'pim-medicion no usa subject: el activo va en device', 'Observation.subject');
    $dref = (string) ($j['device']['reference'] ?? ''); if ($dref === '') throw new Fallo(422, 'required', 'device es obligatorio', 'Observation.device');
    $act = ref_id($dref, 'Device', 'Observation.device'); if (!existe('pim_activo', $act)) throw new Fallo(422, 'not-found', 'Device inexistente', 'Observation.device');
    $punto = (string) ($j['code']['coding'][0]['code'] ?? ''); if (!preg_match('/^[A-Z0-9\-]{2,40}$/', $punto)) throw new Fallo(422, 'code-invalid', 'code: punto de medición (pim-punto, p. ej. PRES-LINEA)', 'Observation.code');
    $vq = $j['valueQuantity'] ?? null;
    if (!is_array($vq) || !is_numeric($vq['value'] ?? null)) throw new Fallo(422, 'required', 'valueQuantity.value es obligatorio', 'Observation.valueQuantity');
    $u = (string) ($vq['code'] ?? $vq['unit'] ?? ''); if ($u === '' || mb_strlen($u) > 20) throw new Fallo(422, 'required', 'valueQuantity.code (unidad UCUM) es obligatorio', 'Observation.valueQuantity.code');
    $t = a_local($j['effectiveDateTime'] ?? null, 'Observation.effectiveDateTime') ?? throw new Fallo(422, 'required', 'effectiveDateTime es obligatorio', 'Observation.effectiveDateTime');
    $min = $j['referenceRange'][0]['low']['value'] ?? null; $max = $j['referenceRange'][0]['high']['value'] ?? null;
    return ['activo_id' => $act, 'punto' => $punto, 'valor' => (float) $vq['value'], 'unidad' => $u, 'instante' => $t,
            'minimo' => is_numeric($min) ? (float) $min : null, 'maximo' => is_numeric($max) ? (float) $max : null];
}

// ================================================================== RiskAssessment (pim-riesgo) y Group
function riesgo_fhir(array $a): ?array {
    $i = iru($a); if (!$i) return null;
    $comp = fn($n, $v) => ['url' => $n, 'valueInteger' => $v];
    return quitar_nulos(['resourceType' => 'RiskAssessment', 'id' => $a['id'] . '-iru', 'meta' => ['lastUpdated' => iso($a['actualizado']), 'profile' => [sd('pim-riesgo')]],
        'status' => 'final', 'method' => ['coding' => [coding(cs('pim-metodo-riesgo'), 'iru', 'Índice de Riesgo Unificado')]],
        'subject' => ['reference' => 'Group/' . $a['id'] . '-g', 'display' => $a['nombre']],
        'occurrenceDateTime' => iso($a['actualizado']), 'performer' => ['reference' => 'Organization/' . org_organismo_id()],
        'basis' => [dev_ref($a['id'], $a['nombre'])],
        'prediction' => [['qualitativeRisk' => ['coding' => [coding(cs('pim-banda-iru'), $i['banda'])], 'text' => 'Banda ' . $i['banda'] . ' (IRU ' . $i['total'] . ')'],
            'rationale' => 'IRU = F ' . $i['F'] . ' + R ' . $i['R'] . ' + M ' . $i['M'] . ' + A ' . $i['A'] . ' = ' . $i['total'] . ($i['ajuste'] ? ' · ' . $i['ajuste'] : '')]],
        'extension' => [['url' => sd('pim-iru'), 'extension' => [$comp('F', $i['F']), $comp('R', $i['R']), $comp('M', $i['M']), $comp('A', $i['A']), $comp('total', $i['total']),
            ['url' => 'banda', 'valueCode' => $i['banda']]]]],
        'mitigation' => $i['ajuste']]);
}
function grupo_fhir(array $a): array {
    return ['resourceType' => 'Group', 'id' => $a['id'] . '-g', 'meta' => ['profile' => [sd('pim-grupo-activo')]], 'type' => 'device', 'actual' => true,
        'name' => $a['nombre'], 'member' => [['entity' => dev_ref($a['id'], $a['nombre'])]]];
}

// ================================================================== Provenance (pim-procedencia)
function prov_fhir(array $v): array {
    return ['resourceType' => 'Provenance', 'id' => substr(hash('sha256', $v['recurso'] . $v['id'] . $v['version']), 0, 32),
        'meta' => ['profile' => [sd('pim-procedencia')]],
        'target' => [['reference' => $v['recurso'] . '/' . $v['id'] . '/_history/' . $v['version']]],
        'recorded' => iso($v['ts']),
        'activity' => ['coding' => [coding('http://terminology.hl7.org/CodeSystem/v3-DataOperation', $v['accion'] === 'create' ? 'CREATE' : 'UPDATE')]],
        'reason' => $v['motivo'] ? [['text' => $v['motivo']]] : null,
        'agent' => [['type' => ['coding' => [coding('http://terminology.hl7.org/CodeSystem/provenance-participant-type', 'author')]],
                     'who' => ['identifier' => ['system' => sid('cliente'), 'value' => $v['cliente']]]]],
        'signature' => [['type' => [coding('urn:iso-astm:E1762-95:2013', '1.2.840.10065.1.12.1.14', 'Source Signature')], 'when' => iso($v['ts']),
            'who' => ['identifier' => ['system' => sid('cliente'), 'value' => $v['cliente']]], 'sigFormat' => 'application/x-pim-huella', 'data' => base64_encode(hex2bin($v['huella']))]]] ;
}

function org_organismo_id(): string { return ulid_legado('organismo', cfg()['organismo']['codigo']); }
