#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba del módulo auditoria 1.0.0 («Auditoría Mant») sobre UN nodo iniciado: la consulta por páginas, por servicio y por varios
# estados del módulo reportes, y lo que hace la ventana «Orden #» por la API PIM, con la prioridad sugerida por el IRU.
#   bash pruebas/auditoria.sh <carpeta-nodo>
# Los recursos que crea quedan en el nodo con un sufijo propio de cada corrida (el Servidor PIM no borra recursos).
set -u
D="$(cd "$1" && pwd)"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
B="http://127.0.0.1:$PORT/api"; T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
A() { curl -s -m 120 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} ${4:+-H "If-Match: $4"} "$B/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
NS=https://iproy.com.ar/fhir; S=$RANDOM; HOY=$(TZ=America/Argentina/Buenos_Aires date +%Y-%m-%d)
id_de() { grep -o "\"resourceType\":\"$1\",\"id\":\"[^\"]*\"" | head -1 | grep -o '[0-9A-Z]\{26\}'; }
VT() { printf 'W/"%s"' "$(A GET "pim/Task/$1" | grep -o '"versionId":"[0-9]*"' | head -1 | grep -o '[0-9]*')"; }
tr1() { A POST "pim/Task/$1/\$transicion" "{\"resourceType\":\"Parameters\",\"parameter\":[$2]}" "$(VT "$1")"; }
al_dia() { A POST tareas/pim:vigia/ejecutar >/dev/null; for _ in 1 2 3 4 5; do A POST nucleo/tick >/dev/null; done; }
Q="desde=$HOY&hasta=$HOY&q=AUD%20$S"

echo "1) Datos de prueba: dos servicios, una empresa, una instalación con IRU y seis órdenes"
loc() { A POST pim/Location "{\"resourceType\":\"Location\",\"identifier\":[{\"system\":\"$NS/sid/local\",\"value\":\"AUD-$1-$S\"}],\"status\":\"active\",\"name\":\"Servicio AUD $1 $S\"}" | id_de Location; }
L1=$(loc UNO); L2=$(loc DOS)
E1=$(A POST pim/Organization "{\"resourceType\":\"Organization\",\"identifier\":[{\"system\":\"$NS/sid/empresa\",\"value\":\"AUD-EMP-$S\"}],\"name\":\"Empresa AUD $S\",\"type\":[{\"coding\":[{\"system\":\"http://terminology.hl7.org/CodeSystem/organization-type\",\"code\":\"bus\"}]}]}" | id_de Organization)
SIS=$(A POST pim/Device "{\"resourceType\":\"Device\",\"meta\":{\"profile\":[\"$NS/StructureDefinition/pim-sistema\"]},\"identifier\":[{\"system\":\"$NS/sid/activo\",\"value\":\"AUD-O2-$S\"}],\"status\":\"active\",\"type\":{\"coding\":[{\"system\":\"$NS/CodeSystem/pim-sistema\",\"code\":\"GAS-O2\"}]},\"deviceName\":[{\"name\":\"Red de oxígeno AUD $S\",\"type\":\"user-friendly-name\"}],\"extension\":[{\"url\":\"$NS/StructureDefinition/pim-categoria-riesgo\",\"valueCoding\":{\"system\":\"$NS/CodeSystem/pim-categoria-riesgo\",\"code\":\"1\"}},{\"url\":\"$NS/StructureDefinition/pim-iru\",\"extension\":[{\"url\":\"F\",\"valueInteger\":9},{\"url\":\"M\",\"valueInteger\":4},{\"url\":\"A\",\"valueInteger\":0}]}]}" | id_de Device)
ver "instalación con categoría NFPA 99 e IRU" "${#SIS}" '26'
al_dia
sol() { A POST pim/ServiceRequest "{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"subject\":{\"reference\":\"Location/$2\"},\"code\":{\"text\":\"Trabajo AUD $S $1\"},\"note\":[{\"text\":\"Detalle AUD $1\"}]}" | grep -o '"reference":"Task/[^"]*"' | grep -o '[0-9A-Z]\{26\}'; }
T1=$(sol A "$L1"); T2=$(sol B "$L1"); T3=$(sol C "$L1"); T4=$(sol D "$L2"); T5=$(sol E "$L2")
T6=$(A POST pim/ServiceRequest "{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"subject\":{\"reference\":\"Device/$SIS\"},\"locationReference\":[{\"reference\":\"Location/$L2\"}],\"code\":{\"text\":\"Trabajo AUD $S F\"},\"note\":[{\"text\":\"Pierde oxígeno\"}]}" | grep -o '"reference":"Task/[^"]*"' | grep -o '[0-9A-Z]\{26\}')
ver "seis órdenes creadas" "${#T6}" '26'
R1="{\"name\":\"responsable\",\"valueReference\":{\"reference\":\"Organization/$E1\"}}"
tr1 "$T1" "{\"name\":\"estado\",\"valueCode\":\"asignada\"},$R1" >/dev/null; tr1 "$T1" '{"name":"estado","valueCode":"aceptada"}' >/dev/null; tr1 "$T1" '{"name":"estado","valueCode":"en-ejecucion"}' >/dev/null
tr1 "$T2" "{\"name\":\"estado\",\"valueCode\":\"asignada\"},$R1" >/dev/null; tr1 "$T2" '{"name":"estado","valueCode":"aceptada"}' >/dev/null; tr1 "$T2" '{"name":"estado","valueCode":"en-espera"},{"name":"motivo","valueString":"Falta repuesto"}' >/dev/null
tr1 "$T4" '{"name":"estado","valueCode":"rechazada"},{"name":"motivo","valueString":"No corresponde"}' >/dev/null
al_dia

