#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba de la COLA DE SALIDA de la colmena (1.6.3) entre DOS nodos iniciados y ya registrados en un hub.
#   bash pruebas/cola.sh <carpeta-nodo-A> <carpeta-nodo-B> <url-del-hub>
# Qué demuestra: con el camino cortado, lo que A le manda a B no se pierde ni da error: queda en cola, sale solo
# cuando el camino vuelve, llega UNA sola vez y en el orden en que se pidió; y una revocación hecha sin conexión
# le llega igual al otro nodo. El corte se simula apuntando el hub de un nodo a un puerto donde no hay nadie
# (no hace falta apagar nada). Al terminar deja el vínculo A–B activo por el hub, con los roles que tenía.
# Tarda unos 3 minutos: espera a que el worker de cada nodo (cada 15 s) haga los reintentos por su cuenta.
set -u
A_DIR="$1"; B_DIR="$2"; HUB="${3:?falta la URL del hub}"; MUERTO="${HUB_MUERTO:-http://127.0.0.1:9}"
cargar() { local d="$1" env; env="$(ls "$d"/data/*.env | grep -v secrets | head -1)"; local P; P="$(basename "$env" .env | tr a-z A-Z)"
  set -a; . "$env"; . "$d/data/secrets.env"; set +a; eval "echo \"$P \$${P}_PUERTO \$${P}_TICK_TOKEN\""; }
read -r PA PORT_A TOK_A <<< "$(cargar "$A_DIR")"; read -r PB PORT_B TOK_B <<< "$(cargar "$B_DIR")"
A() { curl -s -m 90 -X "$1" -H "X-$PA-Tick: $TOK_A" -H 'Content-Type: application/json' ${3:+-d "$3"} "http://127.0.0.1:$PORT_A/api/$2"; }
B() { curl -s -m 90 -X "$1" -H "X-$PB-Tick: $TOK_B" -H 'Content-Type: application/json' ${3:+-d "$3"} "http://127.0.0.1:$PORT_B/api/$2"; }
campo() { grep -o "\"$1\":\"[^\"]*\"" | head -1 | cut -d'"' -f4; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:300}"; MAL=$((MAL+1)); fi; }
# espera hasta $1 segundos a que la salida de un comando contenga un texto
hasta() { local t=$1 que="$2"; shift 2; local i=0 r; while (( i < t )); do r="$("$@")"; [[ "$r" == *"$que"* ]] && { echo "$r"; return 0; }; sleep 3; i=$((i+3)); done; echo "$r"; return 1; }
hub_a() { A PUT colmena/config "{\"hub\":{\"url\":\"$1\",\"activo\":true}}" >/dev/null; }
hub_b() { B PUT colmena/config "{\"hub\":{\"url\":\"$1\",\"activo\":true}}" >/dev/null; }
rol_de() { python3 -c "import sys,json; print(next((v['rol_pim'] for v in json.load(sys.stdin)['vinculos'] if v['par']=='$1'),'CI'))"; }
contar() { python3 -c "import sys,json; print(json.load(sys.stdin).get('rpc',{}).get('respuesta',{}).get('cuerpo',{}).get('total','?'))"; }

IA=$(A GET colmena/identidad | campo node_id); IB=$(B GET colmena/identidad | campo node_id)
echo "Nodo A: $PA $IA (:$PORT_A) · Nodo B: $PB $IB (:$PORT_B) · hub $HUB"
ROL_A_EN_B=$(B GET colmena/vinculos | rol_de "$IA"); ROL_B_EN_A=$(A GET colmena/vinculos | rol_de "$IB")
# 1.7.0 (malla): esta prueba es del camino por el HUB CENTRAL entre dos nodos. Los pone en modo «central» (sin directo ni hubs de
# nodos) mientras dura. Si hay otros nodos vinculados con los dos, pasalos en OTROS="<carpeta> <carpeta>": se los aísla durante
# la prueba; si no, la malla encuentra otro camino por ellos (que es justamente lo que tiene que hacer) y la prueba no mide lo suyo.
modo() { $1 PUT colmena/config "{\"malla\":{\"modo\":\"$2\"}}" >/dev/null; }
otros() { local d P PORT TOK; for d in ${OTROS:-}; do read -r P PORT TOK <<< "$(cargar "$d")"
  curl -s -m 30 -X PUT -H "X-$P-Tick: $TOK" -H 'Content-Type: application/json' -d "{\"malla\":{\"modo\":\"$1\"}}" "http://127.0.0.1:$PORT/api/colmena/config" >/dev/null; done; }
restaurar() { hub_a "$HUB"; hub_b "$HUB"; modo A malla; modo B malla; otros malla; }
trap restaurar EXIT
otros aislado; modo A central; modo B central

