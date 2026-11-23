#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba de lo nuevo del núcleo 1.7.0 sobre UN nodo iniciado: permisos propios de un usuario (sumar o quitar funciones respecto del rol)
# y los archivos de la aplicación instalable (manifiesto y service worker).
#   bash pruebas/nucleo17.sh <carpeta-nodo>
# Crea un usuario ficticio (prefijo n17) y lo desactiva al terminar.
set -u
D="$(cd "$1" && pwd)"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
R="http://127.0.0.1:$PORT"; B="$R/api"; T=$(mktemp -d); CJ="$T/cj"
A() { curl -s -m 60 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
U() { curl -s -m 60 -b "$CJ" -c "$CJ" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
no()  { if [[ "$2" != *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
S=$RANDOM; UU="n17so$S"; CL="N7-$(head -c 30 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16)"
trap 'A PUT "usuarios/$UU" "{\"activo\":0,\"cap_extra\":[],\"cap_bloqueadas\":[]}" >/dev/null; rm -rf "$T"' EXIT

echo "1) Permisos propios de un usuario"
A POST nucleo/usuario "{\"usuario\":\"$UU\",\"nombre\":\"Solicitante N17\",\"rol\":\"SO\",\"clave\":\"$CL\"}" >/dev/null
U POST sesion "{\"usuario\":\"$UU\",\"clave\":\"$CL\"}" >/dev/null
caps() { U GET sesion | js "sorted(d['capacidades'])"; }
ver "por su rol, un solicitante no ve alarmas" "$(U GET m/alarmas/activas)" '"ok":false'
ver "por su rol, sí puede solicitar" "$(caps)" "'pim.solicitar'"
ver "capacidad inexistente → 422" "$(A PUT "usuarios/$UU" '{"cap_extra":["no.existe"]}')" 'No existe la capacidad'
ver "no se puede regalar una función reservada al administrador" "$(A PUT "usuarios/$UU" '{"cap_extra":["usuarios.admin"]}')" 'depende del rol'
ver "ni una del Servidor PIM (las decide el rol)" "$(A PUT "usuarios/$UU" '{"cap_extra":["pim.inventario.editar"]}')" 'depende del rol'
ver "sumarle «alarmas.ver»" "$(A PUT "usuarios/$UU" '{"cap_extra":["alarmas.ver"]}')" '"cap_extra":"alarmas.ver"'
ver "ahora ve las alarmas, sin cambiar de rol ni volver a ingresar" "$(U GET m/alarmas/activas)" '"ok":true'
ver "la sesión lo informa (la app muestra la pestaña)" "$(caps)" "'alarmas.ver'"
ver "pero no puede operarlas" "$(U POST m/alarmas/1/reconocer '{}')" '"ok":false'
ver "quitarle algo que su rol tiene" "$(A PUT "usuarios/$UU" '{"cap_bloqueadas":["paneles.ver"]}')" '"cap_bloqueadas":"paneles.ver"'
ver "ya no entra a los paneles" "$(U GET m/paneles/paneles)" '"ok":false'
no "ni figura entre sus capacidades" "$(caps)" "'paneles.ver'"
ver "el bloqueo le gana al permiso extra" "$(A PUT "usuarios/$UU" '{"cap_bloqueadas":["paneles.ver","alarmas.ver"]}' >/dev/null; U GET m/alarmas/activas)" '"ok":false'
ver "queda en el diario firmado" "$(A GET 'proc/diario?n=20')" 'cap_bloqueadas'
A PUT "usuarios/$UU" '{"cap_extra":[],"cap_bloqueadas":[]}' >/dev/null
ver "al limpiar, vuelve a lo de su rol" "$(U GET m/paneles/paneles)$(U GET m/alarmas/activas)" '"ok":true'
ver "…y solo a lo de su rol" "$(U GET m/alarmas/activas)" '"ok":false'
ver "el usuario no se cambia sus propios permisos" "$(U PUT "usuarios/$UU" '{"cap_extra":["alarmas.ver"]}')" '"ok":false'
ver "la lista de usuarios trae los permisos propios" "$(A GET usuarios)" '"cap_extra"'

echo "2) Aplicación instalable"
M=$(curl -s -m 20 "$R/app/manifest.php")
ver "manifiesto: se abre como aplicación" "$M" '"display": "standalone"'; ver "manifiesto: empieza en la app" "$M" '"start_url": "/app/"'
ver "manifiesto: ícono de 192" "$M" '"sizes": "192x192"'; ver "manifiesto: ícono de 512" "$M" '"sizes": "512x512"'
ver "manifiesto: tipo correcto" "$(curl -s -m 20 -o /dev/null -w '%{content_type}' "$R/app/manifest.php")" 'application/manifest+json'
ver "ícono de 512 px" "$(curl -s -m 20 -o "$T/i.png" -w '%{http_code}' "$R/app/icono-512.png"; python3 -c "import struct; b=open('$T/i.png','rb').read(24); print(struct.unpack('>II', b[16:24]))")" '200(512, 512)'
SW=$(curl -s -m 20 "$R/app/sw.js")
ver "service worker servido como JavaScript" "$(curl -s -m 20 -o /dev/null -w '%{content_type}' "$R/app/sw.js")" 'javascript'
ver "el service worker no guarda respuestas de la API" "$SW" "ES_CASCARA"; no "ni menciona el Servidor PIM como guardable" "$SW" "'/pim/"
PG=$(curl -s -m 20 "$R/app/")
ver "la página enlaza el manifiesto" "$PG" 'rel="manifest"'; ver "color de la barra del celular" "$PG" 'name="theme-color"'
ver "la app registra el service worker" "$(curl -s -m 20 "$R/app/app.js")" "serviceWorker.register('sw.js'"

echo "3) Vigía PIM: cada cambio se publica una sola vez"
A POST tareas/pim:vigia/ejecutar >/dev/null
# se atrasa 5 minutos la marca de los activos, escrita en UTC (…Z) como nace en un nodo nuevo: el servidor contesta en hora local
python3 - "$D/data/nucleo/estado/pim_vigia.json" <<'PY'
import json, sys, datetime
f = sys.argv[1]; d = json.load(open(f))
d['Device'] = {'desde': (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(minutes=5)).strftime('%Y-%m-%dT%H:%M:%SZ'), 'vistos': d.get('Device', {}).get('vistos', [])}
json.dump(d, open(f, 'w'), indent=4)
PY
A POST tareas/pim:vigia/ejecutar >/dev/null
A POST pim/Device "{\"resourceType\":\"Device\",\"identifier\":[{\"system\":\"https://iproy.com.ar/fhir/sid/activo\",\"value\":\"N17-$S\"}],\"deviceName\":[{\"name\":\"Equipo N17 $S\",\"type\":\"user-friendly-name\"}],\"status\":\"active\"}" >/dev/null
sleep 1
ver "el alta se publica en el bus" "$(A POST tareas/pim:vigia/ejecutar)" '1 cambios publicados'
ver "y no se vuelve a publicar en la lectura siguiente" "$(A POST tareas/pim:vigia/ejecutar)" '0 cambios publicados'
ver "la marca «desde» avanzó hasta ese cambio (comparada como instante, no como texto)" "$(python3 - "$D/data/nucleo/estado/pim_vigia.json" <<'PY'
import json, sys, datetime
d = json.load(open(sys.argv[1]))['Device']['desde']
t = datetime.datetime.fromisoformat(d.replace('Z', '+00:00'))
print('al-dia' if abs((datetime.datetime.now(datetime.timezone.utc) - t).total_seconds()) < 60 else 'atrasada ' + d)
PY
)" 'al-dia'

echo; echo "nucleo17: $OK bien, $MAL mal"; [[ $MAL -eq 0 ]]
