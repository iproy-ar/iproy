<?php
/**
 * iProy · Servidor PIM v1.1 · modelo: filas de iproy_pim ⇄ recursos FHIR R4 con perfiles PIM 0.9.2 · 02-11-2026
 *   Directorio alineado con IHE mCSD 4.0.0 (pim-organismo, pim-establecimiento, pim-local, pim-empresa) · PMA con datos REFES y EUI-64 (IHE MEM-DMC)
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
const TIPOS_MANT = ['correctivo-inmediato', 'correctivo-diferido', 'preventivo-sistematico', 'preventivo-condicional', 'predictivo', 'mejora', 'ensayo-reglamentario', 'verificacion-tecnica-pma'];
const SISTEMAS = ['GAS-O2' => 'Oxígeno medicinal', 'GAS-AIRE' => 'Aire medicinal', 'GAS-VAC' => 'Vacío medicinal', 'GAS-N2O' => 'Óxido nitroso',
    'GAS-CO2' => 'Dióxido de carbono', 'GAS-AGSS' => 'Evacuación de gases anestésicos', 'ELE-EMER' => 'Energía de emergencia', 'ELE-UPS' => 'Sistemas de alimentación ininterrumpida',
    'ELE-ITM' => 'Red IT médica', 'ELE-NORM' => 'Distribución eléctrica normal e iluminación', 'HVAC-QX' => 'Climatización de quirófanos', 'HVAC-AIS' => 'Salas de aislamiento',
    'HVAC-GRAL' => 'Climatización general', 'FIL-HEPA' => 'Filtración absoluta', 'EST-VAP' => 'Esterilización y vapor', 'AGU-DIAL' => 'Agua para hemodiálisis',
    'AGU-SAN' => 'Agua sanitaria', 'FRIO-CONS' => 'Frío de conservación', 'INC-DET' => 'Detección de incendio', 'INC-EXT' => 'Extinción de incendio',
    'TRV-ASC' => 'Transporte vertical', 'COM-LLAM' => 'Llamado de enfermería', 'GAS-NAT' => 'Gas natural', 'EDI-GRAL' => 'Edificio y obra civil',
    'AGU-OSM' => 'Circuitos de ósmosis', 'PIS-COND' => 'Pisos conductivos o disipativos', 'TIC-CPD' => 'Centro de datos con sistemas clínicos',
    'DES-CLO' => 'Desagües cloacales y pluviales', 'EDI-ABE' => 'Aberturas'];
const ESTADO_DECLARADO = ['operativo-con-falla', 'degradado', 'fuera-de-servicio'];
const GRUPOS_LOCAL = ['0', '1', '2a', '2b'];
const TIPO_FISICO = ['bu' => 'Building', 'wi' => 'Wing', 'lvl' => 'Level', 'ro' => 'Room', 'bd' => 'Bed', 'area' => 'Area'];
const TIPO_FISICO_ES = ['bu' => 'Edificio', 'wi' => 'Ala', 'lvl' => 'Nivel', 'ro' => 'Local', 'bd' => 'Cama', 'area' => 'Área'];
const PRESION = ['+' => 'positiva', '-' => 'negativa', '0' => 'neutra'];
/** Categoría de alcance (sección 3.3) de cada sistema de instalación (pim-sistema). */
const SISTEMA_ALCANCE = ['GAS-O2' => 'B', 'GAS-AIRE' => 'B', 'GAS-VAC' => 'B', 'GAS-N2O' => 'B', 'GAS-CO2' => 'B', 'GAS-AGSS' => 'B', 'ELE-ITM' => 'B',
    'HVAC-QX' => 'B', 'HVAC-AIS' => 'B', 'FIL-HEPA' => 'B', 'EST-VAP' => 'B', 'AGU-DIAL' => 'B', 'AGU-OSM' => 'B', 'PIS-COND' => 'B',
    'ELE-EMER' => 'C', 'ELE-UPS' => 'C', 'TIC-CPD' => 'C', 'INC-DET' => 'C', 'INC-EXT' => 'C', 'FRIO-CONS' => 'C',
    'COM-LLAM' => 'B', 'GAS-NAT' => 'D', 'ELE-NORM' => 'D', 'HVAC-GRAL' => 'D', 'AGU-SAN' => 'D', 'DES-CLO' => 'D', 'EDI-GRAL' => 'D', 'EDI-ABE' => 'D', 'TRV-ASC' => 'D'];
const PERTENENCIA = ['desconocida'];   // valores de SISA a confirmar con la DNSIS (pim-valores)

