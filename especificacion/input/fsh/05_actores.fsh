// SPDX-License-Identifier: CC-BY-SA-4.0
// Copyright (C) 2026 Ovidio Francisco Arias
// Marco técnico PIM en formato IHE: un CapabilityStatement por actor (como hacen las guías IHE en FHIR R4)
// y las operaciones propias. Las transacciones PIM-1 … PIM-11 están descriptas en marco-tecnico.md.
Alias: $mCSDDir = https://profiles.ihe.net/ITI/mCSD/CapabilityStatement/IHE.mCSD.Directory
Alias: $mCSDDirUpd = https://profiles.ihe.net/ITI/mCSD/CapabilityStatement/IHE.mCSD.Directory.Update
Alias: $mCSDQry = https://profiles.ihe.net/ITI/mCSD/CapabilityStatement/IHE.mCSD.QueryClient

RuleSet: CabeceraActor(id, nombre, titulo)
* url = "https://iproy.com.ar/fhir/CapabilityStatement/{id}"
* name = "{nombre}"
* title = "{titulo}"
* status = #draft
* experimental = false
* date = "2026-11-02"
* publisher = "Ovidio Francisco Arias (autor)"
* kind = #requirements
* fhirVersion = #4.0.1
* format[0] = #json
* implementationGuide = "https://iproy.com.ar/fhir/ImplementationGuide/ar.iproy.pim"

RuleSet: Recurso(tipo, perfil, exp)
* type = #{tipo}
* supportedProfile = "https://iproy.com.ar/fhir/StructureDefinition/{perfil}"
* extension[0].url = "http://hl7.org/fhir/StructureDefinition/capabilitystatement-expectation"
* extension[0].valueCode = #{exp}

RuleSet: Interaccion(codigo, exp)
* code = #{codigo}
* extension[0].url = "http://hl7.org/fhir/StructureDefinition/capabilitystatement-expectation"
* extension[0].valueCode = #{exp}

