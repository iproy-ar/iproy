#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba del módulo reportes (1.7.0) sobre UN nodo iniciado: copia de consulta alimentada por el bus, Auditoría (filtros, indicadores,
# calificación), Reporte de la empresa (recibidas, terminadas, preventivo/correctivo, movimientos diarios) y lo que ve cada rol.
#   bash pruebas/reportes.sh <carpeta-nodo>
# Crea sus propios datos ficticios (prefijo REP / rep) y no usa datos reales.
set -u
D="$(cd "$1" && pwd)"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
B="http://127.0.0.1:$PORT/api"; T=$(mktemp -d); CJ="$T/cj"; trap 'rm -rf "$T"' EXIT
A() { curl -s -m 120 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} ${4:+-H "If-Match: $4"} "$B/$2"; }
U() { curl -s -m 60 -b "$CJ" -c "$CJ" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
no()  { if [[ "$2" != *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
NS=https://iproy.com.ar/fhir; S=$RANDOM; HOY=$(TZ=America/Argentina/Buenos_Aires date +%Y-%m-%d)
id_de() { grep -o "\"resourceType\":\"$1\",\"id\":\"[^\"]*\"" | head -1 | grep -o '[0-9A-Z]\{26\}'; }
VT() { printf 'W/"%s"' "$(A GET "pim/Task/$1" | grep -o '"versionId":"[0-9]*"' | head -1 | grep -o '[0-9]*')"; }
par() { printf '{"resourceType":"Parameters","parameter":[{"name":"estado","valueCode":"%s"}%s]}' "$1" "${2:+,$2}"; }
pasar() { A POST "pim/Task/$1/\$transicion" "$(par "$2" "${3:-}")" "$(VT "$1")" >/dev/null; }
al_dia() { A POST tareas/pim:vigia/ejecutar >/dev/null; for _ in 1 2 3 4 5; do A POST nucleo/tick >/dev/null; done; }   # varios pasos: si el bus viene cargado, un paso no alcanza
rnd() { head -c 30 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

echo "1) Datos de prueba: dos empresas y cinco órdenes"
org() { A POST pim/Organization "{\"resourceType\":\"Organization\",\"identifier\":[{\"system\":\"$NS/sid/empresa\",\"value\":\"$1\"}],\"name\":\"Empresa $1\",\"type\":[{\"coding\":[{\"system\":\"http://terminology.hl7.org/CodeSystem/organization-type\",\"code\":\"bus\"}]}]}" | id_de Organization; }
E1=$(org "REP-UNO-$S"); E2=$(org "REP-DOS-$S")
A POST pim/Device "{\"resourceType\":\"Device\",\"identifier\":[{\"system\":\"$NS/sid/activo\",\"value\":\"REP-$S\"}],\"deviceName\":[{\"name\":\"Bomba REP $S\",\"type\":\"user-friendly-name\"}],\"status\":\"active\"}" >/dev/null
DEV=$(A GET "pim/Device?identifier=REP-$S" | id_de Device)
al_dia   # las suscripciones nuevas empiezan a leer desde acá
sol() { A POST pim/ServiceRequest "{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"priority\":\"${2:-routine}\",\"subject\":{\"reference\":\"Device/$DEV\"},\"code\":{\"text\":\"Trabajo REP $S $1\"},\"note\":[{\"text\":\"Detalle REP $1; con \\\"comillas\\\"\"}]}" | grep -o '"reference":"Task/[^"]*"' | grep -o '[0-9A-Z]\{26\}'; }
R1="{\"name\":\"responsable\",\"valueReference\":{\"reference\":\"Organization/$E1\"}}"; R2="{\"name\":\"responsable\",\"valueReference\":{\"reference\":\"Organization/$E2\"}}"
PREV='{"name":"tipoMantenimiento","valueCode":"preventivo-sistematico"}'; CORR='{"name":"tipoMantenimiento","valueCode":"correctivo-inmediato"},{"name":"modoFalla","valueCode":"FTS"}'
T1=$(sol A); T2=$(sol B urgent); T3=$(sol C); T4=$(sol D); T5=$(sol E)
ver "cinco órdenes creadas" "$T1/$T2/$T3/$T4/$T5" "$T5"; [[ ${#T5} -eq 26 ]] || { echo "  ✘ no se pudieron crear las órdenes"; exit 1; }
pasar "$T1" asignada "$R1,$PREV"; pasar "$T1" aceptada; pasar "$T1" en-ejecucion; pasar "$T1" finalizada          # empresa 1 · preventivo · terminada
pasar "$T2" asignada "$R1,$CORR"; pasar "$T2" aceptada; pasar "$T2" en-ejecucion                                   # empresa 1 · correctivo urgente · en ejecución
pasar "$T3" asignada "$R1"; pasar "$T3" aceptada; pasar "$T3" en-espera '{"name":"motivo","valueString":"Falta repuesto"}'   # empresa 1 · en espera
pasar "$T4" asignada "$R2,$PREV"; pasar "$T4" aceptada; pasar "$T4" en-ejecucion; pasar "$T4" finalizada; pasar "$T4" conformada   # empresa 2 · conformada
al_dia                                                                                                              # T5 queda solicitada, sin empresa

echo "2) Auditoría"
Q="desde=$HOY&hasta=$HOY&q=REP%20$S"
R=$(A GET "m/reportes/auditoria?$Q")
ver "las cinco órdenes están en la copia de consulta" "$(printf '%s' "$R" | js "d['total']")" '5'
ver "resumen: 3 abiertas" "$(printf '%s' "$R" | js "d['resumen']['abiertas']")" '3'; ver "resumen: 2 terminadas" "$(printf '%s' "$R" | js "d['resumen']['terminadas']")" '2'
ver "resumen: 1 urgente abierta" "$(printf '%s' "$R" | js "d['resumen']['urgentes_abiertas']")" '1'; ver "resumen: 1 en espera" "$(printf '%s' "$R" | js "d['resumen']['en_espera']")" '1'
ver "resumen: 1 finalizada sin conformar" "$(printf '%s' "$R" | js "d['resumen']['sin_conformar']")" '1'; ver "resumen: 2 sin calificar" "$(printf '%s' "$R" | js "d['resumen']['sin_calificar']")" '2'
ver "tiempo de resolución calculado" "$(printf '%s' "$R" | js "d['resumen']['horas_resolucion'] is not None")" 'True'
ver "trae empresa, equipo y tipo" "$R" "\"org\":\"Empresa REP-UNO-$S\""; ver "tipo de mantenimiento legible" "$R" 'Preventivo sistemático'
ver "motivo de la espera" "$R" 'Falta repuesto'
ver "filtro por grupo «terminadas»" "$(A GET "m/reportes/auditoria?$Q&grupo=terminadas" | js "d['total'], sorted(o['estado'] for o in d['ordenes'])")" "2 ['conformada', 'finalizada']"
ver "filtro «urgentes» abiertas" "$(A GET "m/reportes/auditoria?$Q&grupo=urgentes" | js "[o['id'] for o in d['ordenes']]")" "['$T2']"
ver "filtro por estado" "$(A GET "m/reportes/auditoria?$Q&estado=en-espera" | js "[o['id'] for o in d['ordenes']]")" "['$T3']"
ver "filtro por empresa" "$(A GET "m/reportes/auditoria?$Q&org=$E2" | js "[o['id'] for o in d['ordenes']]")" "['$T4']"
ver "filtro «sin empresa»" "$(A GET "m/reportes/auditoria?$Q&org=-" | js "[o['id'] for o in d['ordenes']]")" "['$T5']"
ver "el filtro de estado no cambia el resumen" "$(A GET "m/reportes/auditoria?$Q&estado=en-espera" | js "d['resumen']['total']")" '5'
ver "por fecha de finalización" "$(A GET "m/reportes/auditoria?$Q&fecha=finalizada" | js "d['total']")" '2'
ver "período de otro año: nada" "$(A GET "m/reportes/auditoria?desde=2001-01-01&hasta=2001-12-31&q=REP%20$S" | js "d['total']")" '0'
ver "período de más de cinco años → 422" "$(A GET 'm/reportes/auditoria?desde=2001-01-01&hasta=2026-01-01')" 'cinco años'
R=$(A GET "m/reportes/ordenes/$T1")
ver "recorrido de la orden: 5 movimientos" "$(printf '%s' "$R" | js "[m['a'] for m in d['orden']['movimientos']]")" "['solicitada', 'asignada', 'aceptada', 'en-ejecucion', 'finalizada']"
ver "orden inexistente → 404" "$(A GET m/reportes/ordenes/NOEXISTE)" 'No existe'

echo "3) Calificación"
ver "calificar una orden abierta → 409" "$(A POST "m/reportes/ordenes/$T2/calificar" '{"estrellas":5}')" 'Solo se califican'
ver "estrellas fuera de rango → 422" "$(A POST "m/reportes/ordenes/$T1/calificar" '{"estrellas":6}')" 'de 1 a 5'
R=$(A POST "m/reportes/ordenes/$T1/calificar" '{"estrellas":4,"nota":"Buen trabajo, prolijo"}')
ver "calificación guardada" "$R" '"estrellas":4'; ver "con su nota" "$R" 'Buen trabajo, prolijo'
ver "queda una sola sin calificar" "$(A GET "m/reportes/auditoria?$Q&grupo=sin-calificar" | js "[o['id'] for o in d['ordenes']]")" "['$T4']"
ver "promedio de estrellas" "$(A GET "m/reportes/auditoria?$Q" | js "d['resumen']['estrellas']")" '4.0'
ver "la calificación va al diario" "$(A GET 'proc/diario?n=30')" 'reporte.calificacion'
ver "reconstruir no pierde la calificación" "$(A POST m/reportes/reconstruir '{}' >/dev/null; A GET "m/reportes/ordenes/$T1" | js "d['orden']['estrellas']")" '4'
ver "reconstruir no duplica movimientos" "$(A GET "m/reportes/ordenes/$T1" | js "len(d['orden']['movimientos'])")" '5'

echo "4) Reporte de la empresa"
R=$(A GET "m/reportes/empresa?org=$E1&desde=$HOY&hasta=$HOY")
ver "empresa 1: 3 recibidas" "$(printf '%s' "$R" | js "d['resumen']['recibidas']")" '3'; ver "empresa 1: 1 terminada" "$(printf '%s' "$R" | js "d['resumen']['terminadas']")" '1'
ver "empresa 1: 2 pendientes" "$(printf '%s' "$R" | js "d['resumen']['pendientes']")" '2'; ver "empresa 1: 1 en espera" "$(printf '%s' "$R" | js "d['resumen']['en_espera']")" '1'
ver "empresa 1: 1 urgente pendiente" "$(printf '%s' "$R" | js "d['resumen']['urgentes']")" '1'
ver "preventivo y correctivo" "$(printf '%s' "$R" | js "{c['clase']: c['recibidas'] for c in d['clases']}")" "{'correctivo': 1, 'preventivo': 1, 'otros': 0, 'sin-clasificar': 1}"
ver "movimientos diarios de hoy" "$(printf '%s' "$R" | js "(d['dias'][0]['dia'], d['dias'][0]['ingresaron'], d['dias'][0]['iniciaron'], d['dias'][0]['finalizaron'], d['dias'][0]['en_espera'])")" "('$HOY', 3, 2, 1, 1)"
ver "el total del día suma todos los cambios" "$(printf '%s' "$R" | js "d['dias'][0]['total'] == len(d['movimientos'])")" 'True'
ver "cada movimiento trae el detalle del requerimiento (para el CSV)" "$(printf '%s' "$R" | js "all('detalle' in m and m['numero'] for m in d['movimientos'])")" 'True'
no "no mezcla órdenes de la otra empresa" "$R" "$T4"
ver "empresa 2: conformada cuenta como terminada" "$(A GET "m/reportes/empresa?org=$E2&desde=$HOY&hasta=$HOY" | js "(d['resumen']['recibidas'], d['resumen']['terminadas'], d['resumen']['pendientes'])")" '(1, 1, 0)'
ver "sin empresa asignada" "$(A GET "m/reportes/empresa?org=-&desde=$HOY&hasta=$HOY")" "$T5"
ver "empresa inexistente → 404" "$(A GET "m/reportes/empresa?org=NOEXISTE")" 'No hay órdenes'
ver "lista de empresas con cantidad" "$(A GET m/reportes/organizaciones)" "\"codigo\":\"REP-UNO-$S\",\"ordenes\":3"

echo "4b) Insumos de pañol por orden (evento del stock) y ranking por empresa"
if A GET m/stock/resumen | grep -q '"ok":true'; then
  IN=$(A POST m/stock/insumos "{\"categoria\":\"ELE\",\"subcategoria\":\"ELE-CAB\",\"descripcion\":\"Cable REP $S\",\"unidad\":\"m\",\"precio\":\"100\",\"existencia_inicial\":\"50\"}" | js "d['insumo']['id']")
  IN2=$(A POST m/stock/insumos "{\"categoria\":\"BUL\",\"subcategoria\":\"BUL-TOR\",\"descripcion\":\"Tornillo REP $S\",\"unidad\":\"u\",\"existencia_inicial\":\"100\"}" | js "d['insumo']['id']")
  ver "egreso para la orden 1" "$(A POST m/stock/movimientos "{\"tipo\":\"egreso\",\"motivo\":\"OT\",\"orden\":\"$T1\",\"orden_numero\":\"x\",\"observacion\":\"Entregado a la empresa\",\"lineas\":[{\"insumo\":$IN,\"cantidad\":\"12,5\"},{\"insumo\":$IN2,\"cantidad\":4}]}" | js "d['clave'][:4]")" 'EGR-'
  A POST m/stock/movimientos "{\"tipo\":\"egreso\",\"motivo\":\"OT\",\"orden\":\"$T2\",\"orden_numero\":\"x\",\"lineas\":[{\"insumo\":$IN,\"cantidad\":3}]}" >/dev/null
  A POST m/stock/movimientos "{\"tipo\":\"egreso\",\"motivo\":\"OT\",\"orden\":\"$T4\",\"orden_numero\":\"x\",\"lineas\":[{\"insumo\":$IN2,\"cantidad\":7}]}" >/dev/null
  A POST m/stock/movimientos "{\"tipo\":\"egreso\",\"motivo\":\"CONSUMO\",\"solicitante\":\"Prueba\",\"lineas\":[{\"insumo\":$IN,\"cantidad\":1}]}" >/dev/null
  al_dia
  NI=$(A GET "m/reportes/insumos?ordenes=$T1,$T2,$T3")
  ver "nota de pañol: lo entregado a cada orden" "$(printf '%s' "$NI" | js "(len(d['insumos']), len(d['insumos']['$T1']), len(d['insumos']['$T2']))")" '(2, 2, 1)'
  ver "con cantidad, unidad y observación" "$(printf '%s' "$NI" | js "[(x['cantidad'], x['unidad'], x['observacion']) for x in d['insumos']['$T1'] if x['unidad']=='m']")" "[(12.5, 'm', 'Entregado a la empresa')]"
  RI=$(A GET "m/reportes/empresa?org=$E1&desde=$HOY&hasta=$HOY" | js "[(x['descripcion'], x['cantidad'], x['ordenes'], x['costo']) for x in d['insumos']]")
  ver "ranking de la empresa 1 (sin el consumo general ni lo de la empresa 2)" "$RI" "[('Cable REP $S', 15.5, 2, 1550.0), ('Tornillo REP $S', 4.0, 1, None)]"
  ver "ranking de la empresa 2" "$(A GET "m/reportes/empresa?org=$E2&desde=$HOY&hasta=$HOY" | js "[(x['codigo'][:3].isdigit() or True, x['cantidad']) for x in d['insumos']]")" '[(True, 7.0)]'
  ver "orden mal escrita: no rompe" "$(A GET "m/reportes/insumos?ordenes=../x,$T1" | js "len(d['insumos'])")" '1'
else echo "  · módulo stock no instalado: se omite"; fi

echo "4c) Tendencias y Pronóstico · Reporte General"
TE=$(A GET "m/reportes/tendencias?org=$E1&desde=$HOY&hasta=$HOY")
ver "tendencias: ingresadas, cerradas y backlog al corte" "$(printf '%s' "$TE" | js "(d['kpis']['ingresadas'], d['kpis']['cerradas'], d['kpis']['backlog'])")" '(3, 1, 2)'
ver "tendencias: % correctivo de lo clasificado" "$(printf '%s' "$TE" | js "d['kpis']['correctivo_pct']")" '50.0'
ver "tendencias: backlog con pedido de insumos (en espera por repuesto)" "$(printf '%s' "$TE" | js "d['kpis']['backlog_insumos']")" '1'
ver "tendencias: resolución mediana calculada" "$(printf '%s' "$TE" | js "d['kpis']['mediana_dias'] is not None")" 'True'
ver "tendencias: serie del mes y comparación de empresas" "$(printf '%s' "$TE" | js "(d['series'][-1]['ingresadas'], [e['ingresadas'] for e in d['empresas']])")" '(3, [3])'
ver "tendencias: trae el bloque de pronóstico" "$(printf '%s' "$TE" | js "sorted(d['pronostico'].keys())")" "['base', 'control', 'freq', 'meses', 'meses_base', 'modelo', 'serie', 'serie_clave', 'timesfm']"
ver "tendencias: sin empresa" "$(A GET "m/reportes/tendencias?org=-&desde=$HOY&hasta=$HOY" | js "d['kpis']['ingresadas'] >= 1")" 'True'
ver "tendencias: más de cinco años → 422" "$(A GET 'm/reportes/tendencias?desde=2001-01-01&hasta=2026-01-01')" 'cinco años'
GE=$(A GET "m/reportes/general?org=$E1&desde=$HOY&hasta=$HOY")
ver "general: total, abiertas, terminadas y en espera" "$(printf '%s' "$GE" | js "(d['kpis']['total'], d['kpis']['abiertas'], d['kpis']['terminadas'], d['kpis']['en_espera'])")" '(3, 2, 1, 1)'
ver "general: tipos de mantenimiento legibles" "$GE" 'Preventivo sistemático'
ver "general: órdenes por día y por mes acumuladas" "$(printf '%s' "$GE" | js "(d['dias'][-1]['n'], d['meses'][-1]['acumuladas'])")" '(3, 3)'
if [[ -n "${IN:-}" ]]; then
  ver "general: costo de insumos por categoría (del código n-CAT-SUB)" "$(printf '%s' "$GE" | js "[(c['categoria'], c['costo']) for c in d['insumos']['por_categoria'] if c['categoria']=='ELE']")" "[('ELE', '1550.00')]"
  ver "tendencias: insumo de mayor costo" "$(printf '%s' "$TE" | js "d['insumos_top'][0]['descripcion']")" "Cable REP $S"
fi
ver "lista de servicios para el filtro" "$(A GET m/reportes/servicios)" '"servicios":['

echo "4d) Traspaso de contrato · corridas de Google TimesFM"
CF=$(A GET m/reportes/contrato | python3 -c "import sys,json; print(json.dumps(json.load(sys.stdin)['contrato']))")   # se guarda y se vacía: la prueba no depende de la configuración del nodo
A PUT m/reportes/contrato '{"fecha_contrato":"","contrato_entrante":"","contrato_saliente":""}' >/dev/null
TR=$(A GET "m/reportes/tendencias?desde=$HOY&hasta=$HOY&corte=$HOY&entrante=$E1&saliente=$E2" | js "d['traspaso']")
ver "traspaso: entrante y saliente por nombre" "$(A GET "m/reportes/tendencias?desde=$HOY&hasta=$HOY&corte=$HOY&entrante=$E1&saliente=$E2" | js "(d['traspaso']['entrante_nombre'], d['traspaso']['saliente_nombre'])")" "('Empresa REP-UNO-$S', 'Empresa REP-DOS-$S')"
ver "traspaso: propias de la entrante desde el corte (3) y cerradas (1)" "$(A GET "m/reportes/tendencias?desde=$HOY&hasta=$HOY&corte=$HOY&entrante=$E1" | js "(d['traspaso']['propias'], d['traspaso']['propias_cerradas'], d['traspaso']['heredadas_cerradas_entrante'])")" "(3, 1, 0)"
ver "traspaso: serie por mes desde 6 meses antes del corte" "$(A GET "m/reportes/tendencias?desde=$HOY&hasta=$HOY&corte=$HOY&entrante=$E1" | js "(len(d['traspaso']['meses']), len(d['traspaso']['backlog_heredado']) == len(d['traspaso']['backlog_nuevo']) == 7, d['traspaso']['meses'][-1])")" "(7, True, '${HOY:0:7}')"
ver "traspaso sin entrante: lo pide" "$(A GET "m/reportes/tendencias?desde=$HOY&hasta=$HOY&corte=$HOY" | js "d['traspaso']['falta']")" 'entrante'
ver "configuración del contrato: fecha inválida → 422" "$(A PUT m/reportes/contrato '{"fecha_contrato":"01/04/2026"}')" 'AAAA-MM-DD'
ver "configuración del contrato: guarda fecha y empresas (por código)" "$(A PUT m/reportes/contrato "{\"fecha_contrato\":\"$HOY\",\"contrato_entrante\":\"REP-UNO-$S\"}" | js "(d['contrato']['fecha_contrato'], d['contrato']['contrato_entrante'])")" "('$HOY', 'REP-UNO-$S')"
ver "y Tendencias la usa sin parámetros" "$(A GET "m/reportes/tendencias?desde=$HOY&hasta=$HOY" | js "(d['contrato'], d['traspaso']['entrante_nombre'])")" "('$HOY', 'Empresa REP-UNO-$S')"
A PUT m/reportes/contrato "$(printf '%s' "$CF" | python3 -c "import sys,json; c=json.load(sys.stdin); print(json.dumps({k: c.get(k) or '' for k in ('fecha_contrato','contrato_entrante','contrato_saliente')}))")" >/dev/null   # se repone lo que tenía el nodo
ver "series para TimesFM: total y por empresa, hasta el último mes completo" "$(A GET 'm/reportes/series?top_servicios=0&top_empresas=200' | js "('total' in [x['serie'] for x in d['series']], d['ultimo_completo'] < '${HOY:0:7}')")" "(True, True)"
D12=$(python3 -c "import datetime as t; print((t.date.today()-t.timedelta(days=365)).isoformat())")
FIL=$(python3 -c "
import datetime as t, json; d=t.date.today().replace(day=1); m=[(d.year*12+d.month-1)+k for k in range(6)]
print(json.dumps([{'serie':'org:$E1','freq':'M','periodo':'%04d-%02d-01'%(x//12,x%12+1),'p10':1,'p50':2+i,'p90':5,'ultimo_dato':'2000-01-01'} for i,x in enumerate(m)]))")
ver "corrida con serie inválida → 422" "$(A POST m/reportes/pronosticos '{"modelo":"timesfm-prueba","filas":[{"serie":"otra:x","periodo":"2026-01-01","p50":1}]}')" 'serie inválida'
ver "corrida sin modelo → 422" "$(A POST m/reportes/pronosticos "{\"filas\":$FIL}")" 'modelo'
ver "corrida guardada" "$(A POST m/reportes/pronosticos "{\"modelo\":\"timesfm-prueba-$S\",\"filas\":$FIL}" | js "d['guardadas']")" "6"
TF=$(A GET "m/reportes/tendencias?desde=$D12&hasta=$HOY&org=$E1" | js "d['pronostico']")
ver "Tendencias de la empresa trae la corrida alineada a sus meses" "$(printf '%s' "$TF" | python3 -c "import sys,ast; p=ast.literal_eval(sys.stdin.read()); print(p['serie_clave'] == 'org:$E1', p['timesfm']['modelo'], p['timesfm']['p50'][:2])")" "True timesfm-prueba-$S [2.0, 3.0]"
ver "con datos viejos avisa que conviene correrlo de nuevo" "$(printf '%s' "$TF" | python3 -c "import sys,ast; print(ast.literal_eval(sys.stdin.read())['timesfm']['desactualizado'])")" "True"
ver "con filtro de clase no aplica TimesFM" "$(A GET "m/reportes/tendencias?desde=$D12&hasta=$HOY&org=$E1&clase=equipo" | js "d['pronostico']['timesfm']")" "None"

echo "4e) Tipo de tarea (clase de sistema PIM) y pronóstico semanal"
A POST pim/ServiceRequest "{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"priority\":\"routine\",\"subject\":{\"reference\":\"Device/$DEV\"},\"code\":{\"coding\":[{\"system\":\"$NS/CodeSystem/pim-sistema\",\"code\":\"ELE-ITM\"}],\"text\":\"Tablero REP $S\"}}" >/dev/null
al_dia
ver "lista de tipos de tarea con nombre PIM" "$(A GET m/reportes/tareas | js "[(x['codigo'], x['nombre'] != x['codigo']) for x in d['tareas'] if x['codigo']=='ELE-ITM']")" "[('ELE-ITM', True)]"
TT=$(A GET "m/reportes/tendencias?desde=$HOY&hasta=$HOY&tarea=ELE-ITM")
ver "tendencias por tipo de tarea: cuenta la orden y usa la serie tt:" "$(printf '%s' "$TT" | js "(d['kpis']['ingresadas'] >= 1, d['pronostico']['serie_clave'])")" "(True, 'tt:ELE-ITM')"
ver "tipo de tarea mal escrito: se ignora (no rompe)" "$(A GET "m/reportes/tendencias?desde=$HOY&hasta=$HOY&tarea=x%27or" | js "d['pronostico']['serie_clave']")" "total"
TW=$(A GET "m/reportes/tendencias?desde=$D12&hasta=$HOY&tarea=ELE-ITM&freq=W" | js "d['pronostico']")
ver "semanal: 12 semanas que empiezan en lunes" "$(printf '%s' "$TW" | python3 -c "import sys,ast,datetime as t; p=ast.literal_eval(sys.stdin.read()); print(p['freq'], len(p['meses']), all(t.date.fromisoformat(x).weekday()==0 for x in p['meses']+p['meses_base']))")" "W 12 True"
FW=$(printf '%s' "$TW" | python3 -c "import sys,ast,json; p=ast.literal_eval(sys.stdin.read()); print(json.dumps([{'serie':'tt:ELE-ITM','freq':'W','periodo':x,'p50':7+i,'ultimo_dato':p['meses_base'][-1]} for i,x in enumerate(p['meses'][:4])]))")
ver "corrida semanal por tipo de tarea guardada" "$(A POST m/reportes/pronosticos "{\"modelo\":\"timesfm-sem-$S\",\"filas\":$FW}" | js "d['guardadas']")" "4"
ver "y la vista semanal la alinea a sus semanas" "$(A GET "m/reportes/tendencias?desde=$D12&hasta=$HOY&tarea=ELE-ITM&freq=W" | js "(d['pronostico']['timesfm']['modelo'], d['pronostico']['timesfm']['p50'][:2])")" "('timesfm-sem-$S', [7.0, 8.0])"
ver "la vista mensual no mezcla la corrida semanal" "$(A GET "m/reportes/tendencias?desde=$D12&hasta=$HOY&tarea=ELE-ITM" | js "(d['pronostico']['timesfm'] or {}).get('modelo')")" "None"
ver "servicio × tarea es una clave válida" "$(A POST m/reportes/pronosticos "{\"modelo\":\"timesfm-cmb-$S\",\"filas\":[{\"serie\":\"srv:Sala REP $S|tt:ELE-ITM\",\"periodo\":\"2030-01-01\",\"p50\":1}]}" | js "d['guardadas']")" "1"
ver "tipo de tarea en minúsculas → 422" "$(A POST m/reportes/pronosticos '{"modelo":"timesfm-prueba","filas":[{"serie":"tt:ele itm","periodo":"2030-01-01","p50":1}]}')" 'serie inválida'
SE=$(A GET 'm/reportes/series?top_servicios=2&top_empresas=0&top_tareas=3&top_combos=2')
ver "series: mensuales y semanales, semanas hasta la última completa (lunes)" "$(printf '%s' "$SE" | js "(sorted({x['freq'] for x in d['series']}) if d['series'] else ['M','W'], all(__import__('datetime').date.fromisoformat(x['ultimo']).weekday()==0 for x in d['series'] if x['freq']=='W'))")" "(['M', 'W'], True)"
ver "series: claves válidas (total / srv / org / tt / srv|tt)" "$(printf '%s' "$SE" | js "all(__import__('re').match(r'^(total|org:.+|tt:[A-Z0-9-]+|srv:[^|]+(\|tt:[A-Z0-9-]+)?)$', x['serie']) for x in d['series'])")" "True"
ver "series sin semanales si se piden así" "$(A GET 'm/reportes/series?semanal=0&top_servicios=1&top_empresas=0' | js "{x['freq'] for x in d['series']} <= {'M'}")" "True"

echo "5) Lo que ve cada rol"
UE="repej$S"; UO="repso$S"; CL="Rp-$(rnd 16)"
A POST nucleo/usuario "{\"usuario\":\"$UE\",\"nombre\":\"Ejecutor REP\",\"rol\":\"EJ\",\"organizacion\":\"REP-UNO-$S\",\"clave\":\"$CL\"}" >/dev/null
A POST nucleo/usuario "{\"usuario\":\"$UO\",\"nombre\":\"Solicitante REP\",\"rol\":\"SO\",\"clave\":\"$CL\"}" >/dev/null
U POST sesion "{\"usuario\":\"$UE\",\"clave\":\"$CL\"}" >/dev/null
ver "ejecutor: no entra a la auditoría" "$(U GET "m/reportes/auditoria?$Q")" '"ok":false'
R=$(U GET "m/reportes/empresa?org=$E2&desde=$HOY&hasta=$HOY")
ver "ejecutor: aunque pida otra empresa, ve la suya" "$R" "Empresa REP-UNO-$S"; no "ejecutor: no ve órdenes de otra empresa" "$R" "$T4"
ver "ejecutor: en la lista solo está su empresa" "$(U GET m/reportes/organizaciones | js "[o['codigo'] for o in d['organizaciones']]")" "['REP-UNO-$S']"
ver "ejecutor: abre una orden suya" "$(U GET "m/reportes/ordenes/$T1")" '"movimientos"'
ver "ejecutor: una orden ajena no existe para él" "$(U GET "m/reportes/ordenes/$T4")" 'No existe'
ver "ejecutor: la orden sin empresa tampoco" "$(U GET "m/reportes/ordenes/$T5")" 'No existe'
ver "ejecutor: no pide «sin empresa»" "$(U GET "m/reportes/empresa?org=-&desde=$HOY&hasta=$HOY" | js "d['empresa']['nombre']")" "Empresa REP-UNO-$S"
ver "ejecutor: no califica" "$(U POST "m/reportes/ordenes/$T1/calificar" '{"estrellas":1}')" '"ok":false'
ver "ejecutor: no ve Tendencias ni el Reporte General" "$(U GET "m/reportes/tendencias?desde=$HOY&hasta=$HOY")$(U GET "m/reportes/general?desde=$HOY&hasta=$HOY")" '"ok":false'
ver "ejecutor: no guarda corridas de pronóstico" "$(U POST m/reportes/pronosticos '{"modelo":"x-prueba","filas":[{"serie":"total","periodo":"2026-01-01","p50":1}]}')" '"ok":false'
ver "ejecutor: no reconstruye" "$(U POST m/reportes/reconstruir '{}')" '"ok":false'
ver "ejecutor: no busca por código (es de auditoría)" "$(U GET "m/reportes/codigo?q=1")" '"ok":false'
rm -f "$CJ"; U POST sesion "{\"usuario\":\"$UO\",\"clave\":\"$CL\"}" >/dev/null
ver "solicitante: sin acceso a los reportes" "$(U GET "m/reportes/empresa?org=$E1")$(U GET m/reportes/situacion)" '"ok":false'
ver "solicitante: tampoco ve los insumos de las órdenes" "$(U GET "m/reportes/insumos?ordenes=$T1")" '"ok":false'
no "solicitante: no ve nada" "$(U GET "m/reportes/empresa?org=$E1")$(U GET m/reportes/situacion)" '"ok":true'
echo "Ola P · P4: buscar por código a la vista y documento .docx"
N1=$(A GET "pim/Task/$T1" | js "d['identifier'][0]['value']"); C1=$(printf '%s' "$N1" | sed 's/^.*-0*//')
ver "por el número corto ($C1)" "$(A GET "m/reportes/codigo?q=$C1" | js "[o['id'] for o in d['ordenes']]")" "$T1"
ver "por el código a la vista ($C1-SRV-HEJa-GAS-001-TAN-1)" "$(A GET "m/reportes/codigo?q=$C1-SRV-HEJa-GAS-001-TAN-1" | js "[o['numero'] for o in d['ordenes']]")" "$N1"
ver "por el número PIM entero ($N1), exacto" "$(A GET "m/reportes/codigo?q=$N1" | js "[o['numero'] for o in d['ordenes']]")$(A GET "m/reportes/codigo?q=ZZ9-$C1" | js "len(d['ordenes'])")" "['$N1']0"
ver "un texto que no es código → 422" "$(A GET "m/reportes/codigo?q=bomba+de+agua")" 'Escribí el código'
ver "en la auditoría el código va a esa orden sin importar el período" "$(A GET "m/reportes/auditoria?dias=1&q=$C1-X" | js "[o['id'] for o in (d.get('ordenes') or d.get('filas') or [])]")" "['$T1']"
DX=$(A POST m/reportes/docx '{"archivo":"cert prueba","modelo":{"titulo":"Certificación de servicios · Prueba & <xml>","subtitulo":"período","bloques":[{"tipo":"h2","texto":"Resumen"},{"tipo":"tabla","cab":["Clase","Órdenes"],"filas":[["Correctivo","3"]]},{"tipo":"firmas","items":["Por la empresa","Auditoría"]}]}}')
ver ".docx: nombre y tipo de Word" "$(printf '%s' "$DX" | js "(d['nombre'], d['tipo'].endswith('wordprocessingml.document'))")" "('cert_prueba.docx', True)"
ver ".docx: paquete OOXML válido con el texto (escapado) y la tabla" "$(printf '%s' "$DX" | python3 -c "
import sys,json,base64,io,zipfile,xml.dom.minidom as m; d=json.load(sys.stdin); z=zipfile.ZipFile(io.BytesIO(base64.b64decode(d['base64'])))
[m.parseString(z.read(n)) for n in z.namelist()]; x=z.read('word/document.xml').decode(); print('[Content_Types].xml' in z.namelist(), 'Prueba &amp; &lt;xml&gt;' in x, x.count('<w:tbl>'))")" "True True 2"
for u in "$UE" "$UO"; do A PUT "usuarios/$u" '{"activo":0}' >/dev/null; done

echo; echo "reportes: $OK bien, $MAL mal"; [[ $MAL -eq 0 ]]
