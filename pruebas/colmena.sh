#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba de integración de la colmena entre DOS nodos iniciados (pueden ser de productos distintos: iProy, Colmen…).
#   bash pruebas/colmena.sh <carpeta-nodo-A> <carpeta-nodo-B> [url-del-hub]
# Cada carpeta es la de un nodo instalado (con data/). Usa el token del worker (solo funciona en el mismo equipo de cada nodo).
# Para dos PC distintas: corré los pasos equivalentes con «<Prod>-Control.sh colmena …» en cada una (ver docs/COLMENA.md).
set -u
A_DIR="$1"; B_DIR="$2"; HUB="${3:-}"
cargar() {  # carga env y token de un nodo cualquiera (detecta el prefijo del producto)
  local d="$1" env; env="$(ls "$d"/data/*.env | grep -v secrets | head -1)"; local P; P="$(basename "$env" .env | tr a-z A-Z)"
  set -a; . "$env"; . "$d/data/secrets.env"; set +a
  eval "echo \"$P \$${P}_PUERTO \$${P}_TICK_TOKEN\""
}
read -r PA PORT_A TOK_A <<< "$(cargar "$A_DIR")"; read -r PB PORT_B TOK_B <<< "$(cargar "$B_DIR")"
A() { curl -s -m 90 -X "$1" -H "X-$PA-Tick: $TOK_A" -H 'Content-Type: application/json' ${3:+-d "$3"} "http://127.0.0.1:$PORT_A/api/$2"; }
B() { curl -s -m 90 -X "$1" -H "X-$PB-Tick: $TOK_B" -H 'Content-Type: application/json' ${3:+-d "$3"} "http://127.0.0.1:$PORT_B/api/$2"; }
campo() { grep -o "\"$1\":\"[^\"]*\"" | head -1 | cut -d'"' -f4; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:300}"; MAL=$((MAL+1)); fi; }
IA=$(A GET colmena/identidad | campo node_id); IB=$(B GET colmena/identidad | campo node_id)
echo "Nodo A: $PA $IA (:$PORT_A) · Nodo B: $PB $IB (:$PORT_B)"
ver "identidades colmena/1" "$(A GET colmena/identidad)$(B GET colmena/identidad)" '"protocolo":"colmena/1"'
B POST pim/Device '{"resourceType":"Device","identifier":[{"system":"https://iproy.com.ar/pim/sid/activo","value":"PRUEBA-COLMENA"}],"deviceName":[{"name":"Activo de prueba de la colmena","type":"user-friendly-name"}],"status":"active"}' >/dev/null
DEV=$(B GET "pim/Device?identifier=PRUEBA-COLMENA" | grep -o '"resourceType":"Device","id":"[^"]*"' | head -1 | grep -o '[0-9A-Z]\{26\}')
echo "1) Vínculo por la intranet"
A POST colmena/vinculos/$IB/revocar >/dev/null 2>&1; B POST colmena/vinculos/$IA/revocar >/dev/null 2>&1
ver "A pide vínculo a B" "$(A POST colmena/vincular "{\"destino\":\"http://127.0.0.1:$PORT_B\"}")" '"estado":"saliente"'
ver "B ve la solicitud entrante" "$(B GET colmena/vinculos)" '"estado":"entrante"'
ver "B acepta (rol CI)" "$(B POST colmena/vinculos/$IA/aceptar '{"rol":"CI"}')" '"estado":"activo"'
ver "A quedó activo" "$(A GET colmena/vinculos)" '"estado":"activo"'
ver "ping A→B" "$(A POST colmena/vinculos/$IB/ping)" '"respuesta":"pong"'
ver "ping B→A" "$(B POST colmena/vinculos/$IA/ping)" '"respuesta":"pong"'
ver "RPC A→PIM de B (lectura)" "$(A POST colmena/rpc "{\"par\":\"$IB\",\"metodo\":\"GET\",\"ruta\":\"Device?identifier=PRUEBA-COLMENA\"}")" 'Activo de prueba de la colmena'
ver "RPC B→PIM de A (lectura)" "$(B POST colmena/rpc "{\"par\":\"$IA\",\"metodo\":\"GET\",\"ruta\":\"metadata\"}")" '"estado":"respondido","http":200'
SR="{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"priority\":\"urgent\",\"subject\":{\"reference\":\"Device/$DEV\"},\"code\":{\"text\":\"Pedido por la colmena\"},\"note\":[{\"text\":\"Prueba de pedido entre nodos\"}]}"
ver "con rol CI, A no puede pedir trabajos en B (403)" "$(A POST colmena/rpc "{\"par\":\"$IB\",\"metodo\":\"POST\",\"ruta\":\"ServiceRequest\",\"cuerpo\":$SR}")" '"http":403'
B POST colmena/vinculos/$IA/rol '{"rol":"SO"}' >/dev/null
R=$(A POST colmena/rpc "{\"par\":\"$IB\",\"metodo\":\"POST\",\"ruta\":\"ServiceRequest\",\"cuerpo\":$SR}")
ver "con rol SO, A pide un trabajo en B y se crea la orden" "$R" '"x-pim-task":"Task/'
TASK=$(printf '%s' "$R" | grep -o '"x-pim-task":"Task/[^"]*"' | cut -d'"' -f4)
ver "Provenance de B registra al nodo A como autor" "$(B GET "pim/Provenance?target=$TASK")" "colmena-$(echo "$IA" | tr A-Z a-z)"
ver "evento remoto A→bus de B" "$(A POST colmena/evento "{\"par\":\"$IB\",\"tema\":\"aviso/prueba\",\"datos\":{\"x\":1}}")" '"ok":true'
ver "B recibió colmena/<a>/aviso/prueba" "$(B GET 'bus/eventos?tema=colmena/%23&n=500')" "colmena/$(echo "$IA" | tr A-Z a-z)/aviso/prueba"
if [[ -n "$HUB" ]]; then
  echo "2) Por el hub de relevo ($HUB)"
  A PUT colmena/config "{\"hub\":{\"url\":\"$HUB\",\"activo\":true}}" >/dev/null; B PUT colmena/config "{\"hub\":{\"url\":\"$HUB\",\"activo\":true}}" >/dev/null
  ver "A se registra en el hub" "$(A POST colmena/hub/registrar)" '"registrado"'
  ver "B se registra en el hub" "$(B POST colmena/hub/registrar)" '"registrado"'
  A POST colmena/vinculos/$IB/via '{"via":"hub"}' >/dev/null; B POST colmena/vinculos/$IA/via '{"via":"hub"}' >/dev/null
  ver "RPC A→B por el hub (asíncrona, B la atiende en su tick)" "$(A POST colmena/rpc "{\"par\":\"$IB\",\"metodo\":\"GET\",\"ruta\":\"Device?identifier=PRUEBA-COLMENA\",\"esperar\":60}")" '"via":"hub"'
  A POST colmena/vinculos/$IB/via '{"via":"auto"}' >/dev/null; B POST colmena/vinculos/$IA/via '{"via":"auto"}' >/dev/null
fi
ver "diario de A íntegro" "$(A POST proc/diario/verificar)" '"ok":true'
ver "diario de B íntegro" "$(B POST proc/diario/verificar)" '"ok":true'
echo; [[ $MAL -eq 0 ]] && echo "✔ $OK comprobaciones correctas" || echo "✘ $MAL fallas, $OK correctas"; exit $((MAL > 0))
