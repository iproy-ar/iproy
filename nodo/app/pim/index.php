<?php
/**
 * iProy · Servidor PIM v1.1 (Protocolo de Interoperabilidad de Mantenimiento 0.9.2 sobre HL7 FHIR R4, alineado con IHE) · 02-11-2026
 *
 * Sistema PARALELO: base propia «iproy_pim», clientes y claves propios. No toca iProy ni la API /fhir v0.3.
 *
 *   GET    /pim/r4/metadata                              CapabilityStatement (público)
 *   GET    /pim/r4/.well-known/smart-configuration       descubrimiento OAuth2 (público)
 *   GET    /pim/r4/{CodeSystem|ValueSet|ConceptMap|StructureDefinition|CapabilityStatement|OperationDefinition|SearchParameter}[/{id}]   guía PIM (público)
 *   GET    /pim/r4/Device/$refes · Device/{id}/$refes     datos mínimos del REFES (PIM-9; roles GM, RE, CI)
 *   GET    /pim/declaracion                              Declaración de Integración PIM (JSON, público)
 *   POST   /pim/auth/token                               SMART Backend Services → access_token (5 min)
 *   GET    /pim/r4/{Tipo}?…  ·  POST /pim/r4/{Tipo}/_search   búsqueda
 *   GET    /pim/r4/{Tipo}/{id}  ·  …/_history/{v}         lectura y lectura de versión
 *   POST   /pim/r4/{Tipo}                                 alta (Organization, Location, Device, ServiceRequest, Observation)
 *   PUT    /pim/r4/{Tipo}/{id}   (If-Match obligatorio)   modificación
 *   POST   /pim/r4/Task/{id}/$transicion                  cambio de estado de la orden (Tabla 9-2)
 *   GET    /pim/r4/{Tipo}/_history · /{Tipo}/{id}/_history   historial (PIM-FED)
 *   POST   /pim/r4   (Bundle batch)                       lote de Observation / ServiceRequest
 *   /pim  →  Consola PIM (/pim/consola/)
 */
declare(strict_types=1);

namespace IproyPim;

require __DIR__ . '/lib/nucleo.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/modelo.php';
require __DIR__ . '/lib/api.php';
require __DIR__ . '/lib/metadata.php';

try {
    $ruta = rtrim(rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)), '/');
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($ruta === '/pim' || $ruta === '/pim/consola') { header('Location: /pim/consola/', true, 302); exit; }
    if ($ruta === '/pim/auth/token') emitir_token();
    if ($ruta === '/pim/declaracion') responder(200, declaracion(), 'application/json; charset=utf-8');
    if (!str_starts_with($ruta . '/', '/pim/r4/')) responder(404, oo('not-found', 'La API PIM está en /pim/r4 (ver /pim/r4/metadata)'));
    $p = array_values(array_filter(explode('/', substr($ruta, strlen('/pim/r4'))), 'strlen'));
    $n = count($p);

    if ($n === 0) {
        if ($metodo === 'POST') lote();
        responder(404, oo('not-found', 'Usar /pim/r4/metadata'));
    }
    if ($p === ['metadata']) { $metodo === 'GET' || responder(405, oo('not-supported', 'Solo GET')); responder(200, capability()); }
    if ($p === ['.well-known', 'smart-configuration']) responder(200, smart_config(), 'application/json; charset=utf-8');
    if (in_array($p[0], ARTEFACTOS, true)) {                                // artefactos de la guía: públicos y de solo lectura
        $metodo === 'GET' || responder(405, oo('not-supported', 'Los artefactos de la guía son de solo lectura'));
        if ($n === 1) responder(200, artefactos_bundle($p[0]));
        responder(200, artefacto($p[0], $p[1]) ?? throw new Fallo(404, 'not-found', "{$p[0]}/{$p[1]} no existe"));
    }
    if ($p[0] === 'Device' && ($p[1] ?? '') === '$refes' && $n === 2 && $metodo === 'GET') op_refes(null);
    if ($p[0] === 'Device' && ($p[2] ?? '') === '$refes' && $n === 3 && $metodo === 'GET') op_refes($p[1]);

    $tipo = $p[0];
    if (!isset(RECURSOS[$tipo])) responder(404, oo('not-supported', "Recurso no soportado: $tipo. Soportados: " . implode(', ', array_keys(RECURSOS))));
    $id = $p[1] ?? null;
    if ($id !== null && !str_starts_with($id, '_') && !str_starts_with($id, '$') && !es_id($id)) throw new Fallo(400, 'invalid', 'ID con formato inválido');

    switch (true) {
        case $n === 1 && $metodo === 'GET':                                   buscar($tipo);
        case $n === 1 && $metodo === 'POST':                                  crear($tipo);
        case $n === 2 && $id === '_search' && $metodo === 'POST':             buscar($tipo);
        case $n === 2 && $id === '_history' && $metodo === 'GET':             historial($tipo, null);
        case $n === 2 && $metodo === 'GET':                                   leer($tipo, $id);
        case $n === 2 && $metodo === 'PUT':
            if (!RECURSOS[$tipo]['modif']) throw new Fallo(405, 'not-supported', "$tipo no se modifica por PUT" . ($tipo === 'ServiceRequest' ? ' (se cierra sola al conformar, rechazar o anular su orden)' : ''));
            actualizar($tipo, $id);
        case $n === 2 && $metodo === 'DELETE':
            throw new Fallo(405, 'not-supported', 'PIM no borra registros: se anulan o inactivan (estado) y queda la traza en _history');
        case $n === 3 && $p[2] === '$transicion' && $tipo === 'Task' && $metodo === 'POST': op_transicion($id);
        case $n === 3 && $p[2] === '_history' && $metodo === 'GET':          historial($tipo, $id);
        case $n === 4 && $p[2] === '_history' && $metodo === 'GET':
            if (!ctype_digit($p[3])) throw new Fallo(400, 'invalid', 'La versión es un número');
            leer($tipo, $id, $p[3]);
    }
    responder(400, oo('invalid', "Ruta u operación no reconocida: $metodo " . $ruta));
} catch (Fallo $f) {
    responder($f->http, oo($f->tipo, $f->getMessage(), 'error', $f->expresion));
} catch (\Throwable $t) {
    error_log('[pim] ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
    responder(500, oo('exception', 'Error interno del servidor PIM (detalle en data/logs/php_errors.log)'));
}
