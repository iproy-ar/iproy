#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba del Inventario (pim 1.7.0) sobre UN nodo iniciado: planilla de ejemplo, importar (.xlsx, .csv, texto pegado) con revisión previa,
# exportar y volver a importar, alta y edición desde la pantalla, cambio de código con órdenes y tareas preventivas que lo siguen,
# sacar de circulación sin borrar (no recibe pedidos nuevos, se pausa su preventivo) y volver a ponerlo en servicio, ubicaciones en árbol
# y el nombre del establecimiento.
#   bash pruebas/inventario.sh <carpeta-nodo>
# Crea dos usuarios ficticios (los desactiva al terminar) y activos y ubicaciones con un sufijo al azar; los activos de prueba quedan «cargados por error».
set -u
D="$(cd "$1" && pwd)"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
B="http://127.0.0.1:$PORT/api"; T=$(mktemp -d)
A() { curl -s -m 120 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
S() { local cj="$T/$1"; shift; curl -s -m 180 -b "$cj" -c "$cj" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
SF() { local cj="$T/$1"; shift; curl -s -m 180 -b "$cj" -c "$cj" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' --data-binary "@$3" "$B/$2"; }   # cuerpo desde archivo
SP() { local cj="$T/$1"; shift; curl -s -m 60 -D "$T/h" -b "$cj" -c "$cj" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/fhir+json' ${3:+-d "$3"} "$B/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:500}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
esperar() { local i; for i in $(seq 1 30); do local r; r="$(eval "$1")"; [[ "$r" == *"$2"* ]] && { echo "$r"; return; }; sleep 3; done; eval "$1"; }   # hasta 90 s (vigía cada 20 s)
R=$RANDOM; GM="invgm$R"; SO="invso$R"; CL="I1-$(head -c 30 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16)"; M=m/pim
trap 'for u in $GM $SO; do A PUT "usuarios/$u" "{\"activo\":0}" >/dev/null; done; rm -rf "$T"' EXIT
A POST nucleo/usuario "{\"usuario\":\"$GM\",\"nombre\":\"Gestor Inv\",\"rol\":\"GM\",\"clave\":\"$CL\"}" >/dev/null
A POST nucleo/usuario "{\"usuario\":\"$SO\",\"nombre\":\"Solicitante Inv\",\"rol\":\"SO\",\"clave\":\"$CL\"}" >/dev/null
S gm POST sesion "{\"usuario\":\"$GM\",\"clave\":\"$CL\"}" >/dev/null; S so POST sesion "{\"usuario\":\"$SO\",\"clave\":\"$CL\"}" >/dev/null
inv() { S gm GET $M/inventario > "$T/inv.json"; }
act() { inv; python3 - "$T/inv.json" "$1" "$2" <<'EOF'
import json,sys
d=json.load(open(sys.argv[1])); a=next((x for x in d['activos'] if x['codigo']==sys.argv[2]),None)
print('NO' if a is None else eval(sys.argv[3],{'a':a,'d':d}))
EOF
}
ub() { inv; python3 -c "import json,sys; d=json.load(open('$T/inv.json')); u=next((x for x in d['ubicaciones'] if x['codigo']=='$1'),None); print('NO' if u is None else eval(\"$2\",{'u':u}))"; }

echo "1) Establecimiento y planilla de ejemplo"
E0="$(S gm GET $M/inventario | js "d['establecimiento']")"
ver "el inventario responde con el establecimiento" "$E0" "'sugerido'"
if [[ "$E0" == *"'nombre': None"* ]]; then ver "sin nombre escrito se sugiere el del nodo, no «Organismo (configurar…)»" "$E0" "$(cat "$D/data/node.json" | js "d.get('nombre','')")"; fi
ver "nadie ve «Organismo (configurar…)» en las organizaciones" "$(S gm GET 'pim/Organization?_count=100')" '"resourceType":"Bundle"'
[[ "$(S gm GET 'pim/Organization?_count=100')" == *'Organismo (configurar'* ]] && { echo "  ✘ todavía aparece «Organismo (configurar…)»"; MAL=$((MAL+1)); }
S so GET "$M/inventario/plantilla?formato=xlsx" | js "d['base64']" | base64 -d > "$T/ej.xlsx"
ver "planilla de ejemplo .xlsx con sus cuatro hojas" "$(python3 -c "import openpyxl; w=openpyxl.load_workbook('$T/ej.xlsx'); print(w.sheetnames, [c.value for c in w['Activos'][1]][:3], w['Activos'].max_row)")" "['Instrucciones', 'Ubicaciones', 'Activos', 'Listas'] ['Código', 'Nombre', 'Clase'] 5"
ver "y la hoja Listas trae los sistemas del Anexo B" "$(python3 -c "import openpyxl; w=openpyxl.load_workbook('$T/ej.xlsx'); print([r[1] for r in w['Listas'].iter_rows(values_only=True) if r[0]=='Sistema PIM (Anexo B)'][:3])")" "GAS-O2"
ver "ejemplo .csv de activos" "$(S so GET "$M/inventario/plantilla?formato=csv&hoja=activos" | js "d['base64']" | base64 -d | head -1)" "Código"
ver "un solicitante no importa" "$(S so POST $M/inventario/importar '{"texto":"a;b\n1;2","simular":true}')" '"ok":false'

echo "2) Importar la planilla de ejemplo (con un sufijo para no chocar)"
python3 - "$T/ej.xlsx" "$T/p1.xlsx" "$R" <<'EOF'
import openpyxl,sys,re
w=openpyxl.load_workbook(sys.argv[1]); R=sys.argv[3]
cods=['EDI-001','NIV-001','LOC-001','LOC-002','GAS-O2-001','GAS-O2-002','EQM-001','ACT-001']
for h in ('Ubicaciones','Activos'):
    for row in w[h].iter_rows(min_row=2):
        for c in row:
            if isinstance(c.value,str):
                v=c.value
                if v in cods: v=f'T{R}-{v}'
                v=v.replace('Edificio Ampliación',f'Edificio Prueba {R}')
                c.value=v
w.save(sys.argv[2])
EOF
python3 -c "import base64,json; print(json.dumps({'base64':base64.b64encode(open('$T/p1.xlsx','rb').read()).decode(),'nombre':'p1.xlsx','simular':True,'crear_ubicaciones':True}))" > "$T/c1.json"
S1="$(SF gm POST $M/inventario/importar "$T/c1.json")"
ver "revisar: 4 ubicaciones y 4 activos nuevos, sin errores" "$(echo "$S1" | js "(d['ubicaciones']['nuevas'],d['activos']['nuevos'],len(d['ubicaciones']['errores'])+len(d['activos']['errores']),d['simulado'])")" "(4, 4, 0, True)"
ver "las rutas que nombran ubicaciones de la misma planilla no se duplican" "$(echo "$S1" | js "d['ubicaciones_a_crear']")" "[]"
ver "revisar no guarda nada" "$(act "T$R-GAS-O2-001" "a['id']")" "NO"
python3 -c "import json; d=json.load(open('$T/c1.json')); d['simular']=False; json.dump(d,open('$T/c1.json','w'))"
S2="$(SF gm POST $M/inventario/importar "$T/c1.json")"
ver "guardar: 4 activos" "$(echo "$S2" | js "(d['guardados'],len(d['activos']['errores']))")" "(4, 0)"
ver "la ubicación por código: la sala de gases" "$(act "T$R-GAS-O2-001" "a['ubicacion']")" "Edificio Prueba $R › Planta baja › Sala de gases"
ver "la ubicación por ruta llega a la misma sala" "$(act "T$R-GAS-O2-002" "a['ubicacion_codigo']")" "T$R-LOC-001"
ver "«forma parte de» otro activo de la misma planilla" "$(act "T$R-GAS-O2-002" "a['forma_parte_de']")" "T$R-GAS-O2-001"
ver "instalación con su sistema PIM y su NFPA 99" "$(act "T$R-GAS-O2-001" "(a['clase'],a['sistema'],a['categoria_nfpa99'],a['puesta_en_marcha'])")" "('sistema', 'GAS-O2', '1', '2019-05-20')"
ver "equipo médico con ANMAT y clase de riesgo" "$(act "T$R-EQM-001" "(a['clase'],a['anmat_pm'],a['clase_riesgo_pm'])")" "('equipo-medico', 'PM-2430-65', 'II')"
ver "fuera de circulación con su motivo" "$(act "T$R-ACT-001" "(a['estado'],a['motivo_estado'][:20])")" "('fuera-de-circulacion', 'Prestada al servicio')"
ver "la ubicación de un solo nombre (el edificio)" "$(act "T$R-ACT-001" "a['ubicacion']")" "Edificio Prueba $R"
ver "el árbol: la sala está dentro del piso, el piso dentro del edificio" "$(ub "T$R-LOC-001" "(u['nivel'],u['ruta'])")" "(2, 'Edificio Prueba $R › Planta baja › Sala de gases')"
S3="$(SF gm POST $M/inventario/importar "$T/c1.json")"
ver "la misma planilla otra vez: todo igual" "$(echo "$S3" | js "(d['activos']['nuevos'],d['activos']['cambian'],d['activos']['iguales'],d['ubicaciones']['nuevas'],d['ubicaciones']['cambian'])")" "(0, 0, 4, 0, 0)"

echo "3) Exportar y volver a importar"
S gm GET "$M/inventario/exportar?formato=xlsx" | js "d['base64']" | base64 -d > "$T/exp.xlsx"
ver "la exportación trae los activos de la prueba" "$(python3 -c "import openpyxl; w=openpyxl.load_workbook('$T/exp.xlsx'); print([r[0] for r in w['Activos'].iter_rows(values_only=True) if str(r[0]).startswith('T$R-')])")" "T$R-EQM-001"
python3 -c "import base64,json; print(json.dumps({'base64':base64.b64encode(open('$T/exp.xlsx','rb').read()).decode(),'nombre':'exp.xlsx','simular':True}))" > "$T/c2.json"
ver "exportar y volver a importar no cambia nada" "$(SF gm POST $M/inventario/importar "$T/c2.json" | js "(d['activos']['nuevos'],d['activos']['cambian'],d['ubicaciones']['nuevas'],d['ubicaciones']['cambian'])")" "(0, 0, 0, 0)"
python3 - "$T/exp.xlsx" "$T/exp2.xlsx" "$R" <<'EOF'
import openpyxl,sys
w=openpyxl.load_workbook(sys.argv[1]); h=w['Activos']; cab=[c.value for c in h[1]]
for row in h.iter_rows(min_row=2):
    if row[0].value==f'T{sys.argv[3]}-GAS-O2-001':
        row[cab.index('Modelo')].value='TC-7000'; row[cab.index('N.º de serie')].value='-'; row[cab.index('Fabricante o marca')].value=None
w.save(sys.argv[2])
EOF
python3 -c "import base64,json; print(json.dumps({'base64':base64.b64encode(open('$T/exp2.xlsx','rb').read()).decode(),'nombre':'exp2.xlsx','simular':True}))" > "$T/c3.json"
ver "corregir en la planilla: cambia 1 (modelo y serie)" "$(SF gm POST $M/inventario/importar "$T/c3.json" | js "(d['activos']['cambian'],d['activos']['detalle'][0]['que'])")" "(1, ['Modelo', 'N.º de serie'])"
python3 -c "import json; d=json.load(open('$T/c3.json')); d['simular']=False; json.dump(d,open('$T/c3.json','w'))"; SF gm POST $M/inventario/importar "$T/c3.json" >/dev/null
ver "celda vacía no toca, «-» borra" "$(act "T$R-GAS-O2-001" "(a['modelo'],a['serie'],a['fabricante'])")" "('TC-7000', '', 'Air Liquide')"

echo "4) Texto pegado desde Excel, encabezados propios y ubicaciones que se crean solas"
TX="$(printf 'Denominación\tMarca\tUbicación\tSistema\tClase\tCriticidad\nBomba de vacío %s\tBusch\tEdificio Prueba %s > Subsuelo > Sala de bombas\tGAS-VAC\tinstalación\t2\nHeladera de vacunas %s\tGafa\tEdificio Prueba %s > Planta baja > Farmacia\t\totro\t\n' $R $R $R $R)"
C4="$(python3 -c "import json,sys; print(json.dumps({'texto':sys.argv[1],'simular':True,'crear_ubicaciones':False}))" "$TX")"
ver "sin «crear ubicaciones», las que faltan son error" "$(S gm POST $M/inventario/importar "$C4" | js "[e['motivo'][:25] for e in d['activos']['errores']]")" "no existe la ubicación"
C4="$(echo "$C4" | sed 's/"crear_ubicaciones": false/"crear_ubicaciones": true/')"
ver "con «crear ubicaciones»: 3 nuevas y ninguna columna ignorada" "$(S gm POST $M/inventario/importar "$C4" | js "(len(d['ubicaciones_a_crear']),d['activos']['nuevos'],d['columnas_ignoradas'])")" "(3, 2, [])"
S4="$(S gm POST $M/inventario/importar "$(echo "$C4" | sed 's/"simular": true/"simular": false/')")"
ver "guardados con código propuesto" "$(echo "$S4" | js "d['guardados']")" "2"
inv; BV="$(python3 -c "import json; d=json.load(open('$T/inv.json')); print(next(a['codigo'] for a in d['activos'] if a['nombre']=='Bomba de vacío $R'))")"
ver "la bomba: código GAS-VAC-…, NFPA 2, en la sala creada" "$(act "$BV" "(a['codigo'][:8],a['categoria_nfpa99'],a['ubicacion'])")" "('GAS-VAC-', '2', 'Edificio Prueba $R › Subsuelo › Sala de bombas')"

echo "5) Errores claros por fila"
ER="$(printf 'Código;Nombre;Clase;Sistema;Puesta en marcha\nE%s-1;Sin sistema;instalación;;\nE%s-2;Sistema raro;instalación;XYZ;\nE%s-3;Fecha mala;otro;;31/02/2020\nE%s-4;Repetido;otro;;\nE%s-4;Repetido 2;otro;;\n' $R $R $R $R $R)"
ER="$(S gm POST $M/inventario/importar "$(python3 -c "import json,sys; print(json.dumps({'texto':sys.argv[1],'simular':True}))" "$ER")" | js "[e['fila'] for e in d['activos']['errores']], ' | '.join(e['motivo'] for e in d['activos']['errores'])")"
ver "cuatro filas con error y su número de fila" "$ER" "[2, 3, 4, 6]"
ver "dice qué corregir" "$ER" "sistema PIM"; ver "…y el sistema desconocido" "$ER" "no está en el Anexo B"; ver "…y la repetida" "$ER" "repetido"
ver "un enlace que no es de Google, OneDrive ni Dropbox" "$(S gm POST $M/inventario/importar '{"enlace":"https://example.com/x.xlsx","simular":true}')" "no está en la lista"
ver "sin nada que leer" "$(S gm POST $M/inventario/importar '{"simular":true}')" "Elegí un archivo"

echo "6) Alta desde la pantalla"
LS="$(ub "T$R-LOC-001" "u['id']")"
ver "instalación sin sistema → 422" "$(S gm POST $M/activos '{"clase":"sistema","nombre":"Algo"}')" "sistema PIM"
ver "un solicitante no da de alta" "$(S so POST $M/activos '{"clase":"activo","nombre":"Algo"}')" '"ok":false'
N1="$(S gm POST $M/activos "{\"clase\":\"sistema\",\"sistema\":\"GAS-O2\",\"nombre\":\"Tanque de reserva $R\",\"ubicacion_id\":\"$LS\",\"fabricante\":\"Linde\"}")"
ID="$(echo "$N1" | js "d['activo']['id']")"; C0="$(echo "$N1" | js "d['activo']['codigo']")"
ver "alta con código propuesto y su ubicación" "$(echo "$N1" | js "(d['activo']['codigo'][:7],d['activo']['ubicacion_codigo'],d['ordenes']['total'])")" "('GAS-O2-', 'T$R-LOC-001', 0)"

echo "7) Órdenes y tareas preventivas siguen al activo cuando cambia su código"
SR="{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"subject\":{\"reference\":\"Device/$ID\"},\"code\":{\"text\":\"Pérdida\"},\"note\":[{\"text\":\"Prueba inventario $R\"}]}"
SP so POST pim/ServiceRequest "$SR" >/dev/null; TK="$(grep -i '^X-PIM-Task:' "$T/h" | tr -d '\r' | sed 's/.*Task\///')"
ver "el solicitante pidió un trabajo y se generó la orden" "$TK" "0"
ver "la orden lleva el código del activo" "$(S gm GET "pim/Task/$TK" | js "d['focus']['identifier']['value']")" "$C0"
PT="$(A POST m/preventivos/pmp/tareas "{\"activo\":\"$ID\",\"preventivo\":\"Inspección visual $R\",\"frecuencia\":\"M\"}")"
ver "tarea preventiva sobre el activo" "$(echo "$PT" | js "d['tarea']['activo_codigo']")" "$C0"
V="$(act "$C0" "a['version']")"; NC="TAN-$R"
E1="$(S gm PUT "$M/activos/$ID" "{\"codigo\":\"$NC\",\"version\":\"$V\"}")"
ver "cambiar el código: responde con sus órdenes" "$(echo "$E1" | js "(d['activo']['codigo'],d['ordenes']['total']>=1)")" "('$NC', True)"
ver "la orden muestra el código nuevo en el acto" "$(S gm GET "pim/Task/$TK" | js "d['focus']['identifier']['value']")" "$NC"
ver "editar con una versión vieja → 409" "$(S gm PUT "$M/activos/$ID" "{\"nombre\":\"x\",\"version\":\"$V\"}")" "Otra persona"
Y="$(date +%Y)"
ver "la tarea preventiva toma el código nuevo (evento)" "$(esperar "A GET 'm/preventivos/pmp/cronograma?anio=$Y&activo=$ID' | js \"[t['activo_codigo'] for t in d['tareas']]\"" "$NC")" "$NC"
if [[ "$(A GET 'm/reportes/auditoria?q=x' )" == *'"ok":true'* ]]; then
  ver "en reportes la orden se encuentra por el código nuevo" "$(esperar "A GET 'm/reportes/auditoria?q=$NC&dias=30' | js \"[o['activo_codigo'] for o in d['ordenes']]\"" "$NC")" "$NC"
fi
ver "el historial guarda el cambio" "$(S gm GET "$M/activos/$ID" | js "[v['cambios'] for v in d['versiones']][0]")" "Código"

echo "8) Sacar de circulación (sin borrar) y volver a ponerlo en servicio"
V="$(act "$NC" "a['version']")"
ver "sin motivo → 422" "$(S gm POST "$M/activos/$ID/estado" "{\"estado\":\"obsoleto\",\"version\":\"$V\"}")" "motivo"
O1="$(S gm POST "$M/activos/$ID/estado" "{\"estado\":\"obsoleto\",\"motivo\":\"Reemplazado por el tanque nuevo $R\",\"version\":\"$V\"}")"
ver "queda obsoleto, con su motivo" "$(echo "$O1" | js "(d['activo']['estado'],d['activo']['motivo_estado'])")" "('obsoleto', 'Reemplazado por el tanque nuevo $R')"
ver "en PIM: inactive con statusReason" "$(S gm GET "pim/Device/$ID" | js "(d['status'],d['statusReason'][0]['text'][:11])")" "('inactive', 'Reemplazado')"
ver "sigue en el inventario (no se borra)" "$(act "$NC" "a['estado']")" "obsoleto"
SP so POST pim/ServiceRequest "$SR" > "$T/b"
ver "ya no se le pueden pedir trabajos" "$(cat "$T/b")" "no admite pedidos nuevos"
ver "la orden que ya tenía sigue ahí" "$(S gm GET "pim/Task/$TK" | js "d['status']")" "requested"
ver "su tarea preventiva se pausa" "$(esperar "A GET 'm/preventivos/pmp/cronograma?anio=$Y&activo=$ID' | js \"len(d['tareas'])\"" "0")" "0"
V="$(act "$NC" "a['version']")"
ver "volver a ponerlo en servicio" "$(S gm POST "$M/activos/$ID/estado" "{\"estado\":\"en-servicio\",\"version\":\"$V\"}" | js "d['activo']['estado']")" "en-servicio"
ver "su tarea preventiva se reanuda" "$(esperar "A GET 'm/preventivos/pmp/cronograma?anio=$Y&activo=$ID' | js \"len(d['tareas'])\"" "1")" "1"
ver "el diario firmado lo registra" "$(A GET 'proc/diario?n=60')" "inventario.estado"
ver "la ficha muestra el motivo en el historial" "$(S gm GET "$M/activos/$ID" | js "[v.get('motivo','') for v in d['versiones']]")" "Reemplazado"

