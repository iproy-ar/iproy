<?php
/**
 * iProy · Servidor PIM v1.1 · CapabilityStatement, SMART configuration, artefactos de la guía y Declaración de Integración · 02-11-2026
 *
 * Los CodeSystems, ValueSets, StructureDefinitions, CapabilityStatements de actores y OperationDefinitions se sirven tal como los
 * compila la especificación (conf/pim-guia/, copiados de especificacion/fsh-generated por herramientas/guia-al-nodo.sh): el servidor
 * no tiene una copia propia de las terminologías y no se puede desalinear (lección L-329).
 */
declare(strict_types=1);

namespace IproyPim;

const ARTEFACTOS = ['CodeSystem', 'ValueSet', 'ConceptMap', 'StructureDefinition', 'CapabilityStatement', 'OperationDefinition', 'SearchParameter', 'ImplementationGuide'];

function dir_guia(): string { return raiz() . '/conf/pim-guia'; }
function artefacto(string $tipo, string $id): ?array {
    if (!in_array($tipo, ARTEFACTOS, true) || !es_id($id)) return null;
    $f = dir_guia() . "/$tipo-$id.json";
    return is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
}
function artefactos_bundle(string $tipo): array {
    $e = [];
    foreach (glob(dir_guia() . "/$tipo-*.json") ?: [] as $f) {
        $r = json_decode((string) file_get_contents($f), true);
        if (is_array($r)) $e[] = ['fullUrl' => base() . "/$tipo/" . $r['id'], 'resource' => $r, 'search' => ['mode' => 'match']];
    }
    return ['resourceType' => 'Bundle', 'type' => 'searchset', 'total' => count($e)] + ($e ? ['entry' => $e] : []);
}
function codesystem(string $id): ?array { return artefacto('CodeSystem', $id); }
function codesystems_bundle(): array { return artefactos_bundle('CodeSystem'); }
/** Códigos de un CodeSystem de la guía (incluye los anidados). */
function codigos(string $id): array {
    $cs = codesystem($id) ?? [];
    $l = []; $rec = function (array $c) use (&$rec, &$l) { foreach ($c as $x) { $l[] = (string) $x['code']; if (!empty($x['concept'])) $rec($x['concept']); } };
    $rec((array) ($cs['concept'] ?? []));
    return $l;
}

/** Opciones y agrupamientos IHE que este nodo declara (según los módulos instalados). */
function conformidad(): array {
    $mod = fn(string $m) => is_file(raiz() . "/modulos/$m/modulo.json");
    $perf = ['PIM-INV', 'PIM-SOL', 'PIM-OT', 'PIM-REG', 'PIM-RSG', 'PIM-TEL', 'PIM-FED'];
    $op = ['mcsd-update', 'transicion', 'colmena'];
    $ihe = ['ITI mCSD · Directory (ITI-90)', 'ITI mCSD · Directory, opción Update (ITI-91)'];
    if ($mod('mqtt')) $op[] = 'mqtt';
    if ($mod('mem')) { $perf[] = 'PIM-EQM'; $op[] = 'mem-dmc'; $ihe[] = 'DEV MEM-DMC · Device Management Information Consumer (PCD-15)'; }
    if ($mod('alarmas')) $perf[] = 'PIM-ALR';
    $nivel = 'basico';                                                    // Intermedio exige además PRV, ENS y KPI (pendientes en 1.1)
    return ['nivel' => $nivel, 'perfiles' => $perf, 'opciones' => $op, 'ihe' => $ihe, 'actores' => ['GM', 'DI'],
            'pendientes' => array_values(array_diff(['PIM-PRV', 'PIM-ENS', 'PIM-KPI', 'PIM-ALR', 'PIM-EQM'], $perf))];
}

