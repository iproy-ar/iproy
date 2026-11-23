#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba del módulo paneles (1.7.0) sobre UN nodo iniciado: señales por el bus, estado de cada tarjeta (normal, fuera de rango,
# en alarma, sin datos), historial resumido, armado de paneles y quién ve cada uno (por rol y por usuario).
#   bash pruebas/paneles.sh <carpeta-nodo>
# Crea sus propios datos ficticios (prefijo PAN / pan) y borra sus paneles al terminar.
set -u
D="$(cd "$1" && pwd)"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
B="http://127.0.0.1:$PORT/api"; T=$(mktemp -d); CJ="$T/cj"
A() { curl -s -m 120 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
U() { curl -s -m 60 -b "$CJ" -c "$CJ" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
no()  { if [[ "$2" != *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
S=$RANDOM; COD="PAN-$S"; rnd() { head -c 30 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"; }
tick() { A POST nucleo/tick >/dev/null; }
limpiar() { for p in $(A GET m/paneles/paneles | python3 -c "import sys,json; print(' '.join(str(p['id']) for p in json.load(sys.stdin)['paneles'] if p['nombre'].startswith('PAN-$S')))" 2>/dev/null); do A POST "m/paneles/paneles/$p/borrar" '{}' >/dev/null; done
  for u in "${UE:-}" "${US:-}"; do [[ -n "$u" ]] && A PUT "usuarios/$u" '{"activo":0}' >/dev/null; done; rm -rf "$T"; }
trap limpiar EXIT
med() { A POST m/telemetria/medicion "{\"activo\":\"$COD\",\"punto\":\"$1\",\"valor\":$2,\"unidad\":\"$3\"}" >/dev/null; tick; tick; tick; tick; tick; }
item() { python3 -c "import sys,json; d=json.load(sys.stdin); p=[x for x in d['paneles'] if x['id']==$1][0]; i=[y for y in p['items'] if y['punto']=='$2'][0]; print($3)" 2>/dev/null; }

echo "1) Señales que llegan por el bus"
A POST pim/Device "{\"resourceType\":\"Device\",\"identifier\":[{\"system\":\"https://iproy.com.ar/fhir/sid/activo\",\"value\":\"$COD\"}],\"deviceName\":[{\"name\":\"Compresor PAN $S\",\"type\":\"user-friendly-name\"}],\"status\":\"active\"}" >/dev/null
DEV=$(A GET "pim/Device?identifier=$COD" | grep -o '"resourceType":"Device","id":"[^"]*"' | head -1 | grep -o '[0-9A-Z]\{26\}')
tick   # las suscripciones nuevas empiezan a leer desde acá
med PRES-LINEA 700 kPa; med PRES-LINEA 720 kPa; med TEMP-ACEITE 61.5 Cel
R=$(A GET m/paneles/puntos)
ver "la señal de presión quedó registrada con su último valor" "$R" "\"activo_id\":\"$DEV\",\"punto\":\"PRES-LINEA\",\"codigo\":\"$COD\",\"nombre\":\"Compresor PAN $S\",\"valor\":720"
ver "y la de temperatura" "$R" '"punto":"TEMP-ACEITE"'

echo "2) Armar un panel"
nuevo() { A POST m/paneles/paneles "$1"; }
ver "sin nombre → 422" "$(nuevo '{"nombre":""}')" 'Nombre del panel'
ver "señal que nunca informó → 422" "$(nuevo "{\"nombre\":\"PAN-$S x\",\"items\":[{\"activo_id\":\"$DEV\",\"punto\":\"NO-EXISTE\"}]}")" 'todavía no envió'
ver "mínimo mayor que máximo → 422" "$(nuevo "{\"nombre\":\"PAN-$S x\",\"items\":[{\"activo_id\":\"$DEV\",\"punto\":\"PRES-LINEA\",\"minimo\":9,\"maximo\":1}]}")" 'no puede ser mayor'
ver "rol desconocido → 422" "$(nuevo "{\"nombre\":\"PAN-$S x\",\"roles\":[\"ZZ\"]}")" 'Rol desconocido'
ver "color inválido → 422" "$(nuevo "{\"nombre\":\"PAN-$S x\",\"color\":\"rojo\"}")" 'Color'
R=$(nuevo "{\"nombre\":\"PAN-$S Sala de máquinas\",\"descripcion\":\"Compresores\",\"color\":\"#00897B\",\"items\":[{\"activo_id\":\"$DEV\",\"punto\":\"PRES-LINEA\",\"titulo\":\"Presión de línea\",\"minimo\":600,\"maximo\":800,\"decimales\":0},{\"activo_id\":\"$DEV\",\"punto\":\"TEMP-ACEITE\",\"titulo\":\"\",\"maximo\":90}]}")
P1=$(printf '%s' "$R" | js "d['panel']['id']"); ver "panel creado con dos señales" "$(printf '%s' "$R" | js "len(d['panel']['items'])")" '2'
[[ "$P1" =~ ^[0-9]+$ ]] || { echo "  ✘ no se pudo crear el panel"; exit 1; }
ver "sin título, usa el nombre del punto" "$R" '"titulo":"TEMP-ACEITE"'
R=$(A GET m/paneles/paneles)
ver "presión en rango → normal" "$(printf '%s' "$R" | item "$P1" PRES-LINEA "i['estado'], i['valor'], i['unidad']")" "ok 720.0 kPa"
ver "curva de 24 h con al menos un punto" "$(printf '%s' "$R" | item "$P1" PRES-LINEA "len(i['chispa']) >= 1")" 'True'
ver "cuenta del panel" "$(printf '%s' "$R" | js "[p['cuenta'] for p in d['paneles'] if p['id']==$P1][0]")" "{'ok': 2, 'fuera': 0, 'alarma': 0, 'sin-datos': 0}"

echo "3) Estados: fuera de rango, alarma y sin datos"
med PRES-LINEA 820 kPa
ver "820 con máximo 800 → fuera de rango" "$(A GET m/paneles/paneles | item "$P1" PRES-LINEA "i['estado']")" 'fuera'
A POST m/alarmas/definiciones "{\"etiqueta\":\"PAN-$S-H\",\"activo_id\":\"$DEV\",\"punto\":\"PRES-LINEA\",\"tipo\":\"alta\",\"limite\":850,\"banda\":10,\"prioridad\":1,\"mensaje\":\"Presión alta\"}" >/dev/null
med PRES-LINEA 900 kPa
R=$(A GET m/paneles/paneles)
ver "900 con alarma en 850 → en alarma" "$(printf '%s' "$R" | item "$P1" PRES-LINEA "i['estado'], i['alarma_etiqueta'], i['alarma_prioridad']")" "alarma PAN-$S-H 1"
ver "resumen del tablero" "$(A GET m/paneles/resumen | js "d['resumen']['alarma'] >= 1")" 'True'
AL=$(A GET m/alarmas/definiciones | python3 -c "import sys,json; print([a['id'] for a in json.load(sys.stdin)['alarmas'] if a['etiqueta']=='PAN-$S-H'][0])")
A POST "m/alarmas/$AL/reconocer" '{"nota":"prueba"}' >/dev/null; tick; tick
ver "reconocida: sigue en alarma mientras dure la condición" "$(A GET m/paneles/paneles | item "$P1" PRES-LINEA "i['estado'], i['alarma']")" 'alarma reconocida'
med PRES-LINEA 700 kPa
ver "vuelve a 700 → normal" "$(A GET m/paneles/paneles | item "$P1" PRES-LINEA "i['estado'], i['alarma']")" 'ok None'
CFG0=$(A GET modulos/paneles/config | python3 -c "import sys,json; print(json.dumps({'config': json.load(sys.stdin)['config']}))")   # se repone al terminar el paso
A PUT modulos/paneles/config "$(printf '%s' "$CFG0" | python3 -c "import sys,json; d=json.load(sys.stdin); d['config']['sin_datos_min']=0; print(json.dumps(d))")" >/dev/null; sleep 61
ver "si deja de informar → sin datos" "$(A GET m/paneles/paneles | item "$P1" TEMP-ACEITE "i['estado']")" 'sin-datos'
A PUT modulos/paneles/config "$CFG0" >/dev/null

echo "4) Historial"
IT=$(A GET m/paneles/paneles | item "$P1" PRES-LINEA "i['id']")
R=$(A GET "m/paneles/serie/$IT?horas=6")
ver "resumen: mínimo, máximo y cantidad de lecturas" "$(printf '%s' "$R" | js "d['resumen']['min'], d['resumen']['max'], d['resumen']['lecturas']")" '700.0 900.0 5'
ver "promedio ponderado por lecturas" "$(printf '%s' "$R" | js "d['resumen']['prom']")" '768.0'
ver "trae el rango normal para dibujarlo" "$(printf '%s' "$R" | js "d['item']['minimo'], d['item']['maximo'], d['item']['unidad']")" '600.0 800.0 kPa'
ver "un año: sigue siendo dibujable (≤ 500 tramos)" "$(A GET "m/paneles/serie/$IT?horas=8760" | js "len(d['puntos']) <= 500 and d['paso_s'] >= 3600")" 'True'
ver "por fechas" "$(A GET "m/paneles/serie/$IT?desde=2001-01-01&hasta=2001-01-31" | js "len(d['puntos']), d['resumen']")" '0 None'
ver "señal inexistente → 404" "$(A GET m/paneles/serie/99999999)" 'No existe'

echo "5) Quién ve cada panel"
UE="panej$S"; US="panso$S"; CL="Pn-$(rnd 16)"
A POST nucleo/usuario "{\"usuario\":\"$UE\",\"nombre\":\"Ejecutor PAN\",\"rol\":\"EJ\",\"organizacion\":\"PAN-ORG-$S\",\"clave\":\"$CL\"}" >/dev/null
A POST nucleo/usuario "{\"usuario\":\"$US\",\"nombre\":\"Solicitante PAN\",\"rol\":\"SO\",\"clave\":\"$CL\"}" >/dev/null
P2=$(nuevo "{\"nombre\":\"PAN-$S Solo gestores\",\"roles\":[\"GM\"],\"items\":[{\"activo_id\":\"$DEV\",\"punto\":\"TEMP-ACEITE\"}]}" | js "d['panel']['id']")
P3=$(nuevo "{\"nombre\":\"PAN-$S Para una persona\",\"roles\":[\"GM\"],\"usuarios\":\"$US\",\"items\":[{\"activo_id\":\"$DEV\",\"punto\":\"TEMP-ACEITE\"}]}" | js "d['panel']['id']")
P4=$(nuevo "{\"nombre\":\"PAN-$S Oculto\",\"activo\":false}" | js "d['panel']['id']")
IT2=$(A GET m/paneles/paneles | item "$P2" TEMP-ACEITE "i['id']")
mios() { python3 -c "import sys,json; print(sorted(p['nombre'].replace('PAN-$S ','') for p in json.load(sys.stdin)['paneles'] if p['nombre'].startswith('PAN-$S')))" 2>/dev/null; }
U POST sesion "{\"usuario\":\"$UE\",\"clave\":\"$CL\"}" >/dev/null
ver "ejecutor: solo el panel abierto a todos" "$(U GET m/paneles/paneles | mios)" "['Sala de máquinas']"
no "ejecutor: no recibe la lista de roles y usuarios de cada panel" "$(U GET m/paneles/paneles)" '"usuarios"'
ver "ejecutor: no pide el historial de un panel que no ve" "$(U GET "m/paneles/serie/$IT2")" 'No existe'
ver "ejecutor: sí el del que ve" "$(U GET "m/paneles/serie/$IT?horas=6")" '"puntos"'
ver "ejecutor: no configura" "$(U POST m/paneles/paneles "{\"nombre\":\"PAN-$S y\"}")$(U GET m/paneles/puntos)" '"ok":false'
no "ejecutor: no configura (nada pasa)" "$(U PUT "m/paneles/paneles/$P1" '{"nombre":"x"}')$(U POST "m/paneles/paneles/$P1/borrar" '{}')" '"ok":true'
rm -f "$CJ"; U POST sesion "{\"usuario\":\"$US\",\"clave\":\"$CL\"}" >/dev/null
ver "solicitante nombrado: ve el abierto y el suyo" "$(U GET m/paneles/paneles | mios)" "['Para una persona', 'Sala de máquinas']"
ver "gestor (lanzador): ve los cuatro, también el oculto" "$(A GET m/paneles/paneles | mios)" "['Oculto', 'Para una persona', 'Sala de máquinas', 'Solo gestores']"

echo "6) Cambios y baja"
R=$(A PUT "m/paneles/paneles/$P1" '{"nombre":"PAN-'$S' Sala de máquinas","descripcion":"Renombrado"}')
ver "cambiar la descripción no toca las señales" "$(printf '%s' "$R" | js "d['panel']['descripcion'], len(d['panel']['items'])")" 'Renombrado 2'
R=$(A PUT "m/paneles/paneles/$P1" "{\"items\":[{\"activo_id\":\"$DEV\",\"punto\":\"TEMP-ACEITE\",\"titulo\":\"Aceite\"}]}")
ver "reemplazar las señales" "$(printf '%s' "$R" | js "[i['titulo'] for i in d['panel']['items']]")" "['Aceite']"
ver "borrar un panel" "$(A POST "m/paneles/paneles/$P4/borrar" '{}')" '"borrado"'
ver "panel inexistente → 404" "$(A PUT "m/paneles/paneles/$P4" '{"nombre":"x"}')" 'No existe'
ver "queda en el diario" "$(A GET 'proc/diario?n=40')" 'panel.borrado'
ver "tarea de depuración" "$(A POST tareas/paneles:depurar/ejecutar)" 'intervalos finos'

echo; echo "paneles: $OK bien, $MAL mal"; [[ $MAL -eq 0 ]]
