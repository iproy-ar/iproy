#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba del módulo widgets 1.0.0 sobre UN nodo iniciado: catálogo (grupos, nombres, orden), perfiles (qué ve cada persona),
# widgets creados desde la pantalla (formulario, sábana, indicadores, página), sus registros, y exportar e importar.
#   bash pruebas/widgets.sh <carpeta-nodo>
# Todo lo que crea lleva un sufijo propio y se borra al terminar; el catálogo del nodo queda como estaba.
set -u
D="$(cd "$1" && pwd)"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
B="http://127.0.0.1:$PORT/api"; T=$(mktemp -d); CJ="$T/cj"
A() { curl -s -m 60 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
U() { curl -s -m 60 -b "$CJ" -c "$CJ" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
no()  { if [[ "$2" != *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
S=$RANDOM; UU="wdgso$S"; CL="W1-$(head -c 30 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16)"
G="pg$S"; F="pf$S"; SB="ps$S"; IN="pi$S"; PG="pp$S"; PE="pe$S"; M=m/widgets
limpiar() { for w in $IN $SB $F $PG; do A POST "$M/widgets/$w/borrar" '{"con_datos":true}' >/dev/null; done; A POST "$M/widgets/ordenes/borrar" '{}' >/dev/null
  A PUT "$M/usuarios/$UU/perfil" '{"perfil":""}' >/dev/null; A POST "$M/perfiles/$PE/borrar" '{}' >/dev/null; A POST "$M/grupos/$G/borrar" '{}' >/dev/null
  A PUT "usuarios/$UU" '{"activo":0}' >/dev/null; rm -rf "$T"; }
trap limpiar EXIT

echo "1) Grupos"
ver "alta de grupo" "$(A POST $M/grupos "{\"id\":\"$G\",\"nombre\":\"Calderas $S\",\"color\":\"#c62828\",\"orden\":51}")" '"ok":true'
ver "id repetido → 409" "$(A POST $M/grupos "{\"id\":\"$G\",\"nombre\":\"Otra\"}")" 'Ya existe el grupo'
ver "id inválido → 422" "$(A POST $M/grupos '{"id":"Con Espacios","nombre":"x"}')" 'minúsculas'
ver "color inválido → 422" "$(A PUT $M/grupos/$G '{"color":"rojo"}')" '#RRGGBB'
ver "cambio de orden" "$(A PUT $M/grupos/$G '{"orden":52}'; A GET $M/admin | js "[g['orden'] for g in d['grupos'] if g['id']=='$G']")" '[52]'

echo "2) Widget creado: formulario"
DEF='{"ayuda":"Control diario","campos":[{"id":"turno","etiqueta":"Turno","tipo":"lista","opciones":["Mañana","Tarde","Noche"],"requerido":true},{"id":"presion","etiqueta":"Presión","tipo":"numero","unidad":"kg/cm²","minimo":0,"maximo":20,"requerido":true},{"id":"fecha","etiqueta":"Fecha","tipo":"fecha"},{"id":"obs","etiqueta":"Observaciones","tipo":"area"},{"id":"ok","etiqueta":"Conforme","tipo":"casilla"}],"estados":["Normal","Alerta"],"boton":"Registrar control","numerar":"CD-"}'
ver "alta" "$(A POST $M/widgets "{\"id\":\"$F\",\"tipo\":\"formulario\",\"nombre\":\"Control Diario $S\",\"grupo\":\"$G\",\"orden\":10,\"icono\":\"modulo\",\"definicion\":$DEF}")" '"version":1'
ver "grupo inexistente → 422" "$(A POST $M/widgets "{\"id\":\"x$F\",\"tipo\":\"formulario\",\"nombre\":\"x\",\"grupo\":\"noexiste\",\"definicion\":$DEF}")" 'No existe el grupo'
ver "sin campos → 422" "$(A POST $M/widgets "{\"id\":\"x$F\",\"tipo\":\"formulario\",\"nombre\":\"x\",\"definicion\":{\"campos\":[]}}")" 'al menos un campo'
ver "campo repetido → 422" "$(A POST $M/widgets "{\"id\":\"x$F\",\"tipo\":\"formulario\",\"nombre\":\"x\",\"definicion\":{\"campos\":[{\"id\":\"a1\",\"etiqueta\":\"A\"},{\"id\":\"a1\",\"etiqueta\":\"B\"}]}}")" 'repetido'
ver "nombre de campo reservado → 422" "$(A POST $M/widgets "{\"id\":\"x$F\",\"tipo\":\"formulario\",\"nombre\":\"x\",\"definicion\":{\"campos\":[{\"id\":\"estado\",\"etiqueta\":\"E\"}]}}")" 'reservado'
ver "lista sin opciones → 422" "$(A POST $M/widgets "{\"id\":\"x$F\",\"tipo\":\"formulario\",\"nombre\":\"x\",\"definicion\":{\"campos\":[{\"id\":\"a1\",\"etiqueta\":\"A\",\"tipo\":\"lista\"}]}}")" 'necesita opciones'
ver "tipo de widget desconocido → 422" "$(A POST $M/widgets "{\"id\":\"x$F\",\"tipo\":\"programa\",\"nombre\":\"x\",\"definicion\":{}}")" 'Tipo de widget'
ver "el tipo no se cambia" "$(A PUT $M/widgets/$F '{"tipo":"sabana"}')" 'no se cambia'
ver "lo que no entiende la definición se descarta (no guarda código)" "$(A PUT $M/widgets/$F "{\"definicion\":$(echo "$DEF" | sed 's/^{/{"script":"alert(1)","html":"<b>x<\/b>",/')}" | js "sorted(d['widget']['definicion'].keys())")" "['ayuda', 'boton', 'campos', 'estados', 'numerar']"
ver "cada cambio de definición suma versión" "$(A GET $M/admin | js "[w['version'] for w in d['widgets'] if w['id']=='$F']")" '[2]'

echo "3) Registros del formulario"
A POST nucleo/usuario "{\"usuario\":\"$UU\",\"nombre\":\"Solicitante W\",\"rol\":\"SO\",\"clave\":\"$CL\"}" >/dev/null
U POST sesion "{\"usuario\":\"$UU\",\"clave\":\"$CL\"}" >/dev/null
ver "el catálogo trae el widget creado con su grupo" "$(U GET $M/catalogo | js "[(w['nombre'],w['grupo'],w['tipo']) for w in d['widgets'] if w['id']=='$F']")" "('Control Diario $S', '$G', 'formulario')"
ver "un solicitante no configura" "$(U GET $M/admin)" '"ok":false'
ver "ni crea widgets" "$(U POST $M/widgets '{"id":"abc","tipo":"pagina","nombre":"x","definicion":{"texto":"x"}}')" '"ok":false'
ver "carga un registro" "$(U POST $M/datos/$F '{"datos":{"turno":"Tarde","presion":"8,5","fecha":"2026-11-21","obs":"todo bien","ok":true}}')" '"numero":"CD-1"'
ver "el número decimal con coma se guarda como número" "$(U GET $M/datos/$F | js "d['registros'][0]['datos']['presion']")" '8.5'
ver "estado inicial = el primero de la lista" "$(U GET $M/datos/$F | js "d['registros'][0]['estado']")" 'Normal'
ver "falta un obligatorio → 422" "$(U POST $M/datos/$F '{"datos":{"turno":"Tarde"}}')" 'Falta «Presión»'
ver "valor fuera de la lista → 422" "$(U POST $M/datos/$F '{"datos":{"turno":"Madrugada","presion":1}}')" 'de la lista'
ver "fuera de rango → 422" "$(U POST $M/datos/$F '{"datos":{"turno":"Noche","presion":99}}')" 'máximo'
ver "fecha inválida → 422" "$(U POST $M/datos/$F '{"datos":{"turno":"Noche","presion":1,"fecha":"21/11/2026"}}')" 'fecha inválida'
ver "numeración correlativa" "$(U POST $M/datos/$F '{"datos":{"turno":"Noche","presion":7}}' | js "d['registro']['numero']")" 'CD-2'
A POST $M/datos/$F '{"datos":{"turno":"Mañana","presion":9}}' >/dev/null
ver "quien carga ve solo lo suyo" "$(U GET $M/datos/$F | js "d['total']")" '2'
ver "el administrador ve todo" "$(A GET $M/datos/$F | js "d['total']")" '3'
ver "un formulario no se edita desde afuera" "$(U PUT $M/datos/$F/$(U GET $M/datos/$F | js "d['registros'][0]['id']") '{"datos":{"presion":1}}')" 'no se edita'
ver "queda en el diario firmado" "$(A GET 'proc/diario?n=30')" 'widgets.dato.alta'

echo "4) Sábana: filtros, edición en la fila y revisión"
DS='{"campos":[{"id":"servicio","etiqueta":"Servicio","tipo":"lista","opciones":["Guardia","Quirófano","UTI"],"requerido":true},{"id":"detalle","etiqueta":"Detalle","tipo":"area","requerido":true},{"id":"fin","etiqueta":"Fecha fin","tipo":"fecha"},{"id":"nota","etiqueta":"Anotación","tipo":"texto"}],"estados":["Pendiente","En Curso","Finalizado","Cancelado"],"columnas":["numero","creado","servicio","detalle","estado","fin","nota"],"filtros":["servicio"],"editables":["estado","fin","nota"],"alta":true,"descarga":true,"imprimir":true,"numerar":"#"}'
ver "alta de sábana" "$(A POST $M/widgets "{\"id\":\"$SB\",\"tipo\":\"sabana\",\"nombre\":\"Gestión Movilidades $S\",\"grupo\":\"$G\",\"orden\":20,\"definicion\":$DS}")" '"tipo":"sabana"'
ver "columna inexistente → 422" "$(A PUT $M/widgets/$SB "{\"definicion\":$(echo "$DS" | sed 's/"nota"\]/"nota","fantasma"]/')}")" 'no existe el campo'
for s in Guardia Guardia Quirófano UTI; do U POST $M/datos/$SB "{\"datos\":{\"servicio\":\"$s\",\"detalle\":\"Traslado a $s\"}}" >/dev/null; done
L="$(U GET $M/datos/$SB)"; ID1="$(echo "$L" | js "d['registros'][-1]['id']")"
ver "en la sábana se ven todos los registros" "$(echo "$L" | js "d['total']")" '4'
ver "conteo por estado" "$(echo "$L" | js "d['por_estado']")" "'Pendiente': 4"
ver "filtro por campo de lista" "$(U GET "$M/datos/$SB?f%5Bservicio%5D=Guardia" | js "d['total']")" '2'
ver "búsqueda de texto" "$(U GET "$M/datos/$SB?q=uti" | js "d['total']")" '1'
ver "cambiar el estado desde la fila" "$(U PUT $M/datos/$SB/$ID1 '{"estado":"En Curso","revision":1}' | js "(d['registro']['estado'],d['registro']['revision'])")" "('En Curso', 2)"
ver "editar un campo permitido" "$(U PUT $M/datos/$SB/$ID1 '{"datos":{"nota":"chofer avisado","fin":"2026-11-22"},"revision":2}' | js "d['registro']['datos']['nota']")" 'chofer avisado'
ver "no pisa los otros datos del registro" "$(U GET "$M/datos/$SB?estado=En%20Curso" | js "d['registros'][0]['datos']['servicio']")" 'Guardia'
ver "campo no editable desde la fila → 403" "$(U PUT $M/datos/$SB/$ID1 '{"datos":{"detalle":"otro"}}')" 'no se edita desde la fila'
ver "estado desconocido → 422" "$(U PUT $M/datos/$SB/$ID1 '{"estado":"Inventado"}')" 'Estado desconocido'
ver "revisión vieja → 409 (dos personas a la vez)" "$(U PUT $M/datos/$SB/$ID1 '{"estado":"Finalizado","revision":1}')" 'Otra persona cambió'
ver "filtro por estado" "$(U GET "$M/datos/$SB?estado=En%20Curso" | js "d['total']")" '1'
ver "filtro por fecha (mañana: nada)" "$(U GET "$M/datos/$SB?desde=$(date -d tomorrow +%F)" | js "d['total']")" '0'
ver "registro de otro widget → 404" "$(U PUT $M/datos/$F/$ID1 '{"estado":"Normal"}')" 'No existe ese registro'

echo "5) Indicadores y página"
ver "alta de indicadores sobre la sábana" "$(A POST $M/widgets "{\"id\":\"$IN\",\"tipo\":\"indicadores\",\"nombre\":\"Reporte Movilidades $S\",\"grupo\":\"$G\",\"definicion\":{\"fuente\":\"$SB\",\"dias\":0,\"tarjetas\":[{\"titulo\":\"Por área\",\"campo\":\"servicio\",\"operacion\":\"contar\"},{\"titulo\":\"Por estado\",\"campo\":\"estado\",\"operacion\":\"contar\"}]}}")" '"tipo":"indicadores"'
R="$(U GET $M/datos/$IN)"
ver "cuenta por área" "$(echo "$R" | js "d['tarjetas'][0]['grupos']")" "'Guardia': 2"
ver "cuenta por estado" "$(echo "$R" | js "d['tarjetas'][1]['grupos']")" "'En Curso': 1"
ver "sumar un texto → 422" "$(A PUT $M/widgets/$IN "{\"definicion\":{\"fuente\":\"$SB\",\"tarjetas\":[{\"titulo\":\"x\",\"campo\":\"detalle\",\"operacion\":\"sumar\"}]}}")" 'no es un número'
ver "promedio de un campo numérico" "$(A POST $M/widgets "{\"id\":\"x$IN\",\"tipo\":\"indicadores\",\"nombre\":\"x\",\"definicion\":{\"fuente\":\"$F\",\"dias\":0,\"tarjetas\":[{\"titulo\":\"Presión media\",\"campo\":\"presion\",\"operacion\":\"promedio\"}]}}" >/dev/null; A GET $M/datos/x$IN | js "d['tarjetas'][0]['valor']"; A POST $M/widgets/x$IN/borrar '{}' >/dev/null)" '8.17'
ver "no se borra una sábana que alimenta indicadores" "$(A POST $M/widgets/$SB/borrar '{"con_datos":true}')" 'toma sus números'
ver "página con dirección https" "$(A POST $M/widgets "{\"id\":\"$PG\",\"tipo\":\"pagina\",\"nombre\":\"Capacitaciones $S\",\"definicion\":{\"url\":\"https://ejemplo.org/curso\",\"texto\":\"Material\",\"embebida\":true}}")" '"embebida":true'
ver "dirección javascript: → 422" "$(A PUT $M/widgets/$PG '{"definicion":{"url":"javascript:alert(1)"}}')" 'https://'
ver "dirección // (otro sitio disfrazado) → 422" "$(A PUT $M/widgets/$PG '{"definicion":{"url":"//malo.example/x"}}')" 'https://'
ver "una página no guarda registros" "$(U GET $M/datos/$PG)" 'no guarda registros'

echo "6) Catálogo de los accesos del sistema"
ver "renombrar y mover un acceso de módulo" "$(A POST $M/widgets "{\"id\":\"ordenes\",\"origen\":\"modulo\",\"nombre\":\"Ver Sábanas\",\"grupo\":\"$G\",\"orden\":5}")" '"origen":"modulo"'
ver "viaja en el catálogo con el nombre nuevo" "$(U GET $M/catalogo | js "[w['nombre'] for w in d['widgets'] if w['id']=='ordenes']")" "['Ver Sábanas']"
ver "ocultarlo" "$(A PUT $M/widgets/ordenes '{"activo":false}' | js "d['widget']['activo']")" '0'
ver "grupo con widgets no se borra" "$(A POST $M/grupos/$G/borrar '{}')" 'movelos a otro grupo'
ver "volver al original" "$(A POST $M/widgets/ordenes/borrar '{}'; U GET $M/catalogo | js "[w['id'] for w in d['widgets'] if w['id']=='ordenes']")" '[]'

echo "7) Perfiles: qué ve cada persona"
ver "sin perfil, ve todo lo de su rol (visibles = null)" "$(U GET $M/catalogo | js "d['visibles']")" 'None'
ver "alta de perfil" "$(A POST $M/perfiles "{\"id\":\"$PE\",\"nombre\":\"Movilidades $S\",\"widgets\":[\"$SB\",\"$IN\",\"solicitud\"]}")" '"ok":true'
ver "perfil inexistente → 422" "$(A PUT $M/usuarios/$UU/perfil '{"perfil":"noexiste"}')" 'No existe el perfil'
ver "usuario inexistente → 404" "$(A PUT $M/usuarios/nadie$S/perfil "{\"perfil\":\"$PE\"}")" 'No existe ese usuario'
ver "asignarlo" "$(A PUT $M/usuarios/$UU/perfil "{\"perfil\":\"$PE\"}")" '"ok":true'
C="$(U GET $M/catalogo)"
ver "ahora el catálogo le dice qué ve" "$(echo "$C" | js "sorted(d['visibles'])")" "'solicitud'"
no "la definición de lo que no ve no viaja" "$(echo "$C" | js "[w['id'] for w in d['widgets']]")" "'$F'"
ver "y el servidor no lo deja usarlo aunque sepa la dirección" "$(U GET $M/datos/$F)" 'no está habilitado para tu perfil'
ver "ni cargarle registros" "$(U POST $M/datos/$F '{"datos":{"turno":"Tarde","presion":1}}')" 'no está habilitado'
ver "lo que sí está en su perfil funciona" "$(U GET $M/datos/$SB | js "d['total']")" '4'
ver "perfil con personas no se borra" "$(A POST $M/perfiles/$PE/borrar '{}')" 'asignales otro'
ver "un perfil inactivo no restringe" "$(A PUT $M/perfiles/$PE '{"activo":false}' >/dev/null; U GET $M/catalogo | js "d['visibles']")" 'None'
A PUT $M/perfiles/$PE '{"activo":true}' >/dev/null
ver "widget desactivado: no viaja ni se usa" "$(A PUT $M/widgets/$SB '{"activo":false}' >/dev/null; U GET $M/datos/$SB)" 'no está habilitado'
A PUT $M/widgets/$SB '{"activo":true}' >/dev/null

echo "8) Exportar, importar y borrar"
X="$(A POST $M/exportar "{\"widgets\":[\"$SB\",\"$IN\",\"ordenes\"]}")"
ver "el paquete trae solo widgets creados, con su grupo" "$(echo "$X" | js "(d['paquete']['formato'],[w['id'] for w in d['paquete']['widgets']],[g['id'] for g in d['paquete']['grupos']])")" "('iproy-widgets', ['$SB', '$IN'], ['$G'])"
no "sin registros" "$X" 'Traslado a'
echo "$X" | python3 -c "import sys,json; d=json.load(sys.stdin); print(json.dumps({'paquete':d['paquete']}))" > "$T/p.json"
ver "importar sobre lo existente suma versión" "$(A POST $M/importar "$(cat "$T/p.json")"; A GET $M/admin | js "[w['version'] for w in d['widgets'] if w['id']=='$SB']")" '[2]'
ver "…y conserva los registros" "$(A GET $M/datos/$SB | js "d['total']")" '4'
ver "paquete de otro formato → 422" "$(A POST $M/importar '{"paquete":{"formato":"otro"}}')" 'no es un paquete'
ver "paquete que pisa un acceso del sistema → 409" "$(A POST $M/widgets '{"id":"stock","origen":"modulo","nombre":"Stock"}' >/dev/null; A POST $M/importar '{"paquete":{"formato":"iproy-widgets","version":1,"widgets":[{"id":"stock","tipo":"pagina","nombre":"x","definicion":{"texto":"x"}}]}}'; A POST $M/widgets/stock/borrar '{}' >/dev/null)" 'ya es un acceso de un módulo'
ver "borrar con registros pide confirmación" "$(A POST $M/widgets/$F/borrar '{}')" 'registro(s)'
A POST $M/widgets/$IN/borrar '{}' >/dev/null
ver "borrar confirmado quita el widget y sus registros" "$(A POST $M/widgets/$SB/borrar '{"con_datos":true}'; A GET $M/datos/$SB)" 'No existe ese widget'
ver "y lo saca de los perfiles" "$(A GET $M/admin | js "[p['widgets'] for p in d['perfiles'] if p['id']=='$PE']")" "[['solicitud']]"

PH="empresas-bio-$S"
ver "perfil con guion medio, como los del sistema anterior (empresas-bio)" "$(A POST $M/perfiles "{\"id\":\"$PH\",\"nombre\":\"Empresas Bio $S\",\"widgets\":[\"solicitud\"]}" | js "d['perfil']")" "$PH"
ver "mayúsculas se pasan a minúsculas (empresa_Limp)" "$(A POST $M/perfiles "{\"id\":\"Empresa_Limp$S\",\"nombre\":\"Limpieza $S\",\"widgets\":[\"solicitud\"]}" | js "d['perfil']")" "empresa_limp$S"
ver "un id de grupo sigue sin admitir guion" "$(A POST $M/grupos "{\"id\":\"g-$S\",\"nombre\":\"G $S\"}")" 'guion bajo'
A POST "$M/perfiles/$PH/borrar" '{}' >/dev/null; A POST "$M/perfiles/empresa_limp$S/borrar" '{}' >/dev/null

echo; echo "Resultado: $OK bien, $MAL mal"; [ "$MAL" -eq 0 ]