function capability(): array {
    $sp = fn(string $n, string $t, string $doc) => ['name' => $n, 'type' => $t, 'documentation' => $doc];
    $int = fn(array $c) => array_map(fn($x) => ['code' => $x], $c);
    $rec = function (string $tipo, array $perfiles, array $inter, array $params, string $doc, bool $versionado = true, array $extra = []) use ($int) {
        return array_filter(['type' => $tipo, 'profile' => null, 'supportedProfile' => array_map('IproyPim\sd', $perfiles),
            'documentation' => $doc, 'interaction' => $int($inter), 'versioning' => $versionado ? 'versioned-update' : 'no-version',
            'readHistory' => $versionado, 'updateCreate' => false, 'conditionalDelete' => 'not-supported',
            'searchParam' => array_merge([['name' => '_id', 'type' => 'token', 'documentation' => 'ID lógico']], $params)] + $extra, fn($x) => $x !== null);
    };
    $o = cfg()['organismo']; $c = conformidad();
    $ext = [['url' => 'nivel', 'valueCode' => $c['nivel']]];
    foreach ($c['perfiles'] as $p) $ext[] = ['url' => 'perfilIntegracion', 'valueCode' => $p];
    foreach ($c['actores'] as $a) $ext[] = ['url' => 'actor', 'valueCode' => $a];
    foreach ($c['opciones'] as $p) $ext[] = ['url' => 'opcion', 'valueCode' => $p];
    foreach ($c['ihe'] as $p) $ext[] = ['url' => 'perfilIHE', 'valueString' => $p];
    return [
        'resourceType' => 'CapabilityStatement',
        'id' => 'pim-servidor-' . strtolower(nodo_id()),
        'url' => cfg()['ns'] . '/CapabilityStatement/pim-servidor',
        'version' => VERSION_API,
        'name' => 'ServidorPIM',
        'title' => 'Servidor PIM ' . VERSION_API . ' · Protocolo de Interoperabilidad de Mantenimiento ' . VERSION_PIM,
        'status' => 'active', 'experimental' => false, 'date' => '2026-11-02',
        'publisher' => $o['nombre'],
        'description' => 'Servidor de referencia del PIM ' . VERSION_PIM . ' sobre HL7 FHIR R4, alineado con IHE: directorio mCSD (ITI-90 e ITI-91), '
            . 'puente con equipos médicos por IHE MEM-DMC (PCD-15) si el módulo «mem» está instalado. Inventario de PMA e instalaciones, solicitudes, '
            . 'órdenes con la definición de flujo PIM-OT, riesgo (IRU), telemetría, informe al REFES y trazabilidad con huella encadenada. Sin datos de pacientes.',
        'kind' => 'instance',
        'instantiates' => [cfg()['ns'] . '/CapabilityStatement/pim-gm'],
        'imports' => ['https://profiles.ihe.net/ITI/mCSD/CapabilityStatement/IHE.mCSD.Directory', 'https://profiles.ihe.net/ITI/mCSD/CapabilityStatement/IHE.mCSD.Directory.Update'],
        'software' => ['name' => 'iProy · Servidor PIM', 'version' => VERSION_API],
        'implementation' => ['description' => 'Nodo ' . nodo_id() . ' · ' . $o['nombre'], 'url' => base()],
        'fhirVersion' => FHIR_VERSION,
        'format' => ['application/fhir+json', 'json'],
        'implementationGuide' => [cfg()['ns'] . '/ImplementationGuide/ar.iproy.pim|' . VERSION_PIM],
        'extension' => [['url' => sd('pim-conformidad'), 'extension' => $ext]],
        'rest' => [[
            'mode' => 'server',
            'documentation' => 'OAuth2 SMART Backend Services (client_credentials + private_key_jwt RS384/ES384). Alcances SMART v1 y v2. '
                . 'Token de ' . (int) cfg()['token_ttl'] . ' s. Cada cliente tiene un rol PIM (actor): GM, EJ, SO, FT, CI o RE. '
                . 'Las modificaciones exigen If-Match. Nunca hay borrado físico. Auditoría de cada acceso.',
            'security' => [
                'cors' => false,
                'service' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/restful-security-service', 'code' => 'SMART-on-FHIR']]]],
                'description' => 'TLS obligatorio fuera del propio equipo. Auditoría de cada acceso.',
            ],
            'resource' => [
                $rec('Organization', ['pim-organismo', 'pim-empresa'], ['read', 'vread', 'search-type', 'create', 'update', 'history-instance', 'history-type'],
                    [$sp('identifier', 'token', 'sid/organismo · sid/empresa'), $sp('name', 'string', 'Nombre (:exact, :contains)'), $sp('type', 'token', 'facility | prov | dept | bus'),
                     $sp('partof', 'reference', 'Organization contenedora'), $sp('active', 'token', 'true | false')],
                    'PIM-INV y IHE ITI-90/91: organismo (mCSD FacilityOrganization), áreas internas y empresas (mCSD Organization). Alta y modificación: rol GM.'),
                $rec('Location', ['pim-establecimiento', 'pim-local'], ['read', 'vread', 'search-type', 'create', 'update', 'history-instance', 'history-type'],
                    [$sp('identifier', 'token', 'sid/local'), $sp('name', 'string', 'Nombre (:exact, :contains)'), $sp('status', 'token', 'active | inactive | suspended'),
                     $sp('partof', 'reference', 'Location contenedora (el establecimiento es la raíz)'), $sp('organization', 'reference', 'Organization responsable'),
                     $sp('type', 'token', 'facility (establecimiento) o el tipo del local: Edificio, Ala, Nivel, Local, Cama, Área')],
                    'PIM-INV y IHE ITI-90/91: establecimiento (mCSD FacilityLocation) y locales (mCSD Location) con grupo de uso médico y ambiente controlado.'),
                $rec('Device', ['pim-pma', 'pim-sistema', 'pim-activo'], ['read', 'vread', 'search-type', 'create', 'update', 'history-instance', 'history-type'],
                    [$sp('identifier', 'token', 'sid/activo · sid/eui64 · sid/anmat-pm'), $sp('device-name', 'string', 'Nombre'), $sp('location', 'reference', 'Location'),
                     $sp('type', 'token', 'pim-sistema o clase de equipo'), $sp('status', 'token', 'active | inactive'), $sp('parent', 'reference', 'Device padre (ISO 14224)'),
                     $sp('manufacturer', 'string', 'Fabricante'), $sp('categoria-alcance', 'token', 'A | B | C | D')],
                    'PMA (datos REFES y EUI-64), sistemas de instalación y activos, con categoría de alcance, riesgo NFPA 99, redundancia e IRU.', true,
                    ['operation' => [['name' => 'refes', 'definition' => cfg()['ns'] . '/OperationDefinition/Device-refes',
                        'documentation' => 'PIM-9: GET Device/$refes[?completos=true|false] o Device/{id}/$refes. Roles GM, RE y CI.']]]),
                $rec('DeviceMetric', ['pim-punto'], ['read', 'search-type'],
                    [$sp('source', 'reference', 'Device'), $sp('type', 'token', 'pim-punto o código MDC'), $sp('identifier', 'token', '<código del activo>/<punto>')],
                    'Puntos de medición; se crean al configurarlos o con la primera medición.', false),
                $rec('ServiceRequest', ['pim-solicitud'], ['read', 'vread', 'search-type', 'create', 'history-instance', 'history-type'],
                    [$sp('identifier', 'token', 'sid/solicitud'), $sp('status', 'token', 'active | completed | revoked'), $sp('priority', 'token', 'routine | urgent | asap | stat'),
                     $sp('subject', 'reference', 'Location o Device'), $sp('authored', 'date', 'Fecha de alta')],
                    'PIM-SOL (PIM-3): el alta crea la orden (Task) enlazada; la prioridad sale del IRU del activo si no se indica. reasonReference admite una Observation. Roles GM y SO.'),
                $rec('Task', ['pim-orden'], ['read', 'vread', 'search-type', 'update', 'history-instance', 'history-type'],
                    [$sp('identifier', 'token', 'sid/orden'), $sp('status', 'token', 'Estado FHIR'), $sp('business-status', 'token', 'pim-estado-orden'),
                     $sp('owner', 'reference', 'Organization responsable'), $sp('priority', 'token', 'routine | urgent | asap | stat'), $sp('location', 'reference', 'Location'),
                     $sp('focus', 'reference', 'Device'), $sp('based-on', 'reference', 'ServiceRequest'), $sp('code', 'token', 'Categoría'),
                     $sp('authored-on', 'date', 'Alta'), $sp('modified', 'date', 'Última modificación'), $sp('_lastUpdated', 'date', 'Última modificación'),
                     $sp('decision-prioridad', 'token', 'aceptada | cambiada | rechazada (parámetro PIM, SearchParameter pim-task-decision-prioridad)')],
                    'PIM-OT (PIM-4 y PIM-5): definición de flujo PIM-OT (Tabla 9-6). Cambios por PUT (If-Match) o $transicion. El rol EJ solo ve y opera las órdenes de su organización.', true,
                    ['operation' => [['name' => 'transicion', 'definition' => cfg()['ns'] . '/OperationDefinition/Task-transicion']]]),
                $rec('Observation', ['pim-medicion'], ['read', 'search-type', 'create'],
                    [$sp('device', 'reference', 'DeviceMetric (o Device)'), $sp('code', 'token', 'pim-punto o MDC'), $sp('date', 'date', 'Instante'), $sp('interpretation', 'token', 'N | H | L')],
                    'PIM-TEL (PIM-6) y PIM-EQM: valor Quantity, CodeableConcept, string o boolean. Fuera de rango → solicitud y orden automáticas. Roles GM y FT.', false),
                $rec('RiskAssessment', ['pim-riesgo'], ['read', 'search-type'],
                    [$sp('subject', 'reference', 'Group/{activo}-g o Device'), $sp('banda', 'token', 'A | B | C | D (parámetro PIM)')],
                    'PIM-RSG (PIM-7): IRU explicado (F + R + M + A, banda y ajuste por redundancia).', false),
                $rec('Group', ['pim-grupo-activo'], ['read', 'search-type'], [$sp('member', 'reference', 'Device')], 'Grupo de un activo (sujeto del RiskAssessment).', false),
                $rec('Provenance', ['pim-procedencia'], ['search-type'], [$sp('target', 'reference', 'Tipo/id (obligatorio)')],
                    'PIM-FED (PIM-8): una procedencia por versión, con huella SHA-256 encadenada a la anterior.', false),
            ],
            'interaction' => [['code' => 'batch', 'documentation' => 'Bundle batch con POST de Observation y ServiceRequest (máx. 500)']],
        ]],
    ];
}

