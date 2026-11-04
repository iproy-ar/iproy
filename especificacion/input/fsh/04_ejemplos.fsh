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
* type[facility] = https://profiles.ihe.net/ITI/mCSD/CodeSystem/IHE.mCSD.Organization.Location.Types#facility "Facility"
* type[1] = http://terminology.hl7.org/CodeSystem/organization-type#prov "Healthcare Provider"
* active = true

Instance: ejemplo-sitio
InstanceOf: PimEstablecimiento
Title: "Ejemplo — Sitio del establecimiento"
Description: "Datos ficticios. Raíz de la jerarquía de locales; con ejemplo-hospital forma el Facility de IHE mCSD."
Usage: #example
* identifier.system = "https://iproy.com.ar/fhir/sid/local"
* identifier.value = "EJ-SITIO"
* status = #active
* name = "Hospital Ejemplo — sitio"
* type[facility] = https://profiles.ihe.net/ITI/mCSD/CodeSystem/IHE.mCSD.Organization.Location.Types#facility "Facility"
* type[1] = http://terminology.hl7.org/CodeSystem/v3-RoleCode#HOSP "Hospital"
* physicalType = http://terminology.hl7.org/CodeSystem/location-physical-type#si "Site"
* managingOrganization = Reference(ejemplo-hospital)

Instance: ejemplo-quirofano
InstanceOf: PimLocal
Title: "Ejemplo — Quirófano grupo 2b"
Description: "Datos ficticios."
Usage: #example
* identifier.system = "https://iproy.com.ar/fhir/sid/local"
* identifier.value = "EJ-QX-02"
* status = #active
* name = "Quirófano 2"
* type.text = "Quirófano (local de uso médico, grupo 2b)"
* partOf = Reference(ejemplo-sitio)
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
* identifier[anmatPM].value = "PM-0000-000"
* identifier[eui64].value = "001A010000000001"
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
* extension[ordenGenerada].valueReference = Reference(ejemplo-orden)

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
* owner = Reference(ejemplo-empresa)
* extension[tipoMantenimiento].valueCoding = PimTipoMantenimiento#correctivo-inmediato

Instance: ejemplo-empresa
InstanceOf: PimEmpresa
Title: "Ejemplo — Empresa contratista"
Description: "Datos ficticios."
Usage: #example
* identifier.system = "https://iproy.com.ar/fhir/sid/empresa"
* identifier.value = "EJ-EMP-01"
* name = "Empresa Ejemplo de Gases"
* type = http://terminology.hl7.org/CodeSystem/organization-type#bus "Non-Healthcare Business or Corporation"
* partOf = Reference(ejemplo-hospital)

Instance: ejemplo-punto-alimentacion
InstanceOf: PimPunto
Title: "Ejemplo — Atributo de alimentación de un PMA (IHE MEM-DMC)"
Description: "Datos ficticios. Punto creado al recibir un PCD-15: el tipo es el código MDC del atributo."
Usage: #example
* identifier.system = "https://iproy.com.ar/fhir/sid/punto"
* identifier.value = "EJ-PMA-000812/67925"
* type = urn:iso:std:iso:11073:10101#67925 "MDC_ATTR_POWER_STAT"
* source = Reference(ejemplo-respirador)
* category = #setting

Instance: ejemplo-medicion-mem
InstanceOf: PimMedicion
Title: "Ejemplo — Equipo médico que pasó a batería (PCD-15)"
Description: "Datos ficticios. Traducción de un OBX de IHE PCD-15 a FHIR."
Usage: #example
* meta.tag = PimOrigen#mem-dmc
* status = #final
* code = urn:iso:std:iso:11073:10101#67925 "MDC_ATTR_POWER_STAT"
* device = Reference(ejemplo-punto-alimentacion)
* effectiveDateTime = "2026-11-02T10:15:00-03:00"
* valueCodeableConcept.text = "BATT"

Instance: ejemplo-grupo
InstanceOf: PimGrupoActivo
Title: "Ejemplo — Grupo de la red de oxígeno"
Description: "Datos ficticios."
Usage: #example
* type = #device
* actual = true
* member.entity = Reference(ejemplo-red-oxigeno)

Instance: ejemplo-riesgo
InstanceOf: PimRiesgo
Title: "Ejemplo — IRU de la red de oxígeno"
Description: "Datos ficticios."
Usage: #example
* status = #final
* subject = Reference(ejemplo-grupo)
* method = PimMetodoRiesgo#iru
* basis = Reference(ejemplo-red-oxigeno)
* prediction.qualitativeRisk = PimValores#banda-A
* prediction.rationale = "IRU = F 9 + R 5 + M 4 + A 0 = 18"
* extension[iru].extension[F].valueInteger = 9
* extension[iru].extension[R].valueInteger = 5
* extension[iru].extension[M].valueInteger = 4
* extension[iru].extension[A].valueInteger = 0
* extension[iru].extension[total].valueInteger = 18
* extension[iru].extension[banda].valueCoding = PimValores#banda-A
