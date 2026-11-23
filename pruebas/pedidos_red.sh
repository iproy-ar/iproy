#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Pedidos a otros nodos (módulo pedidos_red, Ola P · P2): A le pide un trabajo a B por la colmena y sigue su orden.
#   bash pruebas/pedidos_red.sh <carpeta-nodo-A> <carpeta-nodo-B>
# A tiene que tener el módulo pedidos_red; A y B tienen que estar vinculados (activo). Deja el rol que B le daba a A como estaba.
set -u
A_DIR="$1"; B_DIR="$2"
cargar() { local d="$1" env; env="$(ls "$d"/data/*.env | grep -v secrets | head -1)"; local P; P="$(basename "$env" .env | tr a-z A-Z)"
  set -a; . "$env"; . "$d/data/secrets.env"; set +a; eval "echo \"$P \$${P}_PUERTO \$${P}_TICK_TOKEN\""; }
read -r PA PORT_A TOK_A <<< "$(cargar "$A_DIR")"; read -r PB PORT_B TOK_B <<< "$(cargar "$B_DIR")"
A() { curl -s -m 120 -X "$1" -H "X-$PA-Tick: $TOK_A" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "http://127.0.0.1:$PORT_A/api/$2"; }
B() { curl -s -m 120 -X "$1" -H "X-$PB-Tick: $TOK_B" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "http://127.0.0.1:$PORT_B/api/$2"; }
CJ=$(mktemp); CJ2=$(mktemp); trap 'rm -f "$CJ" "$CJ2"' EXIT
U() { curl -s -m 120 -b "$CJ" -c "$CJ" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "http://127.0.0.1:$PORT_A/api/$2"; }    # solicitante (SO) de A
V() { curl -s -m 60 -b "$CJ2" -c "$CJ2" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "http://127.0.0.1:$PORT_A/api/$2"; }   # consulta (CI) de A
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
campo() { grep -o "\"$1\":\"[^\"]*\"" | head -1 | cut -d'"' -f4; }
S=$RANDOM; M=m/pedidos_red; NS=https://iproy.com.ar/fhir
IA=$(A GET colmena/identidad | campo node_id); IB=$(B GET colmena/identidad | campo node_id)
echo "Nodo A: $IA (:$PORT_A) · Nodo B: $IB (:$PORT_B)"
ROL0=$(B GET colmena/vinculos | js "[v['rol_pim'] for v in d['vinculos'] if v['par']=='$IA'][0]")
[[ -n "$ROL0" ]] || { echo "  ✘ A y B no están vinculados: corré antes pruebas/colmena.sh"; exit 1; }
A POST colmena/vinculos/$IB/via '{"via":"auto"}' >/dev/null