Instance: pim-gm
InstanceOf: CapabilityStatement
Title: "Actor PIM — Gestor de mantenimiento (GM)"
Description: "Requisitos del servidor PIM. Se agrupa con IHE mCSD Directory (ITI-90) y, con la opción mcsd-update, con Directory Update (ITI-91). Con PIM-EQM se agrupa con IHE MEM DMIC (PCD-15) y LOC (PCD-16)."
Usage: #definition
* insert CabeceraActor(pim-gm, PimGestor, Actor PIM — Gestor de mantenimiento)
* imports[0] = $mCSDDir
* imports[1] = $mCSDDirUpd
* rest[0].mode = #server
* rest[0].documentation = "SMART Backend Services (client_credentials + private_key_jwt). Cada cliente tiene un rol PIM (actor); un cliente registrado sin rol opera con el de menor privilegio (CI, solo consulta). Toda modificación exige If-Match, tanto PUT como la operación $transicion: 428 si falta y 412 si la versión no es la vigente. Nunca hay borrado físico. Auditoría interna por cliente y operación; la publicación como AuditEvent (IHE BALP) está planificada."
* rest[0].security.service = http://terminology.hl7.org/CodeSystem/restful-security-service#SMART-on-FHIR
* rest[0].resource[0] insert Recurso(Organization, pim-organismo, SHALL)
* rest[0].resource[0].supportedProfile[1] = "https://iproy.com.ar/fhir/StructureDefinition/pim-empresa"
* rest[0].resource[0].interaction[0] insert Interaccion(read, SHALL)
* rest[0].resource[0].interaction[1] insert Interaccion(search-type, SHALL)
* rest[0].resource[0].interaction[2] insert Interaccion(history-type, SHOULD)
* rest[0].resource[0].searchParam[0].name = "identifier"
* rest[0].resource[0].searchParam[0].type = #token
* rest[0].resource[0].searchParam[1].name = "name"
* rest[0].resource[0].searchParam[1].type = #string
* rest[0].resource[0].searchParam[2].name = "partof"
* rest[0].resource[0].searchParam[2].type = #reference
* rest[0].resource[0].searchParam[3].name = "type"
* rest[0].resource[0].searchParam[3].type = #token
* rest[0].resource[0].searchParam[4].name = "active"
* rest[0].resource[0].searchParam[4].type = #token
* rest[0].resource[1] insert Recurso(Location, pim-local, SHALL)
* rest[0].resource[1].supportedProfile[1] = "https://iproy.com.ar/fhir/StructureDefinition/pim-establecimiento"
* rest[0].resource[1].interaction[0] insert Interaccion(read, SHALL)
* rest[0].resource[1].interaction[1] insert Interaccion(search-type, SHALL)
* rest[0].resource[1].interaction[2] insert Interaccion(history-type, SHOULD)
* rest[0].resource[1].searchParam[0].name = "identifier"
* rest[0].resource[1].searchParam[0].type = #token
* rest[0].resource[1].searchParam[1].name = "name"
* rest[0].resource[1].searchParam[1].type = #string
* rest[0].resource[1].searchParam[2].name = "partof"
* rest[0].resource[1].searchParam[2].type = #reference
* rest[0].resource[1].searchParam[3].name = "organization"
* rest[0].resource[1].searchParam[3].type = #reference
* rest[0].resource[1].searchParam[4].name = "type"
* rest[0].resource[1].searchParam[4].type = #token
* rest[0].resource[1].searchParam[5].name = "status"
* rest[0].resource[1].searchParam[5].type = #token
* rest[0].resource[2] insert Recurso(Device, pim-pma, SHALL)
* rest[0].resource[2].supportedProfile[1] = "https://iproy.com.ar/fhir/StructureDefinition/pim-sistema"
* rest[0].resource[2].supportedProfile[2] = "https://iproy.com.ar/fhir/StructureDefinition/pim-activo"
* rest[0].resource[2].interaction[0] insert Interaccion(read, SHALL)
* rest[0].resource[2].interaction[1] insert Interaccion(search-type, SHALL)
* rest[0].resource[2].interaction[2] insert Interaccion(create, SHALL)
* rest[0].resource[2].interaction[3] insert Interaccion(update, SHALL)
* rest[0].resource[2].searchParam[0].name = "identifier"
* rest[0].resource[2].searchParam[0].type = #token
* rest[0].resource[2].searchParam[1].name = "location"
* rest[0].resource[2].searchParam[1].type = #reference
* rest[0].resource[2].searchParam[2].name = "type"
* rest[0].resource[2].searchParam[2].type = #token
* rest[0].resource[2].searchParam[3].name = "categoria-alcance"
* rest[0].resource[2].searchParam[3].type = #token
* rest[0].resource[2].searchParam[3].definition = "https://iproy.com.ar/fhir/SearchParameter/pim-device-categoria-alcance"
* rest[0].resource[2].operation[0].name = "refes"
* rest[0].resource[2].operation[0].definition = "https://iproy.com.ar/fhir/OperationDefinition/Device-refes"
* rest[0].resource[3] insert Recurso(DeviceMetric, pim-punto, SHALL)
* rest[0].resource[3].interaction[0] insert Interaccion(read, SHALL)
* rest[0].resource[3].interaction[1] insert Interaccion(search-type, SHALL)
* rest[0].resource[3].searchParam[0].name = "source"
* rest[0].resource[3].searchParam[0].type = #reference
* rest[0].resource[3].searchParam[1].name = "type"
* rest[0].resource[3].searchParam[1].type = #token
* rest[0].resource[4] insert Recurso(ServiceRequest, pim-solicitud, SHALL)
* rest[0].resource[4].interaction[0] insert Interaccion(create, SHALL)
* rest[0].resource[4].interaction[1] insert Interaccion(read, SHALL)
* rest[0].resource[4].interaction[2] insert Interaccion(search-type, SHALL)
* rest[0].resource[5] insert Recurso(Task, pim-orden, SHALL)
* rest[0].resource[5].interaction[0] insert Interaccion(read, SHALL)
* rest[0].resource[5].interaction[1] insert Interaccion(search-type, SHALL)
* rest[0].resource[5].interaction[2] insert Interaccion(update, SHALL)
* rest[0].resource[5].interaction[3] insert Interaccion(history-instance, SHALL)
* rest[0].resource[5].searchParam[0].name = "business-status"
* rest[0].resource[5].searchParam[0].type = #token
* rest[0].resource[5].searchParam[1].name = "owner"
* rest[0].resource[5].searchParam[1].type = #reference
* rest[0].resource[5].searchParam[2].name = "focus"
* rest[0].resource[5].searchParam[2].type = #reference
* rest[0].resource[5].searchParam[3].name = "based-on"
* rest[0].resource[5].searchParam[3].type = #reference
* rest[0].resource[5].searchParam[4].name = "decision-prioridad"
* rest[0].resource[5].searchParam[4].type = #token
* rest[0].resource[5].searchParam[4].definition = "https://iproy.com.ar/fhir/SearchParameter/pim-task-decision-prioridad"
* rest[0].resource[5].operation[0].name = "transicion"
* rest[0].resource[5].operation[0].definition = "https://iproy.com.ar/fhir/OperationDefinition/Task-transicion"
* rest[0].resource[6] insert Recurso(Observation, pim-medicion, SHOULD)
* rest[0].resource[6].interaction[0] insert Interaccion(create, SHOULD)
* rest[0].resource[6].interaction[1] insert Interaccion(search-type, SHOULD)
* rest[0].resource[7] insert Recurso(RiskAssessment, pim-riesgo, SHOULD)
* rest[0].resource[7].interaction[0] insert Interaccion(read, SHOULD)
* rest[0].resource[7].interaction[1] insert Interaccion(search-type, SHOULD)
* rest[0].resource[7].searchParam[0].name = "banda"
* rest[0].resource[7].searchParam[0].type = #token
* rest[0].resource[7].searchParam[0].definition = "https://iproy.com.ar/fhir/SearchParameter/pim-riskassessment-banda"
* rest[0].resource[8] insert Recurso(Provenance, pim-procedencia, SHOULD)
* rest[0].resource[8].interaction[0] insert Interaccion(search-type, SHOULD)
* rest[0].resource[9] insert Recurso(Group, pim-grupo-activo, SHOULD)
* rest[0].resource[9].interaction[0] insert Interaccion(read, SHOULD)
* rest[0].resource[9].interaction[1] insert Interaccion(search-type, SHOULD)
* rest[0].resource[10] insert Recurso(DiagnosticReport, pim-informe-ensayo, MAY)
* rest[0].resource[10].interaction[0] insert Interaccion(create, MAY)
* rest[0].resource[10].interaction[1] insert Interaccion(search-type, MAY)
* rest[0].interaction[0].code = #history-system
* rest[0].interaction[0].documentation = "PIM-8 Sincronizar cambios: _history con _since, paginado con _count y _offset (link next), en orden cronológico ascendente y estable (fecha, id y versión), para los recursos versionados: Organization, Location, Device, ServiceRequest y Task (semántica de IHE ITI-91). El ascendente es un desvío declarado respecto de FHIR R4, que ordena el historial con lo más nuevo primero: ese orden se pide con _sort=-_lastUpdated. Observation, DeviceMetric, RiskAssessment y Group no se versionan: las mediciones viajan por PIM-6 y los derivados se recalculan en cada nodo."

