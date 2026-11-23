#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba del plan preventivo por semanas (preventivos 2.0.0) sobre UN nodo iniciado: tareas por equipo, cronograma anual (frecuencias en
# semanas fijas, marcas a mano), copia al año siguiente, una orden por equipo y semana, registro por tarea con fecha real, permisos,
# relevamiento en recorrida (plantillas, envío, rechazo y aprobación que crea el equipo en PIM) e importación desde una hoja.
#   bash pruebas/plan_semanal.sh <carpeta-nodo>
# Usa un año lejano (2031) para el cronograma de prueba y deja las tareas de prueba desactivadas al terminar.
set -u
D="$(cd "$1" && pwd)"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
B="http://127.0.0.1:$PORT/api"; T=$(mktemp -d); CJ="$T/cj"; CJ2="$T/cj2"; trap 'rm -rf "$T"' EXIT
A() { curl -s -m 120 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
AF() { curl -s -m 120 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' --data-binary "@$3" "$B/$2"; }
U() { curl -s -m 60 -b "$CJ" -c "$CJ" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }      # Ejecutor de la empresa asignada
V() { curl -s -m 60 -b "$CJ2" -c "$CJ2" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }    # Ejecutor de otra empresa
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
S=$RANDOM; NS=https://iproy.com.ar/fhir; Y=2031; M=m/preventivos/pmp; rnd() { head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16; }

echo "1) Datos: dos empresas y dos equipos"
org() { A POST pim/Organization "{\"resourceType\":\"Organization\",\"identifier\":[{\"system\":\"$NS/sid/empresa\",\"value\":\"$1\"}],\"name\":\"Empresa $1\",\"type\":[{\"coding\":[{\"system\":\"http://terminology.hl7.org/CodeSystem/organization-type\",\"code\":\"bus\"}]}]}" >/dev/null; }
dev() { A POST pim/Device "{\"resourceType\":\"Device\",\"identifier\":[{\"system\":\"$NS/sid/activo\",\"value\":\"$1\"}],\"deviceName\":[{\"name\":\"$2\",\"type\":\"user-friendly-name\"}],\"status\":\"active\"}" >/dev/null; }
org "PS-UNO-$S"; org "PS-DOS-$S"; dev "PS-GE-$S" "Grupo electrógeno PS $S"; dev "PS-BOM-$S" "Bomba PS $S"

echo "2) Tareas y cronograma anual"
tar() { A POST $M/tareas "{\"activo\":\"$1\",\"sistema\":\"Eléctrico\",\"ubicacion\":\"Edificio PS\",\"parte\":\"$2\",\"preventivo\":\"$3\",\"frecuencia\":\"$4\",\"responsable\":\"PS-UNO-$S\"$5}"; }
R=$(tar "PS-GE-$S" "Baterías" "Mantenimiento de bornes y voltaje" "Mensual" ",\"anio\":$Y,\"semana_inicio\":3"); T1=$(printf '%s' "$R" | js "d['tarea']['id']")
ver "tarea creada con la frecuencia escrita como en la planilla (Mensual → M)" "$(printf '%s' "$R" | js "(d['tarea']['frecuencia'], d['tarea']['activo_nombre'])")" "('M', 'Grupo electrógeno PS $S')"
[[ "$T1" =~ ^[0-9]+$ ]] || { echo "  ✘ no se pudo crear la tarea → ${R:0:300}"; exit 1; }
T2=$(tar "PS-GE-$S" "Alternador" "Limpieza de bobinados y bornes" "T" "" | js "d['tarea']['id']")
T3=$(tar "PS-BOM-$S" "Sello mecánico" "Inspección de goteo" "Q" "" | js "d['tarea']['id']")
ver "frecuencia desconocida → 422" "$(tar "PS-GE-$S" "X" "Y" "Cada tanto" "")" 'frecuencia:'
ver "equipo inexistente → 422" "$(A POST $M/tareas '{"activo":"NO-EXISTE-PS","preventivo":"x","frecuencia":"M"}')" 'No existe el equipo'
CR=$(A GET "$M/cronograma?anio=$Y&q=PS+$S")
ver "el año $Y tiene 53 semanas; mensual desde la 3: 3, 7, 11… (13 veces)" "$(printf '%s' "$CR" | js "(d['semanas'], [t['semanas_marcadas'][:3] + [len(t['semanas_marcadas'])] for t in d['tareas'] if t['id']==$T1])")" "(53, [[3, 7, 11, 13]])"
ver "marcar el trimestral desde la semana 2: 2, 15, 28, 41" "$(A POST $M/tareas/$T2/marcar "{\"anio\":$Y,\"semana_inicio\":2}" | js "d['semanas']")" "[2, 15, 28, 41]"
ver "quincenal desde la 1 en la bomba" "$(A POST $M/tareas/$T3/marcar "{\"anio\":$Y,\"semana_inicio\":1}" | js "d['semanas'][:4]")" "[1, 3, 5, 7]"
ver "semana fuera del año → 422" "$(A POST $M/tareas/$T2/marcar "{\"anio\":$Y,\"semana_inicio\":54}")" '53 semanas'
ver "pintar a mano una semana extra (la 3) en el trimestral" "$(A POST $M/tareas/$T2/semana "{\"anio\":$Y,\"semana\":3}" | js "d['semanas']")" "[2, 3, 15, 28, 41]"
ver "volver a marcar respeta lo puesto a mano" "$(A POST $M/tareas/$T2/marcar "{\"anio\":$Y,\"semana_inicio\":2}" | js "d['semanas']")" "[2, 3, 15, 28, 41]"
ver "despintarla" "$(A POST $M/tareas/$T2/semana "{\"anio\":$Y,\"semana\":3}" | js "d['semanas']")" "[2, 15, 28, 41]"
ver "copiar al año siguiente: arranca en la misma semana" "$(A POST $M/copiar "{\"anio\":$Y}" | js "d['tareas'] >= 3")$(A GET "$M/cronograma?anio=$((Y+1))&q=PS+$S" | js "[t['semanas_marcadas'][:2] for t in d['tareas'] if t['id']==$T1]")" "True[[3, 7]]"
ver "copiar de nuevo no pisa lo que ya tiene el año siguiente" "$(A POST $M/copiar "{\"anio\":$Y}" | js "d['ya_tenian'] >= 3")" "True"

echo "3) Órdenes de la semana: una por equipo (en la semana actual, para poder informar la fecha real)"
read -r YC WC DC HC <<< "$(python3 -c "
import datetime as t; h=t.date.today(); e=t.date(h.year,1,1); w=((h-e).days+e.weekday())//7+1
lun=e-t.timedelta(days=e.weekday())+t.timedelta(days=7*(w-1)); print(h.year, w, max(lun,e), min(lun+t.timedelta(days=6), t.date(h.year,12,31)))")"
A POST $M/tareas/$T1/semana "{\"anio\":$YC,\"semana\":$WC}" >/dev/null; A POST $M/tareas/$T3/semana "{\"anio\":$YC,\"semana\":$WC}" >/dev/null
G=$(A POST $M/semana/generar "{\"anio\":$YC,\"semana\":$WC}")
ver "semana $WC/$YC: los dos equipos de prueba tienen su orden" "$(printf '%s' "$G" | js "(d['equipos'] >= 2, d['errores'])")$(A GET "$M/semana?anio=$YC&semana=$WC" | js "len([e for e in d['equipos'] if e['activo_codigo'] and e['activo_codigo'].endswith('-$S') and e['orden_id']])")" "(True, [])2"
ver "generar otra vez no duplica" "$(A POST $M/semana/generar "{\"anio\":$YC,\"semana\":$WC}" | js "d['creadas']")" "0"
SW=$(A GET "$M/semana?anio=$YC&semana=$WC")
ver "la semana va del $DC al $HC (lunes a domingo, o el borde del año)" "$(printf '%s' "$SW" | js "(d['desde'], d['hasta'])")" "('$DC', '$HC')"
ver "la semana sin año ni número es la actual" "$(A GET "$M/semana" | js "(d['anio'], d['semana'])")" "($YC, $WC)"
OG=$(printf '%s' "$SW" | js "[e['orden_id'] for e in d['equipos'] if e['activo_codigo'] and e['activo_codigo'].endswith('PS-GE-$S')][0]")
ver "el grupo electrógeno: una orden con número PIM y su tarea mensual pendiente" "$(printf '%s' "$SW" | js "[(e['numero'] is not None, [(t['id'], t['resultado']) for t in e['tareas']]) for e in d['equipos'] if e['orden_id']==$OG]")" "[(True, [($T1, 'pendiente')])]"
CL="Ps-$(rnd)"; UE="psej$S"; UO="psot$S"
A POST nucleo/usuario "{\"usuario\":\"$UE\",\"nombre\":\"Ejecutor PS\",\"rol\":\"EJ\",\"organizacion\":\"PS-UNO-$S\",\"clave\":\"$CL\"}" >/dev/null
A POST nucleo/usuario "{\"usuario\":\"$UO\",\"nombre\":\"Otro ejecutor PS\",\"rol\":\"EJ\",\"organizacion\":\"PS-DOS-$S\",\"clave\":\"$CL\"}" >/dev/null
U POST sesion "{\"usuario\":\"$UE\",\"clave\":\"$CL\"}" >/dev/null; V POST sesion "{\"usuario\":\"$UO\",\"clave\":\"$CL\"}" >/dev/null
ver "hecha sin fecha real → 422" "$(U PUT $M/ordenes/$OG "{\"tareas\":[{\"tarea_id\":$T1,\"resultado\":\"hecha\"}]}")" 'fecha real'
ver "fecha real futura → 422" "$(U PUT $M/ordenes/$OG "{\"tareas\":[{\"tarea_id\":$T1,\"resultado\":\"hecha\",\"fecha_real\":\"2099-01-01\"}]}")" 'futura'
ver "fecha real muy anterior a la semana → 422" "$(U PUT $M/ordenes/$OG "{\"tareas\":[{\"tarea_id\":$T1,\"resultado\":\"hecha\",\"fecha_real\":\"2020-01-01\"}]}")" 'muy anterior'
ver "una tarea de otra orden → 422" "$(U PUT $M/ordenes/$OG "{\"tareas\":[{\"tarea_id\":$T3,\"resultado\":\"hecha\",\"fecha_real\":\"$(date +%F)\"}]}")" 'no es de esta orden'
ver "el Ejecutor de otra empresa no registra (403)" "$(V PUT $M/ordenes/$OG "{\"tareas\":[{\"tarea_id\":$T1,\"resultado\":\"no_aplica\"}]}")" 'otra empresa'
ver "el Ejecutor de la empresa asignada la da por hecha con su fecha real" "$(U PUT $M/ordenes/$OG "{\"tareas\":[{\"tarea_id\":$T1,\"resultado\":\"hecha\",\"fecha_real\":\"$(date +%F)\",\"nota\":\"Bornes sulfatados, limpiados\"}]}" | js "(d['cambios'], d['pendientes'])")" "(1, 0)"
ver "queda en la semana con la fecha y quién" "$(A GET "$M/semana?anio=$YC&semana=$WC" | js "[(t['resultado'], t['fecha_real'], t['usuario']) for e in d['equipos'] if e['orden_id']==$OG for t in e['tareas']]")" "[('hecha', '$(date +%F)', '$UE')]"
ver "el tablero de cumplimiento del año la cuenta" "$(A GET "$M/cumplimiento?anio=$YC" | js "[s['hechas'] >= 1 for s in d['semanas'] if s['semana']==$WC]")" "[True]"

echo "3b) Tareas diarias: 7 casillas en la misma orden (2.1.0)"
TD=$(tar "PS-GE-$S" "Tablero" "Lectura de tensión y carga de baterías" "Diaria" "" | js "d['tarea']['id']")
A POST $M/tareas/$TD/semana "{\"anio\":$YC,\"semana\":$WC}" >/dev/null
ver "la diaria entra en la orden ya generada del equipo (no crea otra)" "$(A POST $M/semana/generar "{\"anio\":$YC,\"semana\":$WC}" | js "d['creadas']")$(A GET "$M/semana?anio=$YC&semana=$WC" | js "[len(e['tareas']) for e in d['equipos'] if e['orden_id']==$OG]")" "0[2]"
DS=$(A GET "$M/semana?anio=$YC&semana=$WC" | js "[t['dias'] for e in d['equipos'] if e['orden_id']==$OG for t in e['tareas'] if t['id']==$TD][0]")
read -r NR NP DFUT <<< "$(printf '%s' "$DS" | python3 -c "
import sys,ast,datetime as t; l=ast.literal_eval(sys.stdin.read()); h=str(t.date.today())
print(sum(x['en_rango'] for x in l), sum(x['en_rango'] and x['fecha']<=h for x in l), next((i for i,x in enumerate(l) if x['en_rango'] and x['fecha']>h), -1))")"
ver "la vista trae los 7 días de lunes a domingo, con los que caen dentro del año" "$(printf '%s' "$DS" | python3 -c "import sys,ast; l=ast.literal_eval(sys.stdin.read()); print(len(l), l[0]['fecha'] <= '$DC')")" "7 True"
UNO=$(python3 -c "print(str([True]+[False]*6).lower())")
ver "«hecha» con días sin marcar → 422" "$(U PUT $M/ordenes/$OG "{\"tareas\":[{\"tarea_id\":$TD,\"resultado\":\"hecha\",\"dias\":$UNO}]}")" 'Faltan'
if [ "$DFUT" != "-1" ]; then FUT=$(python3 -c "print(str([i==$DFUT for i in range(7)]).lower())"); ver "no se marca un día que no llegó" "$(U PUT $M/ordenes/$OG "{\"tareas\":[{\"tarea_id\":$TD,\"resultado\":\"pendiente\",\"dias\":$FUT}]}")" 'todavía no llegó'; else ver "no se marca un día que no llegó (hoy es el último día de la semana: nada que probar)" "ok" "ok"; fi
ALL=$(python3 -c "
import datetime as t; lun=t.date.fromisoformat('$DC')-t.timedelta(days=t.date.fromisoformat('$DC').weekday()); print(str([(lun+t.timedelta(days=i))<=t.date.today() for i in range(7)]).lower())")
ESP=$([ "$NP" = "$NR" ] && echo "hecha" || echo "pendiente")
ver "marcar todos los días ya pasados: $NP de $NR → $ESP" "$(U PUT $M/ordenes/$OG "{\"tareas\":[{\"tarea_id\":$TD,\"resultado\":\"pendiente\",\"dias\":$ALL}]}" >/dev/null; A GET "$M/semana?anio=$YC&semana=$WC" | js "[(t['resultado'], sum(x['hecho'] for x in t['dias'])) for e in d['equipos'] if e['orden_id']==$OG for t in e['tareas'] if t['id']==$TD]")" "[('$ESP', $NP)]"
ver "el indicador de la semana cuenta los días de las diarias" "$(A GET "$M/semana?anio=$YC&semana=$WC" | js "d['totales']['dias_hechos'] >= $NP and d['totales']['dias_programados'] >= $NR")" "True"
ver "una tarea no diaria ignora las casillas" "$(U PUT $M/ordenes/$OG "{\"tareas\":[{\"tarea_id\":$T1,\"resultado\":\"hecha\",\"fecha_real\":\"$(date +%F)\",\"nota\":\"Bornes sulfatados, limpiados\",\"dias\":$UNO}]}" | js "d['cambios']")" "0"

echo "3c) Marcar en la grilla las tareas sin frecuencia (2.1.0)"
L1=$(tar "PS-BOM-$S" "Acople" "Revisión de alineación PS" "" "" | js "d['tarea']['id']"); L2=$(tar "PS-BOM-$S" "Base" "Ajuste de bulones PS" "" "" | js "d['tarea']['id']")
ver "filtro «sin frecuencia» y «sin marcar» en $Y" "$(A GET "$M/cronograma?anio=$Y&frecuencia=-&marcas=0&q=PS" | js "sorted(t['id'] for t in d['tareas'] if t['id'] in ($L1,$L2))")" "[$L1, $L2]"
ver "en bloque sin frecuencia: solo esa semana, a mano" "$(A POST $M/tareas/lote "{\"ids\":[$L1,$L2],\"frecuencia\":\"__igual\",\"anio\":$Y,\"semana_inicio\":9}" | js "(d['tareas'], d['marcadas'])")$(A GET "$M/cronograma?anio=$Y&q=alineaci%C3%B3n+PS" | js "[t['semanas_marcadas'] for t in d['tareas'] if t['id']==$L1]")" "(2, 2)[[9]]"
ver "en bloque con frecuencia trimestral desde la 2: 2, 15, 28, 41 (respeta la 9 puesta a mano)" "$(A POST $M/tareas/lote "{\"ids\":[$L1,$L2],\"frecuencia\":\"T\",\"anio\":$Y,\"semana_inicio\":2}" | js "d['frecuencia']")$(A GET "$M/cronograma?anio=$Y&q=bulones+PS" | js "[(t['frecuencia'], t['semanas_marcadas']) for t in d['tareas'] if t['id']==$L2]")" "2[('T', [2, 9, 15, 28, 41])]"
ver "ya no aparecen como «sin frecuencia» ni «sin marcar»" "$(A GET "$M/cronograma?anio=$Y&frecuencia=-&q=PS" | js "[t['id'] for t in d['tareas'] if t['id'] in ($L1,$L2)]")$(A GET "$M/cronograma?anio=$Y&marcas=0&q=PS" | js "[t['id'] for t in d['tareas'] if t['id'] in ($L1,$L2)]")" "[][]"
ver "en bloque sin nada que hacer → 422" "$(A POST $M/tareas/lote "{\"ids\":[$L1],\"frecuencia\":\"__igual\"}")" 'Indicá una frecuencia'
ver "el Ejecutor no marca en bloque (403)" "$(U POST $M/tareas/lote "{\"ids\":[$L1],\"frecuencia\":\"M\"}")" 'no tiene la capacidad'

echo "4) Relevamiento en recorrida"
ver "plantillas de fábrica por tipo de equipo (bomba, UTA, tablero…)" "$(U GET $M/plantillas | js "len(d['plantillas']) >= 8 and any(p['nombre']=='Bomba centrífuga' for p in d['plantillas'])")" "True"
UID1=$(python3 -c "import uuid; print(uuid.uuid4())")
REL="{\"uid\":\"$UID1\",\"datos\":{\"unidad\":{\"codigo\":\"PS-UTA-$S\",\"nombre\":\"UTA quirófano PS $S\",\"sistema\":\"Climatización\",\"ubicacion\":\"Edificio PS\",\"clase\":\"HVAC-QX\",\"responsable\":\"PS-UNO-$S\"},\"tareas\":[{\"parte\":\"Filtros G4\",\"preventivo\":\"Lavado o reemplazo\",\"frecuencia\":\"T\",\"semana_inicio\":5},{\"parte\":\"Serpentinas\",\"preventivo\":\"Bactericida\",\"frecuencia\":\"S\"},{\"parte\":\"\",\"preventivo\":\"\"}]}}"
ver "el operario (Ejecutor) envía lo relevado: queda para revisión" "$(U POST $M/relevamientos "$REL" | js "(d['relevamiento']['estado'], len(d['relevamiento']['datos']['tareas']))")" "('enviado', 2)"
RID=$(A GET "$M/relevamientos?estado=enviado" | js "[r['id'] for r in d['relevamientos'] if r['uid']=='$UID1'][0]")
ver "otro ejecutor no ve el relevamiento ajeno" "$(V GET $M/relevamientos/$RID)" 'otra persona'
ver "el Ejecutor no aprueba (solo el Gestor)" "$(U POST $M/relevamientos/$RID/aprobar '{}')" '"ok":false'
ver "rechazar sin decir qué corregir → 422" "$(A POST $M/relevamientos/$RID/rechazar '{}')" 'qué hay que corregir'
ver "el Gestor lo rechaza con una nota" "$(A POST $M/relevamientos/$RID/rechazar '{"nota":"Falta la frecuencia de la bandeja"}' | js "d['relevamiento']['estado']")" "rechazado"
ver "el operario corrige y reenvía (mismo borrador)" "$(U POST $M/relevamientos "$REL" | js "(d['relevamiento']['id'], d['relevamiento']['estado'])")" "($RID, 'enviado')"
AP=$(A POST $M/relevamientos/$RID/aprobar "{\"anio\":$Y}")
ver "el Gestor aprueba: crea el equipo en PIM y pasa las 2 tareas al plan" "$(printf '%s' "$AP" | js "(d['relevamiento']['estado'], d['relevamiento']['resultado']['activo_creado'], len(d['relevamiento']['resultado']['tareas']))")" "('aprobado', True, 2)"
ver "el equipo nuevo existe en PIM" "$(A GET "pim/Device?identifier=PS-UTA-$S" | js "d['entry'][0]['resource']['deviceName'][0]['name']")" "UTA quirófano PS $S"
ver "la tarea con semana de inicio quedó marcada (trimestral desde la 5)" "$(A GET "$M/cronograma?anio=$Y&q=UTA+quir%C3%B3fano+PS+$S" | js "sorted(tuple(t['semanas_marcadas'][:2]) for t in d['tareas'])")" "[(), (5, 18)]"
ver "aprobado no se vuelve a modificar" "$(U POST $M/relevamientos "$REL")" 'Ya fue aprobado'

echo "5) Importación desde una hoja (columnas de la planilla PMP)"
python3 - "$T/imp.json" "$S" <<'PY'
import json,sys
p,s=sys.argv[1],sys.argv[2]
f=[{'_fila':1,'activo':'PS-BOM-'+s,'sistema':'Agua','parte':'>> RECURSO <<','preventivo':'---'},
   {'_fila':2,'activo':'PS-BOM-'+s,'sistema':'Agua','parte':'Impulsor','preventivo':'Inspección de cavitación','frecuencia':'Semanal','proxima':'06/01/2031'},
   {'_fila':3,'activo':'PS-BOM-'+s,'sistema':'Agua','parte':'Acople','preventivo':'Verificación de alineación','frecuencia':'En temporada'},
   {'_fila':4,'activo':'NO-EXISTE-'+s,'preventivo':'x','frecuencia':'Mensual'}]
json.dump({'filas':f,'simular':False},open(p,'w'))
PY
IM=$(AF POST $M/importar "$T/imp.json")
ver "2 tareas nuevas, el separador se saltea, 1 equipo inexistente, 1 frecuencia sin equivalencia" "$(printf '%s' "$IM" | js "(d['cuenta']['nuevo'], d['cuenta']['sin_referencia'], d['sin_equivalencia'])")" "(2, 1, {'frecuencia': {'En temporada': 1}})"
ver "con próxima ejecución marca el cronograma desde esa semana (06/01/2031 → semana 2)" "$(A GET "$M/cronograma?anio=$Y&q=cavitaci%C3%B3n" | js "[t['semanas_marcadas'][:3] for t in d['tareas'] if t['activo_codigo'] and t['activo_codigo'].endswith('PS-BOM-$S')]")" "[[2, 3, 4]]"
ver "importar de nuevo no duplica" "$(AF POST $M/importar "$T/imp.json" | js "(d['cuenta']['nuevo'], d['cuenta']['igual'])")" "(0, 2)"

echo "6) Desde Configuración › Migración (destino «Plan preventivo por semanas»)"
python3 - "$T/mig.json" "$S" <<'PY'
import json,sys
p,s=sys.argv[1],sys.argv[2]
csv='SUBGRUPO,Codigo,Componente,Preventivo,Frecuencia,Proxima ejecución\nAgua,PS-BOM-%s,Descarga,Control de presión de descarga PS,Quincenal,13/01/2031\n' % s
json.dump({'origen':{'tipo':'csv','texto':csv},'destino':'tareas_pmp','mapa':{'activo':'Codigo','preventivo':'Preventivo','sistema':'SUBGRUPO','parte':'Componente','frecuencia':'Frecuencia','proxima':'Proxima ejecución'},'simular':True,'opciones':{}},open(p,'w'))
PY
ver "el destino aparece en la migración" "$(A GET m/migracion/estado | js "d['destinos']['tareas_pmp']['nombre']")" "Plan preventivo por semanas"
ver "simular no escribe" "$(AF POST m/migracion/cargar "$T/mig.json" | js "(d['simulada'], d['cuenta']['nuevo'], d['pmp']['tareas'])")$(A GET "$M/cronograma?anio=$Y&q=descarga+PS" | js "len(d['tareas'])")" "(True, 1, 1)0"
sed -i 's/"simular": true/"simular": false/' "$T/mig.json"
ver "cargar: 1 tarea quincenal marcada desde la semana 3 de $Y" "$(AF POST m/migracion/cargar "$T/mig.json" | js "(d['cuenta']['nuevo'], d['pmp']['cronogramas_marcados'])")$(A GET "$M/cronograma?anio=$Y&q=descarga+PS" | js "[(t['frecuencia'], t['semanas_marcadas'][:3]) for t in d['tareas']]")" "(1, 1)[('Q', [3, 5, 7])]"
ver "repetir la carga no duplica" "$(AF POST m/migracion/cargar "$T/mig.json" | js "(d['cuenta']['nuevo'], d['cuenta']['igual'])")" "(0, 1)"
ver "hechas a tiempo nunca supera a las hechas de la semana" "$(A GET "$M/semana?anio=$YC&semana=$WC" | js "d['totales']['a_tiempo'] <= d['totales']['hecha']")" "True"

# limpieza: tareas de prueba desactivadas y usuarios dados de baja (el Servidor PIM no borra)
for t in $(A GET "$M/cronograma?anio=$Y&q=PS+$S" | js "' '.join(str(t['id']) for t in d['tareas'])") $(A GET "$M/cronograma?anio=$Y&q=cavitaci%C3%B3n" | js "' '.join(str(t['id']) for t in d['tareas'])") $(A GET "$M/cronograma?anio=$Y&q=descarga+PS" | js "' '.join(str(t['id']) for t in d['tareas'])") $TD $L1 $L2; do A PUT $M/tareas/$t '{"activa":false}' >/dev/null; done
A PUT "usuarios/$UE" '{"activo":0}' >/dev/null; A PUT "usuarios/$UO" '{"activo":0}' >/dev/null
echo; echo "plan_semanal: $OK bien, $MAL mal"; [ "$MAL" -eq 0 ]