echo "1) Datos: un equipo en B y usuarios en A"
B POST pim/Device "{\"resourceType\":\"Device\",\"identifier\":[{\"system\":\"$NS/sid/activo\",\"value\":\"RED-AUT-$S\"}],\"deviceName\":[{\"name\":\"Autoclave red $S\",\"type\":\"user-friendly-name\"}],\"status\":\"active\"}" >/dev/null
B POST pim/Organization "{\"resourceType\":\"Organization\",\"identifier\":[{\"system\":\"$NS/sid/empresa\",\"value\":\"RED-EMP-$S\"}],\"name\":\"Empresa red $S\",\"type\":[{\"coding\":[{\"system\":\"http://terminology.hl7.org/CodeSystem/organization-type\",\"code\":\"bus\"}]}]}" >/dev/null
CL="Pr-$(head -c 12 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 12)"; US="redso$S"; UC="redci$S"
A POST nucleo/usuario "{\"usuario\":\"$US\",\"nombre\":\"Solicitante red\",\"rol\":\"SO\",\"clave\":\"$CL\"}" >/dev/null
A POST nucleo/usuario "{\"usuario\":\"$UC\",\"nombre\":\"Consulta red\",\"rol\":\"CI\",\"clave\":\"$CL\"}" >/dev/null
U POST sesion "{\"usuario\":\"$US\",\"clave\":\"$CL\"}" >/dev/null; V POST sesion "{\"usuario\":\"$UC\",\"clave\":\"$CL\"}" >/dev/null
ver "A ve a B entre los nodos a los que puede pedir" "$(U GET $M/nodos)" "\"par\":\"$IB\""
ver "buscar en B por nombre (por la colmena)" "$(U GET "$M/buscar?par=$IB&q=Autoclave+red+$S" | js "[(x['tipo'], x['codigo']) for x in d['resultados']]")" "('equipo', "
REF=$(U GET "$M/buscar?par=$IB&q=RED-AUT-$S" | js "d['resultados'][0]['ref']")
ver "buscar en B por código" "$REF" "Device/"
ver "búsqueda de una sola letra → 422" "$(U GET "$M/buscar?par=$IB&q=A")" 'al menos 2'
ver "nodo sin vínculo → 404" "$(U GET "$M/buscar?par=ZZZ&q=algo")" 'No hay vínculo'
ver "el usuario de consulta (CI) no puede pedir" "$(V POST $M/pedidos "{\"par\":\"$IB\",\"activo\":\"$REF\",\"detalle\":\"No debería salir\"}")" 'no tiene la capacidad'

echo "2) Con rol CI en B, el pedido se rechaza y se explica"
B POST colmena/vinculos/$IA/rol '{"rol":"CI"}' >/dev/null
P0=$(U POST $M/pedidos "{\"par\":\"$IB\",\"activo\":\"$REF\",\"activo_nombre\":\"Autoclave red $S\",\"detalle\":\"Pedido sin permiso $S\",\"prioridad\":\"urgent\"}")
ver "queda «rechazado» con el motivo (pedir el rol SO)" "$(printf '%s' "$P0" | js "(d['pedido']['estado'], 'rol SO' in d['pedido']['error'])")" "('rechazado', True)"
ver "sin trabajo escrito → 422" "$(U POST $M/pedidos "{\"par\":\"$IB\",\"activo\":\"$REF\",\"detalle\":\"x\"}")" 'Describí el trabajo'
ver "referencia inválida → 422" "$(U POST $M/pedidos "{\"par\":\"$IB\",\"activo\":\"Task/1\",\"detalle\":\"Pedido inválido\"}")" 'Elegí el equipo'