echo "0) Preparación: vínculo A–B activo, forzado por el hub, y B deja pedir trabajos a A (rol SO)"
hub_a "$HUB"; hub_b "$HUB"; A POST colmena/hub/registrar >/dev/null; B POST colmena/hub/registrar >/dev/null
if [[ "$(A GET colmena/vinculos)" != *"\"par\":\"$IB\""*'"estado":"activo"'* ]]; then
  A POST colmena/vincular "{\"destino\":\"$IB\"}" >/dev/null; B POST colmena/hub/buzon >/dev/null
  B POST "colmena/vinculos/$IA/aceptar" '{"rol":"SO"}' >/dev/null; A POST colmena/hub/buzon >/dev/null
fi
A POST "colmena/vinculos/$IB/via" '{"via":"hub"}' >/dev/null; B POST "colmena/vinculos/$IA/via" '{"via":"hub"}' >/dev/null
B POST "colmena/vinculos/$IA/rol" '{"rol":"SO"}' >/dev/null
A POST colmena/cola/reintentar >/dev/null
ver "la cola de A empieza vacía" "$(A GET colmena/cola)" '"pendientes":0'
ver "con el camino sano, el RPC responde" "$(A POST colmena/rpc "{\"par\":\"$IB\",\"metodo\":\"GET\",\"ruta\":\"metadata\",\"esperar\":60}")" '"estado":"respondido","http":200'
MARCA="COLA-$(date +%s)"
B POST pim/Device "{\"resourceType\":\"Device\",\"identifier\":[{\"system\":\"https://iproy.com.ar/fhir/sid/activo\",\"value\":\"$MARCA\"}],\"deviceName\":[{\"name\":\"Activo de prueba de la cola\",\"type\":\"user-friendly-name\"}],\"status\":\"active\"}" >/dev/null
DEV=$(B GET "pim/Device?identifier=$MARCA" | grep -o '"resourceType":"Device","id":"[^"]*"' | head -1 | grep -o '[0-9A-Z]\{26\}')
sr() { echo "{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"priority\":\"routine\",\"subject\":{\"reference\":\"Device/$DEV\"},\"code\":{\"text\":\"$1\"},\"note\":[{\"text\":\"$1\"}]}"; }

echo "1) Se corta el camino de A (hub inalcanzable)"
hub_a "$MUERTO"
R1=$(A POST colmena/rpc "{\"par\":\"$IB\",\"metodo\":\"POST\",\"ruta\":\"ServiceRequest\",\"cuerpo\":$(sr "$MARCA primero"),\"esperar\":20}")
ver "un pedido de trabajo no da error: queda «en-cola»" "$R1" '"estado":"en-cola"'
ver "y dice que va por la cola" "$R1" '"via":"cola"'
ID1=$(echo "$R1" | grep -o '"rpc":{"id":"[^"]*"' | cut -d'"' -f6)
R2=$(A POST colmena/rpc "{\"par\":\"$IB\",\"metodo\":\"POST\",\"ruta\":\"ServiceRequest\",\"cuerpo\":$(sr "$MARCA segundo")}")
ID2=$(echo "$R2" | grep -o '"rpc":{"id":"[^"]*"' | cut -d'"' -f6)
ver "un segundo pedido se pone detrás" "$R2" '"estado":"en-cola"'
ver "un evento también espera" "$(A POST colmena/evento "{\"par\":\"$IB\",\"tema\":\"prueba/cola\",\"datos\":{\"marca\":\"$MARCA\"}}")" '"via":"cola"'
ver "la cola muestra los 3 envíos" "$(A GET colmena/cola)" '"pendientes":3'
ver "el ping no se encola: avisa que no hay camino" "$(A POST "colmena/vinculos/$IB/ping")" '"ok":false'
ver "B todavía no recibió nada" "$(B GET "pim/ServiceRequest?subject=Device/$DEV&_summary=count")" '"total":0'
sleep 20
ver "pasan los reintentos y sigue todo en cola, sin perderse" "$(A GET colmena/cola)" '"pendientes":3'
ver "el motivo del último intento queda a la vista" "$(A GET colmena/cola)" '"ultimo_error":"No se pudo entregar al nodo '

echo "2) Vuelve el camino: sale solo, sin que nadie haga nada"
hub_a "$HUB"
ver "la cola de A se vacía sola (worker, hasta 90 s)" "$(hasta 90 '"pendientes":0' A GET colmena/cola)" '"pendientes":0'
ver "el primer pedido obtuvo su respuesta (201)" "$(hasta 90 '"estado":"respondido"' A GET "colmena/rpc/$ID1")" '"estado":"respondido","http":201'
ver "el segundo también" "$(hasta 90 '"estado":"respondido"' A GET "colmena/rpc/$ID2")" '"estado":"respondido","http":201'
ver "B tiene exactamente 2 solicitudes: ninguna perdida, ninguna repetida" "$(B GET "pim/ServiceRequest?subject=Device/$DEV&_summary=count")" '"total":2'
ORDEN=$(B GET "pim/ServiceRequest?subject=Device/$DEV&_sort=_lastUpdated" | grep -o "$MARCA [a-z]*" | awk '!v[$0]++' | tr '\n' ',')
ver "y llegaron en el orden en que se pidieron" "$ORDEN" "$MARCA primero,$MARCA segundo"
ver "el diario de A asienta la entrega diferida" "$(A GET 'proc/diario?accion=colmena.cola_entregado&limite=5')" 'colmena.cola_entregado'
ver "el diario de A sigue íntegro" "$(A POST proc/diario/verificar)" '"ok":true'