Instance: pim-so
InstanceOf: CapabilityStatement
Title: "Actor PIM — Solicitante (SO)"
Description: "Cliente que registra solicitudes (PIM-3) y consulta su estado (PIM-4)."
Usage: #definition
* insert CabeceraActor(pim-so, PimSolicitante, Actor PIM — Solicitante)
* rest[0].mode = #client
* rest[0].resource[0] insert Recurso(ServiceRequest, pim-solicitud, SHALL)
* rest[0].resource[0].interaction[0] insert Interaccion(create, SHALL)
* rest[0].resource[1] insert Recurso(Task, pim-orden, SHALL)
* rest[0].resource[1].interaction[0] insert Interaccion(search-type, SHALL)

Instance: pim-ej
InstanceOf: CapabilityStatement
Title: "Actor PIM — Ejecutor (EJ)"
Description: "Cliente de la empresa o área que ejecuta: consulta sus órdenes (PIM-4), las actualiza (PIM-5) e informa ensayos (PIM-10)."
Usage: #definition
* insert CabeceraActor(pim-ej, PimEjecutor, Actor PIM — Ejecutor)
* rest[0].mode = #client
* rest[0].resource[0] insert Recurso(Task, pim-orden, SHALL)
* rest[0].resource[0].interaction[0] insert Interaccion(search-type, SHALL)
* rest[0].resource[0].interaction[1] insert Interaccion(update, SHALL)
* rest[0].resource[1] insert Recurso(DiagnosticReport, pim-informe-ensayo, MAY)
* rest[0].resource[1].interaction[0] insert Interaccion(create, MAY)