echo "2) La consulta que usa el panel"
R=$(A GET "m/reportes/auditoria?$Q&limite=2&con_lugares=1")
ver "total de la búsqueda: 6" "$(echo "$R" | js "d['total']")" '6'
ver "página de 2" "$(echo "$R" | js "len(d['ordenes'])")" '2'
ver "trae los servicios con su cantidad" "$(echo "$R" | js "(d['lugares'].get('Servicio AUD UNO $S'), d['lugares'].get('Servicio AUD DOS $S'))")" '(3, 3)'
P2=$(A GET "m/reportes/auditoria?$Q&limite=2&desde_fila=2")
ver "la segunda página trae otras dos" "$(python3 -c "import json,sys;a=json.loads(sys.argv[1]);b=json.loads(sys.argv[2]);print(len(b['ordenes']), len({o['id'] for o in a['ordenes']} & {o['id'] for o in b['ordenes']}))" "$R" "$P2")" '2 0'
ver "y la cuarta página queda vacía" "$(A GET "m/reportes/auditoria?$Q&limite=2&desde_fila=6" | js "len(d['ordenes'])")" '0'
ver "sin «limite» se comporta como antes (todas)" "$(A GET "m/reportes/auditoria?$Q" | js "len(d['ordenes'])")" '6'
ver "filtro por servicio" "$(A GET "m/reportes/auditoria?$Q&lugar=Servicio%20AUD%20UNO%20$S" | js "d['total']")" '3'
ver "varios estados a la vez (grupo «En Proceso»)" "$(A GET "m/reportes/auditoria?$Q&estados=asignada,aceptada,lista,en-ejecucion" | js "(d['total'], d['ordenes'][0]['estado'])")" "(1, 'en-ejecucion')"
ver "grupo «Atención»" "$(A GET "m/reportes/auditoria?$Q&estados=en-espera" | js "d['total']")" '1'
ver "grupo «Denegado»" "$(A GET "m/reportes/auditoria?$Q&estados=rechazada,anulada" | js "d['total']")" '1'
ver "grupo «Pendiente»" "$(A GET "m/reportes/auditoria?$Q&estados=solicitada" | js "d['total']")" '3'
ver "el resumen no depende del estado elegido (sirve para las tarjetas)" "$(A GET "m/reportes/auditoria?$Q&estados=en-espera" | js "sorted(d['resumen']['por_estado'].items())")" "[('en-ejecucion', 1), ('en-espera', 1), ('rechazada', 1), ('solicitada', 3)]"
ver "estado que no existe: no trae nada (no ignora el filtro)" "$(A GET "m/reportes/auditoria?$Q&estados=inventado" | js "d['total']")" '0'
ver "servicio y estado combinados" "$(A GET "m/reportes/auditoria?$Q&lugar=Servicio%20AUD%20DOS%20$S&estados=solicitada" | js "d['total']")" '2'
ver "clase: la orden sobre la instalación y las de servicio son «instalación»" "$(A GET "m/reportes/auditoria?$Q&clase=instalacion" | js "d['total']")" '6'
ver "clase «equipo»: ninguna en este juego de datos" "$(A GET "m/reportes/auditoria?$Q&clase=equipo" | js "d['total']")" '0'
EQ=$(A POST pim/Device "{\"resourceType\":\"Device\",\"identifier\":[{\"system\":\"$NS/sid/activo\",\"value\":\"AUD-EQ-$S\"}],\"deviceName\":[{\"name\":\"Monitor AUD $S\",\"type\":\"user-friendly-name\"}],\"status\":\"active\"}" | id_de Device)
A POST pim/ServiceRequest "{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"subject\":{\"reference\":\"Device/$EQ\"},\"locationReference\":[{\"reference\":\"Location/$L1\"}],\"code\":{\"coding\":[{\"code\":\"FALLA\"}],\"text\":\"Trabajo AUD $S G\"},\"note\":[{\"text\":\"No enciende\"}]}" >/dev/null; al_dia
ver "un pedido sobre un equipo médico queda en la clase «equipo» (Auditor Bio)" "$(A GET "m/reportes/auditoria?$Q&clase=equipo" | js "(d['total'], d['ordenes'][0]['clase'], d['ordenes'][0]['activo'])")" "(1, 'equipo', 'Monitor AUD $S')"
ver "y no aparece en la de instalaciones (Auditoría Mant)" "$(A GET "m/reportes/auditoria?$Q&clase=instalacion" | js "d['total']")" '6'
ver "el ajuste del módulo" "$(A GET m/auditoria/estado | js "d['por_pagina']")" '50'