echo "3) Revocar sin conexión: el aviso le llega igual al otro nodo"
hub_a "$MUERTO"
R3=$(A POST colmena/rpc "{\"par\":\"$IB\",\"metodo\":\"GET\",\"ruta\":\"metadata\"}"); ID3=$(echo "$R3" | grep -o '"rpc":{"id":"[^"]*"' | cut -d'"' -f6)
ver "un pedido queda en cola" "$R3" '"estado":"en-cola"'
ver "A revoca: el aviso queda en cola" "$(A POST "colmena/vinculos/$IB/revocar")" '"via":"cola"'
ver "el pedido pendiente se cierra como «rechazado» (no se entrega a un vínculo revocado)" "$(A GET "colmena/rpc/$ID3")" '"estado":"rechazado"'
ver "en la cola queda solo el aviso de revocación" "$(A GET colmena/cola)" '"pendientes":1'
ver "B todavía cree que el vínculo está activo" "$(B GET colmena/vinculos)" "\"par\":\"$IA\""
hub_a "$HUB"
ver "al volver el camino, B queda «revocado» sin intervención (hasta 90 s)" "$(hasta 90 '"estado":"revocado"' B GET colmena/vinculos)" '"estado":"revocado"'
ver "y la cola de A queda vacía" "$(hasta 30 '"pendientes":0' A GET colmena/cola)" '"pendientes":0'

echo "4) Se rehace el vínculo como estaba"
A POST colmena/vincular "{\"destino\":\"$IB\"}" >/dev/null; B POST colmena/hub/buzon >/dev/null
B POST "colmena/vinculos/$IA/aceptar" "{\"rol\":\"$ROL_A_EN_B\"}" >/dev/null; A POST colmena/hub/buzon >/dev/null
A POST "colmena/vinculos/$IB/rol" "{\"rol\":\"$ROL_B_EN_A\"}" >/dev/null
A POST "colmena/vinculos/$IB/via" '{"via":"hub"}' >/dev/null; B POST "colmena/vinculos/$IA/via" '{"via":"hub"}' >/dev/null
ver "vínculo A–B activo otra vez" "$(A GET colmena/vinculos)" '"estado":"activo"'
ver "y el RPC vuelve a responder" "$(A POST colmena/rpc "{\"par\":\"$IB\",\"metodo\":\"GET\",\"ruta\":\"metadata\",\"esperar\":60}")" '"estado":"respondido","http":200'

echo "5) Un nodo que revocó contesta que no (antes el otro quedaba esperando en silencio)"
hub_b "$MUERTO"
B POST "colmena/vinculos/$IA/revocar" >/dev/null                       # B revoca sin conexión: A no se entera todavía
R5=$(A POST colmena/rpc "{\"par\":\"$IB\",\"metodo\":\"GET\",\"ruta\":\"metadata\"}"); ID5=$(echo "$R5" | grep -o '"rpc":{"id":"[^"]*"' | cut -d'"' -f6)
ver "A, que no sabe nada, manda un pedido por el hub" "$R5" '"estado":"enviado"'
hub_b "$HUB"
ver "A recibe una respuesta explícita 403, no un silencio" "$(hasta 90 '"estado":"respondido"' A GET "colmena/rpc/$ID5")" '"http":403'
ver "con el motivo" "$(A GET "colmena/rpc/$ID5")" 'vinculo-no-activo'
ver "y A queda con el vínculo «revocado»" "$(hasta 30 '"estado":"revocado"' A GET colmena/vinculos)" '"estado":"revocado"'
A POST colmena/vincular "{\"destino\":\"$IB\"}" >/dev/null; B POST colmena/hub/buzon >/dev/null
B POST "colmena/vinculos/$IA/aceptar" "{\"rol\":\"$ROL_A_EN_B\"}" >/dev/null; A POST colmena/hub/buzon >/dev/null
A POST "colmena/vinculos/$IB/rol" "{\"rol\":\"$ROL_B_EN_A\"}" >/dev/null
A POST "colmena/vinculos/$IB/via" '{"via":"hub"}' >/dev/null; B POST "colmena/vinculos/$IA/via" '{"via":"hub"}' >/dev/null
ver "vínculo rehecho al final" "$(A GET colmena/vinculos)" '"estado":"activo"'
ver "los diarios de A y B siguen íntegros" "$(A POST proc/diario/verificar)$(B POST proc/diario/verificar)" '"ok":true'

echo; echo "Resultado: $OK correctas, $MAL fallidas"; exit $(( MAL > 0 ))
