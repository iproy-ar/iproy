#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba del módulo migracion 1.1.0 sobre UN nodo iniciado: orígenes (CSV, hoja publicada, MySQL), unión de columnas, equivalencias,
# simulación, carga repetible por el Servidor PIM y el ajuste que muestra u oculta el detalle escrito.
#   bash pruebas/migracion.sh <carpeta-nodo>
# Crea una base de origen temporal (se borra al terminar). Los registros que carga quedan en el nodo marcados como traídos de otro
# sistema, con un sufijo propio de cada corrida (el Servidor PIM no borra recursos).
set -u
D="$(cd "$1" && pwd)"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN DBP=\$${P}_DB_PORT ROOT=\$${P}_DB_ROOT_PASS"
B="http://127.0.0.1:$PORT/api"; T=$(mktemp -d); CJ="$T/cj"; SOCK="$(grep -m1 '^socket=' "$D/data/my.cnf" | cut -d= -f2)"
A() { curl -s -m 300 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
AF() { curl -s -m 300 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' --data-binary "@$3" "$B/$2"; }
U() { curl -s -m 60 -b "$CJ" -c "$CJ" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
SQL() { mariadb --socket="$SOCK" -uroot -p"$ROOT" -N -e "$1" 2>/dev/null; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:500}"; MAL=$((MAL+1)); fi; }
no()  { if [[ "$2" != *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
R=$RANDOM; GM="miggm$R"; CL="M1-$(head -c 30 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16)"; DBO="mig_origen_$R"; UO="migro$R"; PO="o$(head -c 20 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 14)"; M=m/migracion
DET0="$(A GET $M/estado | js "d['ajustes']['exponer_detalle']")"
trap 'A PUT $M/ajustes "{\"sigla\":\"${SG0:-}\"}" >/dev/null; A PUT "usuarios/$GM" "{\"activo\":0}" >/dev/null; SQL "DROP DATABASE IF EXISTS $DBO; DROP USER IF EXISTS \"$UO\"@\"%\""; A PUT $M/ajustes "{\"exponer_detalle\":$([ "$DET0" = True ] && echo true || echo false)}" >/dev/null; A PUT $M/equivalencias/estado "{\"equivalencias\":[{\"etiqueta\":\"Estado Raro $R\",\"borrar\":true}]}" >/dev/null; rm -rf "$T"' EXIT
A POST nucleo/usuario "{\"usuario\":\"$GM\",\"nombre\":\"Gestor Mig\",\"rol\":\"GM\",\"clave\":\"$CL\"}" >/dev/null; U POST sesion "{\"usuario\":\"$GM\",\"clave\":\"$CL\"}" >/dev/null
cuerpo() { python3 - "$@" <<'PY'
import json,sys
a=sys.argv; o={'origen':{'tipo':'csv','texto':open(a[1],encoding='utf-8').read(),'nombre':a[1].split('/')[-1]},'destino':a[2]}
if len(a)>3 and a[3]!='-': o['mapa']=json.loads(a[3])
if len(a)>4: o['simular']=a[4]=='sim'
if len(a)>5: o['crear_faltantes']=a[5]=='crear'
print(json.dumps(o))
PY
}

echo "1) Permisos y estado"
ver "solo el administrador entra (un gestor no)" "$(U GET $M/estado)" '"ok":false'
E="$(A GET $M/estado)"
ver "trae los siete destinos (1.1.0: planes especiales; 1.2.0: tareas del plan por semanas)" "$(echo "$E" | js "sorted(d['destinos'])")" "['empresas', 'equipos', 'ordenes', 'planes', 'servicios', 'sistemas', 'tareas_pmp']"
ver "y los valores PIM contra los que se traduce" "$(echo "$E" | js "(len(d['pim']['estados']),len(d['pim']['tipos']),'GAS-O2' in d['pim']['sistemas'])")" '(11, 8, True)'
ver "equivalencias de fábrica: Atención → en-espera" "$(echo "$E" | js "[e['destino'] for e in d['equivalencias']['estado'] if e['origen']=='atencion']")" "['en-espera']"
ver "y la condicional: Revisión con fecha de fin → finalizada" "$(echo "$E" | js "[e['destino'] for e in d['equivalencias']['estado'] if e['origen']=='revision|con_fin']")" "['finalizada']"

echo "2) Equivalencias"
ver "valor PIM inexistente → 422" "$(A PUT $M/equivalencias/estado '{"equivalencias":[{"etiqueta":"X","destino":"terminadisima"}]}')" 'no es un valor PIM'
ver "lista inexistente → 404" "$(A PUT $M/equivalencias/colores '{"equivalencias":[]}')" 'No existe esa lista'
ver "agregar una propia" "$(A PUT $M/equivalencias/estado "{\"equivalencias\":[{\"etiqueta\":\"Estado Raro $R\",\"destino\":\"en-espera\",\"nota\":\"prueba\"}]}" | js "d['guardadas']")" '1'

echo "3) Origen CSV: servicios, empresas y equipos"
printf 'Servicio;Sigla\nGuardia Mig %s;GUA%s\nQuirófano Mig %s;QUI%s\n;SINNOMBRE\n' $R $R $R $R > "$T/serv.csv"
cuerpo "$T/serv.csv" servicios > "$T/b.json"; AN="$(AF POST $M/analizar "$T/b.json")"
ver "detecta el separador y las columnas" "$(echo "$AN" | js "(d['columnas'],d['filas'])")" "(['Servicio', 'Sigla'], 3)"
ver "propone la unión de columnas" "$(echo "$AN" | js "d['mapa_propuesto']")" "'nombre': 'Servicio'"
cuerpo "$T/serv.csv" servicios '{"nombre":"Servicio","codigo":"Sigla"}' sim > "$T/b.json"
ver "simular no escribe; la fila sin nombre se informa" "$(AF POST $M/cargar "$T/b.json" | js "(d['simulada'],d['cuenta']['nuevo'],d['cuenta']['error'])")" '(True, 2, 1)'
cuerpo "$T/serv.csv" servicios '{"nombre":"Servicio","codigo":"Sigla"}' ya > "$T/b.json"
ver "carga: 2 servicios nuevos" "$(AF POST $M/cargar "$T/b.json" | js "d['cuenta']['nuevo']")" '2'
ver "repetir la carga no duplica (iguales)" "$(AF POST $M/cargar "$T/b.json" | js "(d['cuenta']['nuevo'],d['cuenta']['igual'])")" '(0, 2)'
ver "quedan como Location en PIM" "$(U GET "pim/Location?identifier=GUA$R" | js "d['entry'][0]['resource']['name']")" "Guardia Mig $R"
ver "columna obligatoria sin unir → 422" "$(cuerpo "$T/serv.csv" servicios '{"codigo":"Sigla"}' sim > "$T/b.json"; AF POST $M/cargar "$T/b.json")" 'Falta elegir la columna'
ver "columna que no existe → 422" "$(cuerpo "$T/serv.csv" servicios '{"nombre":"Fantasma"}' sim > "$T/b.json"; AF POST $M/cargar "$T/b.json")" 'no está en el origen'
printf 'codigo,nombre\nEMP-A%s,"Empresa A, S.A. %s"\nEMP-B%s,Empresa B %s\n' $R $R $R $R > "$T/emp.csv"
cuerpo "$T/emp.csv" empresas '{"codigo":"codigo","nombre":"nombre"}' ya > "$T/b.json"
ver "empresas (CSV con comas y comillas)" "$(AF POST $M/cargar "$T/b.json" | js "d['cuenta']['nuevo']")" '2'
ver "el nombre con coma entró entero" "$(U GET "pim/Organization?identifier=EMP-A$R" | js "d['entry'][0]['resource']['name']")" "Empresa A, S.A. $R"
printf 'PMA\tEquipo\tMarca\tModelo\tSerie\tServicio\tEstado\nMSV-%s\tMonitor multiparamétrico\tAcme\tM1\tS%s\tGuardia Mig %s\tActivo\nRES-%s\tRespirador\tAcme\tR2\tT%s\tNo Existe %s\tBaja\n\tSin código\t\t\t\t\t\n' $R $R $R $R $R $R > "$T/eq.tsv"
cuerpo "$T/eq.tsv" equipos '{"codigo":"PMA","nombre":"Equipo","fabricante":"Marca","modelo":"Modelo","serie":"Serie","servicio":"Servicio","estado":"Estado"}' ya > "$T/b.json"; RE="$(AF POST $M/cargar "$T/b.json")"
ver "equipos (separado por tabulaciones): 2 nuevos, 1 con error" "$(echo "$RE" | js "(d['cuenta']['nuevo'],d['cuenta']['error'])")" '(2, 1)'
ver "el error dice la fila y el motivo" "$(echo "$RE" | js "d['errores'][0]")" "'fila': 4, 'motivo': 'falta el código'"
ver "servicio que no existe: entra sin ubicación y se cuenta" "$(echo "$RE" | js "d['cuenta']['sin_referencia']")" '1'
ver "equipo dado de baja → inactivo" "$(U GET "pim/Device?identifier=RES-$R" | js "d['entry'][0]['resource']['status']")" 'inactive'
ver "equipo activo con su servicio" "$(U GET "pim/Device?identifier=MSV-$R" | js "d['entry'][0]['resource']['location']['display']")" "Guardia Mig $R"

echo "3b) Archivo grande, subido en partes"
SID="$(A POST $M/subir '{"texto":"codigo;nombre\nSUB-A'$R';Empresa subida A '$R'\n"}' | js "d['id']")"
ver "la primera parte devuelve el identificador" "${#SID}" '24'
ver "la segunda parte se suma al mismo archivo" "$(A POST $M/subir "{\"id\":\"$SID\",\"texto\":\"SUB-B$R;Empresa subida B $R\\n\"}" | js "d['id']=='$SID' and d['bytes']>60")" 'True'
ver "se lee como un archivo entero" "$(A POST $M/analizar "{\"origen\":{\"tipo\":\"csv\",\"subida\":\"$SID\"},\"destino\":\"empresas\"}" | js "d['filas']")" '2'
ver "identificador inventado → 422" "$(A POST $M/analizar '{"origen":{"tipo":"csv","subida":"../../etc/passwd"},"destino":"empresas"}')" 'inválido'
ver "archivo que no existe → 422" "$(A POST $M/analizar '{"origen":{"tipo":"csv","subida":"aaaaaaaaaaaaaaaaaaaaaaaa"},"destino":"empresas"}')" 'ya no está'

echo "4) Órdenes con equivalencias"
N=$((900000 + R)); cat > "$T/ord.csv" <<CSV
id;marca_temporal;estado;servicio_solicitante;detalle;tipo_tarea;empresa;tipo_man;prioridad;fecha_atencion;fecha_fin;codigo;pma
$((N+1));2026-10-01 08:00:00;Finalizado;Guardia Mig $R;Canilla que pierde;Sanitario;EMP-A$R;Correctivo;B;2026-10-01 09:00:00;2026-10-02 10:00:00;$((N+1))-GUA-SAN;
$((N+2));02/10/2026 08:30;En Proceso;Guardia Mig $R;Luz quemada;Luminarias;EMP-A$R;Correctivo;A;;;;
$((N+3));2026-10-03;Atencion;Quirófano Mig $R;Falta repuesto;Climatización Central;EMP-B$R;Preventivo;C;;;;
$((N+4));2026-10-04;Revision;Quirófano Mig $R;A revisar;Electricidad;EMP-B$R;;D;;;;
$((N+5));2026-10-05;Revision;Quirófano Mig $R;Terminada a conformar;Electricidad;EMP-B$R;Correctivo;;;2026-10-06;;
$((N+6));2026-10-06;DENEGADO;Servicio Nuevo $R;No corresponde;Control de Plagas;EMP-C$R;;;;;;
$((N+7));2026-10-07;Estado Raro $R;Guardia Mig $R;Monitor no enciende;Tareas No Clasificadas;EMP-A$R;Predictivo;;;;;MSV-$R
$((N+8));2026-10-08;Inventado;Guardia Mig $R;Estado sin equivalencia;Sanitario;EMP-A$R;;;;;;
$((N+9));fecha mala;Finalizado;Guardia Mig $R;Fecha inválida;Sanitario;EMP-A$R;;;;;;
$((N+10));2026-10-10;Finalizado;Guardia Mig $R;Fin antes del alta;Sanitario;EMP-A$R;;;;2026-10-01;;
CSV
cuerpo "$T/ord.csv" ordenes > "$T/b.json"; MP="$(AF POST $M/analizar "$T/b.json" | js "__import__('json').dumps(d['mapa_propuesto'])")"
ver "propone el mapa con los nombres de columna habituales" "$MP" '"alta": "marca_temporal"'
cuerpo "$T/ord.csv" ordenes "$MP" > "$T/b.json"; AN="$(AF POST $M/analizar "$T/b.json")"
ver "cuenta los valores de cada estado" "$(echo "$AN" | js "[(v['valor'],v['filas'],v['con_fin'],v['destino']) for v in d['valores']['estado'] if v['valor']=='Revision']")" "[('Revision', 2, 1, 'en-revision')]"
ver "marca lo que no tiene equivalencia" "$(echo "$AN" | js "[v['destino'] for v in d['valores']['estado'] if v['valor']=='Inventado']")" '[None]'
ver "prioridad A–D: propone la tabla normativa de PIM" "$(echo "$AN" | js "[v['destino'] for v in d['valores']['prioridad'] if v['valor']=='A'][0] in ('stat','asap','urgent','routine')")" 'True'
cuerpo "$T/ord.csv" ordenes "$MP" sim crear > "$T/b.json"; SI="$(AF POST $M/cargar "$T/b.json")"
ver "simulación: 7 entran, 3 con error" "$(echo "$SI" | js "(d['cuenta']['nuevo'],d['cuenta']['error'])")" '(7, 3)'
ver "al simular con «crear lo que falte», servicios y empresas se dan por creados" "$(echo "$SI" | js "d['cuenta']['sin_referencia']")" '0'
ver "avisa los estados sin equivalencia" "$(echo "$SI" | js "d['sin_equivalencia']['estado']")" "'Inventado': 1"
ver "los motivos de error son claros" "$(echo "$SI" | js "[sum(e['motivo'].startswith(x) for e in d['errores']) for x in ('estado sin equivalencia','fecha inválida','la fecha de fin es anterior')]")" '[1, 1, 1]'
ver "la simulación no creó órdenes" "$(U GET "pim/Task?identifier=$((N+1))" | js "d.get('total', len(d.get('entry',[])))")" '0'
cuerpo "$T/ord.csv" ordenes "$MP" ya crear > "$T/b.json"; CA="$(AF POST $M/cargar "$T/b.json")"
ver "carga: 7 órdenes" "$(echo "$CA" | js "d['cuenta']['nuevo']")" '7'
ver "creó el servicio y la empresa que faltaban" "$(echo "$CA" | js "(d['previos']['servicios']['nuevo'],d['previos']['empresas']['nuevo'])")" '(1, 1)'
t() { U GET "pim/Task?identifier=$1" | js "$2"; }
ver "Finalizado → finalizada, con sus fechas" "$(t $((N+1)) "(d['entry'][0]['resource']['businessStatus']['coding'][0]['code'], d['entry'][0]['resource']['executionPeriod']['end'][:10])")" "('finalizada', '2026-10-02')"
ver "conserva el número y el código del sistema anterior" "$(t $((N+1)) "[i['value'] for i in d['entry'][0]['resource']['identifier']]")" "['$((N+1))', '$((N+1))-GUA-SAN']"
ver "queda marcada como traída de otro sistema" "$(t $((N+1)) "[x['code'] for x in d['entry'][0]['resource']['meta'].get('tag',[])]")" 'legado'
ver "categoría con equivalente → clase de sistema PIM" "$(t $((N+1)) "d['entry'][0]['resource']['code']['coding'][0]['code']")" 'AGU-SAN'
ver "Correctivo → correctivo-diferido (EN 13306)" "$(t $((N+1)) "[x['valueCoding']['code'] for x in d['entry'][0]['resource']['extension'] if x['url'].endswith('tipo-mantenimiento')]")" 'correctivo-diferido'
ver "Correctivo con prioridad A → correctivo-inmediato" "$(t $((N+2)) "[x['valueCoding']['code'] for x in d['entry'][0]['resource']['extension'] if x['url'].endswith('tipo-mantenimiento')]")" 'correctivo-inmediato'
ver "fecha dd/mm/aaaa bien leída" "$(t $((N+2)) "d['entry'][0]['resource']['authoredOn'][:16]")" '2026-10-02T08:30'
ver "En Proceso → asignada, con su empresa" "$(t $((N+2)) "(d['entry'][0]['resource']['businessStatus']['coding'][0]['code'], d['entry'][0]['resource']['owner']['display'])")" "('asignada', 'Empresa A, S.A. $R')"
ver "Atención → en-espera, con motivo (PIM lo exige)" "$(t $((N+3)) "(d['entry'][0]['resource']['businessStatus']['coding'][0]['code'], 'statusReason' in d['entry'][0]['resource'])")" "('en-espera', True)"
ver "Revisión sin fecha de fin → en-revision" "$(t $((N+4)) "d['entry'][0]['resource']['businessStatus']['coding'][0]['code']")" 'en-revision'
ver "Revisión con fecha de fin → finalizada" "$(t $((N+5)) "d['entry'][0]['resource']['businessStatus']['coding'][0]['code']")" 'finalizada'
ver "Denegado → rechazada, sobre el servicio recién creado" "$(t $((N+6)) "(d['entry'][0]['resource']['businessStatus']['coding'][0]['code'], d['entry'][0]['resource']['location']['display'])")" "('rechazada', 'Servicio Nuevo $R')"
ver "categoría sin equivalente: conserva el texto" "$(t $((N+6)) "d['entry'][0]['resource']['code']['text']")" 'Control de Plagas'
ver "equivalencia propia + orden de equipo médico (focus)" "$(t $((N+7)) "(d['entry'][0]['resource']['businessStatus']['coding'][0]['code'], d['entry'][0]['resource']['focus']['display'])")" "('en-espera', 'Monitor multiparamétrico')"
ver "repetir la carga: nada nuevo" "$(AF POST $M/cargar "$T/b.json" | js "(d['cuenta']['nuevo'],d['cuenta']['igual'])")" '(0, 7)'
sed -i "s/Canilla que pierde/Canilla que pierde mucho/" "$T/ord.csv"; cuerpo "$T/ord.csv" ordenes "$MP" ya crear > "$T/b.json"
ver "si cambia el origen, cambia solo esa orden (versión nueva)" "$(AF POST $M/cargar "$T/b.json" | js "(d['cuenta']['cambio'],d['cuenta']['igual'])"; t $((N+1)) "d['entry'][0]['resource']['meta']['versionId']")" "(1, 6)"
ver "queda en el historial y en el diario" "$(A GET $M/estado | js "[c['destino'] for c in d['corridas'][:3]]")$(A GET 'proc/diario?n=30')" 'migracion.carga'

echo "5) Detalle escrito: siempre se guarda, el ajuste decide si se muestra"
A PUT $M/ajustes '{"exponer_detalle":false}' >/dev/null
ver "apagado: la orden no muestra el texto" "$(t $((N+2)) "'description' in d['entry'][0]['resource']")" 'False'
ver "encendido desde la pantalla" "$(A PUT $M/ajustes '{"exponer_detalle":true}' | js "d['ajustes']['exponer_detalle']")" 'True'
ver "ahora sí: el dato estaba" "$(t $((N+2)) "d['entry'][0]['resource']['description']")" 'Luz quemada'
ver "un gestor no cambia el ajuste" "$(U PUT $M/ajustes '{"exponer_detalle":false}')" '"ok":false'

echo "6) Origen MySQL (solo lectura)"
SQL "CREATE DATABASE $DBO CHARACTER SET utf8mb4; CREATE TABLE $DBO.ordenes (id INT PRIMARY KEY, marca_temporal DATETIME, estado VARCHAR(30), servicio_solicitante VARCHAR(80), detalle TEXT, tipo_tarea VARCHAR(60), empresa VARCHAR(40), fecha_fin DATETIME NULL);
  INSERT INTO $DBO.ordenes VALUES ($((N+21)),'2026-09-01 10:00:00','Finalizado','Guardia Mig $R','Desde MySQL uno','Ascensores','EMP-A$R','2026-09-02 10:00:00'),($((N+22)),'2026-09-03 10:00:00','En Proceso','Guardia Mig $R','Desde MySQL dos','Aberturas','EMP-A$R',NULL);
  CREATE USER '$UO'@'%' IDENTIFIED BY '$PO'; GRANT SELECT ON $DBO.* TO '$UO'@'%';"
my() { python3 -c "
import json,sys
o={'origen':{'tipo':'mysql','host':'127.0.0.1','puerto':$DBP,'base':'$DBO','usuario':'$UO','clave':sys.argv[1],'tabla':sys.argv[2],'consulta':sys.argv[3]},'destino':'ordenes'}
if len(sys.argv)>4: o['mapa']=json.loads(sys.argv[4]); o['simular']=sys.argv[5]=='sim'
print(json.dumps(o))" "$@"; }
ver "lee una tabla" "$(A POST $M/analizar "$(my "$PO" ordenes '')" | js "(d['filas'],d['columnas'][:3])")" "(2, ['id', 'marca_temporal', 'estado'])"
ver "o una consulta SELECT" "$(A POST $M/analizar "$(my "$PO" '' "SELECT id, estado FROM ordenes WHERE estado = 'Finalizado'")" | js "(d['filas'],d['columnas'])")" "(1, ['id', 'estado'])"
ver "clave incorrecta: lo dice sin mostrar detalles" "$(A POST $M/analizar "$(my mala ordenes '')")" 'usuario o clave incorrectos'
ver "consulta que no es SELECT → rechazada" "$(A POST $M/analizar "$(my "$PO" '' 'DELETE FROM ordenes')")" 'tiene que empezar con SELECT'
ver "dos sentencias → rechazada" "$(A POST $M/analizar "$(my "$PO" '' 'SELECT 1; DROP TABLE ordenes')")" 'no es de solo lectura'
ver "INTO OUTFILE → rechazada" "$(A POST $M/analizar "$(my "$PO" '' "SELECT * FROM ordenes INTO OUTFILE '/tmp/x'")")" 'no es de solo lectura'
ver "nombre de tabla con inyección → rechazado" "$(A POST $M/analizar "$(my "$PO" 'ordenes; DROP TABLE ordenes' '')")" 'Nombre de tabla inválido'
MQ='{"numero":"id","alta":"marca_temporal","estado":"estado","servicio":"servicio_solicitante","detalle":"detalle","categoria":"tipo_tarea","empresa":"empresa","fin":"fecha_fin"}'
ver "carga desde MySQL" "$(A POST $M/cargar "$(my "$PO" ordenes '' "$MQ" ya)" | js "d['cuenta']['nuevo']")" '2'
ver "la orden llegó con su clase de sistema" "$(t $((N+21)) "d['entry'][0]['resource']['code']['coding'][0]['code']")" 'TRV-ASC'
ver "la tabla de origen sigue intacta" "$(SQL "SELECT COUNT(*) FROM $DBO.ordenes")" '2'
no "la clave no queda en el historial ni en el diario" "$(A GET $M/estado)$(A GET 'proc/diario?n=40')" "$PO"

echo "7) Origen «hoja publicada»: solo https y sitios permitidos"
url() { A POST $M/analizar "{\"origen\":{\"tipo\":\"url\",\"url\":\"$1\"},\"destino\":\"servicios\"}"; }
ver "http → rechazada" "$(url 'http://docs.google.com/x')" 'https://'
ver "sitio fuera de la lista → rechazado" "$(url 'https://ejemplo.org/datos.csv')" 'no está en la lista de sitios permitidos'
ver "sitio que solo se parece → rechazado" "$(url 'https://docs.google.com.malo.example/x.csv')" 'no está en la lista'
ver "con usuario en la dirección → rechazada" "$(url 'https://u:p@docs.google.com/x')" 'https://'
ver "origen desconocido → 422" "$(A POST $M/analizar '{"origen":{"tipo":"ftp"},"destino":"servicios"}')" 'csv | url | mysql'

echo "7b) Sigla del establecimiento delante del código de equipos y sistemas"
SG0="$(A GET $M/estado | js "d['ajustes']['sigla']")"; SG="Zq$((R % 90 + 10))"
ver "sigla mal formada → 422" "$(A PUT $M/ajustes '{"sigla":"X"}')" '4 caracteres'
ver "sigla que empieza con número → 422" "$(A PUT $M/ajustes '{"sigla":"1abc"}')" '4 caracteres'
ver "se guarda la sigla" "$(A PUT $M/ajustes "{\"sigla\":\"$SG\"}" | js "d['ajustes']['sigla']")" "$SG"
ver "guardar la sigla no toca el otro ajuste" "$(A GET $M/estado | js "d['ajustes']['exponer_detalle']")" "$(A GET $M/estado | js "d['ajustes']['exponer_detalle']")"
printf 'codigo;nombre;clase\nSIG-%s-TAN-1;Tanque de prueba %s;GAS-O2\n' "$R" "$R" > "$T/sg.csv"
cuerpo "$T/sg.csv" sistemas '{"codigo":"codigo","nombre":"nombre","tipo_codigo":"clase"}' ya > "$T/b.json"
ver "se carga un sistema con su código propio" "$(AF POST $M/cargar "$T/b.json" | js "d['cuenta']['nuevo']")" '1'
idv() { js "[i['value'] for i in d['entry'][0]['resource']['identifier'] if i.get('system','').endswith('/sid/activo')]"; }
ver "queda guardado con la sigla delante" "$(U GET "pim/Device?identifier=SIG-$R-TAN-1" | idv)" "['$SG-SIG-$R-TAN-1']"
ver "se encuentra también por el código con sigla" "$(U GET "pim/Device?identifier=$SG-SIG-$R-TAN-1" | js "len(d.get('entry',[]))")" '1'
ver "volver a cargarlo no lo duplica ni le repite la sigla" "$(AF POST $M/cargar "$T/b.json" | js "d['cuenta']['igual']")$(U GET "pim/Device?identifier=$SG-$SG-SIG-$R-TAN-1" | js "len(d.get('entry',[]))")" '10'
printf 'codigo;nombre\n%s-SIG-%s-EQ-1;Monitor de prueba %s\n' "$SG" "$R" "$R" > "$T/sg2.csv"
cuerpo "$T/sg2.csv" equipos '{"codigo":"codigo","nombre":"nombre"}' ya > "$T/b.json"; AF POST $M/cargar "$T/b.json" >/dev/null
ver "un código que ya trae la sigla no la recibe dos veces" "$(U GET "pim/Device?identifier=SIG-$R-EQ-1" | idv)" "['$SG-SIG-$R-EQ-1']"
printf 'id;marca_temporal;estado;pma\n%s;2026-10-21;Finalizado;SIG-%s-EQ-1\n' "$((N+60))" "$R" > "$T/sg3.csv"
cuerpo "$T/sg3.csv" ordenes '{"numero":"id","alta":"marca_temporal","estado":"estado","equipo":"pma"}' ya > "$T/b.json"; AF POST $M/cargar "$T/b.json" >/dev/null
ver "la orden que nombra el equipo sin sigla queda unida a él" "$(U GET "pim/Task?identifier=$((N+60))" | js "d['entry'][0]['resource']['focus']['display']")" "Monitor de prueba $R"
ver "alta por la API: también recibe la sigla" "$(A POST pim/Device "{\"resourceType\":\"Device\",\"identifier\":[{\"system\":\"https://iproy.com.ar/fhir/sid/activo\",\"value\":\"SIG-$R-API\"}],\"deviceName\":[{\"name\":\"Equipo API $R\",\"type\":\"user-friendly-name\"}],\"status\":\"active\"}" | js "[i['value'] for i in d['identifier'] if i['system'].endswith('/sid/activo')]")" "['$SG-SIG-$R-API']"
printf 'codigo;equipo;criticidad\nSIG-%s-R1;Respirador %s;A\nSIG-%s-R2;Balanza %s;4\nSIG-%s-R3;Raro %s;Z9\n' "$R" "$R" "$R" "$R" "$R" "$R" > "$T/sg5.csv"
cuerpo "$T/sg5.csv" equipos > "$T/b.json"; ver "propone criticidad → riesgo" "$(AF POST $M/analizar "$T/b.json" | js "d['mapa_propuesto'].get('riesgo')")" 'criticidad'
cuerpo "$T/sg5.csv" equipos '{"codigo":"codigo","nombre":"equipo","riesgo":"criticidad"}' ya > "$T/b.json"; RI="$(AF POST $M/cargar "$T/b.json")"
ver "criticidad sin equivalencia: se avisa y el equipo entra igual" "$(echo "$RI" | js "(d['cuenta']['nuevo'], d['sin_equivalencia']['riesgo'])")" "(3, {'Z9': 1})"
rg() { U GET "pim/Device?identifier=$1" | js "[x['valueCoding']['code'] for x in d['entry'][0]['resource'].get('extension',[]) if x['url'].endswith('pim-categoria-riesgo')]"; }
ver "criticidad A → categoría de riesgo 1 (NFPA 99)" "$(rg SIG-$R-R1)" "['1']"
ver "un 4 escrito entra directo" "$(rg SIG-$R-R2)" "['4']"
ver "sin equivalencia: queda sin categoría" "$(rg SIG-$R-R3)" '[]'
A PUT $M/ajustes "{\"sigla\":\"$SG0\"}" >/dev/null
ver "sin sigla, el código entra tal cual" "$(printf 'codigo;nombre;clase\nSIN-%s-1;Sin sigla %s;GAS-O2\n' "$R" "$R" > "$T/sg4.csv"; cuerpo "$T/sg4.csv" sistemas '{"codigo":"codigo","nombre":"nombre","tipo_codigo":"clase"}' ya > "$T/b.json"; AF POST $M/cargar "$T/b.json" >/dev/null; [ -n "$SG0" ] && echo "['SIN-$R-1']" || U GET "pim/Device?identifier=SIN-$R-1" | idv)" "['SIN-$R-1']"

echo "8) Lo migrado no dispara avisos"
if A GET m/notificaciones/resumen | grep -q '"ok":true'; then
  A POST tareas/pim:vigia/ejecutar >/dev/null; for _ in 1 2 3; do A POST nucleo/tick >/dev/null; done   # ola 3: que lo pendiente de otras pruebas salga ANTES de crear la regla
  RG="$(A POST m/notificaciones/reglas "{\"nombre\":\"MIG-$R\",\"tema\":\"pim/Task/create\",\"canal\":\"webhook\",\"destino\":\"http://127.0.0.1:9/mig-$R\"}" | js "d['regla']['id']")"
  A POST tareas/pim:vigia/ejecutar >/dev/null; A POST nucleo/tick >/dev/null
  printf 'id;marca_temporal;estado;servicio_solicitante\n%s;2026-10-20;Finalizado;Guardia Mig %s\n' "$((N+50))" "$R" > "$T/av.csv"
  cuerpo "$T/av.csv" ordenes '{"numero":"id","alta":"marca_temporal","estado":"estado","servicio":"servicio_solicitante"}' ya crear > "$T/b.json"
  ver "se carga una orden más con la regla activa" "$(AF POST $M/cargar "$T/b.json" | js "d['cuenta']['nuevo']")" '1'
  sleep 1; A POST tareas/pim:vigia/ejecutar >/dev/null; for _ in 1 2 3; do A POST nucleo/tick >/dev/null; done
  ver "el bus la publica marcada como traída de otro sistema" "$(SQL "SELECT COUNT(*) FROM iproy.nucleo_evento WHERE tema='pim/Task/create' AND datos LIKE '%\"numero\":\"$((N+50))\"%' AND datos LIKE '%\"legado\":true%'" 2>/dev/null)" '1'
  ver "y no deja ningún aviso en cola" "$(SQL "SELECT COUNT(*) FROM iproy.ntf_envio WHERE regla_id = ${RG:-0}")" '0'
  A PUT "m/notificaciones/reglas/$RG" '{"activa":0}' >/dev/null
else echo "  · módulo notificaciones no instalado: se omite"; fi

echo "9) Carga grande en un instante: el vigía no se queda repitiendo"
for _ in 1 2 3 4 5 6; do A POST tareas/pim:vigia/ejecutar >/dev/null; done
{ echo 'codigo;nombre;clase'; for i in $(seq 1 450); do echo "VG-$R-$i;Tablero $i de $R;ELE-NORM"; done; } > "$T/vg.csv"
cuerpo "$T/vg.csv" sistemas '{"codigo":"codigo","nombre":"nombre","tipo_codigo":"clase"}' ya > "$T/b.json"
ver "450 sistemas en una carga" "$(AF POST $M/cargar "$T/b.json" | js "d['cuenta']['nuevo']")" '450'
sleep 1; ver "el vigía publica los 450 de una pasada" "$(A POST tareas/pim:vigia/ejecutar | js "int(d['resultado'].split()[0]) >= 450")" 'True'
ver "y en la pasada siguiente no repite ninguno" "$(A POST tareas/pim:vigia/ejecutar)" '"0 cambios publicados"'

echo "10) Planes preventivos (hoja tipo PMP → módulo Preventivos)"
printf 'codigo;nombre;clase\nPMP-%s-TAN-1;Tanque PMP %s;GAS-O2\nPMP-%s-BOM-1;Bomba PMP %s;GAS-VAC\n' $R $R $R $R > "$T/ps.csv"
cuerpo "$T/ps.csv" sistemas '{"codigo":"codigo","nombre":"nombre","tipo_codigo":"clase"}' ya > "$T/b.json"; AF POST $M/cargar "$T/b.json" >/dev/null
cat > "$T/pmp.csv" <<CSV
Codigo,Recurso Físico,Componente,Sub componente,Preventivo,Frecuencia,Proxima ejecución
PMP-$R-TAN-1,Tanque,>>> SALA <<<,,,,
PMP-$R-TAN-1,Tanque,>> RECURSO <<,---,------------------------,,-----
PMP-$R-TAN-1,Tanque,Instrumentación,Manómetro,Validación de aguja,Quincenal,21/12/2026
PMP-$R-TAN-1,Tanque,Control Presión,Válvula Seguridad,Test de disparo,quincenal,30/11/2026
PMP-$R-TAN-1,Tanque,Cuerpo,Válvula Purga,Limpieza de fondo,Mensual,16/11/2026
PMP-$R-BOM-1,Bomba,Motor,Rodamientos,Verificación de temperatura,,
PMP-$R-BOM-1,Bomba,Motor,Bobinado,Prueba de aislamiento,Cada tanto,
NOEXISTE-$R,X,Y,Z,Algo,Mensual,
CSV
pl() { python3 - "$T/pmp.csv" "$1" "$2" "$3" <<'PY'
import json,sys
a=sys.argv; m={'activo':'Codigo','preventivo':'Preventivo','componente':'Componente','subcomponente':'Sub componente','frecuencia':'Frecuencia','proxima':'Proxima ejecución'}
print(json.dumps({'origen':{'tipo':'csv','texto':open(a[1],encoding='utf-8').read(),'nombre':'pmp.csv'},'destino':'planes','mapa':m,'simular':a[2]=='sim','opciones':{'agrupar':a[3]=='1','habilitar':a[4]=='1'}}))
PY
}
ver "propone la unión de columnas de la hoja PMP" "$(cuerpo "$T/pmp.csv" planes > "$T/b.json"; AF POST $M/analizar "$T/b.json" | js "sorted(d['mapa_propuesto'].items())")" "('activo', 'Codigo'), ('componente', 'Componente'), ('frecuencia', 'Frecuencia'), ('preventivo', 'Preventivo'), ('proxima', 'Proxima ejecución'), ('subcomponente', 'Sub componente')"
pl sim 1 0 > "$T/b.json"; SIM="$(AF POST $M/cargar "$T/b.json")"
ver "simulación: 3 planes (quincenal y mensual del tanque, sin frecuencia de la bomba), 1 activo inexistente" "$(echo "$SIM" | js "(d['simulada'], d['planes']['planes'], d['cuenta']['nuevo'], d['cuenta']['sin_referencia'], d['planes']['sin_frecuencia'])")" "(True, 3, 3, 1, 1)"
ver "los separadores de la hoja no son tareas; el quincenal junta sus 2 tareas" "$(echo "$SIM" | js "sorted((x['cada_dias'] or 0, x['tareas']) for x in d['planes']['muestra'])")" "[(0, 2), (15, 2), (30, 1)]"
ver "la próxima del grupo es la más cercana (DD/MM/AAAA)" "$(echo "$SIM" | js "[x['proxima'] for x in d['planes']['muestra'] if x['cada_dias']==15]")" "['2026-11-30']"
ver "frecuencia que no se entiende: se informa" "$(echo "$SIM" | js "d['sin_equivalencia']")" "'Cada tanto': 1"
ver "simular no escribe" "$(A GET m/preventivos/planes | js "len([p for p in d['planes'] if 'PMP $R' in (p['activo_nombre'] or '')])")" "0"
pl ya 1 0 > "$T/b.json"
ver "carga: 3 planes nuevos" "$(AF POST $M/cargar "$T/b.json" | js "d['cuenta']['nuevo']")" "3"
ver "repetir no duplica" "$(AF POST $M/cargar "$T/b.json" | js "(d['cuenta']['nuevo'], d['cuenta']['igual'])")" "(0, 3)"
PL="$(A GET m/preventivos/planes)"; PQ=$(echo "$PL" | js "[p['id'] for p in d['planes'] if p['cada_dias']==15 and 'Tanque PMP $R' in (p['activo_nombre'] or '')][0]"); PS=$(echo "$PL" | js "[p['id'] for p in d['planes'] if not p['cada_dias'] and 'Bomba PMP $R' in (p['activo_nombre'] or '')][0]")
ver "quedan en pausa, marcados como traídos, con las tareas como lista" "$(echo "$PL" | js "[(p['habilitado'], p['origen'], p['tareas'].split(chr(10))) for p in d['planes'] if p['id']==$PQ]")" "[(0, 'importado', ['Instrumentación / Manómetro: Validación de aguja', 'Control Presión / Válvula Seguridad: Test de disparo'])]"
sed -i 's/Limpieza de fondo,Mensual/Limpieza de fondo,Bimestral/' "$T/pmp.csv"; pl ya 1 0 > "$T/b.json"
A PUT "m/preventivos/planes/$(echo "$PL" | js "[p['id'] for p in d['planes'] if p['cada_dias']==30 and 'Tanque PMP $R' in (p['activo_nombre'] or '')][0]")" '{"habilitado":true}' >/dev/null
ver "cambia la frecuencia en el origen: plan bimestral nuevo y el mensual que ya no está queda en pausa (no se borra)" "$(AF POST $M/cargar "$T/b.json" | js "(d['cuenta']['nuevo'], d['cuenta']['igual'], d['cuenta']['retirado'])")" "(1, 2, 1)"
ver "el mensual retirado quedó en pausa" "$(A GET m/preventivos/planes | js "[(p['habilitado']) for p in d['planes'] if p['cada_dias']==30 and 'Tanque PMP $R' in (p['activo_nombre'] or '')]")" "[0]"
pl sim 0 0 > "$T/b.json"
ver "sin agrupar: un plan por tarea" "$(AF POST $M/cargar "$T/b.json" | js "d['planes']['planes']")" "5"
ver "habilitar un plan sin frecuencia → 422" "$(A PUT "m/preventivos/planes/$PS" '{"habilitado":true}')" 'elegí una frecuencia'
ver "habilitar en bloque: solo los que tienen frecuencia" "$(A POST m/preventivos/planes/habilitar "{\"ids\":[$PQ,$PS]}" | js "d['habilitados']")" "1"
ver "darle frecuencia al de la bomba y habilitarlo (la próxima se calcula)" "$(A PUT "m/preventivos/planes/$PS" '{"cada_dias":30,"habilitado":true}' | js "(d['plan']['habilitado'], d['plan']['cada_dias'], d['plan']['proxima'] is not None)")" "(1, 30, True)"
A PUT "m/preventivos/planes/$PQ" '{"habilitado":false}' >/dev/null; A PUT "m/preventivos/planes/$PS" '{"habilitado":false}' >/dev/null   # no generan órdenes en el nodo de prueba
ver "el Gestor de la prueba no carga planes (migración solo administrador)" "$(pl sim 1 0 > "$T/b.json"; curl -s -b "$CJ" -X POST -H 'X-Nucleo: 1' -H 'Content-Type: application/json' --data-binary "@$T/b.json" "$B/$M/cargar")" '"ok":false'

echo; echo "migracion: $OK bien, $MAL mal"; [ "$MAL" -eq 0 ]