echo "3) Con rol SO: el pedido crea la orden en B y A la sigue"
B POST colmena/vinculos/$IA/rol '{"rol":"SO"}' >/dev/null
P1=$(U POST $M/pedidos "{\"par\":\"$IB\",\"activo\":\"$REF\",\"activo_nombre\":\"Autoclave red $S\",\"activo_codigo\":\"RED-AUT-$S\",\"detalle\":\"No levanta presión el ciclo de 134 °C ($S)\",\"prioridad\":\"urgent\"}")
PID=$(printf '%s' "$P1" | js "d['pedido']['id']"); TASK=$(printf '%s' "$P1" | js "d['pedido']['task_id']"); NUM=$(printf '%s' "$P1" | js "d['pedido']['numero']")
ver "pedido creado con el número de orden de B" "$(printf '%s' "$P1" | js "(d['pedido']['estado'], d['pedido']['numero'].startswith('$IB-'))")" "('solicitada', True)"
ver "en B existe la orden, con prioridad urgente y el texto del pedido" "$(B GET "pim/Task/$TASK" | js "(d['identifier'][0]['value'], d['priority'], d['focus']['reference'])")" "('$NUM', 'urgent', '$REF')"
ver "en B la solicitud dice de qué nodo y quién la pidió" "$(B GET "pim/ServiceRequest?_count=5&_sort=-_lastUpdated" | js "[e['resource']['code']['text'] for e in d['entry'] if '$S' in e['resource']['code']['text']][0]")" "(Solicitante red): No levanta presión"
ver "Provenance de B registra al nodo A como autor" "$(B GET "pim/Provenance?target=Task/$TASK")" "colmena-$(echo "$IA" | tr A-Z a-z)"
V1=$(B GET "pim/Task/$TASK" | js "d['meta']['versionId']"); ORG=$(B GET "pim/Organization?identifier=RED-EMP-$S" | js "d['entry'][0]['resource']['id']")
T=$(curl -s -m 60 -X POST -H "X-$PB-Tick: $TOK_B" -H 'Content-Type: application/fhir+json' -H "If-Match: W/\"$V1\"" -d "{\"resourceType\":\"Parameters\",\"parameter\":[{\"name\":\"estado\",\"valueCode\":\"asignada\"},{\"name\":\"responsable\",\"valueReference\":{\"reference\":\"Organization/$ORG\"}}]}" "http://127.0.0.1:$PORT_B/api/pim/Task/$TASK/\$transicion")
ver "B asigna la orden a una empresa" "$T" '"asignada"'
ver "A actualiza y ve «asignada»" "$(U POST $M/pedidos/$PID/actualizar | js "(d['pedido']['estado'], d['pedido']['numero'])")" "('asignada', '$NUM')"
ver "la tarea periódica de seguimiento lo revisa" "$(A POST tareas/pedidos_red:seguimiento/ejecutar | js "'revisados' in d['resultado']")$(A GET "$M/pedidos?par=$IB&abiertos=1" | js "any(p['id']==$PID for p in d['pedidos'])")" "TrueTrue"
ver "el usuario de consulta ve los pedidos" "$(V GET "$M/pedidos?par=$IB" | js "len([p for p in d['pedidos'] if p['id']==$PID])")" "1"
ver "diario de A registra el pedido" "$(A GET 'proc/diario?n=80')" 'pedidos_red.pedido'

echo "4) Sin camino: B apagado, el pedido espera en la cola y sale solo"
bash "$B_DIR/$(basename "$(ls "$B_DIR"/*-Control.sh | head -1)")" stop >/dev/null 2>&1
P2=$(U POST $M/pedidos "{\"par\":\"$IB\",\"activo\":\"$REF\",\"activo_nombre\":\"Autoclave red $S\",\"detalle\":\"Pedido con B apagado ($S)\"}"); PID2=$(printf '%s' "$P2" | js "d['pedido']['id']")
E2=$(printf '%s' "$P2" | js "d['pedido']['estado']")
ver "con B apagado el pedido no se pierde: espera en la cola de salida (sin hub) o en el hub" "$([[ "$E2" == en-cola || "$E2" == por-hub ]] && echo si || echo "$E2")" "si"
bash "$B_DIR/$(basename "$(ls "$B_DIR"/*-Control.sh | head -1)")" start --no-abrir >/dev/null 2>&1
A POST colmena/cola/reintentar >/dev/null
for i in $(seq 1 20); do R2=$(U POST $M/pedidos/$PID2/actualizar | js "(d['pedido']['estado'], (d['pedido']['numero'] or '').startswith('$IB-'))"); [[ "$R2" == "('solicitada', True)" ]] && break; B POST colmena/hub/buzon >/dev/null 2>&1; A POST colmena/hub/buzon >/dev/null 2>&1; sleep 3; done
ver "B vuelve: el pedido llega ($E2) y recibe su número" "$R2" "('solicitada', True)"

# deja el rol como estaba y da de baja los usuarios de prueba
B POST colmena/vinculos/$IA/rol "{\"rol\":\"$ROL0\"}" >/dev/null
A PUT "usuarios/$US" '{"activo":0}' >/dev/null; A PUT "usuarios/$UC" '{"activo":0}' >/dev/null
echo; echo "pedidos_red: $OK bien, $MAL mal"; [ "$MAL" -eq 0 ]