function smart_config(): array {
    $tipos = array_keys(RECURSOS);
    return [
        'issuer' => base(),
        'token_endpoint' => url_token(),
        'token_endpoint_auth_methods_supported' => ['private_key_jwt'],
        'token_endpoint_auth_signing_alg_values_supported' => ['RS384', 'ES384'],
        'grant_types_supported' => ['client_credentials'],
        'scopes_supported' => array_merge(['system/*.read', 'system/*.write', 'system/*.cruds'], array_map(fn($t) => "system/$t.cruds", $tipos)),
        'capabilities' => ['client-confidential-asymmetric', 'permission-v1', 'permission-v2'],
    ];
}

/** Declaración de Integración PIM (Anexo I del PIM; versión en VERSION_PIM), generada desde la conformidad que declara este servidor. */
function declaracion(): array {
    $c = conformidad();
    $prod = is_file(raiz() . '/producto.json') ? (json_decode((string) file_get_contents(raiz() . '/producto.json'), true) ?: []) : [];
    $opPorPerfil = ['PIM-INV' => ['mcsd-update'], 'PIM-OT' => ['transicion', 'colmena'], 'PIM-FED' => ['colmena'], 'PIM-TEL' => ['mqtt'], 'PIM-EQM' => ['mem-dmc']];
    return [
        'documento' => 'Declaración de Integración PIM',
        'basada_en' => 'IHE Integration Statement',
        'producto' => ['nombre' => ($prod['nombre'] ?? 'iProy') . ' · Servidor PIM', 'version' => VERSION_API, 'proveedor' => $prod['autor'] ?? ($prod['nombre'] ?? 'iProy'),
                       'fuente' => $prod['fuente'] ?? null, 'nodo' => nodo_id()],
        'fecha' => date('Y-m-d'),
        'version_pim' => VERSION_PIM,
        'nivel' => $c['nivel'],
        'perfiles' => array_map(fn($p) => ['perfil' => $p, 'actor' => 'GM', 'opciones' => array_values(array_intersect($opPorPerfil[$p] ?? [], $c['opciones']))], $c['perfiles']),
        'perfiles_ihe' => array_map(fn($p) => ['perfil' => $p, 'agrupado_con' => 'GM'], $c['ihe']),
        'pendientes' => $c['pendientes'],
        'vinculos' => ['capability_statement' => base() . '/metadata', 'guia' => cfg()['ns'] . '/ImplementationGuide/ar.iproy.pim', 'pruebas' => 'pruebas/pim.sh y pruebas/mem.sh del repositorio'],
    ];
}
