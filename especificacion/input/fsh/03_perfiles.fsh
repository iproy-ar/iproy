// SPDX-License-Identifier: CC-BY-SA-4.0
// Copyright (C) 2026 Ovidio Francisco Arias
Invariant: pim-anmat-pm
Description: "El n.º de producto ANMAT tiene el formato PM-NNNN-NNNN."
Severity: #error
Expression: "matches('^PM-[0-9]{4}-[0-9]{4}$')"

Invariant: pim-pma-fecha
Description: "Debe informarse la fecha de fabricación o la de puesta en marcha (Res. SGS 532/2026)."
Severity: #error
Expression: "manufactureDate.exists() or extension('https://iproy.com.ar/fhir/StructureDefinition/puestaEnMarcha').exists()"

Invariant: pim-orden-objeto
Description: "La orden debe referirse a un activo (focus) o a un local (location)."
Severity: #error
Expression: "focus.exists() or location.exists()"

Profile: PimOrganismo
Parent: Organization
Id: pim-organismo
Title: "PIM — Organismo o establecimiento"
Description: "Establecimiento u organismo de salud. Debe usar el código REFES cuando exista. [Pendiente: derivar de Organization_ar_core de AR.FHIR.CORE al confirmar su paquete.]"
* identifier 1..* MS
* name 1..1 MS
* type MS

Profile: PimEmpresa
Parent: Organization
Id: pim-empresa
Title: "PIM — Empresa contratista o área interna"
Description: "Empresa contratista, prestador de servicios técnicos o área interna; se identifica por CUIT."
* identifier 1..* MS
* name 1..1 MS
* partOf MS

Profile: PimLocal
Parent: Location
Id: pim-local
Title: "PIM — Local o ubicación"
Description: "Edificio, nivel, local, sala o cama, con su grupo de uso médico."
* identifier 1..* MS
* status 1..1 MS
* name 1..1 MS
* physicalType 1..1 MS
* managingOrganization 1..1 MS
* managingOrganization only Reference(PimOrganismo)
* partOf MS
* extension contains GrupoUsoMedico named grupoUsoMedico 0..1 MS and AmbienteControlado named ambienteControlado 0..1 MS

Profile: PimPma
Parent: Device
Id: pim-pma
Title: "PIM — Producto médico activo"
Description: "Producto médico activo en uso, con los cinco datos mínimos del REFES (Res. SGS 532/2026)."
* identifier 2..* MS
* identifier ^slicing.discriminator.type = #value
* identifier ^slicing.discriminator.path = "system"
* identifier ^slicing.rules = #open
* identifier contains inventario 1..1 MS and anmatPM 1..1 MS
* identifier[inventario].system = "https://iproy.com.ar/fhir/sid/activo" (exactly)
* identifier[inventario].value 1..1
* identifier[anmatPM].system = "https://iproy.com.ar/fhir/sid/anmat-pm" (exactly)
* identifier[anmatPM].value 1..1
* identifier[anmatPM].value obeys pim-anmat-pm
* status 1..1 MS
* manufacturer 1..1 MS
* modelNumber 1..1 MS
* manufactureDate MS
* serialNumber MS
* owner 1..1 MS
* owner only Reference(PimOrganismo)
* location MS
* location only Reference(PimLocal)
* extension contains CategoriaAlcance named categoriaAlcance 1..1 MS and PuestaEnMarcha named puestaEnMarcha 0..1 MS and Pertenencia named pertenencia 1..1 MS and ClaseRiesgoPM named claseRiesgoPM 0..1 MS
* extension[categoriaAlcance].valueCoding = PimCategoriaAlcance#A
* obeys pim-pma-fecha

Profile: PimSistema
Parent: Device
Id: pim-sistema
Title: "PIM — Sistema de instalación"
Description: "Sistema de instalación (nivel 5 de ISO 14224) de categoría B, C o D."
* identifier 1..* MS
* type 1..1 MS
* type from PimSistemaVS (extensible)
* status 1..1 MS
* owner 1..1 MS
* owner only Reference(PimOrganismo)
* location MS
* extension contains CategoriaAlcance named categoriaAlcance 1..1 MS and CategoriaRiesgo named categoriaRiesgo 1..1 MS and Redundancia named redundancia 0..1 MS

Profile: PimActivo
Parent: Device
Id: pim-activo
Title: "PIM — Equipo o componente de instalación"
Description: "Equipo o componente de un sistema de instalación (niveles 6 a 8 de ISO 14224)."
* identifier 1..* MS
* type 1..1 MS
* status 1..1 MS
* parent 1..1 MS
* parent only Reference(PimSistema or PimActivo)
* owner 1..1 MS
* manufacturer MS
* modelNumber MS
* serialNumber MS
* location MS

Profile: PimPunto
Parent: DeviceMetric
Id: pim-punto
Title: "PIM — Punto de medición"
Description: "Punto de medición de un activo, sistema o PMA. Las lecturas son Observation que lo referencian."
* identifier 1..* MS
* type from PimPuntoVS (extensible)
* unit 1..1 MS
* source 1..1 MS
* source only Reference(PimActivo or PimSistema or PimPma)
* parent MS
* calibration MS

Profile: PimMedicion
Parent: Observation
Id: pim-medicion
Title: "PIM — Medición"
Description: "Lectura de un punto de medición (telemetría o medición manual)."
* status MS
* code from PimPuntoVS (extensible)
* subject only Reference(Location or Device)
* device 1..1 MS
* device only Reference(PimPunto)
* effective[x] 1..1 MS
* effective[x] only instant
* value[x] 1..1 MS
* value[x] only Quantity
* interpretation MS
* referenceRange MS

Profile: PimSolicitud
Parent: ServiceRequest
Id: pim-solicitud
Title: "PIM — Solicitud de servicio"
Description: "Pedido de intervención sobre un local o un activo. Nunca se refiere a un paciente."
* status MS
* intent MS
* code 1..1 MS
* subject only Reference(Location or Device)
* authoredOn 1..1 MS
* priority MS
* locationReference MS
* extension contains EstadoDeclarado named estadoDeclarado 0..1 MS

Profile: PimOrden
Parent: Task
Id: pim-orden
Title: "PIM — Orden de trabajo"
Description: "Orden de trabajo con la máquina de estados del PIM (Tabla 9-3)."
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
* owner MS
* priority MS
* executionPeriod MS
* extension contains TipoMantenimiento named tipoMantenimiento 1..1 MS and ModoFalla named modoFalla 0..1 MS
* obeys pim-orden-objeto

Profile: PimInformeEnsayo
Parent: DiagnosticReport
Id: pim-informe-ensayo
Title: "PIM — Informe de ensayo o verificación técnica"
Description: "Ensayo reglamentario o verificación técnica de un PMA, con acta firmada (Ley 25.506)."
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
Description: "Evaluación de riesgo explicable de un activo. En R4, subject solo admite Patient o Group: se usa un Group de tipo device."
* status MS
* subject only Reference(Group)
* method 1..1 MS
* method from PimMetodoRiesgoVS (required)
* extension contains ComponentesIRU named componentesIRU 0..1 MS

Profile: PimVinculo
Parent: Contract
Id: pim-vinculo
Title: "PIM — Vínculo institucional"
Description: "Vínculo elegido y aceptado entre dos nodos o instituciones de la red colmena, firmado por ambas partes [propuesta para la versión 0.9.2]."
* status 1..1 MS
* type 1..1 MS
* type from PimTipoVinculoVS (required)
* applies 1..1 MS
* signer 2..* MS
* signer.party only Reference(PimOrganismo or PimEmpresa)
* signer.signature 1..* MS
* term MS
