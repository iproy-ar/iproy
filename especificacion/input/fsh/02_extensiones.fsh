// SPDX-License-Identifier: CC-BY-SA-4.0
// Copyright (C) 2026 Ovidio Francisco Arias
// Extensiones PIM 0.9.2. Desde la 0.9.2 los ids siguen la convención de FHIR (minúsculas con guiones, prefijo pim-),
// igual que la implementación de referencia. Los ids camelCase de la 0.9.1 se aceptan como alias de entrada (Anexo H).
Alias: $UCUM = http://unitsofmeasure.org

Extension: CategoriaAlcance
Id: pim-categoria-alcance
Title: "Categoría de alcance PIM"
Description: "Categoría A a D del activo (sección 3.3): A producto médico activo; B instalación específica de salud; C instalación general que sostiene servicios críticos; D instalación general."
Context: Device
* value[x] only Coding
* valueCoding from PimCategoriaAlcanceVS (required)

Extension: ClaseRiesgoPM
Id: pim-clase-riesgo-pm
Title: "Clase de riesgo del producto médico"
Description: "Clase I a IV según la Disp. ANMAT 64/2025."
Context: Device
* value[x] only Coding
* valueCoding from PimClaseRiesgoPMVS (required)

Extension: PuestaEnMarcha
Id: pim-puesta-en-marcha
Title: "Fecha de puesta en marcha"
Description: "Fecha de puesta en marcha del PMA (dato mínimo del REFES, Res. SGS 532/2026)."
Context: Device
* value[x] only dateTime

Extension: Pertenencia
Id: pim-pertenencia
Title: "Pertenencia del PMA"
Description: "Pertenencia del PMA (dato mínimo del REFES, Res. SGS 532/2026)."
Context: Device
* value[x] only Coding
* valueCoding from PimPertenenciaVS (extensible)

Extension: CategoriaRiesgo
Id: pim-categoria-riesgo
Title: "Categoría de riesgo NFPA 99"
Description: "Categoría de riesgo ante la falla del sistema o activo."
Context: Device
* value[x] only Coding
* valueCoding from PimCategoriaRiesgoVS (required)

Extension: Redundancia
Id: pim-redundancia
Title: "Redundancia"
Description: "Redundancia del sistema."
Context: Device
* value[x] only code
* valueCode from PimRedundanciaVS (required)

Extension: GrupoUsoMedico
Id: pim-grupo-uso-medico
Title: "Grupo de uso médico del local"
Description: "Grupo del local según AEA 90364-7-710:2024."
Context: Location
* value[x] only Coding
* valueCoding from PimGrupoLocalVS (required)

Extension: AmbienteControlado
Id: pim-ambiente-controlado
Title: "Ambiente controlado"
Description: "Condiciones de diseño de un local con ambiente controlado (ISO 14644)."
Context: Location
* extension contains presionRelativa 0..1 and renovacionesHora 0..1 and claseISO 0..1
* extension[presionRelativa].value[x] only code
* extension[presionRelativa].valueCode from PimPresionRelativaVS (required)
* extension[renovacionesHora].value[x] only Quantity
* extension[renovacionesHora].valueQuantity.system = $UCUM (exactly)
* extension[renovacionesHora].valueQuantity.code = #/h (exactly)
* extension[claseISO].value[x] only string

Extension: TipoMantenimiento
Id: pim-tipo-mantenimiento
Title: "Tipo de mantenimiento"
Description: "Tipo de mantenimiento de la orden (EN 13306 y códigos PIM)."
Context: Task
* value[x] only Coding
* valueCoding from PimTipoMantenimientoVS (required)

Extension: ModoFalla
Id: pim-modo-falla
Title: "Modo de falla"
Description: "Modo de falla observado (ISO 14224). Se informa al finalizar una orden correctiva."
Context: Task
* value[x] only Coding
* valueCoding from PimModoFallaVS (extensible)

Extension: EstadoDeclarado
Id: pim-estado-declarado
Title: "Estado declarado del activo"
Description: "Estado del activo según quien solicita. Sube o baja la prioridad calculada con el IRU."
Context: ServiceRequest
* value[x] only Coding
* valueCoding from PimEstadoDeclaradoVS (required)

Extension: Iru
Id: pim-iru
Title: "Índice de Riesgo Unificado (IRU)"
Description: "Componentes F (función), R (riesgo), M (mantenimiento) y A (antecedentes) del IRU, su total y la banda de prioridad."
Context: Device, RiskAssessment
* extension contains F 1..1 and R 1..1 and M 1..1 and A 1..1 and total 1..1 and banda 1..1
* extension[F].value[x] only integer
* extension[R].value[x] only integer
* extension[M].value[x] only integer
* extension[A].value[x] only integer
* extension[total].value[x] only integer
* extension[banda].value[x] only Coding
* extension[banda].valueCoding from PimBandaIruVS (required)

Extension: DecisionPrioridad
Id: pim-decision-prioridad
Title: "Decisión sobre la prioridad sugerida"
Description: "Prioridad que el Gestor sugiere a partir del IRU del activo, su fundamento y lo que decidió la persona responsable (aceptarla, cambiarla o rechazarla), con motivo, autor y fecha. Sirve para auditar el sesgo de automatización: la sugerencia nunca reemplaza la decisión humana."
Context: Task
* extension contains sugerida 1..1 and fundamento 0..1 and decision 1..1 and motivo 0..1 and autor 0..1 and fecha 0..1
* extension[sugerida].value[x] only code
* extension[sugerida].valueCode from http://hl7.org/fhir/ValueSet/request-priority (required)
* extension[fundamento].value[x] only string
* extension[decision].value[x] only Coding
* extension[decision].valueCoding from PimDecisionPrioridadVS (required)
* extension[motivo].value[x] only string
* extension[autor].value[x] only string
* extension[fecha].value[x] only dateTime

Extension: OrdenGenerada
Id: pim-orden-generada
Title: "Orden generada"
Description: "Orden de trabajo que el Gestor generó a partir de esta solicitud o medición. Es una navegación de conveniencia: la referencia normativa es Task.basedOn (solicitud) o Task.reasonReference (medición)."
Context: ServiceRequest, Observation
* value[x] only Reference(PimOrden)

Extension: Conformidad
Id: pim-conformidad
Title: "Conformidad PIM declarada"
Description: "Nivel de conformidad, perfiles de integración y opciones que declara un sistema en su CapabilityStatement. Es la versión computable de la Declaración de Integración PIM (Parte 6)."
Context: CapabilityStatement
* extension contains nivel 1..1 and perfilIntegracion 1..* and actor 1..* and opcion 0..* and perfilIHE 0..*
* extension[nivel].value[x] only code
* extension[nivel].valueCode from PimNivelConformidadVS (required)
* extension[perfilIntegracion].value[x] only code
* extension[perfilIntegracion].valueCode from PimPerfilIntegracionVS (required)
* extension[actor].value[x] only code
* extension[actor].valueCode from PimActorVS (required)
* extension[opcion].value[x] only code
* extension[opcion].valueCode from PimOpcionVS (extensible)
* extension[perfilIHE].value[x] only string
* extension[perfilIHE] ^short = "Perfil y actor IHE agrupado, p. ej. «ITI mCSD · Directory» o «DEV MEM-DMC · DMIC»"