echo "3) La ventana «Orden #»: decidir por la API PIM respetando el IRU"
TK=$(A GET "pim/Task/$T6")
ver "la orden sobre la instalación trae la prioridad sugerida por el IRU" "$(echo "$TK" | js "[s['valueCode'] for x in d['extension'] if x['url'].endswith('pim-decision-prioridad') for s in x['extension'] if s['url']=='sugerida']")" "['stat']"
ver "y la decisión está pendiente" "$(echo "$TK" | js "[s['valueCoding']['code'] for x in d['extension'] if x['url'].endswith('pim-decision-prioridad') for s in x['extension'] if s['url']=='decision']")" "['pendiente']"
ver "cambiar la prioridad sin seguir la sugerida y sin motivo → 422" "$(tr1 "$T6" '{"name":"prioridad","valueCode":"routine"}')" 'exige el motivo'
RC=$(tr1 "$T6" '{"name":"prioridad","valueCode":"urgent"},{"name":"motivoPrioridad","valueString":"Hay tanque de respaldo en servicio"}')
ver "con motivo: queda registrada como «cambiada»" "$(echo "$RC" | js "[s.get('valueCoding',{}).get('code') or s.get('valueString') for x in d['extension'] if x['url'].endswith('pim-decision-prioridad') for s in x['extension'] if s['url'] in ('decision','motivo')]")" "['cambiada', 'Hay tanque de respaldo en servicio']"
RA=$(tr1 "$T6" '{"name":"prioridad","valueCode":"stat"}')
ver "volver a la sugerida: «aceptada», sin pedir motivo" "$(echo "$RA" | js "(d['priority'], [s['valueCoding']['code'] for x in d['extension'] if x['url'].endswith('pim-decision-prioridad') for s in x['extension'] if s['url']=='decision'])")" "('stat', ['aceptada'])"
ver "estado + empresa + tipo en un solo guardado" "$(tr1 "$T6" "{\"name\":\"estado\",\"valueCode\":\"asignada\"},$R1,{\"name\":\"tipoMantenimiento\",\"valueCode\":\"correctivo-inmediato\"},{\"name\":\"nota\",\"valueString\":\"Se manda a la empresa\"}" | js "(d['businessStatus']['coding'][0]['code'], d['owner']['display'])")" "('asignada', 'Empresa AUD $S')"
ver "pasar a espera sin motivo → 422" "$(tr1 "$T1" '{"name":"estado","valueCode":"en-espera"}')" 'exige un motivo'
ver "transición que PIM no permite → 422" "$(tr1 "$T3" '{"name":"estado","valueCode":"finalizada"}')" 'Transición no permitida'
ver "sin If-Match → 428" "$(A POST "pim/Task/$T3/\$transicion" '{"resourceType":"Parameters","parameter":[{"name":"estado","valueCode":"en-revision"},{"name":"motivo","valueString":"x"}]}')" 'If-Match'
al_dia
ver "el panel refleja lo decidido" "$(A GET "m/reportes/auditoria?$Q&estados=asignada,aceptada,lista,en-ejecucion" | js "sorted((o['estado'], o['org'], o['prioridad']) for o in d['ordenes'])")" "[('asignada', 'Empresa AUD $S', 'stat'), ('en-ejecucion', 'Empresa AUD $S', 'routine')]"

echo "4) Áreas de auditoría: qué empresas ofrece cada panel"
AR0=$(A GET m/auditoria/areas/instalacion | js "','.join(d['empresas'])"); AR1=$(A GET m/auditoria/areas/equipo | js "','.join(d['empresas'])")
lista() { python3 -c "import json,sys; print(json.dumps({'empresas':[x for x in sys.argv[1:] if x]}))" "$@"; }
ver "guardar las empresas de Auditoría Mant" "$(A PUT m/auditoria/areas/instalacion "$(lista "$E1")" | js "d['empresas']")" "['$E1']"
ver "la misma empresa también en Auditor Bio" "$(A PUT m/auditoria/areas/equipo "$(lista "$E1")" | js "d['empresas']")$(A GET m/auditoria/areas/instalacion | js "d['empresas']")" "['$E1']['$E1']"
ver "panel desconocido → 404" "$(A GET m/auditoria/areas/otro)" 'Panel desconocido'
ver "empresa mal escrita → 422" "$(A PUT m/auditoria/areas/equipo '{"empresas":["../x"]}')" 'inválida'
ver "un gestor común no la cambia" "$(A GET m/auditoria/estado | js "d['configura']")" 'True'
A PUT m/auditoria/areas/instalacion "$(IFS=,; lista $AR0)" >/dev/null; A PUT m/auditoria/areas/equipo "$(IFS=,; lista $AR1)" >/dev/null
ver "se repone lo que había" "$(A GET m/auditoria/areas/instalacion | js "','.join(d['empresas'])")" "$AR0"

echo; echo "auditoria: $OK bien, $MAL mal"; [ "$MAL" -eq 0 ]
