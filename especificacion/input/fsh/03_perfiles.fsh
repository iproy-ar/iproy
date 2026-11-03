// SPDX-License-Identifier: CC-BY-SA-4.0
// Copyright (C) 2026 Ovidio Francisco Arias
// Perfiles PIM 0.9.2. Principio rector: apoyarse en los bordes que IHE ya estandarizó (mCSD, MEM, ACM, BALP)
// y crear solo lo que nadie cubre (el flujo del mantenimiento entre organismos y las instalaciones vinculadas al riesgo).
Alias: $mCSDOrg = https://profiles.ihe.net/ITI/mCSD/StructureDefinition/IHE.mCSD.Organization
Alias: $mCSDFacOrg = https://profiles.ihe.net/ITI/mCSD/StructureDefinition/IHE.mCSD.FacilityOrganization
Alias: $mCSDLoc = https://profiles.ihe.net/ITI/mCSD/StructureDefinition/IHE.mCSD.Location
Alias: $mCSDFacLoc = https://profiles.ihe.net/ITI/mCSD/StructureDefinition/IHE.mCSD.FacilityLocation
Alias: $mCSDTipos = https://profiles.ihe.net/ITI/mCSD/CodeSystem/IHE.mCSD.Organization.Location.Types

Invariant: pim-anmat-pm
Description: "El n.º de producto ANMAT tiene el formato PM-NNNN-NNNN."
Severity: #error
Expression: "matches('^PM-[0-9]{4}-[0-9]{4}$')"

Invariant: pim-pma-refes
Description: "Faltan datos mínimos del REFES (n.º PM ANMAT, pertenencia y fecha de fabricación o de puesta en marcha). Son obligatorios para las tipologías de la Res. SGS 532/2026; la operación Device/$refes informa cuáles faltan."
Severity: #warning
Expression: "identifier.where(system = 'https://iproy.com.ar/fhir/sid/anmat-pm').exists() and extension('https://iproy.com.ar/fhir/StructureDefinition/pim-pertenencia').exists() and (manufactureDate.exists() or extension('https://iproy.com.ar/fhir/StructureDefinition/pim-puesta-en-marcha').exists())"

Invariant: pim-orden-objeto
Description: "La orden debe referirse a un activo (focus) o a un local (location)."
Severity: #error
Expression: "focus.exists() or location.exists()"

Invariant: pim-orden-tipo
Description: "Una orden finalizada debe informar el tipo de mantenimiento (EN 13306)."
Severity: #warning
Expression: "status != 'completed' or extension('https://iproy.com.ar/fhir/StructureDefinition/pim-tipo-mantenimiento').exists()"

Invariant: pim-sin-paciente
Description: "Ningún recurso PIM se refiere a un paciente."
Severity: #error
Expression: "descendants().ofType(Reference).where(reference.startsWith('Patient/')).empty()"

// ---------------------------------------------------------------- Directorio (alineado con IHE ITI mCSD 4.0.0)
Profile: PimOrganismo
Parent: $mCSDFacOrg
Id: pim-organismo
Title: "PIM — Organismo o establecimiento de salud"
Description: "Establecimiento de salud como organización. Deriva de IHE mCSD FacilityOrganization: un sistema que entiende mCSD lo lee sin adaptaciones. Debe usar el código REFES cuando exista. Los ministerios y regiones sanitarias usan directamente IHE mCSD JurisdictionOrganization."
* obeys pim-sin-paciente
* identifier 1..* MS
* name 1..1 MS
* type MS
* partOf MS

Profile: PimEmpresa
Parent: $mCSDOrg
Id: pim-empresa
Title: "PIM — Empresa contratista o área interna"
Description: "Empresa contratista, prestador de servicios técnicos o área interna; se identifica por CUIT. Deriva de IHE mCSD Organization (type obligatorio: bus | dept | prov)."
* identifier 1..* MS
* name 1..1 MS
* type MS
* partOf MS

Profile: PimEstablecimiento
Parent: $mCSDFacLoc
Id: pim-establecimiento
Title: "PIM — Establecimiento (sitio físico)"
Description: "Sitio físico del establecimiento. Junto con pim-organismo forma el «Facility» de IHE mCSD. Es la raíz de la jerarquía de locales."
* identifier MS
* name 1..1 MS
* status 1..1 MS
* managingOrganization only Reference(PimOrganismo)
* managingOrganization MS
* address MS
* position MS