Instance: pim-ft
InstanceOf: CapabilityStatement
Title: "Actor PIM — Fuente de telemetría (FT)"
Description: "Gateway, PLC o sensor que envía mediciones (PIM-6) por REST o, con la opción mqtt, por MQTT."
Usage: #definition
* insert CabeceraActor(pim-ft, PimFuenteTelemetria, Actor PIM — Fuente de telemetría)
* rest[0].mode = #client
* rest[0].resource[0] insert Recurso(Observation, pim-medicion, SHALL)
* rest[0].resource[0].interaction[0] insert Interaccion(create, SHALL)
* rest[0].interaction[0].code = #batch

Instance: pim-ci
InstanceOf: CapabilityStatement
Title: "Actor PIM — Consumidor de información (CI)"
Description: "Cliente que consulta inventario (PIM-1), órdenes (PIM-4) y riesgo (PIM-7). Se agrupa con IHE mCSD Query Client."
Usage: #definition
* insert CabeceraActor(pim-ci, PimConsumidor, Actor PIM — Consumidor de información)
* imports[0] = $mCSDQry
* rest[0].mode = #client
* rest[0].resource[0] insert Recurso(Device, pim-pma, SHALL)
* rest[0].resource[0].interaction[0] insert Interaccion(search-type, SHALL)
* rest[0].resource[1] insert Recurso(Task, pim-orden, SHALL)
* rest[0].resource[1].interaction[0] insert Interaccion(search-type, SHALL)

Instance: pim-re
InstanceOf: CapabilityStatement
Title: "Actor PIM — Registro estatal (RE)"
Description: "Registro del Estado (REFES) que obtiene los datos mínimos de los PMA con la operación Device/$refes (PIM-9)."
Usage: #definition
* insert CabeceraActor(pim-re, PimRegistroEstatal, Actor PIM — Registro estatal)
* rest[0].mode = #client
* rest[0].resource[0] insert Recurso(Device, pim-pma, SHALL)
* rest[0].resource[0].operation[0].name = "refes"
* rest[0].resource[0].operation[0].definition = "https://iproy.com.ar/fhir/OperationDefinition/Device-refes"

// ---------------------------------------------------------------- Operaciones
Instance: Task-transicion
InstanceOf: OperationDefinition
Title: "Operación $transicion de una orden"
Description: "Cambia el estado de una orden según la definición de flujo PIM-OT (quién puede hacer cada transición, motivo obligatorio cuando corresponde). Como toda modificación, exige la cabecera If-Match con la versión de la orden (el versionId que el cliente leyó): 428 si falta y 412 si no es la vigente. El Gestor (GM) puede hacer también las transiciones del Ejecutor (EJ), en su nombre; el EJ solo opera las órdenes de su organización."
Usage: #definition
* url = "https://iproy.com.ar/fhir/OperationDefinition/Task-transicion"
* name = "TaskTransicion"
* status = #draft
* kind = #operation
* code = #transicion
* resource = #Task
* system = false
* type = false
* instance = true
* parameter[0].name = #estado
* parameter[0].use = #in
* parameter[0].min = 0
* parameter[0].max = "1"
* parameter[0].type = #code
* parameter[0].binding.strength = #required
* parameter[0].binding.valueSet = "https://iproy.com.ar/fhir/ValueSet/pim-estado-orden"
* parameter[1].name = #motivo
* parameter[1].use = #in
* parameter[1].min = 0
* parameter[1].max = "1"
* parameter[1].type = #string
* parameter[2].name = #responsable
* parameter[2].use = #in
* parameter[2].min = 0
* parameter[2].max = "1"
* parameter[2].type = #Reference
* parameter[3].name = #tipoMantenimiento
* parameter[3].use = #in
* parameter[3].min = 0
* parameter[3].max = "1"
* parameter[3].type = #code
* parameter[4].name = #modoFalla
* parameter[4].use = #in
* parameter[4].min = 0
* parameter[4].max = "1"
* parameter[4].type = #code
* parameter[5].name = #prioridad
* parameter[5].use = #in
* parameter[5].min = 0
* parameter[5].max = "1"
* parameter[5].type = #code
* parameter[5].binding.strength = #required
* parameter[5].binding.valueSet = "http://hl7.org/fhir/ValueSet/request-priority"
* parameter[6].name = #nota
* parameter[6].use = #in
* parameter[6].min = 0
* parameter[6].max = "*"
* parameter[6].type = #string
* parameter[7].name = #decisionPrioridad
* parameter[7].use = #in
* parameter[7].min = 0
* parameter[7].max = "1"
* parameter[7].type = #code
* parameter[7].documentation = "Qué hizo el Gestor con la prioridad sugerida por el IRU: aceptada | cambiada | rechazada. cambiada y rechazada exigen motivoPrioridad. Si se omite y la orden sale de solicitada o en-revision, se registra la decisión implícita."
* parameter[7].binding.strength = #required
* parameter[7].binding.valueSet = "https://iproy.com.ar/fhir/ValueSet/pim-decision-prioridad"
* parameter[8].name = #motivoPrioridad
* parameter[8].use = #in
* parameter[8].min = 0
* parameter[8].max = "1"
* parameter[8].type = #string
* parameter[9].name = #return
* parameter[9].use = #out
* parameter[9].min = 1
* parameter[9].max = "1"
* parameter[9].type = #Task