echo "9) Ubicaciones"
U1="$(S gm POST $M/ubicaciones "{\"nombre\":\"Depósito $R\",\"tipo\":\"ro\",\"dentro_de\":\"$(ub "T$R-NIV-001" "u['id']")\"}")"
ver "nueva ubicación dentro del piso, con código propuesto" "$(echo "$U1" | js "(d['ubicacion']['codigo'][:4],d['ubicacion']['ruta'])")" "('LOC-', 'Edificio Prueba $R › Planta baja › Depósito $R')"
EDI="$(ub "T$R-EDI-001" "u['id']")"
ver "un edificio no puede quedar dentro de su propia sala" "$(S gm PUT "$M/ubicaciones/$EDI" "{\"dentro_de\":\"$LS\"}")" "dentro de sí misma"
ver "renombrar el piso cambia la ruta de sus activos" "$(S gm PUT "$M/ubicaciones/$(ub "T$R-NIV-001" "u['id']")" '{"nombre":"PB"}' >/dev/null; act "T$R-GAS-O2-001" "a['ubicacion']")" "Edificio Prueba $R › PB › Sala de gases"
ver "fuera de uso" "$(S gm PUT "$M/ubicaciones/$(echo "$U1" | js "d['ubicacion']['id']")" '{"estado":"inactive"}' | js "d['ubicacion']['estado']")" "inactive"

