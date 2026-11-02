// SPDX-License-Identifier: CC-BY-SA-4.0
// Copyright (C) 2026 Ovidio Francisco Arias
Alias: $UCUM = http://unitsofmeasure.org

Extension: CategoriaAlcance
Id: categoriaAlcance
Title: "Categoría de alcance PIM"
Description: "Categoría A a D del activo (sección 3.3)."
Context: Device
* value[x] only Coding
* valueCoding from PimCategoriaAlcanceVS (required)

Extension: ClaseRiesgoPM
Id: claseRiesgoPM
Title: "Clase de riesgo del producto médico"
Description: "Clase I a IV según la Disp. ANMAT 64/2025."
Context: Device
* value[x] only Coding
* valueCoding from PimClaseRiesgoPMVS (required)

Extension: PuestaEnMarcha
Id: puestaEnMarcha
Title: "Fecha de puesta en marcha"
Description: "Fecha de puesta en marcha del PMA (dato mínimo del REFES, Res. SGS 532/2026)."
Context: Device
* value[x] only dateTime

Extension: Pertenencia
Id: pertenencia
Title: "Pertenencia del PMA"
Description: "Pertenencia del PMA (dato mínimo del REFES, Res. SGS 532/2026)."
Context: Device
* value[x] only Coding
* valueCoding from PimPertenenciaVS (extensible)

Extension: CategoriaRiesgo
Id: categoriaRiesgo
Title: "Categoría de riesgo NFPA 99"
Description: "Categoría de riesgo ante la falla del sistema o activo."
Context: Device
* value[x] only Coding
* valueCoding from PimCategoriaRiesgoVS (required)

Extension: Redundancia
Id: redundancia
Title: "Redundancia"
Description: "Redundancia del sistema."
Context: Device
* value[x] only code
* valueCode from PimRedundanciaVS (required)

Extension: GrupoUsoMedico
Id: grupoUsoMedico
Title: "Grupo de uso médico del local"
Description: "Grupo del local según AEA 90364-7-710:2024."
Context: Location
* value[x] only Coding
* valueCoding from PimGrupoLocalVS (required)

Extension: AmbienteControlado
Id: ambienteControlado
Title: "Ambiente controlado"
Description: "Condiciones de diseño de un local con ambiente controlado."
Context: Location
* extension contains presionRelativa 0..1 and renovacionesHora 0..1 and claseISO 0..1
* extension[presionRelativa].value[x] only code
* extension[presionRelativa].valueCode from PimPresionRelativaVS (required)
* extension[renovacionesHora].value[x] only Quantity
* extension[claseISO].value[x] only string

Extension: TipoMantenimiento
Id: tipoMantenimiento
Title: "Tipo de mantenimiento"
Description: "Tipo de mantenimiento de la orden (EN 13306 y códigos PIM)."
Context: Task
* value[x] only Coding
* valueCoding from PimTipoMantenimientoVS (required)

Extension: ModoFalla
Id: modoFalla
Title: "Modo de falla"
Description: "Modo de falla observado (ISO 14224). Obligatorio al finalizar una orden correctiva."
Context: Task
* value[x] only Coding
* valueCoding from PimModoFallaVS (extensible)

Extension: EstadoDeclarado
Id: estadoDeclarado
Title: "Estado declarado del activo"
Description: "Estado del activo según quien solicita."
Context: ServiceRequest
* value[x] only Coding
* valueCoding from PimEstadoDeclaradoVS (required)

Extension: ComponentesIRU
Id: componentesIRU
Title: "Componentes del IRU"
Description: "Componentes F, R, M y A del Índice de Riesgo Unificado, su total y la banda."
Context: RiskAssessment
* extension contains F 1..1 and R 1..1 and M 1..1 and A 1..1 and total 1..1 and banda 1..1
* extension[F].value[x] only integer
* extension[R].value[x] only integer
* extension[M].value[x] only integer
* extension[A].value[x] only integer
* extension[total].value[x] only integer
* extension[banda].value[x] only Coding
* extension[banda].valueCoding from PimBandaIruVS (required)
