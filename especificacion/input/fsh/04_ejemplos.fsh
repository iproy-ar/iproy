// SPDX-License-Identifier: CC-BY-SA-4.0
// Copyright (C) 2026 Ovidio Francisco Arias
Instance: ejemplo-hospital
InstanceOf: PimOrganismo
Title: "Ejemplo — Hospital ficticio"
Description: "Datos ficticios."
Usage: #example
* identifier.system = "https://iproy.com.ar/fhir/sid/refes"
* identifier.value = "00000000000000"
* name = "Hospital Ejemplo"

Instance: ejemplo-quirofano
InstanceOf: PimLocal
Title: "Ejemplo — Quirófano grupo 2b"
Description: "Datos ficticios."
Usage: #example
* identifier.system = "https://iproy.com.ar/fhir/sid/local"
* identifier.value = "EJ-QX-02"
* status = #active
* name = "Quirófano 2"
* physicalType = http://terminology.hl7.org/CodeSystem/location-physical-type#ro "Room"
* managingOrganization = Reference(ejemplo-hospital)
* extension[grupoUsoMedico].valueCoding = PimGrupoLocal#2b
* extension[ambienteControlado].extension[presionRelativa].valueCode = PimValores#positiva
* extension[ambienteControlado].extension[renovacionesHora].valueQuantity = 20 $UCUM#/h "/h"

Instance: ejemplo-respirador
InstanceOf: PimPma
Title: "Ejemplo — Producto médico activo con datos del REFES"
Description: "Datos ficticios."
Usage: #example
* identifier[inventario].value = "EJ-PMA-000812"
* identifier[anmatPM].value = "PM-0000-0000"
* status = #active
* manufacturer = "Fabricante Ejemplo"
* modelNumber = "VM-900"
* serialNumber = "SN-123456"
* manufactureDate = "2021-06-01"
* owner = Reference(ejemplo-hospital)
* location = Reference(ejemplo-quirofano)
* extension[categoriaAlcance].valueCoding = PimCategoriaAlcance#A
* extension[puestaEnMarcha].valueDateTime = "2021-09-15"
* extension[pertenencia].valueCoding = PimValores#desconocida
* extension[claseRiesgoPM].valueCoding = PimClaseRiesgoPM#III

Instance: ejemplo-red-oxigeno
InstanceOf: PimSistema
Title: "Ejemplo — Red de oxígeno medicinal"
Description: "Datos ficticios."
Usage: #example
* identifier.system = "https://iproy.com.ar/fhir/sid/activo"
* identifier.value = "EJ-IN-000045"
* type = PimSistemaCS#GAS-O2
* status = #active
* owner = Reference(ejemplo-hospital)
* extension[categoriaAlcance].valueCoding = PimCategoriaAlcance#B
* extension[categoriaRiesgo].valueCoding = PimCategoriaRiesgo#1
* extension[redundancia].valueCode = PimValores#N+1

Instance: ejemplo-presion-linea
InstanceOf: PimPunto
Title: "Ejemplo — Punto de presión de línea"
Description: "Datos ficticios."
Usage: #example
* identifier.system = "https://iproy.com.ar/fhir/sid/punto"
* identifier.value = "EJ-IN-000045/PRES-LINEA"
* type = PimPuntoCS#PRES-LINEA
* unit = $UCUM#kPa
* source = Reference(ejemplo-red-oxigeno)
* operationalStatus = #on
* category = #measurement

Instance: ejemplo-medicion
InstanceOf: PimMedicion
Title: "Ejemplo — Medición fuera de rango"
Description: "Datos ficticios."
Usage: #example
* status = #final
* code = PimPuntoCS#PRES-LINEA
* device = Reference(ejemplo-presion-linea)
* effectiveInstant = "2026-10-29T14:30:00-03:00"
* valueQuantity = 330 $UCUM#kPa "kPa"
* interpretation = http://terminology.hl7.org/CodeSystem/v3-ObservationInterpretation#L
* referenceRange.low = 380 $UCUM#kPa "kPa"
* referenceRange.high = 450 $UCUM#kPa "kPa"

Instance: ejemplo-orden
InstanceOf: PimOrden
Title: "Ejemplo — Orden generada por la medición"
Description: "Datos ficticios."
Usage: #example
* identifier.system = "https://iproy.com.ar/fhir/sid/orden"
* identifier.value = "EJ-OT-2026-000001"
* status = #requested
* businessStatus = PimEstadoOrden#solicitada
* intent = #order
* priority = #stat
* focus = Reference(ejemplo-red-oxigeno)
* location = Reference(ejemplo-quirofano)
* authoredOn = "2026-10-29T14:30:05-03:00"
* lastModified = "2026-10-29T14:30:05-03:00"
* reasonReference = Reference(ejemplo-medicion)
* extension[tipoMantenimiento].valueCoding = PimTipoMantenimiento#correctivo-inmediato