Profile: PimLocal
Parent: $mCSDLoc
Id: pim-local
Title: "PIM — Local o ubicación"
Description: "Edificio, nivel, local, sala o cama, con su grupo de uso médico. Deriva de IHE mCSD Location (status, name y type obligatorios). La ubicación PL de HL7 v2 que informa IHE MEM-LS (establecimiento, edificio, piso, sala, cama) se corresponde con la cadena partOf."
* identifier 1..* MS
* status MS
* name MS
* type MS
* physicalType 1..1 MS
* managingOrganization 1..1 MS
* managingOrganization only Reference(PimOrganismo)
* partOf MS
* partOf only Reference(PimLocal or PimEstablecimiento)
* extension contains GrupoUsoMedico named grupoUsoMedico 0..1 MS and AmbienteControlado named ambienteControlado 0..1 MS

// ---------------------------------------------------------------- Activos
Profile: PimPma
Parent: Device
Id: pim-pma
Title: "PIM — Producto médico activo"
Description: "Producto médico activo en uso. Lleva los cinco datos mínimos del REFES (Res. SGS 532/2026) como MustSupport: la exigencia legal depende de la tipología y la verifica la operación Device/$refes (invariante de advertencia pim-pma-refes). Un PMA conectado se identifica además por su EUI-64 (IHE MEM-DMC)."
* obeys pim-pma-refes and pim-sin-paciente
* identifier 1..* MS
* identifier ^slicing.discriminator.type = #value
* identifier ^slicing.discriminator.path = "system"
* identifier ^slicing.rules = #open
* identifier contains inventario 1..1 MS and anmatPM 0..1 MS and eui64 0..1 MS
* identifier[inventario].system = "https://iproy.com.ar/fhir/sid/activo" (exactly)
* identifier[inventario].value 1..1
* identifier[anmatPM].system = "https://iproy.com.ar/fhir/sid/anmat-pm" (exactly)
* identifier[anmatPM].value 1..1
* identifier[anmatPM].value obeys pim-anmat-pm
* identifier[eui64].system = "https://iproy.com.ar/fhir/sid/eui64" (exactly)
* identifier[eui64].value 1..1
* identifier[eui64] ^short = "EUI-64 del equipo (MSH-3 / OBX-18 de IHE PCD-15)"
* status 1..1 MS
* manufacturer MS
* modelNumber MS
* manufactureDate MS
* serialNumber MS
* owner 1..1 MS
* owner only Reference(PimOrganismo)
* location MS
* location only Reference(PimLocal)
* extension contains CategoriaAlcance named categoriaAlcance 1..1 MS and PuestaEnMarcha named puestaEnMarcha 0..1 MS and Pertenencia named pertenencia 0..1 MS and ClaseRiesgoPM named claseRiesgoPM 0..1 MS and Iru named iru 0..1 MS
* extension[categoriaAlcance].valueCoding = PimCategoriaAlcance#A

Profile: PimSistema
Parent: Device
Id: pim-sistema
Title: "PIM — Sistema de instalación"
Description: "Sistema de instalación (nivel 5 de ISO 14224) de categoría B, C o D."
* obeys pim-sin-paciente
* identifier 1..* MS
* type 1..1 MS
* type from PimSistemaVS (extensible)
* status 1..1 MS
* owner 1..1 MS
* owner only Reference(PimOrganismo)
* location MS
* extension contains CategoriaAlcance named categoriaAlcance 1..1 MS and CategoriaRiesgo named categoriaRiesgo 0..1 MS and Redundancia named redundancia 0..1 MS and Iru named iru 0..1 MS

Profile: PimActivo
Parent: Device
Id: pim-activo
Title: "PIM — Equipo o componente de instalación"
Description: "Equipo o componente de un sistema de instalación (niveles 6 a 8 de ISO 14224). Debería indicar el sistema al que pertenece (parent)."
* obeys pim-sin-paciente
* identifier 1..* MS
* type MS
* status 1..1 MS
* parent MS
* parent only Reference(PimSistema or PimActivo)
* owner 1..1 MS
* manufacturer MS
* modelNumber MS
* serialNumber MS
* location MS
* extension contains CategoriaAlcance named categoriaAlcance 0..1 MS and CategoriaRiesgo named categoriaRiesgo 0..1 MS and Iru named iru 0..1 MS

Profile: PimGrupoActivo
Parent: Group
Id: pim-grupo-activo
Title: "PIM — Grupo de un activo"
Description: "Grupo de un solo activo. Existe porque en R4 RiskAssessment.subject admite Patient o Group, no Device."
* type = #device
* actual = true
* member 1..1 MS
* member.entity only Reference(PimPma or PimSistema or PimActivo)

// ---------------------------------------------------------------- Telemetría
Profile: PimPunto
Parent: DeviceMetric
Id: pim-punto
Title: "PIM — Punto de medición"
Description: "Punto de medición de un activo, sistema o PMA. Las lecturas son Observation que lo referencian. Para equipos médicos, el tipo es el código MDC del atributo que informa IHE MEM-DMC."
* identifier 1..* MS
* type from PimPuntoMedicionVS (extensible)
* unit MS
* source 1..1 MS
* source only Reference(PimActivo or PimSistema or PimPma)
* parent MS
* category MS
* calibration MS

