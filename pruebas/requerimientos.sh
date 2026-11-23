#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba del módulo requerimientos 1.0.0 sobre UN nodo iniciado: servicio de cada usuario, función transversal y el camino PIM de los
# pedidos (instalación sobre un Location con clase de sistema; equipo médico sobre un Device) hasta el cambio de estado de la orden.
#   bash pruebas/requerimientos.sh <carpeta-nodo>
# Necesita al menos un Location y un Device en el nodo. Crea dos usuarios ficticios y los desactiva al terminar; las órdenes de prueba quedan anuladas.
set -u
D="$(cd "$1" && pwd)"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
B="http://127.0.0.1:$PORT/api"; T=$(mktemp -d)
A() { curl -s -m 60 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
S() { local cj="$T/$1"; shift; curl -s -m 60 -b "$cj" -c "$cj" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${4:+-H "$4"} ${3:+-d "$3"} "$B/$2"; }
SH() { local cj="$T/$1"; shift; curl -s -m 60 -D "$T/h" -o "$T/b" -b "$cj" -c "$cj" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/fhir+json' ${3:+-d "$3"} "$B/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
R=$RANDOM; SO="reqso$R"; GM="reqgm$R"; CL="R1-$(head -c 30 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16)"; M=m/requerimientos; ORD=""
trap 'for u in $SO $GM; do A PUT "usuarios/$u" "{\"activo\":0}" >/dev/null; A PUT "$M/usuarios/$u/servicio" "{\"local_id\":\"\"}" >/dev/null; done; rm -rf "$T"' EXIT
A POST nucleo/usuario "{\"usuario\":\"$SO\",\"nombre\":\"Enfermera Req\",\"rol\":\"SO\",\"clave\":\"$CL\"}" >/dev/null; A POST nucleo/usuario "{\"usuario\":\"$GM\",\"nombre\":\"Gestor Req\",\"rol\":\"GM\",\"clave\":\"$CL\"}" >/dev/null
S so POST sesion "{\"usuario\":\"$SO\",\"clave\":\"$CL\"}" >/dev/null; S gm POST sesion "{\"usuario\":\"$GM\",\"clave\":\"$CL\"}" >/dev/null
LOCS="$(S gm GET 'pim/Location?_count=50')"
IFS=$'\t' read -r L1 L1N L1C L2 <<<"$(echo "$LOCS" | python3 -c "
import sys,json
e=[x['resource'] for x in json.load(sys.stdin).get('entry',[]) if not any(p.endswith('pim-establecimiento') for p in x['resource'].get('meta',{}).get('profile',[]))]
c=lambda l: next((i['value'] for i in l.get('identifier',[]) if i.get('system','').endswith('/sid/local')),'')
print(e[0]['id'], e[0]['name'].replace(' ','_'), c(e[0]) or '-', e[1]['id'], sep='\t')   # con tabulador: en datos reales el código puede traer espacios")"
DEV="$(S gm GET 'pim/Device?_count=1&status=active' | js "d['entry'][0]['resource']['id']")"   # 1.7.0: solo un activo en servicio admite pedidos
[ -n "$L1" ] && [ -n "$DEV" ] || { echo "El nodo no tiene Location o Device: no se puede probar"; exit 1; }

echo "1) Servicio del usuario y función transversal"
ver "sin asignación: no tiene servicio y no es transversal" "$(S so GET $M/yo | js "(d['servicio'],d['transversal'])")" '(None, False)'
ver "el gestor es transversal" "$(S gm GET $M/yo | js "d['transversal']")" 'True'
ver "un solicitante no ve la lista de usuarios" "$(S so GET $M/usuarios)" '"ok":false'
ver "ni se asigna un servicio a sí mismo" "$(S so PUT $M/usuarios/$SO/servicio "{\"local_id\":\"$L1\",\"local_nombre\":\"x\"}")" '"ok":false'
ver "el administrador le asigna el servicio" "$(A PUT $M/usuarios/$SO/servicio "{\"local_id\":\"$L1\",\"local_nombre\":\"$L1N\"}")" '"ok":true'
ver "ahora lo trae su sesión" "$(S so GET $M/yo | js "d['servicio']")" "$L1"
ver "usuario inexistente → 404" "$(A PUT $M/usuarios/nadie$R/servicio "{\"local_id\":\"$L1\",\"local_nombre\":\"x\"}")" 'No existe ese usuario'
ver "identificador inválido → 422" "$(A PUT $M/usuarios/$SO/servicio '{"local_id":"../../x y","local_nombre":"x"}')" 'inválido'
ver "servicio sin nombre → 422" "$(A PUT $M/usuarios/$SO/servicio "{\"local_id\":\"$L1\"}")" 'Falta el nombre'
ver "recordar el último servicio elegido" "$(S gm PUT $M/yo/ultimo "{\"local_id\":\"$L2\"}" >/dev/null; S gm GET $M/yo | js "d['ultimo']")" "$L2"
ver "recordar no pisa el servicio asignado" "$(S so PUT $M/yo/ultimo "{\"local_id\":\"$L2\"}" >/dev/null; S so GET $M/yo | js "(d['servicio'],d['ultimo'])")" "('$L1', '$L2')"
ver "queda en el diario firmado" "$(A GET 'proc/diario?n=30')" 'requerimientos.servicio'
ver "la función transversal se puede dar a una persona" "$(A PUT "usuarios/$SO" '{"cap_extra":["requerimientos.transversal"]}' >/dev/null; S so GET $M/yo | js "d['transversal']"; A PUT "usuarios/$SO" '{"cap_extra":[]}' >/dev/null)" 'True'

echo "2) Pedido de instalación: ServiceRequest sobre el servicio, con la clase de sistema PIM"
SH so POST pim/ServiceRequest "{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"subject\":{\"reference\":\"Location/$L1\"},\"code\":{\"coding\":[{\"system\":\"https://iproy.com.ar/fhir/CodeSystem/pim-sistema\",\"code\":\"GAS-O2\"}],\"text\":\"Oxígeno medicinal\"},\"note\":[{\"text\":\"Pérdida en la toma de la cama 3 (prueba $R)\"}],\"requester\":{\"display\":\"Enfermera Req\"}}"
ID="$(grep -i '^x-pim-task:' "$T/h" | tr -d '\r' | awk '{print $2}' | sed 's|^Task/||')"; ORD="$ID"
ver "la solicitud crea su orden (cabecera X-PIM-Task)" "$(grep -i '^x-pim-task:' "$T/h")" 'Task/'
TK="$(S gm GET "pim/Task?_id=$ID")"; NUM="$(echo "$TK" | js "d['entry'][0]['resource']['identifier'][0]['value']")"
ver "la orden queda sobre el servicio" "$(echo "$TK" | js "d['entry'][0]['resource']['location']['reference']")" "Location/$L1"
ver "con la clase de sistema como código (instalación)" "$(echo "$TK" | js "d['entry'][0]['resource']['code']['coding'][0]['code']")" 'GAS-O2'
ver "y sin equipo (focus): es lo que la distingue de un equipo médico" "$(echo "$TK" | js "'focus' in d['entry'][0]['resource']")" 'False'
ver "nace «solicitada»" "$(echo "$TK" | js "d['entry'][0]['resource']['businessStatus']['coding'][0]['code']")" 'solicitada'
ver "pedido sin servicio ni equipo → 422" "$(S so POST pim/ServiceRequest '{"resourceType":"ServiceRequest","status":"active","intent":"order","code":{"text":"x"}}')" 'subject es obligatorio'
ver "servicio inexistente → 422" "$(S so POST pim/ServiceRequest '{"resourceType":"ServiceRequest","status":"active","intent":"order","subject":{"reference":"Location/NOEXISTE0000000000000000AA"},"code":{"text":"x"}}')" 'no existe'

echo "3) Pedido de equipo médico: ServiceRequest sobre el Device, pedido por un servicio"
SH so POST pim/ServiceRequest "{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"subject\":{\"reference\":\"Device/$DEV\"},\"locationReference\":[{\"reference\":\"Location/$L1\"}],\"code\":{\"coding\":[{\"code\":\"FALLA\"}],\"text\":\"Falla o no funciona\"},\"note\":[{\"text\":\"No enciende (prueba $R)\"}]}"
ID2="$(grep -i '^x-pim-task:' "$T/h" | tr -d '\r' | awk '{print $2}' | sed 's|^Task/||')"; TK2="$(S gm GET "pim/Task?_id=$ID2")"; NUM2="$(echo "$TK2" | js "d['entry'][0]['resource']['identifier'][0]['value']")"
ver "la orden apunta al equipo (focus)" "$(echo "$TK2" | js "d['entry'][0]['resource']['focus']['reference']")" "Device/$DEV"
ver "y conserva el servicio que la pidió" "$(echo "$TK2" | js "d['entry'][0]['resource']['location']['reference']")" "Location/$L1"

echo "3b) Pedido sobre una instalación concreta: lleva su categoría de riesgo NFPA 99 (perfil pim-sistema)"
NSF=https://iproy.com.ar/fhir
SIS="$(A POST pim/Device "{\"resourceType\":\"Device\",\"meta\":{\"profile\":[\"$NSF/StructureDefinition/pim-sistema\"]},\"identifier\":[{\"system\":\"$NSF/sid/activo\",\"value\":\"REQ-$R-O2\"}],\"deviceName\":[{\"name\":\"Tanque de oxígeno $R\",\"type\":\"user-friendly-name\"}],\"type\":{\"coding\":[{\"system\":\"$NSF/CodeSystem/pim-sistema\",\"code\":\"GAS-O2\"}]},\"status\":\"active\",\"extension\":[{\"url\":\"$NSF/StructureDefinition/pim-categoria-riesgo\",\"valueCoding\":{\"system\":\"$NSF/CodeSystem/pim-categoria-riesgo\",\"code\":\"1\"}}]}")"
SID="$(echo "$SIS" | js "d['id']")"
ver "la instalación guarda su categoría NFPA 99" "$(echo "$SIS" | js "[x['valueCoding']['code'] for x in d['extension'] if x['url'].endswith('pim-categoria-riesgo')]")" "['1']"
ver "se la encuentra por su clase de sistema" "$(S so GET "pim/Device?type=GAS-O2&identifier=REQ-$R-O2" | js "[e['resource']['id'] for e in d['entry']]")" "$SID"
SH so POST pim/ServiceRequest "{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"subject\":{\"reference\":\"Device/$SID\"},\"locationReference\":[{\"reference\":\"Location/$L1\"}],\"code\":{\"coding\":[{\"system\":\"$NSF/CodeSystem/pim-sistema\",\"code\":\"GAS-O2\"}],\"text\":\"Oxígeno medicinal\"},\"note\":[{\"text\":\"Pérdida en el tanque\"}]}"
ID3="$(grep -i '^x-pim-task:' "$T/h" | tr -d '\r' | awk '{print $2}' | sed 's|^Task/||')"; TK3="$(S gm GET "pim/Task?_id=$ID3")"
ver "la orden queda unida a la instalación" "$(echo "$TK3" | js "d['entry'][0]['resource']['focus']['reference']")" "Device/$SID"
ver "conserva la clase de sistema y el servicio que pidió" "$(echo "$TK3" | js "(d['entry'][0]['resource']['code']['coding'][0]['code'], d['entry'][0]['resource']['location']['reference'])")" "('GAS-O2', 'Location/$L1')"
ver "numeración correlativa" "$(python3 -c "print(int('$NUM2'.split('-')[-1])-int('$NUM'.split('-')[-1]))")" '1'

echo "4) Cambios desde la fila de la sábana (operación \$transicion)"
V() { S gm GET "pim/Task/$1" | js "d['meta']['versionId']"; }
TR() { S gm POST "pim/Task/$1/\$transicion" "$2" "If-Match: W/\"$(V "$1")\""; }
ver "un solicitante no cambia el estado" "$(S so POST "pim/Task/$ID/\$transicion" '{"resourceType":"Parameters","parameter":[{"name":"estado","valueCode":"en-revision"},{"name":"motivo","valueString":"x"}]}' "If-Match: W/\"1\"")" 'issue'
ver "pasar a «en revisión» sin motivo → lo exige" "$(TR "$ID" '{"resourceType":"Parameters","parameter":[{"name":"estado","valueCode":"en-revision"}]}')" 'exige un motivo'
ver "con la anotación de la fila, pasa" "$(TR "$ID" '{"resourceType":"Parameters","parameter":[{"name":"estado","valueCode":"en-revision"},{"name":"motivo","valueString":"Falta presupuesto"}]}' >/dev/null; S gm GET "pim/Task/$ID" | js "d['businessStatus']['coding'][0]['code']")" 'en-revision'
ver "guardar solo el tipo de tarea, sin cambiar de estado" "$(TR "$ID" '{"resourceType":"Parameters","parameter":[{"name":"tipoMantenimiento","valueCode":"correctivo-diferido"}]}' >/dev/null; S gm GET "pim/Task/$ID" | js "[x['valueCoding']['code'] for x in d.get('extension',[]) if x['url'].endswith('pim-tipo-mantenimiento')]")" "correctivo-diferido"
ver "tipo de tarea fuera de la lista EN 13306 → 422" "$(TR "$ID" '{"resourceType":"Parameters","parameter":[{"name":"tipoMantenimiento","valueCode":"correctivo"}]}')" 'Tipo de mantenimiento' 
ver "salto de estado no permitido → 422" "$(TR "$ID" '{"resourceType":"Parameters","parameter":[{"name":"estado","valueCode":"finalizada"}]}')" 'no permitida'
ver "versión vieja (otra persona la cambió) → rechazada" "$(S gm POST "pim/Task/$ID/\$transicion" '{"resourceType":"Parameters","parameter":[{"name":"estado","valueCode":"anulada"},{"name":"motivo","valueString":"x"}]}' 'If-Match: W/"1"')" 'issue'
ver "búsqueda por estado de negocio" "$(S gm GET "pim/Task?business-status=en-revision&_count=200" | js "'$ID' in [e['resource']['id'] for e in d['entry']]")" 'True'
for i in $ID $ID2; do TR "$i" "{\"resourceType\":\"Parameters\",\"parameter\":[{\"name\":\"estado\",\"valueCode\":\"anulada\"},{\"name\":\"motivo\",\"valueString\":\"Prueba automática $R\"}]}" >/dev/null; done
ver "las órdenes de prueba quedan anuladas" "$(S gm GET "pim/Task/$ID" | js "d['businessStatus']['coding'][0]['code']")$(S gm GET "pim/Task/$ID2" | js "d['businessStatus']['coding'][0]['code']")" 'anuladaanulada'

echo "8) Operarios de la empresa y su trabajo en la orden"
OG="REQ-EMP-$R"; M2=m/requerimientos
NSF=https://iproy.com.ar/fhir
org8() { A POST pim/Organization "{\"resourceType\":\"Organization\",\"identifier\":[{\"system\":\"$NSF/sid/empresa\",\"value\":\"$1\"}],\"name\":\"Empresa $1\",\"type\":[{\"coding\":[{\"system\":\"http://terminology.hl7.org/CodeSystem/organization-type\",\"code\":\"bus\"}]}]}" | js "d['id']"; }
ORG8="$(org8 "$OG")"; ORG9="$(org8 "REQ-OTRA-$R")"
nueva() { SH so POST pim/ServiceRequest "{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"subject\":{\"reference\":\"Device/$DEV\"},\"locationReference\":[{\"reference\":\"Location/$L1\"}],\"code\":{\"coding\":[{\"code\":\"FALLA\"}],\"text\":\"Operarios $R $1\"},\"note\":[{\"text\":\"x\"}]}"
  local id; id="$(grep -i '^x-pim-task:' "$T/h" | tr -d '\r' | awk '{print $2}' | sed 's|^Task/||')"; TR "$id" "{\"resourceType\":\"Parameters\",\"parameter\":[{\"name\":\"estado\",\"valueCode\":\"asignada\"},{\"name\":\"responsable\",\"valueReference\":{\"reference\":\"Organization/$2\"}}]}" >/dev/null; echo "$id"; }
ID8="$(nueva A "$ORG8")"; ID9="$(nueva B "$ORG9")"; NUM8="$(S gm GET "pim/Task/$ID8" | js "d['identifier'][0]['value']")"
ver "dos órdenes asignadas a dos empresas" "${#ID8}${#ID9}" '2626'
ver "sin empresa → 422" "$(A GET "$M2/operarios")" 'Falta la empresa'
O1="$(A POST $M2/operarios "{\"org\":\"$OG\",\"nombre\":\"  Juan   Pérez \"}" | js "(d['operario']['id'], d['operario']['nombre'])")"; OID="$(echo "$O1" | tr -dc '0-9,' | cut -d, -f1)"
ver "alta de operario (nombre prolijo)" "$O1" "'Juan Pérez')"
ver "repetirlo no lo duplica" "$(A POST $M2/operarios "{\"org\":\"$OG\",\"nombre\":\"Juan Pérez\"}" | js "d['operario']['id']")$(A GET "$M2/operarios?org=$OG" | js "len(d['operarios'])")" "${OID}1"
O2="$(A POST $M2/operarios "{\"org\":\"$OG\",\"nombre\":\"Ana Gómez\"}" | js "d['operario']['id']")"
ver "nombre vacío → 422" "$(A POST $M2/operarios "{\"org\":\"$OG\",\"nombre\":\" \"}")" 'nombre del operario'
W1="$(A POST $M2/trabajos "{\"orden\":\"$ID8\",\"numero\":\"inventado\",\"operario_id\":$OID,\"inicio\":\"ahora\"}" | js "(d['trabajo']['id'], d['trabajo']['operario'], d['trabajo']['inicio'] is not None, d['trabajo']['fin'])")"; WID="$(echo "$W1" | tr -dc '0-9,' | cut -d, -f1)"
ver "sumar un operario a la orden, con inicio" "$W1" "'Juan Pérez', True, None)"
ver "el mismo operario dos veces → 409" "$(A POST $M2/trabajos "{\"orden\":\"$ID8\",\"operario_id\":$OID}")" 'ya está en la orden'
ver "operario inexistente → 422" "$(A POST $M2/trabajos "{\"orden\":\"$ID8\",\"operario_id\":99999999}")" 'Elegí un operario'
ver "orden de otra empresa → 409" "$(A POST $M2/trabajos "{\"orden\":\"$ID9\",\"operario_id\":$OID}")" 'otra empresa'
ver "orden que no existe → 404" "$(A POST $M2/trabajos "{\"orden\":\"0000000000NOEXISTE00000000\",\"operario_id\":$OID}")" 'No existe esa orden'
ver "el número sale de la orden, no del pedido" "$(A GET "$M2/trabajos?ordenes=$ID8" | js "d['trabajos'][0]['numero']")" "$NUM8"
ver "orden mal escrita → 422" "$(A POST $M2/trabajos "{\"orden\":\"../x\",\"operario_id\":$OID}")" 'Falta la orden'
W2="$(A POST $M2/trabajos "{\"orden\":\"$ID8\",\"numero\":\"$NUM8\",\"operario_id\":$O2}" | js "d['trabajo']['id']")"
ver "fin sin inicio → 422" "$(A PUT $M2/trabajos/$W2 '{"fin":"ahora"}')" 'primero tiene que estar el inicio'
ver "fin anterior al inicio → 422" "$(A PUT $M2/trabajos/$WID '{"fin":"2020-01-01 10:00"}')" 'anterior al inicio'
sleep 1; ver "terminar: calcula la duración" "$(A PUT $M2/trabajos/$WID '{"fin":"ahora"}' | js "(d['trabajo']['fin'] is not None, d['trabajo']['minutos'])")" '(True, 0)'
ver "la orden muestra sus dos operarios" "$(A GET "$M2/trabajos?ordenes=$ID8,$ID3" | js "sorted(t['operario'] for t in d['trabajos'])")" "['Ana Gómez', 'Juan Pérez']"
ver "reporte: por operario y registro" "$(A GET "$M2/operarios/reporte?org=$OG" | js "(len(d['por_operario']), len(d['registro']), [p['cerradas'] for p in d['por_operario'] if p['operario']=='Juan Pérez'])")" '(2, 2, [1])'
ver "quitar un operario de la orden" "$(A POST $M2/trabajos/$W2/borrar '{}' | js "d['borrado']")$(A GET "$M2/trabajos?ordenes=$ID8" | js "len(d['trabajos'])")" "${W2}1"
ver "dar de baja un operario: deja de ofrecerse" "$(A PUT $M2/operarios/$O2 '{"activo":0}' | js "d['operario']['activo']")$(A GET "$M2/operarios?org=$OG" | js "len(d['operarios'])")" '01'
ver "un servicio (sin permiso de operar) no entra" "$(S so GET "$M2/operarios?org=$OG")" '"ok":false'

echo; echo "requerimientos: $OK bien, $MAL mal"; [ "$MAL" -eq 0 ]