function coding(string $sys, string $code, ?string $disp = null): array { return array_filter(['system' => $sys, 'code' => $code, 'display' => $disp], fn($x) => $x !== null); }
function meta(array $r, string $perfil): array {
    return array_filter(['versionId' => isset($r['version']) ? (string) $r['version'] : null,
        'lastUpdated' => iso($r['actualizado'] ?? null), 'profile' => [sd($perfil)],
        'tag' => match ($r['origen'] ?? '') { 'legado' => [coding(cs('pim-origen'), 'legado', 'Sistema anterior')], 'mem-dmc' => [coding(cs('pim-origen'), 'mem-dmc', 'MEM-DMC')], default => null }],
        fn($x) => $x !== null);
}
function quitar_nulos(array $a): array { return array_filter($a, fn($x) => $x !== null && $x !== [] && $x !== ''); }
function dm_ref(?string $activo, ?string $punto): ?array { return $activo && $punto !== null ? ['reference' => 'DeviceMetric/' . dm_id($activo, $punto)] : null; }
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
        'type' => array_values(array_filter([$r['tipo'] === 'organismo' ? ['coding' => [coding(MCSD_TIPOS, 'facility', 'Facility')]] : null,
            ['coding' => [coding('http://terminology.hl7.org/CodeSystem/organization-type', $t[0], $t[1])]]])),
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
        $r['presion_relativa'] !== null ? ['url' => 'presionRelativa', 'valueCode' => PRESION[$r['presion_relativa']] ?? $r['presion_relativa']] : null,
        $r['renovaciones_h'] !== null ? ['url' => 'renovacionesHora', 'valueQuantity' => ['value' => (float) $r['renovaciones_h'], 'unit' => '/h', 'system' => UCUM, 'code' => '/h']] : null,
        $r['clase_iso14644'] !== null ? ['url' => 'claseISO', 'valueString' => $r['clase_iso14644']] : null]);
    if ($amb) $ext[] = ['url' => sd('pim-ambiente-controlado'), 'extension' => array_values($amb)];
    return quitar_nulos(['resourceType' => 'Location', 'id' => $r['id'], 'meta' => meta($r, 'pim-local'), 'extension' => $ext ?: null,
        'identifier' => [['use' => 'official', 'system' => sid('local'), 'value' => $r['codigo']]],
        'status' => $r['estado'], 'name' => $r['nombre'], 'mode' => 'instance',
        'type' => [['text' => TIPO_FISICO_ES[$r['tipo_fisico']] ?? 'Local']],
        'physicalType' => ['coding' => [coding('http://terminology.hl7.org/CodeSystem/location-physical-type', $r['tipo_fisico'], TIPO_FISICO[$r['tipo_fisico']] ?? null)]],
        'managingOrganization' => org_ref(org_organismo_id()), 'partOf' => loc_ref($r['parte_de'] ?: estab_id())]);
}
/** Sitio físico del establecimiento (pim-establecimiento, IHE mCSD FacilityLocation): raíz de la jerarquía de locales. */
function estab_id(): string { return ulid_legado('establecimiento', cfg()['organismo']['codigo']); }
function estab_fhir(): array {
    $o = cfg()['organismo'];
    return ['resourceType' => 'Location', 'id' => estab_id(), 'meta' => ['profile' => [sd('pim-establecimiento')]],
        'identifier' => [['use' => 'official', 'system' => sid('local'), 'value' => $o['codigo']]],
        'status' => 'active', 'name' => $o['nombre'], 'mode' => 'instance',
        'type' => [['coding' => [coding(MCSD_TIPOS, 'facility', 'Facility')]], ['coding' => [coding('http://terminology.hl7.org/CodeSystem/v3-RoleCode', 'HOSP', 'Hospital')]]],
        'physicalType' => ['coding' => [coding('http://terminology.hl7.org/CodeSystem/location-physical-type', 'si', 'Site')]],
        'managingOrganization' => org_ref(org_organismo_id())];
}
function loc_desde(array $j): array {
    if (($j['resourceType'] ?? '') !== 'Location') throw new Fallo(422, 'invalid', 'Se esperaba Location');
    $cod = null; foreach ((array) ($j['identifier'] ?? []) as $i) if (es_sistema((string) ($i['system'] ?? ''), 'local')) $cod = trim((string) ($i['value'] ?? ''));
    if (!$cod) throw new Fallo(422, 'required', 'identifier con system ' . sid('local') . ' es obligatorio', 'Location.identifier');
    $nombre = trim((string) ($j['name'] ?? '')); if ($nombre === '') throw new Fallo(422, 'required', 'name es obligatorio', 'Location.name');
    $tf = (string) ($j['physicalType']['coding'][0]['code'] ?? 'area'); if (!isset(TIPO_FISICO[$tf])) throw new Fallo(422, 'code-invalid', "physicalType inválido: $tf", 'Location.physicalType');
    $f = ['codigo' => mb_substr($cod, 0, 60), 'nombre' => mb_substr($nombre, 0, 160), 'tipo_fisico' => $tf,
          'estado' => in_array($j['status'] ?? 'active', ['active', 'inactive', 'suspended'], true) ? ($j['status'] ?? 'active') : 'active',
          'parte_de' => isset($j['partOf']['reference']) && ($pa = ref_id($j['partOf']['reference'], 'Location', 'Location.partOf')) !== estab_id() ? $pa : null,
          'grupo_uso_medico' => null, 'clase_iso14644' => null, 'presion_relativa' => null, 'renovaciones_h' => null];
    foreach ((array) ($j['extension'] ?? []) as $e) {
        if (($e['url'] ?? '') === sd('pim-grupo-uso-medico')) {
            $g = (string) ($e['valueCoding']['code'] ?? ''); if (!in_array($g, GRUPOS_LOCAL, true)) throw new Fallo(422, 'code-invalid', "Grupo de uso médico inválido: $g (0, 1, 2a, 2b)");
            $f['grupo_uso_medico'] = $g;
        }
        if (($e['url'] ?? '') === sd('pim-ambiente-controlado')) foreach ((array) ($e['extension'] ?? []) as $x) {
            $u = $x['url'] ?? '';
            if ($u === 'claseISO' || $u === 'claseISO14644') $f['clase_iso14644'] = mb_substr((string) ($x['valueString'] ?? ''), 0, 10);
            if ($u === 'presionRelativa') { $pr = (string) ($x['valueCode'] ?? ''); $pr = array_search($pr, PRESION, true) ?: $pr;
                if (!isset(PRESION[$pr])) throw new Fallo(422, 'code-invalid', 'presionRelativa: positiva | negativa | neutra'); $f['presion_relativa'] = $pr; }
            if ($u === 'renovacionesHora') $f['renovaciones_h'] = (float) ($x['valueQuantity']['value'] ?? $x['valueDecimal'] ?? 0);
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

function alcance(array $r): ?string {
    if (!empty($r['categoria_alcance'])) return $r['categoria_alcance'];
    if ($r['clase'] === 'equipo-medico') return 'A';
    return $r['clase'] === 'sistema' ? (SISTEMA_ALCANCE[$r['tipo_codigo'] ?? ''] ?? null) : null;
}
function iru_ext(array $i): array {
    return ['url' => sd('pim-iru'), 'extension' => [
        ['url' => 'F', 'valueInteger' => $i['F']], ['url' => 'R', 'valueInteger' => $i['R']], ['url' => 'M', 'valueInteger' => $i['M']],
        ['url' => 'A', 'valueInteger' => $i['A']], ['url' => 'total', 'valueInteger' => $i['total']],
        ['url' => 'banda', 'valueCoding' => coding(cs('pim-valores'), 'banda-' . $i['banda'], 'Banda ' . $i['banda'])]]];
}
function dev_fhir(array $r): array {
    $perfil = ['sistema' => 'pim-sistema', 'activo' => 'pim-activo', 'equipo-medico' => 'pim-pma'][$r['clase']];
    $ext = [];
    if ($al = alcance($r)) $ext[] = ['url' => sd('pim-categoria-alcance'), 'valueCoding' => coding(cs('pim-categoria-alcance'), $al)];
    if (!empty($r['clase_riesgo_pm'])) $ext[] = ['url' => sd('pim-clase-riesgo-pm'), 'valueCoding' => coding(cs('pim-clase-riesgo-pm'), $r['clase_riesgo_pm'])];
    if (!empty($r['puesta_en_marcha'])) $ext[] = ['url' => sd('pim-puesta-en-marcha'), 'valueDateTime' => $r['puesta_en_marcha']];
    if (!empty($r['pertenencia'])) $ext[] = ['url' => sd('pim-pertenencia'), 'valueCoding' => coding(cs('pim-valores'), $r['pertenencia'])];
    if ($r['categoria_riesgo'] !== null) $ext[] = ['url' => sd('pim-categoria-riesgo'), 'valueCoding' => coding(cs('pim-categoria-riesgo'), (string) $r['categoria_riesgo'])];
    if ($r['redundancia'] !== null) $ext[] = ['url' => sd('pim-redundancia'), 'valueCode' => $r['redundancia']];
    if ($i = iru($r)) $ext[] = iru_ext($i);
    $ids = [['use' => 'official', 'system' => sid('activo'), 'value' => $r['codigo']]];
    if (!empty($r['anmat_pm'])) $ids[] = ['system' => sid('anmat-pm'), 'value' => $r['anmat_pm']];
    if (!empty($r['eui64'])) $ids[] = ['system' => sid('eui64'), 'value' => $r['eui64']];
    $tipo = null;
    if ($r['tipo_codigo']) $tipo = quitar_nulos(['coding' => [coding($r['clase'] === 'equipo-medico' ? cs_local('clase-equipo') : cs('pim-sistema'), $r['tipo_codigo'], $r['clase'] === 'equipo-medico' ? null : (SISTEMAS[$r['tipo_codigo']] ?? null))], 'text' => $r['tipo_texto']]);
    elseif ($r['tipo_texto']) $tipo = ['text' => $r['tipo_texto']];
    return quitar_nulos(['resourceType' => 'Device', 'id' => $r['id'], 'meta' => meta($r, $perfil), 'extension' => $ext ?: null,
        'identifier' => $ids,
        'status' => $r['estado'], 'manufacturer' => $r['fabricante'], 'manufactureDate' => $r['fecha_fabricacion'] ?? null,
        'serialNumber' => $r['serie'], 'modelNumber' => $r['modelo'],
        'deviceName' => [['name' => $r['nombre'], 'type' => 'user-friendly-name']], 'type' => $tipo,
        'owner' => org_ref($r['propietario_id'] ?: org_organismo_id()), 'location' => loc_ref($r['local_id'], nombre_de('pim_local', $r['local_id'])),
        'parent' => dev_ref($r['padre_id'], nombre_de('pim_activo', $r['padre_id']))]);
}
function dev_desde(array $j): array {
    if (($j['resourceType'] ?? '') !== 'Device') throw new Fallo(422, 'invalid', 'Se esperaba Device');
    sin_pacientes($j);
    $perfil = (string) ($j['meta']['profile'][0] ?? sd('pim-activo'));
    $clase = str_ends_with($perfil, 'pim-sistema') ? 'sistema' : (str_ends_with($perfil, 'pim-pma') ? 'equipo-medico' : 'activo');
    $cod = null; $anmat = null; $eui = null;
    foreach ((array) ($j['identifier'] ?? []) as $i) {
        $sys = (string) ($i['system'] ?? ''); $val = trim((string) ($i['value'] ?? ''));
        if (es_sistema($sys, 'activo')) $cod = $val;
        if (es_sistema($sys, 'anmat-pm')) { if (!preg_match('/^PM-\d{4}-\d{4}$/', $val)) throw new Fallo(422, 'invalid', 'N.º de producto ANMAT: PM-NNNN-NNNN', 'Device.identifier'); $anmat = $val; }
        if (es_sistema($sys, 'eui64')) { $val = strtoupper($val); if (!preg_match('/^[0-9A-F]{16}$/', $val)) throw new Fallo(422, 'invalid', 'EUI-64: 16 dígitos hexadecimales', 'Device.identifier'); $eui = $val; }
    }
    if (!$cod) throw new Fallo(422, 'required', 'identifier con system ' . sid('activo') . ' es obligatorio', 'Device.identifier');
    $nombre = trim((string) ($j['deviceName'][0]['name'] ?? '')); if ($nombre === '') throw new Fallo(422, 'required', 'deviceName.name es obligatorio', 'Device.deviceName');
    $tc = $j['type']['coding'][0]['code'] ?? null;
    if ($clase === 'sistema' && (!$tc || !isset(SISTEMAS[$tc]))) throw new Fallo(422, 'code-invalid', 'pim-sistema exige type con un código de ' . cs('pim-sistema') . ' (Anexo B)', 'Device.type');
    $fab = $j['manufactureDate'] ?? null; if ($fab !== null && !preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $fab)) throw new Fallo(422, 'invalid', 'manufactureDate: AAAA-MM-DD', 'Device.manufactureDate');
    $f = ['clase' => $clase, 'codigo' => mb_substr($cod, 0, 60), 'nombre' => mb_substr($nombre, 0, 200), 'tipo_codigo' => $tc ? mb_substr((string) $tc, 0, 40) : null,
          'tipo_texto' => isset($j['type']['text']) ? mb_substr((string) $j['type']['text'], 0, 160) : null,
          'estado' => in_array($j['status'] ?? 'active', ['active', 'inactive', 'entered-in-error'], true) ? ($j['status'] ?? 'active') : 'active',
          'fabricante' => $j['manufacturer'] ?? null, 'modelo' => $j['modelNumber'] ?? null, 'serie' => $j['serialNumber'] ?? null,
          'local_id' => isset($j['location']['reference']) ? ref_id($j['location']['reference'], 'Location', 'Device.location') : null,
          'padre_id' => isset($j['parent']['reference']) ? ref_id($j['parent']['reference'], 'Device', 'Device.parent') : null,
          'propietario_id' => isset($j['owner']['reference']) ? ref_id($j['owner']['reference'], 'Organization', 'Device.owner') : null,
          'categoria_riesgo' => null, 'redundancia' => null, 'iru_f' => null, 'iru_r' => null, 'iru_m' => null, 'iru_a' => null,
          'categoria_alcance' => null, 'anmat_pm' => $anmat, 'eui64' => $eui, 'fecha_fabricacion' => $fab ? substr((string) $fab, 0, 10) : null,
          'puesta_en_marcha' => null, 'pertenencia' => null, 'clase_riesgo_pm' => null];
    foreach ((array) ($j['meta']['tag'] ?? []) as $t) if (($t['code'] ?? '') === 'mem-dmc') $f['_origen'] = 'mem-dmc';
    foreach ((array) ($j['extension'] ?? []) as $e) {
        $u = $e['url'] ?? '';
        if ($u === sd('pim-categoria-riesgo')) { $c = (int) ($e['valueCoding']['code'] ?? 0); if ($c < 1 || $c > 4) throw new Fallo(422, 'code-invalid', 'Categoría NFPA 99: 1 a 4'); $f['categoria_riesgo'] = $c; }
        if ($u === sd('pim-redundancia')) { if (!in_array($e['valueCode'] ?? '', ['N', 'N+1', '2N'], true)) throw new Fallo(422, 'code-invalid', 'Redundancia: N, N+1 o 2N'); $f['redundancia'] = $e['valueCode']; }
        if ($u === sd('pim-categoria-alcance')) { $c = (string) ($e['valueCoding']['code'] ?? ''); if (!in_array($c, ['A', 'B', 'C', 'D'], true)) throw new Fallo(422, 'code-invalid', 'Categoría de alcance: A, B, C o D'); $f['categoria_alcance'] = $c; }
        if ($u === sd('pim-clase-riesgo-pm')) { $c = (string) ($e['valueCoding']['code'] ?? ''); if (!in_array($c, ['I', 'II', 'III', 'IV'], true)) throw new Fallo(422, 'code-invalid', 'Clase de riesgo: I a IV'); $f['clase_riesgo_pm'] = $c; }
        if ($u === sd('pim-puesta-en-marcha')) { $d = (string) ($e['valueDateTime'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $d)) throw new Fallo(422, 'invalid', 'Puesta en marcha: AAAA-MM-DD'); $f['puesta_en_marcha'] = substr($d, 0, 10); }
        if ($u === sd('pim-pertenencia')) $f['pertenencia'] = mb_substr((string) ($e['valueCoding']['code'] ?? ''), 0, 40) ?: null;
        if ($u === sd('pim-punto-medicion')) {          // configuración de puntos (forma de la v1.0, se sigue aceptando): rango aceptable de PIM-TEL
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

// ================================================================== DeviceMetric (pim-punto)
/** ID público del punto: determinístico a partir del activo y el código del punto. */
function dm_id(string $activo, string $punto): string { return ulid_legado('punto', $activo . '|' . $punto); }
/** Los puntos creados por versiones anteriores no tienen id: se completa al primer uso. */
function completar_ids_punto(): void {
    static $hecho = false; if ($hecho) return; $hecho = true;
    $q = pdo()->query('SELECT activo_id, punto FROM pim_punto WHERE id IS NULL LIMIT 5000');
    $u = pdo()->prepare('UPDATE pim_punto SET id = ? WHERE activo_id = ? AND punto = ?');
    foreach ($q->fetchAll() as $r) $u->execute([dm_id($r['activo_id'], $r['punto']), $r['activo_id'], $r['punto']]);
}
/** Código FHIR de un punto: MDC (dígitos), sin código asignado (MDCX…) o pim-punto. */
function punto_code(string $punto): array {
    if (ctype_digit($punto)) return ['coding' => [coding(MDC, $punto)]];
    if (str_starts_with($punto, 'MDCX')) return ['text' => $punto];
    return ['coding' => [coding(cs('pim-punto'), $punto)]];
}
function dm_fhir(array $r): array {
    $a = fila_activo_min($r['activo_id']);
    return quitar_nulos(['resourceType' => 'DeviceMetric', 'id' => $r['id'] ?: dm_id($r['activo_id'], $r['punto']),
        'meta' => quitar_nulos(['profile' => [sd('pim-punto')], 'tag' => ($r['origen'] ?? 'pim') === 'mem-dmc' ? [coding(cs('pim-origen'), 'mem-dmc')] : null]),
        'identifier' => [['system' => sid('punto'), 'value' => ($a['codigo'] ?? $r['activo_id']) . '/' . $r['punto']]],
        'type' => punto_code($r['punto']),
        'unit' => $r['unidad'] !== '' && $r['unidad'] !== '1' ? ['coding' => [coding(UCUM, $r['unidad'])]] : null,
        'source' => dev_ref($r['activo_id'], $a['nombre'] ?? null),
        'operationalStatus' => 'on', 'category' => ctype_digit($r['punto']) || str_starts_with($r['punto'], 'MDCX') ? 'setting' : 'measurement']);
}
function fila_activo_min(string $id): ?array {
    static $c = [];
    if (!array_key_exists($id, $c)) { $q = pdo()->prepare('SELECT codigo, nombre FROM pim_activo WHERE id = ?'); $q->execute([$id]); $c[$id] = $q->fetch() ?: null; }
    return $c[$id];
}
function rango(?int $v, int $min, int $max, string $n): ?int {
    if ($v === null) return null;
    if ($v < $min || $v > $max) throw new Fallo(422, 'value', "$n fuera de rango ($min a $max)");
    return $v;
}

// ================================================================== ServiceRequest (pim-solicitud)
function sr_fhir(array $r): array {
    $ext = [];
    if ($r['estado_declarado']) $ext[] = ['url' => sd('pim-estado-declarado'), 'valueCoding' => coding(cs('pim-valores'), $r['estado_declarado'])];
    $q = pdo()->prepare('SELECT id FROM pim_orden WHERE solicitud_id = ? ORDER BY autoria LIMIT 1'); $q->execute([$r['id']]);
    if ($o = $q->fetchColumn()) $ext[] = ['url' => sd('pim-orden-generada'), 'valueReference' => ['reference' => 'Task/' . $o]];
    $subj = $r['activo_id'] ? dev_ref($r['activo_id'], nombre_de('pim_activo', $r['activo_id'])) : loc_ref($r['local_id'], nombre_de('pim_local', $r['local_id']));
    return quitar_nulos(['resourceType' => 'ServiceRequest', 'id' => $r['id'], 'meta' => meta($r, 'pim-solicitud'), 'extension' => $ext ?: null,
        'identifier' => [['use' => 'official', 'system' => sid('solicitud'), 'value' => $r['numero']]],
        'status' => $r['estado'], 'intent' => 'order', 'priority' => $r['prioridad'],
        'code' => categoria($r),
        'subject' => $subj, 'reasonReference' => !empty($r['observacion_id']) ? [['reference' => 'Observation/' . $r['observacion_id']]] : null, 'locationReference' => $r['local_id'] ? [loc_ref($r['local_id'], nombre_de('pim_local', $r['local_id']))] : null,
        'authoredOn' => iso($r['autoria']), 'requester' => org_ref(org_organismo_id()),
        'note' => $r['descripcion'] && cfg()['exponer_detalle'] ? [['text' => $r['descripcion']]] : null,
        'reasonCode' => $r['solicitante'] ? [['text' => 'Solicitado por: ' . $r['solicitante']]] : null]);
}
/** Categoría del pedido o de la orden: código de pim-sistema si corresponde; si no, categoría local del nodo. Nunca vacía (pim-solicitud exige code). */
function categoria(array $r): array {
    $c = $r['categoria_codigo'] ?? null;
    return quitar_nulos(['coding' => $c ? [coding(isset(SISTEMAS[$c]) ? cs('pim-sistema') : cs_local('categoria'), $c)] : null,
        'text' => $r['categoria_texto'] ?: ($c ? null : 'Solicitud de mantenimiento')]);
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
        $ed = (string) ($e['valueCoding']['code'] ?? ($e['valueCode'] ?? '')); if (!in_array($ed, ESTADO_DECLARADO, true)) throw new Fallo(422, 'code-invalid', 'Estado declarado: ' . implode(' | ', ESTADO_DECLARADO));
    }
    return ['prioridad' => $pr, 'categoria_codigo' => $cat ? mb_substr((string) $cat, 0, 40) : null, 'categoria_texto' => $catTxt ? mb_substr((string) $catTxt, 0, 160) : null,
            'descripcion' => mb_substr($desc, 0, 5000), 'local_id' => $local, 'activo_id' => $activo, 'estado_declarado' => $ed,
            'solicitante' => isset($j['requester']['display']) ? mb_substr((string) $j['requester']['display'], 0, 160) : null,
            'observacion_id' => obs_motivo($j)];
}

/** reasonReference → Observation (orden pedida a partir de una medición o de un mensaje IHE PCD-15). */
function obs_motivo(array $j): ?string {
    foreach ((array) ($j['reasonReference'] ?? []) as $rr) if (tipo_ref((string) ($rr['reference'] ?? '')) === 'Observation') {
        $id = ref_id((string) $rr['reference'], 'Observation', 'ServiceRequest.reasonReference');
        if (!existe('pim_observacion', $id)) throw new Fallo(422, 'not-found', 'Observation inexistente', 'ServiceRequest.reasonReference');
        return $id;
    }
    return null;
}

// ================================================================== Task (pim-orden)
function obs_de_orden(array $r): ?string {
    $q = pdo()->prepare('SELECT id FROM pim_observacion WHERE orden_id = ? ORDER BY instante LIMIT 1'); $q->execute([$r['id']]);
    return $q->fetchColumn() ?: null;
}
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
        'code' => categoria($r),
        'description' => cfg()['exponer_detalle'] ? $r['descripcion'] : null,
        'reasonReference' => ($ob = obs_de_orden($r)) ? ['reference' => 'Observation/' . $ob] : null,
        'focus' => dev_ref($r['activo_id'], nombre_de('pim_activo', $r['activo_id'])),
        'executionPeriod' => quitar_nulos(['start' => iso($r['inicio']), 'end' => iso($r['fin'])]) ?: null,
        'authoredOn' => iso($r['autoria']), 'lastModified' => iso($r['actualizado']),
        'requester' => org_ref(org_organismo_id()), 'owner' => org_ref($r['responsable_id'], nombre_de('pim_organizacion', $r['responsable_id'])),
        'location' => loc_ref($r['local_id'], nombre_de('pim_local', $r['local_id'])), 'note' => $notas ?: null]);
}

// ================================================================== Observation (pim-medicion)
function obs_fhir(array $r): array {
    $o = ['resourceType' => 'Observation', 'id' => $r['id'],
        'meta' => quitar_nulos(['lastUpdated' => iso($r['creado']), 'profile' => [sd('pim-medicion')], 'tag' => ($r['origen'] ?? 'pim') === 'mem-dmc' ? [coding(cs('pim-origen'), 'mem-dmc')] : null]),
        'status' => 'final', 'category' => [['coding' => [coding('http://terminology.hl7.org/CodeSystem/observation-category', 'survey', 'Survey')],
            'text' => ($r['origen'] ?? '') === 'mem-dmc' ? 'Estado técnico de equipo médico (IHE MEM-DMC)' : 'Medición de instalación']],
        'code' => punto_code($r['punto']),
        'device' => dm_ref($r['activo_id'], $r['punto']),
        'effectiveDateTime' => iso($r['instante'])];
    switch ($r['valor_tipo'] ?? 'q') {
        case 'c': $o['valueCodeableConcept'] = ['text' => (string) $r['valor_texto']]; break;
        case 's': $o['valueString'] = (string) $r['valor_texto']; break;
        case 'b': $o['valueBoolean'] = $r['valor_texto'] === 'true'; break;
        default:  $o['valueQuantity'] = quitar_nulos(['value' => (float) $r['valor'], 'unit' => $r['unidad'], 'system' => $r['unidad'] ? UCUM : null, 'code' => $r['unidad']]);
    }
    if ($r['interpretacion']) $o['interpretation'] = [['coding' => [coding('http://terminology.hl7.org/CodeSystem/v3-ObservationInterpretation', $r['interpretacion'])]]];
    $rr = quitar_nulos(['low' => $r['minimo'] !== null ? ['value' => (float) $r['minimo'], 'unit' => $r['unidad']] : null,
                        'high' => $r['maximo'] !== null ? ['value' => (float) $r['maximo'], 'unit' => $r['unidad']] : null]);
    if ($rr) $o['referenceRange'] = [$rr];
    if ($r['orden_id']) $o['extension'] = [['url' => sd('pim-orden-generada'), 'valueReference' => ['reference' => 'Task/' . $r['orden_id']]]];
    return $o;
}
/** Punto de una medición: device → DeviceMetric (pim-punto, 1.1) o Device + code (forma de la 1.0). */
function punto_de_ref(string $ref, array $code): array {
    if (tipo_ref($ref) === 'DeviceMetric') {
        completar_ids_punto();
        $id = ref_id($ref, 'DeviceMetric', 'Observation.device');
        $q = pdo()->prepare('SELECT activo_id, punto FROM pim_punto WHERE id = ?'); $q->execute([$id]);
        $p = $q->fetch() ?: throw new Fallo(422, 'not-found', 'DeviceMetric inexistente', 'Observation.device');
        return [$p['activo_id'], $p['punto']];
    }
    $act = ref_id($ref, 'Device', 'Observation.device'); if (!existe('pim_activo', $act)) throw new Fallo(422, 'not-found', 'Device inexistente', 'Observation.device');
    $c = $code['coding'][0] ?? null;
    $punto = $c ? (string) ($c['code'] ?? '') : strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) ($code['text'] ?? '')));
    if (!preg_match('/^[A-Z0-9_\-]{1,40}$/', $punto)) throw new Fallo(422, 'code-invalid', 'code: punto de medición (pim-punto, p. ej. PRES-LINEA, o código MDC)', 'Observation.code');
    return [$act, $punto];
}
function obs_desde(array $j): array {
    if (($j['resourceType'] ?? '') !== 'Observation') throw new Fallo(422, 'invalid', 'Se esperaba Observation');
    sin_pacientes($j);
    if (isset($j['subject'])) throw new Fallo(422, 'business-rule', 'pim-medicion no usa subject: el punto va en device', 'Observation.subject');
    $dref = (string) ($j['device']['reference'] ?? ''); if ($dref === '') throw new Fallo(422, 'required', 'device es obligatorio', 'Observation.device');
    [$act, $punto] = punto_de_ref($dref, (array) ($j['code'] ?? []));
    $t = a_local($j['effectiveDateTime'] ?? ($j['effectiveInstant'] ?? null), 'Observation.effectiveDateTime') ?? throw new Fallo(422, 'required', 'effectiveDateTime es obligatorio', 'Observation.effectiveDateTime');
    $f = ['activo_id' => $act, 'punto' => $punto, 'instante' => $t, 'valor' => null, 'unidad' => null, 'valor_tipo' => 'q', 'valor_texto' => null, 'minimo' => null, 'maximo' => null,
          'origen' => in_array('mem-dmc', array_column((array) ($j['meta']['tag'] ?? []), 'code'), true) ? 'mem-dmc' : 'pim'];
    if (isset($j['valueQuantity'])) {
        $vq = $j['valueQuantity'];
        if (!is_numeric($vq['value'] ?? null)) throw new Fallo(422, 'required', 'valueQuantity.value es obligatorio', 'Observation.valueQuantity');
        $u = (string) ($vq['code'] ?? $vq['unit'] ?? ''); if ($u === '' || mb_strlen($u) > 20) throw new Fallo(422, 'required', 'valueQuantity.code (unidad UCUM) es obligatorio', 'Observation.valueQuantity.code');
        $f['valor'] = (float) $vq['value']; $f['unidad'] = $u;
        $min = $j['referenceRange'][0]['low']['value'] ?? null; $max = $j['referenceRange'][0]['high']['value'] ?? null;
        $f['minimo'] = is_numeric($min) ? (float) $min : null; $f['maximo'] = is_numeric($max) ? (float) $max : null;
    } elseif (isset($j['valueCodeableConcept'])) {
        $cc = $j['valueCodeableConcept'];
        $f['valor_tipo'] = 'c'; $f['valor_texto'] = mb_substr((string) ($cc['text'] ?? ($cc['coding'][0]['code'] ?? ($cc['coding'][0]['display'] ?? ''))), 0, 200);
    } elseif (isset($j['valueString'])) { $f['valor_tipo'] = 's'; $f['valor_texto'] = mb_substr((string) $j['valueString'], 0, 200); }
    elseif (isset($j['valueBoolean'])) { $f['valor_tipo'] = 'b'; $f['valor_texto'] = $j['valueBoolean'] ? 'true' : 'false'; }
    else throw new Fallo(422, 'required', 'value[x] es obligatorio (Quantity, CodeableConcept, string o boolean)', 'Observation.value[x]');
    return $f;
}

