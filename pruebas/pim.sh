#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba del Servidor PIM 1.1 (PIM 0.9.2) sobre UN nodo iniciado: guía, conformidad en formato IHE, directorio mCSD (ITI-90/91),
# PMA con datos REFES y EUI-64, Device/$refes, DeviceMetric + Observation, solicitud → orden con reasonReference, alias de la 0.9.1.
#   bash pruebas/pim.sh <carpeta-nodo>
# Crea sus propios datos ficticios (prefijo EJ-) y no usa datos reales.
set -u
D="$1"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
A() { curl -s -m 90 -X "$1" -H "X-$P-Tick: $TOK" -H 'Content-Type: application/json' ${3:+-d "$3"} ${4:+-H "If-Match: $4"} "http://127.0.0.1:$PORT/api/pim/$2"; }
G() { curl -s -m 30 "http://127.0.0.1:$PORT/pim/$1"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:300}"; MAL=$((MAL+1)); fi; }
no()  { if [[ "$2" != *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:300}"; MAL=$((MAL+1)); fi; }
id_de() { grep -o "\"resourceType\":\"$1\",\"id\":\"[^\"]*\"" | head -1 | grep -o '[0-9A-Z]\{26\}'; }
NS=https://iproy.com.ar/fhir; S=$RANDOM; DESDE=$(date -d '-1 min' +%Y-%m-%dT%H:%M:%S-03:00)

echo "1) Guía y conformidad (formato IHE)"
M=$(A GET metadata)
ver "CapabilityStatement 1.1.0" "$M" '"version":"1.1.0"'
ver "instancia el actor pim-gm" "$M" "$NS/CapabilityStatement/pim-gm"
ver "importa IHE mCSD Directory" "$M" 'IHE.mCSD.Directory'
ver "extensión pim-conformidad con nivel" "$M" "$NS/StructureDefinition/pim-conformidad"
ver "declara la opción mcsd-update" "$M" '"valueCode":"mcsd-update"'
ver "Declaración de Integración PIM" "$(G declaracion)" '"basada_en":"IHE Integration Statement"'
ver "CodeSystem de la guía (pim-estado-orden)" "$(A GET CodeSystem/pim-estado-orden)" '"code":"conformada"'
ver "ValueSet MDC de MEM-DMC" "$(A GET ValueSet/pim-mem-dmc)" '"code":"67925"'
ver "pim-organismo deriva de mCSD FacilityOrganization" "$(A GET StructureDefinition/pim-organismo)" 'IHE.mCSD.FacilityOrganization'
ver "OperationDefinition Device-refes" "$(A GET OperationDefinition/Device-refes)" '"code":"refes"'
ver "CapabilityStatement del actor RE" "$(A GET CapabilityStatement/pim-re)" '"name":"PimRegistroEstatal"'

echo "2) Directorio (IHE mCSD ITI-90 / ITI-91)"
EST=$(A GET 'Location?type=facility' | id_de Location)
ver "type=facility → establecimiento (pim-establecimiento)" "$(A GET "Location/$EST")" "$NS/StructureDefinition/pim-establecimiento"
ORG=$(A GET "Location/$EST" | grep -o '"managingOrganization":{"reference":"Organization/[^"]*"' | grep -o '[0-9A-Z]\{26\}')
R=$(A POST Location "{\"resourceType\":\"Location\",\"identifier\":[{\"system\":\"$NS/sid/local\",\"value\":\"EJ-ED-$S\"}],\"status\":\"active\",\"name\":\"Edificio EJ $S\",\"physicalType\":{\"coding\":[{\"system\":\"http://terminology.hl7.org/CodeSystem/location-physical-type\",\"code\":\"bu\"}]}}")
ED=$(printf '%s' "$R" | id_de Location); ver "alta de edificio (partOf → establecimiento)" "$R" "\"partOf\":{\"reference\":\"Location/$EST\"}"
R=$(A POST Location "{\"resourceType\":\"Location\",\"identifier\":[{\"system\":\"$NS/sid/local\",\"value\":\"EJ-QX-$S\"}],\"status\":\"active\",\"name\":\"Quirófano EJ $S\",\"partOf\":{\"reference\":\"Location/$ED\"},\"physicalType\":{\"coding\":[{\"system\":\"http://terminology.hl7.org/CodeSystem/location-physical-type\",\"code\":\"ro\"}]},\"extension\":[{\"url\":\"$NS/StructureDefinition/grupoUsoMedico\",\"valueCoding\":{\"system\":\"$NS/CodeSystem/pim-grupo-local\",\"code\":\"2b\"}},{\"url\":\"$NS/StructureDefinition/pim-ambiente-controlado\",\"extension\":[{\"url\":\"presionRelativa\",\"valueCode\":\"positiva\"},{\"url\":\"renovacionesHora\",\"valueQuantity\":{\"value\":20,\"unit\":\"/h\",\"system\":\"http://unitsofmeasure.org\",\"code\":\"/h\"}}]}]}")
QX=$(printf '%s' "$R" | id_de Location)
ver "alias de la 0.9.1 (grupoUsoMedico) → pim-grupo-uso-medico" "$R" "$NS/StructureDefinition/pim-grupo-uso-medico"
ver "ambiente controlado: presión positiva y renovaciones /h" "$R" '"valueCode":"positiva"'
ver "type obligatorio de mCSD (texto del tipo de local)" "$R" '"type":[{"text":"Local"}]'
ver "partof=Location/{establecimiento} incluye el edificio" "$(A GET "Location?partof=Location/$EST&_count=200")" "\"id\":\"$ED\""
ver "name:exact" "$(A GET "Location?name:exact=Quir%C3%B3fano%20EJ%20$S")" "\"id\":\"$QX\""
ver "organization=Organization/{organismo}" "$(A GET "Location?organization=Organization/$ORG&_count=1&_summary=count")" '"total":'
ver "ITI-91: Location/_history?_since" "$(A GET "Location/_history?_since=$DESDE")" "Location/$QX"
R=$(A POST Organization "{\"resourceType\":\"Organization\",\"identifier\":[{\"system\":\"$NS/sid/empresa\",\"value\":\"EJ-EMP-$S\"}],\"name\":\"Empresa EJ $S\",\"type\":[{\"coding\":[{\"system\":\"http://terminology.hl7.org/CodeSystem/organization-type\",\"code\":\"bus\"}]}]}")
EMP=$(printf '%s' "$R" | id_de Organization); ver "empresa (pim-empresa)" "$R" "$NS/StructureDefinition/pim-empresa"
ver "Organization?type=bus" "$(A GET "Organization?type=bus&_count=200")" "\"id\":\"$EMP\""
ver "ITI-91: Organization/_history?_since" "$(A GET "Organization/_history?_since=$DESDE")" "Organization/$EMP"

echo "3) PMA con datos REFES y EUI-64; categoría de alcance"
EUI=$(printf '%016X' $((RANDOM * 65536 + S)))
R=$(A POST Device "{\"resourceType\":\"Device\",\"meta\":{\"profile\":[\"$NS/StructureDefinition/pim-pma\"]},\"identifier\":[{\"system\":\"$NS/sid/activo\",\"value\":\"EJ-PMA-$S\"},{\"system\":\"$NS/sid/anmat-pm\",\"value\":\"PM-0000-0001\"},{\"system\":\"$NS/sid/eui64\",\"value\":\"$EUI\"}],\"status\":\"active\",\"manufacturer\":\"Fabricante EJ\",\"modelNumber\":\"VM-900\",\"manufactureDate\":\"2021-06-01\",\"deviceName\":[{\"name\":\"Respirador EJ $S\",\"type\":\"user-friendly-name\"}],\"location\":{\"reference\":\"Location/$QX\"},\"extension\":[{\"url\":\"$NS/StructureDefinition/pertenencia\",\"valueCoding\":{\"system\":\"$NS/CodeSystem/pim-valores\",\"code\":\"desconocida\"}},{\"url\":\"$NS/StructureDefinition/claseRiesgoPM\",\"valueCoding\":{\"system\":\"$NS/CodeSystem/pim-clase-riesgo-pm\",\"code\":\"III\"}}]}")
PMA=$(printf '%s' "$R" | id_de Device)
ver "perfil pim-pma" "$R" "$NS/StructureDefinition/pim-pma"
ver "categoría de alcance A (deducida)" "$R" '"code":"A"'
ver "identificador EUI-64" "$R" "\"system\":\"$NS/sid/eui64\",\"value\":\"$EUI\""
ver "búsqueda por EUI-64" "$(A GET "Device?identifier=$NS/sid/eui64%7C$EUI")" "\"id\":\"$PMA\""
R=$(A POST Device "{\"resourceType\":\"Device\",\"meta\":{\"profile\":[\"$NS/StructureDefinition/pim-equipo-medico\"]},\"identifier\":[{\"system\":\"$NS/sid/activo\",\"value\":\"EJ-PMA2-$S\"}],\"status\":\"active\",\"deviceName\":[{\"name\":\"Monitor EJ $S\",\"type\":\"user-friendly-name\"}]}")
PMA2=$(printf '%s' "$R" | id_de Device); ver "perfil de la 1.0 (pim-equipo-medico) → pim-pma" "$R" "$NS/StructureDefinition/pim-pma"
ver "n.º ANMAT inválido → 422" "$(A POST Device "{\"resourceType\":\"Device\",\"meta\":{\"profile\":[\"$NS/StructureDefinition/pim-pma\"]},\"identifier\":[{\"system\":\"$NS/sid/activo\",\"value\":\"EJ-X-$S\"},{\"system\":\"$NS/sid/anmat-pm\",\"value\":\"12\"}],\"deviceName\":[{\"name\":\"x\"}]}")" 'ANMAT: PM-'
R=$(A POST Device "{\"resourceType\":\"Device\",\"meta\":{\"profile\":[\"$NS/StructureDefinition/pim-pma\"]},\"identifier\":[{\"system\":\"$NS/sid/activo\",\"value\":\"EJ-PMA3-$S\"},{\"system\":\"$NS/sid/anmat-pm\",\"value\":\"PM: 2430-65\"}],\"status\":\"active\",\"deviceName\":[{\"name\":\"Bomba EJ $S\",\"type\":\"user-friendly-name\"}]}")
ver "n.º ANMAT «PM: 2430-65» (forma de las disposiciones) se normaliza a PM-2430-65" "$R" '"value":"PM-2430-65"'
R=$(A POST Device "{\"resourceType\":\"Device\",\"meta\":{\"profile\":[\"$NS/StructureDefinition/pim-sistema\"]},\"identifier\":[{\"system\":\"$NS/sid/activo\",\"value\":\"EJ-O2-$S\"}],\"status\":\"active\",\"type\":{\"coding\":[{\"system\":\"$NS/CodeSystem/pim-sistema\",\"code\":\"GAS-O2\"}]},\"deviceName\":[{\"name\":\"Red de oxígeno EJ $S\",\"type\":\"user-friendly-name\"}],\"extension\":[{\"url\":\"$NS/StructureDefinition/pim-categoria-riesgo\",\"valueCoding\":{\"system\":\"$NS/CodeSystem/pim-categoria-riesgo\",\"code\":\"1\"}},{\"url\":\"$NS/StructureDefinition/pim-iru\",\"extension\":[{\"url\":\"F\",\"valueInteger\":9},{\"url\":\"M\",\"valueInteger\":4},{\"url\":\"A\",\"valueInteger\":0}]},{\"url\":\"$NS/StructureDefinition/pim-punto-medicion\",\"extension\":[{\"url\":\"punto\",\"valueCode\":\"PRES-LINEA\"},{\"url\":\"unidad\",\"valueCode\":\"kPa\"},{\"url\":\"minimo\",\"valueDecimal\":380},{\"url\":\"maximo\",\"valueDecimal\":450}]}]}")
O2=$(printf '%s' "$R" | id_de Device)
ver "sistema GAS-O2: alcance B (Tabla 3-x)" "$R" "\"url\":\"$NS/StructureDefinition/pim-categoria-alcance\",\"valueCoding\":{\"system\":\"$NS/CodeSystem/pim-categoria-alcance\",\"code\":\"B\"}"
ver "IRU con banda en pim-valores" "$R" '"code":"banda-A"'
no  "los puntos ya no van dentro de Device" "$R" 'pim-punto-medicion'
ver "Device?categoria-alcance=B" "$(A GET "Device?categoria-alcance=B&_count=200")" "\"id\":\"$O2\""

echo "4) PIM-REG: Device/\$refes"
R=$(A GET "Device/$PMA/\$refes"); ver "PMA completo" "$R" '"name":"completo","valueBoolean":true'
R=$(A GET "Device/$PMA2/\$refes"); ver "PMA incompleto: informa lo que falta" "$R" '"name":"falta","valueString":"n.º de producto ANMAT"'
ver "a nivel de tipo, con total" "$(A GET 'Device/$refes?completos=false')" '"name":"total"'
ver "un sistema no es PMA → 404" "$(A GET "Device/$O2/\$refes")" 'no es un producto médico activo'
ver "archivo CSV de carga (_format=csv)" "$(A GET 'Device/$refes?_format=csv')" "\"EJ-PMA-$S\";\"Fabricante EJ\""

echo "5) Telemetría: DeviceMetric + Observation"
DM=$(A GET "DeviceMetric?source=Device/$O2" | id_de DeviceMetric)
ver "DeviceMetric del punto configurado (pim-punto)" "$(A GET "DeviceMetric/$DM")" '"code":"PRES-LINEA"'
R=$(A POST Observation "{\"resourceType\":\"Observation\",\"status\":\"final\",\"code\":{\"coding\":[{\"system\":\"$NS/CodeSystem/pim-punto\",\"code\":\"PRES-LINEA\"}]},\"device\":{\"reference\":\"DeviceMetric/$DM\"},\"effectiveDateTime\":\"$(date +%Y-%m-%dT%H:%M:%S-03:00)\",\"valueQuantity\":{\"value\":330,\"unit\":\"kPa\",\"system\":\"http://unitsofmeasure.org\",\"code\":\"kPa\"}}")
OB=$(printf '%s' "$R" | id_de Observation)
ver "medición fuera de rango → orden generada" "$R" "$NS/StructureDefinition/pim-orden-generada"
ver "Observation.device → DeviceMetric" "$R" "\"device\":{\"reference\":\"DeviceMetric/$DM\"}"
T=$(printf '%s' "$R" | grep -o '"reference":"Task/[^"]*"' | grep -o '[0-9A-Z]\{26\}')
ver "Task.reasonReference → la medición" "$(A GET "Task/$T")" "\"reasonReference\":{\"reference\":\"Observation/$OB\"}"
ver "Observation?device=DeviceMetric/{id}" "$(A GET "Observation?device=DeviceMetric/$DM")" "\"id\":\"$OB\""
R=$(A POST Observation "{\"resourceType\":\"Observation\",\"status\":\"final\",\"code\":{\"coding\":[{\"system\":\"urn:iso:std:iso:11073:10101\",\"code\":\"67925\"}]},\"device\":{\"reference\":\"Device/$PMA\"},\"effectiveDateTime\":\"$(date +%Y-%m-%dT%H:%M:%S-03:00)\",\"valueCodeableConcept\":{\"text\":\"BATT\"}}")
ver "valor codificado y código MDC (DeviceMetric automático)" "$R" '"valueCodeableConcept":{"text":"BATT"}'
ver "DeviceMetric MDC 67925" "$(A GET "DeviceMetric?source=Device/$PMA")" '"system":"urn:iso:std:iso:11073:10101","code":"67925"'

echo "6) Solicitud → orden (PIM-3 a PIM-5)"
R=$(A POST ServiceRequest "{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"subject\":{\"reference\":\"Device/$PMA\"},\"code\":{\"text\":\"Revisión EJ\"},\"note\":[{\"text\":\"Prueba\"}],\"reasonReference\":[{\"reference\":\"Observation/$OB\"}],\"extension\":[{\"url\":\"$NS/StructureDefinition/estadoDeclarado\",\"valueCoding\":{\"system\":\"$NS/CodeSystem/pim-valores\",\"code\":\"degradado\"}}]}")
ver "estado declarado como Coding de pim-valores" "$R" '"code":"degradado"'
ver "reasonReference en la solicitud" "$R" "\"reasonReference\":[{\"reference\":\"Observation/$OB\"}]"
T2=$(printf '%s' "$R" | grep -o '"reference":"Task/[^"]*"' | grep -o '[0-9A-Z]\{26\}')
R=$(A POST "Task/$T2/\$transicion" "{\"resourceType\":\"Parameters\",\"parameter\":[{\"name\":\"estado\",\"valueCode\":\"asignada\"},{\"name\":\"responsable\",\"valueReference\":{\"reference\":\"Organization/$EMP\"}}]}")
ver "transición solicitada → asignada (PIM-OT)" "$R" '"code":"asignada"'
ver "transición no permitida → 422" "$(A POST "Task/$T2/\$transicion" '{"resourceType":"Parameters","parameter":[{"name":"estado","valueCode":"conformada"}]}')" 'Transición no permitida'
ver "Provenance encadenado" "$(A GET "Provenance?target=Task/$T2")" 'application/x-pim-huella'
ver "RiskAssessment con banda en pim-valores" "$(A GET "RiskAssessment?subject=Device/$O2")" '"code":"banda-A"'

echo "7) Decisión humana sobre la prioridad sugerida por el IRU (1.4.0)"
sol() { A POST ServiceRequest "{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"subject\":{\"reference\":\"Device/$O2\"},\"code\":{\"text\":\"Decisión EJ $1\"},\"note\":[{\"text\":\"Prueba de prioridad\"}]}" | grep -o '"reference":"Task/[^"]*"' | grep -o '[0-9A-Z]\{26\}'; }
par() { printf '{"resourceType":"Parameters","parameter":[%s]}' "$1"; }
T3=$(sol 1); R=$(A GET "Task/$T3")
ver "la orden guarda la prioridad sugerida (banda A → stat)" "$R" '"url":"sugerida","valueCode":"stat"'
ver "fundamento visible (IRU = F + R + M + A)" "$R" 'IRU = F 9 + R 5 + M 4 + A 0 = 18'
ver "decisión pendiente al nacer" "$R" '"code":"pendiente"'
ver "cambiar la prioridad sin motivo → 422" "$(A POST "Task/$T3/\$transicion" "$(par '{"name":"prioridad","valueCode":"routine"}')")" 'exige el motivo'
R=$(A POST "Task/$T3/\$transicion" "$(par '{"name":"prioridad","valueCode":"routine"},{"name":"motivoPrioridad","valueString":"Hay red de respaldo en servicio"}')")
ver "cambio con motivo → decisión «cambiada»" "$R" '"code":"cambiada"'
ver "el motivo queda registrado" "$R" 'Hay red de respaldo en servicio'
ver "búsqueda Task?decision-prioridad=cambiada" "$(A GET "Task?decision-prioridad=cambiada&_count=50")" "\"id\":\"$T3\""
T4=$(sol 2); R=$(A POST "Task/$T4/\$transicion" "{\"resourceType\":\"Parameters\",\"parameter\":[{\"name\":\"estado\",\"valueCode\":\"asignada\"},{\"name\":\"responsable\",\"valueReference\":{\"reference\":\"Organization/$EMP\"}}]}")
ver "asignar sin decidir → decisión implícita «aceptada»" "$R" '"code":"aceptada"'
T5=$(sol 3)
ver "rechazar la sugerencia sin motivo → 422" "$(A POST "Task/$T5/\$transicion" "$(par '{"name":"decisionPrioridad","valueCode":"rechazada"}')")" 'exige el motivo'
ver "decisión sobre una orden sin IRU → 422" "$(A POST "Task/$T2/\$transicion" "$(par '{"name":"decisionPrioridad","valueCode":"aceptada"}')")" 'no tiene prioridad sugerida'

echo "8) PIM-8: _history paginado en orden cronológico (1.5.1) · IRU normativo (0.9.4)"
R=$(A GET "Task/_history?_count=2")
ver "history con total real y link next" "$R" '"relation":"next"'
P1=$(printf '%s' "$R" | grep -o '"lastModified":"[^"]*"' | head -1 | cut -d'"' -f4); P2=$(printf '%s' "$R" | grep -o '"lastModified":"[^"]*"' | sed -n 2p | cut -d'"' -f4)
ver "orden cronológico ascendente" "$([[ "$P1" < "$P2" || "$P1" == "$P2" ]] && echo asc)" 'asc'
ver "segunda página con _offset" "$(A GET "Task/_history?_count=2&_offset=2")" '_offset=4'
R=$(A GET "Device/$O2")
ver "pim-iru publica la banda base" "$R" '"url":"banda","valueCoding":{"system":"'"$NS"'/CodeSystem/pim-valores","code":"banda-A"'
ver "pim-iru publica la prioridad sugerida (A → stat)" "$R" '"url":"prioridad","valueCode":"stat"'
ver "ConceptMap banda → prioridad servido por el nodo" "$(G r4/ConceptMap/pim-banda-a-prioridad)" '"code":"banda-A","target":[{"code":"stat"'
ver "SearchParameter decision-prioridad servido por el nodo" "$(G r4/SearchParameter/pim-task-decision-prioridad)" '"code":"decision-prioridad"'
ver "CapabilityStatement declara decision-prioridad" "$(G r4/metadata)" '"name":"decision-prioridad"'

echo; [[ $MAL -eq 0 ]] && echo "✔ $OK comprobaciones correctas" || echo "✘ $MAL fallas, $OK correctas"; exit $((MAL > 0))