echo "10) Nombre del establecimiento"
ver "nombre muy corto → 422" "$(S gm PUT $M/establecimiento '{"nombre":"x"}')" "entre 3 y 120"
EN="$(S gm GET $M/inventario | js "d['establecimiento']['nombre'] or 'Establecimiento de prueba'")"
ver "se guarda" "$(S gm PUT $M/establecimiento "{\"nombre\":\"$EN\"}" | js "d['establecimiento']['nombre']")" "$EN"
ver "el organismo de PIM toma ese nombre" "$(S gm GET 'pim/Organization?_count=100' | js "[e['resource']['name'] for e in d['entry'] if any(p.endswith('pim-organismo') for p in e['resource']['meta'].get('profile',[]))]")" "$EN"

echo "11) Limpieza: los activos de la prueba quedan «cargados por error» (nada se borra)"
inv; for c in $(python3 -c "import json; d=json.load(open('$T/inv.json')); print(' '.join(a['id']+':'+a['version'] for a in d['activos'] if a['codigo'].startswith('T$R-') or a['codigo'] in ('$NC','$BV') or a['nombre'].endswith(' $R')))"); do
  S gm POST "$M/activos/${c%%:*}/estado" "{\"estado\":\"cargado-por-error\",\"motivo\":\"Activo de la prueba inventario.sh\",\"version\":\"${c#*:}\"}" >/dev/null; done
ver "cargados por error: fuera de la lista para pedir trabajos" "$(S so GET "pim/Device?_count=500&status=active" | js "[e['resource']['id'] for e in d.get('entry',[])]")" "["
[[ "$(S so GET "pim/Device?_count=500&status=active")" == *"$ID"* ]] && { echo "  ✘ el activo cargado por error sigue entre los activos"; MAL=$((MAL+1)); }
ver "y en Inventario quedan como «cargado por error»" "$(act "$NC" "a['estado']")" "cargado-por-error"

echo; echo "Resultado: $OK bien, $MAL mal"; [ "$MAL" -eq 0 ]
