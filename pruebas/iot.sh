#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba de la Ola 4 (IoT) sobre UN nodo iniciado: módulo fuentes 1.0.0 (lectura en solo lectura de un sistema externo por HTTP/JSON,
# sin repetir lecturas ni eventos, historial a pedido) y paneles 2.0.0 (áreas con reglas por perfil y organización, tipos de panel,
# curvas, eventos, informe de energía, paquete). Levanta su propio servidor JSON simulado en 127.0.0.1 y usa datos ficticios (prefijo IOT).
#   bash pruebas/iot.sh <carpeta-nodo>
set -u
D="$(cd "$1" && pwd)"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
B="http://127.0.0.1:$PORT/api"; T=$(mktemp -d); CJ="$T/cj"; S=$RANDOM; MP=$((20000 + S % 20000)); W="$T/www"; mkdir -p "$W"
A() { curl -s -m 300 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+--data-binary "$3"} "$B/$2"; }
U() { curl -s -m 60 -b "$CJ" -c "$CJ" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
no()  { if [[ "$2" != *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
tick() { for _ in $(seq 1 "${1:-4}"); do A POST nucleo/tick >/dev/null; done; }
rnd() { head -c 30 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"; }
E1="IOT-MED-$S"; E2="IOT-EQ1-$S"; E3="IOT-EQ2-$S"; UO="iotok$S"; UN="iotno$S"; CL="Io-$(rnd 16)"; AR="iot-$S"
limpiar() { kill "$SRV" 2>/dev/null
  for f in $(A GET m/fuentes/fuentes | python3 -c "import sys,json; print(' '.join(str(f['id']) for f in json.load(sys.stdin)['fuentes'] if f['nombre'].startswith('IOT-$S')))" 2>/dev/null); do A POST "m/fuentes/fuentes/$f/borrar" '{}' >/dev/null; done
  for p in $(A GET m/paneles/paneles | python3 -c "import sys,json; print(' '.join(str(p['id']) for p in json.load(sys.stdin)['paneles'] if (p.get('clave') or '').startswith('iot$S')))" 2>/dev/null); do A POST "m/paneles/paneles/$p/borrar" '{}' >/dev/null; done
  for a in $(A GET m/paneles/areas | python3 -c "import sys,json; print(' '.join(str(a['id']) for a in json.load(sys.stdin)['areas'] if a['clave'].startswith('iot-$S')))" 2>/dev/null); do A POST "m/paneles/areas/$a/borrar" '{}' >/dev/null; done
  for u in "$UO" "$UN"; do A PUT "usuarios/$u" '{"activo":0}' >/dev/null; done; rm -rf "$T"; }
trap limpiar EXIT

# servidor JSON simulado: un medidor (objeto), equipos (lista), eventos y un historial por variable (con espacio en el nombre)
AH=$(date +%s); python3 - "$W" "$AH" "$S" <<'PY'
import json, sys, os
w, ah, s = sys.argv[1], int(sys.argv[2]), sys.argv[3]
json.dump({'ultimo': {'ts': (ah - 30) * 1000, 'v_l1n': 230.5, 'p_total': 150000, 'fp_total': 0.97, 'frecuencia': 50.01, 'flag_x': 1}}, open(f'{w}/medidor.json', 'w'))
json.dump({'estado': {'ts': ah - 20, 'equipos': [{'c': 'Equipo 1', 'p': [['ai/1', 'Temperatura', 22.5, '°C'], ['av/2', 'Consigna', 23, '°C'], ['ao/1', 'Valvula', 40, '%']]},
  {'c': 'Equipo 2', 'p': [['ai/1', 'Temperatura', 26.1, '°C'], ['av/2', 'Consigna', 23, '°C'], ['ao/1', 'Valvula', 100, '%']]}]}}, open(f'{w}/equipos.json', 'w'))
json.dump({'filas': [{'id': 1, 'cat': 0, 'msg': 'Alta conductividad', 't': (ah - 7200) * 1000, 'rec': (ah - 3600) * 1000}, {'id': 2, 'cat': 0, 'msg': 'Bomba detenida', 't': (ah - 600) * 1000, 'rec': None},
  {'id': 3, 'cat': 3, 'msg': 'Lavado iniciado', 't': (ah - 300) * 1000, 'rec': None}]}, open(f'{w}/eventos.json', 'w'))
os.makedirs(f'{w}/hist', exist_ok=True)
for eq in ('Equipo 1', 'Equipo 2'):
    serie = [[(ah - 86400 * 2 + k * 600) * 1000, 22 + (k % 7) * 0.5 + (3 if eq == 'Equipo 2' else 0)] for k in range(288)]
    json.dump({'series': {'temperatura': serie, 'setpoint': [[t, 23] for t, _ in serie]}}, open(f'{w}/hist/{eq}.json', 'w'))
PY
( cd "$W" && exec python3 -m http.server "$MP" --bind 127.0.0.1 >/dev/null 2>&1 ) & SRV=$!; sleep 1
URL="http://127.0.0.1:$MP"

echo "1) Dispositivos PIM ficticios"
for x in "$E1|Medidor IOT $S" "$E2|Equipo IOT 1 $S" "$E3|Equipo IOT 2 $S"; do A POST pim/Device "{\"resourceType\":\"Device\",\"identifier\":[{\"system\":\"https://iproy.com.ar/fhir/sid/activo\",\"value\":\"${x%%|*}\"}],\"deviceName\":[{\"name\":\"${x#*|}\",\"type\":\"user-friendly-name\"}],\"status\":\"active\"}" >/dev/null; done
tick 2

echo "2) Fuentes: alta, validaciones y prueba sin publicar"
F() { A POST m/fuentes/fuentes "$1"; }
ver "sin dirección http → 422" "$(F "{\"nombre\":\"IOT-$S x\",\"url\":\"ftp://a\",\"reglas\":[]}")" 'http'
ver "tipo de regla desconocido → 422" "$(F "{\"nombre\":\"IOT-$S x\",\"url\":\"$URL/m.json\",\"reglas\":[{\"tipo\":\"raro\"}]}")" 'tipo'
RM="[{\"tipo\":\"objeto\",\"ruta\":\"ultimo\",\"ts\":\"ts\",\"activo\":\"$E1\",\"campos\":\"*\",\"excluir\":[\"flag_x\"],\"unidades\":{\"v_l1n\":\"V\",\"p_total\":\"kW\",\"frecuencia\":\"Hz\"},\"etiquetas\":{\"v_l1n\":\"Tensión L1-N\"},\"escalas\":{\"p_total\":0.001}}]"
R=$(A POST m/fuentes/probar "{\"url\":\"$URL/medidor.json?token=secreto$S\",\"reglas\":$RM}")
ver "probar: lee 4 valores (sin la bandera excluida)" "$R" '"mediciones":4'
ver "probar: escala W → kW" "$R" '"punto":"P-TOTAL","valor":150'
FM=$(F "{\"nombre\":\"IOT-$S medidor\",\"url\":\"$URL/medidor.json?token=secreto$S\",\"cada_s\":60,\"activa\":true,\"reglas\":$RM}" | js "d['fuente']['id']")
ver "la clave de la dirección no se muestra" "$(A GET m/fuentes/fuentes)" 'token=•••'
RL="[{\"tipo\":\"lista\",\"ruta\":\"estado.equipos\",\"activo_de\":\"c\",\"activo_mapa\":{\"Equipo 1\":\"$E2\",\"Equipo 2\":\"$E3\"},\"puntos_ruta\":\"p\",\"punto\":0,\"etiqueta\":1,\"valor\":2,\"unidad\":3,\"ts_raiz\":\"estado.ts\"}]"
HI="{\"url\":\"$URL/hist/{equipo}.json?h={horas}\",\"tramo_horas\":0,\"max_dias\":30,\"variables\":{\"equipo\":[{\"valor\":\"Equipo 1\",\"codigo\":\"$E2\"},{\"valor\":\"Equipo 2\",\"codigo\":\"$E3\"}]},\"reglas\":[{\"tipo\":\"series\",\"ruta\":\"series\",\"activo\":\"{equipo_codigo}\",\"campos\":{\"temperatura\":{\"punto\":\"ai/1\",\"unidad\":\"°C\"},\"setpoint\":{\"punto\":\"av/2\",\"unidad\":\"°C\"}}}]}"
FE=$(F "{\"nombre\":\"IOT-$S equipos\",\"url\":\"$URL/equipos.json\",\"cada_s\":60,\"activa\":true,\"reglas\":$RL,\"historial\":$HI}" | js "d['fuente']['id']")
RE="[{\"tipo\":\"eventos\",\"ruta\":\"filas\",\"activo\":\"$E1\",\"id\":\"id\",\"ts\":\"t\",\"fin\":\"rec\",\"mensaje\":\"msg\",\"categoria\":\"cat\",\"alarma_si\":{\"cat\":\"0\"}}]"
FV=$(F "{\"nombre\":\"IOT-$S eventos\",\"url\":\"$URL/eventos.json\",\"cada_s\":300,\"activa\":true,\"reglas\":$RE}" | js "d['fuente']['id']")
ver "se crearon las tres fuentes" "$FM $FE $FV" ' '

echo "3) Lectura: valores y eventos nuevos, sin repetir"
ver "medidor: 4 valores nuevos" "$(A POST m/fuentes/fuentes/$FM/leer)" '"mediciones":4'
ver "medidor otra vez: 0 (ya leídos)" "$(A POST m/fuentes/fuentes/$FM/leer)" '"mediciones":0'
ver "equipos: 6 valores" "$(A POST m/fuentes/fuentes/$FE/leer)" '"mediciones":6'
ver "eventos: 3 nuevos" "$(A POST m/fuentes/fuentes/$FV/leer)" '"eventos":3'
ver "eventos otra vez: 0" "$(A POST m/fuentes/fuentes/$FV/leer)" '"eventos":0'
python3 -c "import json; d=json.load(open('$W/eventos.json')); d['filas'][1]['rec']=d['filas'][1]['t']+300000; json.dump(d,open('$W/eventos.json','w'))"
ver "un evento terminó: se vuelve a avisar solo ese" "$(A POST m/fuentes/fuentes/$FV/leer)" '"eventos":1'
tick 6
R=$(A GET m/paneles/puntos)
ver "paneles recibió la tensión con su nombre legible" "$R" '"punto":"V-L1N"'
ver "y la etiqueta" "$R" 'Tensión L1-N'

echo "4) Historial a pedido (variable con espacio en la dirección)"
R=$(A POST m/fuentes/fuentes/$FE/historial '{"dias":5}'); ver "2 consultas, 4 señales, 1152 lecturas" "$R" '"llamadas":2,"series":4,"lecturas":1152'
ver "sin errores" "$R" '"errores":[]'
tick 8

echo "5) Áreas y permisos por perfil y organización"
A POST m/widgets/perfiles "{\"id\":\"iotp$S\",\"nombre\":\"IOT perfil $S\",\"widgets\":[]}" >/dev/null
A POST nucleo/usuario "{\"usuario\":\"$UO\",\"nombre\":\"Con permiso\",\"rol\":\"EJ\",\"organizacion\":\"IOT-ORG-$S\",\"clave\":\"$CL\"}" >/dev/null
A POST nucleo/usuario "{\"usuario\":\"$UN\",\"nombre\":\"Sin permiso\",\"rol\":\"EJ\",\"organizacion\":\"OTRA-$S\",\"clave\":\"$CL\"}" >/dev/null
A PUT "m/widgets/usuarios/$UO/perfil" "{\"perfil\":\"iotp$S\"}" >/dev/null; A PUT "m/widgets/usuarios/$UN/perfil" "{\"perfil\":\"iotp$S\"}" >/dev/null
ver "clave inválida → 422" "$(A POST m/paneles/areas '{"clave":"Con Espacios","nombre":"x"}')" 'Clave del área'
ver "rol desconocido en una regla → 422" "$(A POST m/paneles/areas "{\"clave\":\"$AR-x\",\"nombre\":\"x\",\"reglas\":[{\"rol\":\"ZZ\"}]}")" 'Rol desconocido'
AID=$(A POST m/paneles/areas "{\"clave\":\"$AR\",\"nombre\":\"IOT $S\",\"icono\":\"rayo\",\"reglas\":[{\"perfil\":\"iotp$S\",\"organizacion\":\"IOT-ORG-$S\"}]}" | js "d['area']['id']")
ver "área creada" "$AID" ''
ver "clave repetida → 409" "$(A POST m/paneles/areas "{\"clave\":\"$AR\",\"nombre\":\"otra\"}")" 'Ya hay'

echo "6) Paneles de cada tipo"
N() { A POST m/paneles/paneles "$1"; }
ver "energía sin medidor → 422" "$(N "{\"nombre\":\"x\",\"tipo\":\"energia\",\"vista\":{\"curvas\":[]}}")" 'activo'
ver "sinóptico con plantilla desconocida → 422" "$(N "{\"nombre\":\"x\",\"tipo\":\"sinoptico\",\"vista\":{\"activo\":\"$E1\",\"plantilla\":\"nave\"}}")" 'Plantilla'
ver "tipo desconocido → 422" "$(N "{\"nombre\":\"x\",\"tipo\":\"mapa\"}")" 'Tipo de panel'
ver "área inexistente → 422" "$(N "{\"nombre\":\"x\",\"area\":\"no-existe-$S\"}")" 'No existe el área'
VE="{\"activo\":\"$E1\",\"curvas\":[{\"id\":\"v\",\"titulo\":\"Tensión\",\"puntos\":[\"V-L1N\"]}],\"referencias\":{\"V-L1N\":{\"verde\":[202.4,237.6],\"amarillo\":[198,242]}},\"energia\":{\"potencia\":\"P-TOTAL\",\"factor_potencia\":\"FP-TOTAL\"}}"
PE=$(N "{\"clave\":\"iot${S}e\",\"area\":\"$AR\",\"nombre\":\"Energía $S\",\"tipo\":\"energia\",\"vista\":$VE,\"items\":[{\"activo\":\"$E1\",\"punto\":\"V-L1N\",\"titulo\":\"Tensión\",\"minimo\":198,\"maximo\":242}]}" | js "d['panel']['id']")
PQ=$(N "{\"clave\":\"iot${S}q\",\"area\":\"$AR\",\"nombre\":\"Equipos $S\",\"tipo\":\"equipos\",\"vista\":{\"grupos\":[{\"titulo\":\"Sector A\",\"activos\":[\"$E2\",\"$E3\"]}],\"tarjeta\":[\"AI-1\",\"AV-2\"],\"rangos\":{\"AI-1\":[18,25]},\"analisis\":[{\"tipo\":\"desvio\",\"real\":\"AI-1\",\"consigna\":\"AV-2\",\"tolerancia\":1.5}]}}" | js "d['panel']['id']")
PV=$(N "{\"clave\":\"iot${S}v\",\"area\":\"$AR\",\"nombre\":\"Eventos $S\",\"tipo\":\"eventos\",\"vista\":{\"activos\":[\"$E1\"]}}" | js "d['panel']['id']")
PS=$(N "{\"clave\":\"iot${S}s\",\"area\":\"$AR\",\"nombre\":\"Sinóptico $S\",\"tipo\":\"sinoptico\",\"vista\":{\"activo\":\"$E1\",\"plantilla\":\"lista\"}}" | js "d['panel']['id']")
ver "se crearon los cuatro paneles" "$PE $PQ $PV $PS" ' '
R=$(A GET "m/paneles/paneles?area=$AR")
ver "equipos: el equipo 2 queda fuera de rango (26,1 °C)" "$(echo "$R" | js "[p for p in d['paneles'] if p['id']==$PQ][0]['cuenta']")" "'fuera': 1"
ver "eventos: 1 alarma activa (la bomba se dio por terminada)" "$(echo "$R" | js "[p for p in d['paneles'] if p['id']==$PV][0]['alarmas_activas']")" '0'
ver "portada: el área suma sus señales" "$(A GET m/paneles/portada | js "[a for a in d['areas'] if a['clave']=='$AR'][0]['paneles'].__len__()")" '4'

echo "7) Curvas, eventos e informe"
R=$(A GET "m/paneles/curvas/$PQ?p=$E3|AI-1,$E3|AV-2&horas=72")
ver "curvas: dos series con su historial" "$(echo "$R" | js "[len(s['puntos'])>100 for s in d['series']]")" '[True, True]'
ver "curvas: el resumen dice cuándo fue el máximo" "$R" '"max_ts":'
L1=$(echo "$R" | js "d['series'][0]['resumen']['lecturas']"); A POST m/fuentes/fuentes/$FE/historial '{"dias":5}' >/dev/null; tick 8
ver "traer el historial otra vez no duplica lecturas" "$(A GET "m/paneles/curvas/$PQ?p=$E3|AI-1,$E3|AV-2&horas=72" | js "d['series'][0]['resumen']['lecturas']")" "$L1"
ver "una señal que no es del panel → 403" "$(A GET "m/paneles/curvas/$PV?p=$E3|AI-1")" 'no es de este panel'
R=$(A GET "m/paneles/eventos/$PV?horas=24"); ver "eventos: 2 alarmas y 1 evento" "$(echo "$R" | js "d['kpis']")" "'alarmas': 2, 'eventos': 1"
ver "eventos: filtro por texto" "$(A GET "m/paneles/eventos/$PV?horas=24&q=Lavado" | js "d['total']")" '1'
ver "eventos: con duración" "$R" '"minutos":60'
R=$(A GET "m/paneles/informe/$PE?horas=24"); ver "informe: la tensión queda Normal" "$R" '"estado":"verde"'
ver "informe: calcula la energía" "$R" '"kwh":'
ver "informe solo para paneles de energía" "$(A GET "m/paneles/informe/$PQ")" 'paneles de energía'

echo "8) Quién ve qué"
U POST sesion "{\"usuario\":\"$UO\",\"clave\":\"$CL\"}" >/dev/null
ver "con perfil y organización: ve el área" "$(U GET m/paneles/portada)" "\"clave\":\"$AR\""
ver "y sus curvas" "$(U GET "m/paneles/curvas/$PQ?p=$E2|AI-1&horas=24")" '"series"'
no "no recibe las reglas del área" "$(U GET m/paneles/portada)" '"reglas"'
ver "no configura áreas" "$(U GET m/paneles/areas)" 'ok":false'
ver "no ve las fuentes" "$(U GET m/fuentes/fuentes)" 'ok":false'
rm -f "$CJ"; U POST sesion "{\"usuario\":\"$UN\",\"clave\":\"$CL\"}" >/dev/null
no "mismo perfil, otra organización: no ve el área" "$(U GET m/paneles/portada)" "\"clave\":\"$AR\""
ver "ni sus paneles" "$(U GET "m/paneles/paneles?area=$AR")" 'No existe'
ver "ni sus curvas" "$(U GET "m/paneles/curvas/$PQ?p=$E2|AI-1")" 'No existe'
A PUT "m/paneles/areas/$AID" "{\"reglas\":[{\"perfil\":\"iotp$S\",\"organizacion\":\"IOT-ORG-$S\"},{\"usuario\":\"$UN\"}]}" >/dev/null
ver "una regla por usuario le abre el área" "$(U GET m/paneles/portada)" "\"clave\":\"$AR\""
A PUT "m/paneles/areas/$AID" '{"activo":false}' >/dev/null
no "área oculta: no la ve" "$(U GET m/paneles/portada)" "\"clave\":\"$AR\""
A PUT "m/paneles/areas/$AID" '{"activo":true}' >/dev/null

echo "9) Paquetes"
X=$(A GET m/paneles/paquete); ver "exportar paneles: formato y señales por código" "$X" '"formato":"iproy-paneles/1"'
ver "el panel de energía va con su medidor por código" "$X" "\"activo\":\"$E1\",\"punto\":\"V-L1N\""
R=$(A POST m/paneles/paquete "$(echo "$X" | python3 -c "import sys,json; d=json.load(sys.stdin); d['areas']=[a for a in d['areas'] if a['clave']=='$AR']; d['paneles']=[p for p in d['paneles'] if (p['clave'] or '').startswith('iot$S')]; print(json.dumps(d))")")
ver "reimportar actualiza (1 área, 4 paneles)" "$R" '"areas":1,"paneles":4'
ver "formato equivocado → 422" "$(A POST m/paneles/paquete '{"formato":"otro"}')" 'iproy-paneles/1'
ver "exportar fuentes" "$(A GET m/fuentes/exportar)" "IOT-$S medidor"
ver "importar fuentes: actualiza por nombre" "$(A POST m/fuentes/importar "$(A GET m/fuentes/exportar | python3 -c "import sys,json; d=json.load(sys.stdin); d['fuentes']=[f for f in d['fuentes'] if f['nombre'].startswith('IOT-$S')]; print(json.dumps(d))")")" '"nuevas":0,"actualizadas":3'

echo "10) Tareas"
ver "depuración de paneles" "$(A POST tareas/paneles:depurar/ejecutar)" 'intervalos finos'
ver "lectura periódica de fuentes" "$(A POST tareas/fuentes:leer/ejecutar)" 'ok":true'
echo; echo "iot: $OK bien, $MAL mal"; [[ $MAL -eq 0 ]]