// ================================================================== RiskAssessment (pim-riesgo) y Group
function riesgo_fhir(array $a): ?array {
    $i = iru($a); if (!$i) return null;
    return quitar_nulos(['resourceType' => 'RiskAssessment', 'id' => $a['id'] . '-iru', 'meta' => ['lastUpdated' => iso($a['actualizado']), 'profile' => [sd('pim-riesgo')]],
        'status' => 'final', 'method' => ['coding' => [coding(cs('pim-metodo-riesgo'), 'iru', 'Índice de Riesgo Unificado')]],
        'subject' => ['reference' => 'Group/' . $a['id'] . '-g', 'display' => $a['nombre']],
        'occurrenceDateTime' => iso($a['actualizado']), 'performer' => ['reference' => 'Organization/' . org_organismo_id()],
        'basis' => [dev_ref($a['id'], $a['nombre'])],
        'prediction' => [['qualitativeRisk' => ['coding' => [coding(cs('pim-valores'), 'banda-' . $i['banda'], 'Banda ' . $i['banda'])], 'text' => 'Banda ' . $i['banda'] . ' (IRU ' . $i['total'] . ')'],
            'rationale' => 'IRU = F ' . $i['F'] . ' + R ' . $i['R'] . ' + M ' . $i['M'] . ' + A ' . $i['A'] . ' = ' . $i['total'] . ($i['ajuste'] ? ' · ' . $i['ajuste'] : '')]],
        'extension' => [iru_ext($i)],
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
