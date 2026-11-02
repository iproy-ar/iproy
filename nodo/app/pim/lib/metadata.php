<?php
/**
 * iProy · Servidor PIM v1.0 · CapabilityStatement, SMART configuration y CodeSystems PIM · 30-10-2026
 * Declaración computable de conformidad: PIM v0.9 nivel Básico completo + perfiles INV, SOL, OT, RSG, TEL y FED.
 */
declare(strict_types=1);

namespace IproyPim;

function capability(): array {
    $sp = fn(string $n, string $t, string $doc) => ['name' => $n, 'type' => $t, 'documentation' => $doc];
    $int = fn(array $c) => array_map(fn($x) => ['code' => $x], $c);
    $rec = function (string $tipo, array $perfiles, array $inter, array $params, string $doc, bool $versionado = true) use ($int) {
        return array_filter(['type' => $tipo, 'profile' => sd($perfiles[0]), 'supportedProfile' => count($perfiles) > 1 ? array_map('IproyPim\sd', array_slice($perfiles, 1)) : null,
            'documentation' => $doc, 'interaction' => $int($inter), 'versioning' => $versionado ? 'versioned-update' : 'no-version',
            'readHistory' => $versionado, 'updateCreate' => false, 'conditionalDelete' => 'not-supported',
            'searchParam' => array_merge([['name' => '_id', 'type' => 'token', 'documentation' => 'ID lógico']], $params)], fn($x) => $x !== null);
    };
    $o = cfg()['organismo'];
    return [
        'resourceType' => 'CapabilityStatement',
        'id' => 'pim-servidor-' . strtolower(nodo_id()),
        'url' => cfg()['ns'] . '/CapabilityStatement/pim-servidor',
        'version' => VERSION_API,
        'name' => 'ServidorPIM',
        'title' => 'Servidor PIM ' . VERSION_API . ' · Protocolo de Interoperabilidad de Mantenimiento v' . VERSION_PIM,
        'status' => 'active', 'experimental' => false, 'date' => '2026-10-30',
        'publisher' => $o['nombre'],
        'description' => 'Servidor de referencia del Protocolo de Interoperabilidad de Mantenimiento (PIM v' . VERSION_PIM . ') sobre HL7 FHIR R4. '
            . 'Gestiona inventario de instalaciones y equipos, solicitudes, órdenes de trabajo con máquina de estados, riesgo (IRU), '
            . 'telemetría y trazabilidad (historial y procedencia con huella encadenada). No contiene datos de pacientes.',
        'kind' => 'instance',
        'software' => ['name' => 'iProy · Servidor PIM', 'version' => VERSION_API],
        'implementation' => ['description' => 'Nodo ' . nodo_id() . ' · ' . $o['nombre'], 'url' => base()],
        'fhirVersion' => FHIR_VERSION,
        'format' => ['application/fhir+json', 'json'],
        'implementationGuide' => [cfg()['ns'] . '/ImplementationGuide/pim|' . VERSION_PIM],
        'extension' => [['url' => sd('pim-conformidad'), 'extension' => [
            ['url' => 'nivel', 'valueCode' => 'basico'],
            ['url' => 'perfilIntegracion', 'valueCode' => 'PIM-INV'], ['url' => 'perfilIntegracion', 'valueCode' => 'PIM-SOL'],
            ['url' => 'perfilIntegracion', 'valueCode' => 'PIM-OT'], ['url' => 'perfilIntegracion', 'valueCode' => 'PIM-RSG'],
            ['url' => 'perfilIntegracion', 'valueCode' => 'PIM-TEL'], ['url' => 'perfilIntegracion', 'valueCode' => 'PIM-FED'],
            ['url' => 'roles', 'valueString' => 'GM gestor · EJ ejecutor · SO solicitante · FT fuente de telemetría · CI consulta'],
        ]]],
        'rest' => [[
            'mode' => 'server',
            'documentation' => 'OAuth2 SMART Backend Services (client_credentials + private_key_jwt RS384/ES384). Alcances SMART v1 y v2: '
                . 'system/<Recurso>.read|write o system/<Recurso>.cruds. Token de ' . (int) cfg()['token_ttl'] . ' s. Cada cliente tiene un rol PIM '
                . '(GM, EJ, SO, FT, CI) que limita qué puede dar de alta, modificar y qué transiciones de orden puede hacer. '
                . 'Las modificaciones exigen If-Match (control de concurrencia). Nunca hay borrado físico.',
            'security' => [
                'cors' => false,
                'service' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/restful-security-service', 'code' => 'SMART-on-FHIR']]]],
                'extension' => [['url' => 'http://fhir-registry.smarthealthit.org/StructureDefinition/oauth-uris',
                    'extension' => [['url' => 'token', 'valueUri' => url_token()]]]],
                'description' => 'TLS obligatorio fuera del propio equipo. Auditoría de cada acceso (AuditEvent interno).',
            ],
            'resource' => [
                $rec('Organization', ['pim-organismo', 'pim-empresa'], ['read', 'vread', 'search-type', 'create', 'update', 'history-instance', 'history-type'],
                    [$sp('identifier', 'token', 'sid/organismo · sid/empresa'), $sp('name', 'string', 'Nombre'), $sp('type', 'token', 'prov | dept | bus')],
                    'Organismo, áreas internas y empresas contratistas. Alta y modificación: rol GM.'),
                $rec('Location', ['pim-local'], ['read', 'vread', 'search-type', 'create', 'update', 'history-instance', 'history-type'],
                    [$sp('identifier', 'token', 'sid/local'), $sp('name', 'string', 'Nombre'), $sp('status', 'token', 'active | inactive | suspended'), $sp('partof', 'reference', 'Location contenedora')],
                    'Edificios, niveles, locales y áreas; grupo de uso médico (AEA 90364-7-710) y ambiente controlado.'),
                $rec('Device', ['pim-activo', 'pim-sistema', 'pim-equipo-medico'], ['read', 'vread', 'search-type', 'create', 'update', 'history-instance', 'history-type'],
                    [$sp('identifier', 'token', 'sid/activo'), $sp('device-name', 'string', 'Nombre'), $sp('location', 'reference', 'Location'),
                     $sp('type', 'token', 'pim-sistema (Anexo B) o clase de equipo'), $sp('status', 'token', 'active | inactive'), $sp('parent', 'reference', 'Device padre (ISO 14224)'),
                     $sp('manufacturer', 'string', 'Fabricante')],
                    'Sistemas de instalación, activos y equipos médicos con categoría de riesgo NFPA 99, redundancia e IRU.'),
                $rec('ServiceRequest', ['pim-solicitud'], ['read', 'vread', 'search-type', 'create', 'history-instance', 'history-type'],
                    [$sp('identifier', 'token', 'sid/solicitud'), $sp('status', 'token', 'active | completed | revoked'), $sp('priority', 'token', 'routine | urgent | asap | stat'),
                     $sp('subject', 'reference', 'Location o Device'), $sp('authored', 'date', 'Fecha de alta')],
                    'PIM-SOL: el alta crea la orden (Task) enlazada; la prioridad sale del IRU del activo si no se indica. Roles GM y SO.'),
                $rec('Task', ['pim-orden'], ['read', 'vread', 'search-type', 'update', 'history-instance', 'history-type'],
                    [$sp('identifier', 'token', 'sid/orden'), $sp('status', 'token', 'Estado FHIR'), $sp('business-status', 'token', 'pim-estado-orden'),
                     $sp('owner', 'reference', 'Organization responsable'), $sp('priority', 'token', 'routine | urgent | asap | stat'), $sp('location', 'reference', 'Location'),
                     $sp('focus', 'reference', 'Device'), $sp('based-on', 'reference', 'ServiceRequest'), $sp('code', 'token', 'Categoría'),
                     $sp('authored-on', 'date', 'Alta'), $sp('modified', 'date', 'Última modificación'), $sp('_lastUpdated', 'date', 'Última modificación')],
                    'PIM-OT: orden de trabajo con máquina de estados (Tabla 9-2). Cambios por PUT (If-Match) o por la operación $transicion. '
                    . 'El rol EJ solo ve y opera las órdenes de su organización.'),
                $rec('Observation', ['pim-medicion'], ['read', 'search-type', 'create'],
                    [$sp('device', 'reference', 'Device'), $sp('code', 'token', 'pim-punto'), $sp('date', 'date', 'Instante'), $sp('interpretation', 'token', 'N | H | L')],
                    'PIM-TEL: mediciones de instalaciones. Fuera de rango → solicitud y orden automáticas (sin duplicar órdenes abiertas). Roles GM y FT.', false),
                $rec('RiskAssessment', ['pim-riesgo'], ['read', 'search-type'],
                    [$sp('subject', 'reference', 'Group/{activo}-g o Device'), $sp('banda', 'token', 'A | B | C | D (parámetro PIM)')],
                    'PIM-RSG: IRU explicado (F + R + M + A, banda y ajuste por redundancia).', false),
                $rec('Group', ['pim-grupo-activo'], ['read', 'search-type'], [$sp('member', 'reference', 'Device')], 'Grupo de un activo (sujeto del RiskAssessment).', false),
                $rec('Provenance', ['pim-procedencia'], ['search-type'], [$sp('target', 'reference', 'Tipo/id (obligatorio)')],
                    'PIM-FED: una procedencia por versión, con huella SHA-256 encadenada a la anterior (detecta alteraciones).', false),
            ],
            'interaction' => [['code' => 'batch', 'documentation' => 'Bundle batch con POST de Observation y ServiceRequest (máx. 500)']],
            'operation' => [['name' => 'transicion', 'definition' => cfg()['ns'] . '/OperationDefinition/Task-transicion',
                'documentation' => 'POST Task/{id}/$transicion con Parameters: estado, motivo, responsable, prioridad, tipoMantenimiento, modoFalla, nota']],
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

/** Catálogos PIM publicados como CodeSystem (derivados de las constantes del modelo: nunca se desalinean). */
function catalogos(): array {
    $c = fn(array $m) => array_map(fn($k, $v) => ['code' => (string) $k, 'display' => $v], array_keys($m), $m);
    return [
        'pim-estado-orden' => ['Estado de la orden de trabajo (Tabla 9-2)', $c(array_combine(array_keys(ESTADOS_ORDEN), array_map(fn($k, $v) => "$k → Task.status $v", array_keys(ESTADOS_ORDEN), ESTADOS_ORDEN)))],
        'pim-tipo-mantenimiento' => ['Tipo de mantenimiento (EN 13306)', $c(array_combine(TIPOS_MANT, TIPOS_MANT))],
        'pim-sistema' => ['Sistemas de instalación hospitalaria (Anexo B)', $c(SISTEMAS)],
        'pim-categoria-riesgo' => ['Categoría de riesgo NFPA 99', $c(['1' => 'Falla puede causar lesión grave o muerte', '2' => 'Lesión menor', '3' => 'Molestia sin lesión', '4' => 'Sin impacto en la atención'])],
        'pim-banda-iru' => ['Banda del Índice de Riesgo Unificado', $c(['A' => 'IRU ≥ 18 · prioridad stat', 'B' => 'IRU 14–17 · asap', 'C' => 'IRU 10–13 · urgent', 'D' => 'IRU < 10 · routine'])],
        'pim-grupo-local' => ['Grupo de uso médico del local (AEA 90364-7-710)', $c(['0' => 'Grupo 0', '1' => 'Grupo 1', '2a' => 'Grupo 2a', '2b' => 'Grupo 2b'])],
        'pim-estado-declarado' => ['Estado declarado del activo al pedir', $c(array_combine(ESTADO_DECLARADO, ESTADO_DECLARADO))],
    ];
}
function codesystem(string $id): ?array {
    $k = catalogos()[$id] ?? null;
    if (!$k) return null;
    return ['resourceType' => 'CodeSystem', 'id' => $id, 'url' => cs($id), 'version' => VERSION_PIM, 'name' => str_replace('-', '', ucwords($id, '-')),
        'title' => $k[0], 'status' => 'active', 'content' => 'complete', 'caseSensitive' => true, 'count' => count($k[1]), 'concept' => $k[1]];
}
function codesystems_bundle(): array {
    $e = [];
    foreach (array_keys(catalogos()) as $id) $e[] = ['fullUrl' => base() . '/CodeSystem/' . $id, 'resource' => codesystem($id), 'search' => ['mode' => 'match']];
    return ['resourceType' => 'Bundle', 'type' => 'searchset', 'total' => count($e), 'entry' => $e];
}