Profile: PimMedicion
Parent: Observation
Id: pim-medicion
Title: "PIM — Medición"
Description: "Lectura de un punto de medición (telemetría, medición manual o atributo de un equipo médico recibido por IHE PCD-15)."
* obeys pim-sin-paciente
* status MS
* code from PimPuntoMedicionVS (extensible)
* subject 0..0
* device 1..1 MS
* device only Reference(PimPunto)
* effective[x] 1..1 MS
* effective[x] only dateTime or instant
* value[x] 1..1 MS
* value[x] only Quantity or CodeableConcept or string or boolean
* interpretation MS
* referenceRange MS
* extension contains OrdenGenerada named ordenGenerada 0..1 MS

// ---------------------------------------------------------------- Flujo de trabajo
Profile: PimSolicitud
Parent: ServiceRequest
Id: pim-solicitud
Title: "PIM — Solicitud de servicio"
Description: "Pedido de intervención sobre un local o un activo. Nunca se refiere a un paciente."
* obeys pim-sin-paciente
* identifier MS
* status MS
* intent MS
* code 1..1 MS
* subject only Reference(Location or Device)
* authoredOn 1..1 MS
* priority MS
* locationReference MS
* extension contains EstadoDeclarado named estadoDeclarado 0..1 MS and OrdenGenerada named ordenGenerada 0..1 MS

Profile: PimOrden
Parent: Task
Id: pim-orden
Title: "PIM — Orden de trabajo"
Description: "Orden de trabajo. Su ciclo de vida es la definición de flujo de trabajo PIM-OT (capa 3 del modelo IHE XDW, sobre FHIR Task): businessStatus lleva el estado PIM y status el estado FHIR equivalente."
* obeys pim-orden-objeto and pim-orden-tipo and pim-sin-paciente
* identifier 1..* MS
* status MS
* businessStatus 1..1 MS
* businessStatus from PimEstadoOrdenVS (required)
* intent = #order
* authoredOn 1..1 MS
* lastModified 1..1 MS
* focus MS
* focus only Reference(PimPma or PimSistema or PimActivo)
* location MS
* location only Reference(PimLocal)
* for 0..0
* basedOn MS
* basedOn only Reference(PimSolicitud)
* reasonReference MS
* owner MS
* priority MS
* executionPeriod MS
* extension contains TipoMantenimiento named tipoMantenimiento 0..1 MS and ModoFalla named modoFalla 0..1 MS and DecisionPrioridad named decisionPrioridad 0..1 MS

Profile: PimInformeEnsayo
Parent: DiagnosticReport
Id: pim-informe-ensayo
Title: "PIM — Informe de ensayo o verificación técnica"
Description: "Ensayo reglamentario o verificación técnica de un PMA o de una instalación, con acta firmada (Ley 25.506)."
* obeys pim-sin-paciente
* status MS
* code from PimEnsayoVS (extensible)
* subject 1..1 MS
* subject only Reference(Device or Location)
* effective[x] 1..1 MS
* effective[x] only dateTime
* performer 1..* MS
* conclusion 1..1 MS
* conclusionCode from PimConformidadVS (required)
* presentedForm 1..* MS

Profile: PimRiesgo
Parent: RiskAssessment
Id: pim-riesgo
Title: "PIM — Evaluación de riesgo"
Description: "Evaluación de riesgo explicable de un activo. En R4, subject solo admite Patient o Group: se usa pim-grupo-activo."
* status MS
* subject only Reference(PimGrupoActivo)
* method 1..1 MS
* method from PimMetodoRiesgoVS (required)
* basis MS
* prediction MS
* extension contains Iru named iru 0..1 MS

Profile: PimProcedencia
Parent: Provenance
Id: pim-procedencia
Title: "PIM — Procedencia con huella encadenada"
Description: "Una procedencia por versión de recurso. signature.data lleva el SHA-256 de la versión encadenado al de la versión anterior: cualquier alteración del historial rompe la cadena."
* target 1..1 MS
* recorded MS
* activity MS
* agent 1..* MS
* signature 1..1 MS
* signature.sigFormat = #application/x-pim-huella
* signature.data 1..1 MS

Profile: PimVinculo
Parent: Contract
Id: pim-vinculo
Title: "PIM — Vínculo institucional"
Description: "Vínculo elegido y aceptado entre dos nodos o instituciones de la red colmena, firmado por ambas partes. Del acta se registra su huella SHA-256."
* status 1..1 MS
* type 1..1 MS
* type from PimTipoVinculoVS (required)
* applies 1..1 MS
* signer 2..* MS
* signer.party only Reference(PimOrganismo or PimEmpresa)
* signer.signature 1..* MS
* term MS