Instance: Device-refes
InstanceOf: OperationDefinition
Title: "Operación $refes"
Description: "Devuelve, por cada PMA, los cinco datos mínimos del REFES (Res. SGS 532/2026) y la lista de los que faltan. A nivel de tipo devuelve todos los PMA del organismo."
Usage: #definition
* url = "https://iproy.com.ar/fhir/OperationDefinition/Device-refes"
* name = "DeviceRefes"
* status = #draft
* kind = #operation
* code = #refes
* resource = #Device
* system = false
* type = true
* instance = true
* parameter[0].name = #completos
* parameter[0].use = #in
* parameter[0].min = 0
* parameter[0].max = "1"
* parameter[0].type = #boolean
* parameter[0].documentation = "true: solo los PMA con los cinco datos; false: solo los incompletos."
* parameter[1].name = #return
* parameter[1].use = #out
* parameter[1].min = 1
* parameter[1].max = "1"
* parameter[1].type = #Parameters

// ---------- Parámetros de búsqueda propios del PIM (los demás son los estándar de FHIR R4 y de mCSD)
Instance: pim-task-decision-prioridad
InstanceOf: SearchParameter
Title: "Task · decision-prioridad"
Description: "Decisión del Gestor sobre la prioridad sugerida por el IRU (extensión pim-decision-prioridad.decision). Sirve para medir el sesgo de automatización: Task?decision-prioridad=cambiada&_summary=count."
Usage: #definition
* url = "https://iproy.com.ar/fhir/SearchParameter/pim-task-decision-prioridad"
* name = "PimTaskDecisionPrioridad"
* status = #draft
* code = #decision-prioridad
* base = #Task
* type = #token
* expression = "Task.extension.where(url='https://iproy.com.ar/fhir/StructureDefinition/pim-decision-prioridad').extension.where(url='decision').value"

Instance: pim-riskassessment-banda
InstanceOf: SearchParameter
Title: "RiskAssessment · banda"
Description: "Banda vigente del IRU (A, B, C o D)."
Usage: #definition
* url = "https://iproy.com.ar/fhir/SearchParameter/pim-riskassessment-banda"
* name = "PimRiskAssessmentBanda"
* status = #draft
* code = #banda
* base = #RiskAssessment
* type = #token
* expression = "RiskAssessment.prediction.qualitativeRisk"

Instance: pim-device-categoria-alcance
InstanceOf: SearchParameter
Title: "Device · categoria-alcance"
Description: "Categoría de alcance A, B, C o D del activo (extensión pim-categoria-alcance)."
Usage: #definition
* url = "https://iproy.com.ar/fhir/SearchParameter/pim-device-categoria-alcance"
* name = "PimDeviceCategoriaAlcance"
* status = #draft
* code = #categoria-alcance
* base = #Device
* type = #token
* expression = "Device.extension.where(url='https://iproy.com.ar/fhir/StructureDefinition/pim-categoria-alcance').value"
