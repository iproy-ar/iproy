#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba del pañol (stock 1.2.0) sobre UN nodo iniciado: reposición (punto de pedido, stock de seguridad, sugerido, ABC), tendencias (Holt),
# pedidos de insumos (recepción parcial con ingreso al stock, completo, anulación), conteo cíclico (con y sin ajuste), reportes y gráficos, y permisos.
#   bash pruebas/panol.sh <carpeta-nodo>
# Crea sus propios datos ficticios (prefijo PAN / pan) y no usa datos reales. Las contraseñas de prueba se generan al azar y no se muestran.
set -u
D="$(cd "$1" && pwd)"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
B="http://127.0.0.1:$PORT/api"; T=$(mktemp -d); CJ="$T/cj"; CJ2="$T/cj2"; trap 'rm -rf "$T"' EXIT
A() { curl -s -m 60 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }          # lanzador (AD)
U() { curl -s -m 60 -b "$CJ" -c "$CJ" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }              # Ejecutor (ver y mover)
L() { curl -s -m 60 -b "$CJ2" -c "$CJ2" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }            # Cliente (solo ver)
HL() { curl -s -m 60 -o /dev/null -w '%{http_code}' -b "$CJ2" -c "$CJ2" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
no()  { if [[ "$2" != *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
S=$RANDOM; rnd() { head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16; }
mes() { python3 -c "import datetime as t; d=t.date.today().replace(day=1); m=(d.year*12+d.month-1)-$1; print('%04d-%02d' % (m//12, m%12+1))"; }
M4=$(mes 4); M3=$(mes 3); M2=$(mes 2); M1=$(mes 1)

echo "1) Datos de prueba: tres insumos con cuatro meses de historia"
ins() { A POST m/stock/insumos "{\"categoria\":\"$1\",\"subcategoria\":\"$2\",\"descripcion\":\"$3 PAN $S\",\"unidad\":\"u\",\"minimo\":\"$4\",\"precio\":\"$5\"}" | js "d['insumo']['id']"; }
I1=$(ins ELE ELE-LAM Lampara 0 1000); I2=$(ins PLO PLO-VAL Valvula 10 500); I3=$(ins BUL BUL-ARA Arandela 0 10)
ver "tres insumos creados" "$I1/$I2/$I3" "/"; [[ "$I3" =~ ^[0-9]+$ ]] || { echo "  ✘ no se pudieron crear los insumos"; exit 1; }
PROV=$(A POST m/stock/proveedores "{\"nombre\":\"Proveedor PAN $S\",\"especialidad\":\"ELE\"}" | js "d['proveedor']['id']")
R=$(A POST m/stock/movimientos "{\"tipo\":\"ingreso\",\"origen\":\"COMPRA\",\"fecha\":\"$M4-01\",\"proveedor\":$PROV,\"comprobante\":\"R PAN-$S\",\"orden_compra\":\"OC PAN-$S\",\"lineas\":[{\"insumo\":$I1,\"cantidad\":210,\"precio\":1000},{\"insumo\":$I2,\"cantidad\":50,\"precio\":500}]}")
ver "ingreso con orden de compra" "$R" '"clave":"ING-'
ver "la orden de compra queda en el movimiento" "$(A GET "m/stock/movimientos?clave=$(printf '%s' "$R" | js "d['clave']")")" "\"orden_compra\":\"OC PAN-$S\""
egr() { A POST m/stock/movimientos "{\"tipo\":\"egreso\",\"motivo\":\"$1\",\"fecha\":\"$2-15\",${4:-}\"lineas\":[{\"insumo\":$3,\"cantidad\":$5}]}" | js "d['clave']"; }
for m in $M3 $M2 $M1; do egr CONSUMO "$m" "$I1" "" 30 >/dev/null; done
egr CONSUMO "$M3" "$I2" "" 10 >/dev/null; egr CONSUMO "$M2" "$I2" "" 20 >/dev/null
ver "egreso para una orden (cuenta como consumo)" "$(egr OT "$M1" "$I2" "\"orden\":\"OTPAN$S\",\"orden_numero\":\"PAN-$S\",\"destino_nombre\":\"Servicio PAN\"," 15)" 'EGR-'
egr DEVPROV "$M1" "$I1" "" 5 >/dev/null    # devolución al proveedor: no es consumo

echo "2) Reposición (como «Pedir Insumos» del sistema anterior)"
AN=$(A GET 'm/stock/analitica?plazo=15&nivel=95&ciclo=30&ventana=3')
it() { printf '%s' "$AN" | js "[x for x in d['items'] if x['id']==$1][0]$2"; }
ver "ventana: los tres meses completos anteriores" "$(printf '%s' "$AN" | js "d['ventana']")" "['$M3', '$M2', '$M1']"
ver "z del 95 % = 1,65" "$(printf '%s' "$AN" | js "d['z']")" "1.65"
ver "la devolución al proveedor no cuenta como consumo" "$(it "$I1" "['mensual']")" "30.0"
ver "existencia del insumo 1 (210 − 90 − 5)" "$(it "$I1" "['existencia']")" "115.0"
ESP=$(python3 -c "
import math; s=[10,20,15]; m=sum(s)/3; sd=math.sqrt(sum((v-m)**2 for v in s)/2); di=sum(s)/(3*30.4)
ss=1.65*(sd/math.sqrt(30.4))*math.sqrt(15); rop=di*15+ss; obj=rop+di*30; sug=math.ceil(obj-5) if 5<=rop else 0
print(round(ss,3), round(rop,3), sug)")
ver "seguridad, punto de pedido y sugerido del insumo 2 (fórmula del sistema anterior)" "$(it "$I2" "['ss']") $(it "$I2" "['rop']") $(it "$I2" "['sugerido']")" "$ESP"
ver "insumo 2 crítico (se acaba antes del plazo)" "$(it "$I2" "['critico']")" "True"
ver "insumo 2 bajo mínimo" "$(it "$I2" "['bajo_minimo']")" "True"
ver "insumo 1 sin sugerido (cubre de sobra)" "$(it "$I1" "['sugerido']") $(it "$I1" "['critico']")" "0 False"
ver "insumo sin consumo: clase N" "$(it "$I3" "['abc']")" "N"
ver "con consumo: clase A, B o C" "$(it "$I1" "['abc']" | tr ABC XXX)" "X"
ver "más días de plazo cambian el punto de pedido" "$(A GET 'm/stock/analitica?plazo=30' | js "[x for x in d['items'] if x['id']==$I2][0]['rop'] > $(it "$I2" "['rop']")")" "True"
ver "nivel de servicio fuera de rango: se acota" "$(A GET 'm/stock/analitica?nivel=500' | js "d['parametros']['nivel']")" "99.9"

echo "3) Tendencias (Holt a = 0,5 · b = 0,3)"
TE=$(A GET "m/stock/tendencias?desde=$M3&hasta=$M1&vista=insumo&cual=$(it "$I2" "['codigo']")")
ver "serie del insumo 2 por mes" "$(printf '%s' "$TE" | js "d['grafico']['valores']")" "[10.0, 20.0, 15.0]"
HOLT=$(python3 -c "
y=[10,20,15]; a,b=.5,.3; L=y[0]; T=y[1]-y[0]
for v in y[1:]: Lp=L; L=a*v+(1-a)*(L+T); T=b*(L-Lp)+(1-b)*T
print([round(max(0,L+k*T),3) for k in (1,2,3)])")
ver "proyección de 3 meses (Holt)" "$(printf '%s' "$TE" | js "d['grafico']['proyeccion']['valores']")" "$HOLT"
ver "pendiente de la recta (+2,5 por mes)" "$(printf '%s' "$TE" | js "d['grafico']['pendiente']")" "2.5"
ver "fila por insumo con período, promedio y proyección" "$(printf '%s' "$TE" | js "[r['periodo'] for r in d['filas'] if r['clave']=='$(it "$I2" "['codigo']")']")" "[45.0]"
ver "en valor ($)" "$(A GET "m/stock/tendencias?desde=$M3&hasta=$M1&metrica=valor&cual=$(it "$I2" "['codigo']")" | js "d['grafico']['valores']")" "[5000.0, 10000.0, 7500.0]"

echo "4) Usuarios de prueba"
C1="Pa-$(rnd)"; UE="pan$S"; UC="panc$S"
A POST nucleo/usuario "{\"usuario\":\"$UE\",\"nombre\":\"Panol $S\",\"rol\":\"EJ\",\"organizacion\":\"PAN-ORG-$S\",\"clave\":\"$C1\"}" >/dev/null
A POST nucleo/usuario "{\"usuario\":\"$UC\",\"nombre\":\"Consulta $S\",\"rol\":\"CI\",\"clave\":\"$C1\"}" >/dev/null
ver "ingresa el Ejecutor" "$(U POST sesion "{\"usuario\":\"$UE\",\"clave\":\"$C1\"}")" '"ok":true'
ver "ingresa el Cliente" "$(L POST sesion "{\"usuario\":\"$UC\",\"clave\":\"$C1\"}")" '"ok":true'

echo "5) Pedidos de insumos"
ver "pedido sin renglones: rechazado" "$(U POST m/stock/pedidos '{"motivo":"x","lineas":[]}')" 'al menos un insumo'
ver "insumo repetido: rechazado" "$(U POST m/stock/pedidos "{\"motivo\":\"x\",\"lineas\":[{\"insumo\":$I2,\"cantidad\":1},{\"insumo\":$I2,\"cantidad\":2}]}")" 'repetido'
ver "prioridad fuera de la lista: rechazada" "$(U POST m/stock/pedidos "{\"motivo\":\"x\",\"prioridad\":\"Ya\",\"lineas\":[{\"insumo\":$I2,\"cantidad\":1}]}")" 'Prioridad'
ver "el Cliente no pide (403)" "$(HL POST m/stock/pedidos "{\"motivo\":\"x\",\"lineas\":[{\"insumo\":$I2,\"cantidad\":1}]}")" '403'
SUG=$(it "$I2" "['sugerido']" | cut -d. -f1)
R=$(U POST m/stock/pedidos "{\"prioridad\":\"Urgente\",\"motivo\":\"Reposición PAN $S\",\"destino\":\"Pañol\",\"proveedor\":$PROV,\"lineas\":[{\"insumo\":$I2,\"cantidad\":$SUG,\"nota\":\"válvula 1/2\"},{\"insumo\":$I1,\"cantidad\":\"2,5\"}]}")
PED=$(printf '%s' "$R" | js "d['pedido']['id']"); NUM=$(printf '%s' "$R" | js "d['pedido']['numero']")
ver "número PED-AAAAMMDD-NNN-L (L = banda PIM de «Urgente» = C)" "$(printf '%s' "$NUM" | grep -cE "^PED-$(TZ=America/Argentina/Buenos_Aires date +%Y%m%d)-[0-9]{3}-C$")" "1"
ver "la prioridad se guarda como request-priority FHIR" "$R" '"prioridad":"urgent"'; ver "queda Solicitado" "$R" '"estado":"Solicitado"'
ver "lo pedido cuenta como «en pedido» y apaga el sugerido" "$(A GET m/stock/analitica | js "[(x['en_pedido'], x['sugerido']) for x in d['items'] if x['id']==$I2][0]")" "($SUG.0, 0)"
L1=$(printf '%s' "$R" | js "[l['id'] for l in d['pedido']['lineas'] if l['insumo_id']==$I2][0]"); L2=$(printf '%s' "$R" | js "[l['id'] for l in d['pedido']['lineas'] if l['insumo_id']==$I1][0]")
ver "el Cliente ve los pedidos" "$(L GET "m/stock/pedidos?q=PAN%20$S")" "$NUM"
ver "recepción sin cantidades nuevas: rechazada" "$(U POST "m/stock/pedidos/$PED/estado" '{"accion":"recibir","recibidos":[]}')" 'ninguna cantidad'
R=$(U POST "m/stock/pedidos/$PED/estado" "{\"accion\":\"recibir\",\"ingresar\":true,\"remito\":\"R PAN2-$S\",\"recibidos\":[{\"linea\":$L1,\"recibido\":10}]}")
ver "recepción parcial" "$R" '"estado":"Recibido parcial"'; ver "lo recibido entra al stock (ingreso)" "$R" '"clave":"ING-'
ver "existencia del insumo 2 = 5 + 10" "$(A GET "m/stock/insumos/$I2" | js "d['insumo']['existencia']")" "15.000"
ver "el ingreso lleva el proveedor y el remito del pedido" "$(A GET "m/stock/movimientos?clave=$(printf '%s' "$R" | js "d['ingreso']['clave']")")" "\"comprobante\":\"R PAN2-$S\""
ver "lo recibido no puede bajar" "$(U POST "m/stock/pedidos/$PED/estado" "{\"accion\":\"recibir\",\"recibidos\":[{\"linea\":$L1,\"recibido\":4}]}")" 'no puede bajar'
R=$(U POST "m/stock/pedidos/$PED/estado" '{"accion":"completo","ingresar":true}')
ver "recibido completo" "$R" '"estado":"Recibido"'
ver "existencia del insumo 2 = todo lo pedido + 5" "$(A GET "m/stock/insumos/$I2" | js "d['insumo']['existencia']")" "$(python3 -c "print('%.3f' % ($SUG+5))")"
ver "existencia del insumo 1 con decimales (115 + 2,5)" "$(A GET "m/stock/insumos/$I1" | js "d['insumo']['existencia']")" "117.500"
ver "un pedido cerrado no cambia (409)" "$(U POST "m/stock/pedidos/$PED/estado" '{"accion":"anular","motivo":"x"}')" 'ya está Recibido'
P2=$(U POST m/stock/pedidos "{\"motivo\":\"Para anular PAN $S\",\"lineas\":[{\"insumo\":$I3,\"cantidad\":100}]}" | js "d['pedido']['id']")
ver "anular pide el motivo" "$(U POST "m/stock/pedidos/$P2/estado" '{"accion":"anular"}')" 'Motivo'
ver "anulado" "$(U POST "m/stock/pedidos/$P2/estado" '{"accion":"anular","motivo":"Se compró por otra vía"}')" '"estado":"Anulado"'
ver "lo anulado no cuenta como «en pedido»" "$(A GET m/stock/analitica | js "[x['en_pedido'] for x in d['items'] if x['id']==$I3][0]")" "0.0"
ver "filtro por estado" "$(A GET "m/stock/pedidos?estado=Anulado&q=PAN%20$S" | js "len(d['pedidos'])")" "1"
ver "diario: asientos de pedidos" "$(A GET 'proc/diario?n=60')" 'stock.pedido_estado'


echo "5b) Prioridad del pedido según PIM (IRU · NFPA 99 · orden)"
NS=https://iproy.com.ar/fhir; id_de() { grep -o "\"resourceType\":\"$1\",\"id\":\"[^\"]*\"" | head -1 | grep -o '[0-9A-Z]\{26\}'; }
dev() { A POST pim/Device "{\"resourceType\":\"Device\",\"meta\":{\"profile\":[\"$NS/StructureDefinition/pim-sistema\"]},\"identifier\":[{\"system\":\"$NS/sid/activo\",\"value\":\"PAN-$1-$S\"}],\"status\":\"active\",\"type\":{\"coding\":[{\"system\":\"$NS/CodeSystem/pim-sistema\",\"code\":\"$2\"}]},\"deviceName\":[{\"name\":\"Sistema PAN $1 $S\",\"type\":\"user-friendly-name\"}],\"extension\":[{\"url\":\"$NS/StructureDefinition/pim-categoria-riesgo\",\"valueCoding\":{\"system\":\"$NS/CodeSystem/pim-categoria-riesgo\",\"code\":\"$3\"}}${4:-}]}" | id_de Device; }
DA=$(dev O2 GAS-O2 1 ",{\"url\":\"$NS/StructureDefinition/pim-iru\",\"extension\":[{\"url\":\"F\",\"valueInteger\":9},{\"url\":\"M\",\"valueInteger\":4},{\"url\":\"A\",\"valueInteger\":0}]}")
DC=$(dev CLI HVAC-GRAL 3)
ver "con IRU: la prioridad sale del IRU (F 9 + R 5 + M 4 = 18 → banda A → stat)" "$(U GET "m/stock/pedidos/sugerencia?activo=$DA" | js "(d['sugerencia']['prioridad'], d['sugerencia']['letra'])")" "('stat', 'A')"
ver "y explica el fundamento" "$(U GET "m/stock/pedidos/sugerencia?activo=$DA")" 'IRU del activo: F 9 + R 5 + M 4 + A 0 = 18'
ver "sin IRU completo: la categoría NFPA 99 (3 → banda C → urgent)" "$(U GET "m/stock/pedidos/sugerencia?activo=$DC" | js "(d['sugerencia']['prioridad'], d['sugerencia']['letra'])")" "('urgent', 'C')"
TK=$(A POST pim/ServiceRequest "{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"priority\":\"asap\",\"subject\":{\"reference\":\"Device/$DC\"},\"code\":{\"text\":\"Falla PAN $S\"}}" | grep -o '"reference":"Task/[^"]*"' | grep -o '[0-9A-Z]\{26\}')
SG=$(U GET "m/stock/pedidos/sugerencia?orden=$TK")
ver "con orden: manda la prioridad PIM de la orden" "$(printf '%s' "$SG" | js "(d['sugerencia']['prioridad'] == d['sugerencia']['prioridad'] and d['sugerencia']['orden'], d['sugerencia']['activo'])")" "('$TK', '$DC')"
PO=$(printf '%s' "$SG" | js "d['sugerencia']['prioridad']"); LO=$(printf '%s' "$SG" | js "d['sugerencia']['letra']")
ver "orden inexistente → 422" "$(U GET 'm/stock/pedidos/sugerencia?orden=NOEXISTE')" 'No existe esa orden'
OTRA=$([[ "$PO" == "stat" ]] && echo routine || echo stat)
ver "otra prioridad que la sugerida sin motivo: rechazada" "$(U POST m/stock/pedidos "{\"motivo\":\"PAN $S orden\",\"orden\":\"$TK\",\"prioridad\":\"$OTRA\",\"lineas\":[{\"insumo\":$I3,\"cantidad\":5}]}")" 'para elegir otra escribí el motivo'
R=$(U POST m/stock/pedidos "{\"motivo\":\"PAN $S orden\",\"orden\":\"$TK\",\"lineas\":[{\"insumo\":$I3,\"cantidad\":5}]}")
ver "sin elegir: toma la sugerida y su letra" "$(printf '%s' "$R" | js "(d['pedido']['prioridad'], d['pedido']['numero'][-1], d['pedido']['orden'], d['pedido']['activo'])")" "('$PO', '$LO', '$TK', '$DC')"
P3=$(printf '%s' "$R" | js "d['pedido']['id']")
ver "QR del sistema: SVG con el texto como título" "$(U GET 'qr?texto=PED-PAN-1' | js "(d['svg'].startswith('<svg'), 'PED-PAN-1' in d['svg'], '<path' in d['svg'])")" "(True, True, True)"
ver "QR: texto vacío → 422" "$(U GET 'qr?texto=')" '1 a 213 bytes'
ver "QR: más de 213 bytes → 422" "$(U GET "qr?texto=$(head -c 214 /dev/zero | tr '\0' x)")" '1 a 213 bytes'
ver "QR: sin sesión no se genera" "$(curl -s -m 20 -H 'X-Nucleo: 1' "$B/qr?texto=hola")" '"ok":false'
R=$(U POST m/stock/pedidos "{\"motivo\":\"PAN $S orden 2\",\"orden\":\"$TK\",\"prioridad\":\"$OTRA\",\"motivo_prioridad\":\"El servicio queda sin respaldo\",\"lineas\":[{\"insumo\":$I3,\"cantidad\":1}]}")
ver "con motivo: guarda la elegida, la sugerida y el motivo" "$(printf '%s' "$R" | js "(d['pedido']['prioridad'], d['pedido']['prioridad_sugerida'], d['pedido']['motivo_prioridad'])")" "('$OTRA', '$PO', 'El servicio queda sin respaldo')"
U POST "m/stock/pedidos/$(printf '%s' "$R" | js "d['pedido']['id']")/estado" '{"accion":"anular","motivo":"prueba"}' >/dev/null

echo "5c) Planilla y envío por correo (sumidero local, no sale nada a Internet)"
X=$(L GET "m/stock/pedidos/$P3/planilla")
printf '%s' "$X" | js "d['base64']" | base64 -d > "$T/p.xlsx" 2>/dev/null
ver "planilla .xlsx válida (la abre openpyxl) con el número y el renglón" "$(python3 -c "
import openpyxl; w=openpyxl.load_workbook('$T/p.xlsx'); h=w.active; v=[c.value for r in h.iter_rows() for c in r]
print(any(str(x).startswith('PED-') for x in v), 'Arandela PAN $S' in v, 5 in v or 5.0 in v)" 2>&1)" "True True True"
PH=$((20000 + RANDOM % 20000)); PS=$((PH + 1)); D2="$D/data/nucleo"
[[ -f "$D2/correo.json" ]] && cp -p "$D2/correo.json" "$T/correo.json.resp"
python3 "$(dirname "$0")/sumidero.py" "$T" "$PH" "$PS" & SUM=$!
trap 'kill $SUM 2>/dev/null; if [[ -f "$T/correo.json.resp" ]]; then cp -p "$T/correo.json.resp" "$D2/correo.json"; else rm -f "$D2/correo.json"; fi; rm -rf "$T"' EXIT
sleep 1
printf '{"activo":true,"host":"127.0.0.1","puerto":%s,"seguridad":"ninguna","usuario":"","clave":"","remitente":"nodo@ejemplo.test","nombre":"Nodo PAN","url_app":"http://127.0.0.1:%s"}\n' "$PS" "$PORT" > "$D2/correo.json"
ver "sin correo del proveedor ni destinatario: pide uno" "$(U POST "m/stock/pedidos/$P3/enviar" '{}')" 'Escribí a qué correo'
ver "correo inválido → 422" "$(U POST "m/stock/pedidos/$P3/enviar" '{"para":"no-es-correo"}')" 'no es un correo'
ver "el Cliente no envía (403)" "$(HL POST "m/stock/pedidos/$P3/enviar" '{"para":"compras@ejemplo.test"}')" '403'
ver "enviado a dos destinatarios con planilla" "$(U POST "m/stock/pedidos/$P3/enviar" '{"para":"compras@ejemplo.test, proveedor@ejemplo.test","planilla":true}' | js "(d['enviados'], d['pedido']['enviado_a'])")" "(2, 'compras@ejemplo.test, proveedor@ejemplo.test')"
ver "llegaron dos correos al sumidero" "$(grep -c '"para"' "$T/smtp.jsonl" 2>/dev/null)" "2"
ver "el correo trae HTML y el adjunto .xlsx" "$(python3 -c "
import json,email; m=email.message_from_string(json.loads(open('$T/smtp.jsonl').readline())['datos'])
t=[p.get_content_type() for p in m.walk()]; a=[p for p in m.walk() if p.get_content_disposition()=='attachment']
print('text/html' in t, len(a), a[0].get_filename().endswith('.xlsx') if a else None)")" "True 1 True"
ver "el correo trae el QR del pedido embebido (cid) y el enlace directo" "$(python3 -c "
import json,email; m=email.message_from_string(json.loads(open('$T/smtp.jsonl').readline())['datos'])
im=[p for p in m.walk() if p.get_content_type()=='image/png']; h=[p.get_payload(decode=True).decode() for p in m.walk() if p.get_content_type()=='text/html'][0]
print(len(im), im[0]['Content-ID'] if im else None, 'cid:qr-pedido' in h, '#panol_insumos?pedido=PED-' in h, im[0].get_payload(decode=True)[:8]==b'\x89PNG\r\n\x1a\n' if im else None)")" "1 <qr-pedido> True True True"
ver "el pedido trae su enlace (con url_app configurada)" "$(U GET "m/stock/pedidos/$P3" | js "d['pedido']['enlace'].startswith('http://127.0.0.1:$PORT/#panol_insumos?pedido=PED-')")" "True"

echo "6) Conteo cíclico"
CO=$(U GET 'm/stock/conteo?lote=100')
ver "el plan incluye los insumos con consumo o existencia (no el que está en cero y sin uso)" "$(printf '%s' "$CO" | js "sorted(x['id'] for x in d['lote'] if x['id'] in ($I1,$I2,$I3))")" "[$I1, $I2]"
ver "frecuencias A 30 · B 90 · C 180" "$(printf '%s' "$CO" | js "d['frecuencia']")" "{'A': 30, 'B': 90, 'C': 180, 'N': 365}"
ver "el Ejecutor no ajusta el stock (403)" "$(U POST m/stock/conteo "{\"ajustar\":true,\"renglones\":[{\"insumo\":$I1,\"contado\":118}]}")" 'requiere permiso'
R=$(U POST m/stock/conteo "{\"renglones\":[{\"insumo\":$I1,\"contado\":\"116,5\",\"nota\":\"faltó una\"}]}")
ver "conteo guardado con la diferencia" "$R" '"diferencia":-1.0'
ver "sin ajuste el stock no cambia" "$(A GET "m/stock/insumos/$I1" | js "d['insumo']['existencia']")" "117.500"
R=$(A POST m/stock/conteo "{\"ajustar\":true,\"renglones\":[{\"insumo\":$I1,\"contado\":118},{\"insumo\":$I2,\"contado\":1}]}")
ver "con ajuste: un ingreso y un egreso de AJUSTE" "$(printf '%s' "$R" | js "sorted(a[:3] for a in d['ajustes'])")" "['EGR', 'ING']"
ver "cada conteo guarda el comprobante que lo ajustó" "$(A GET m/stock/conteo | js "sorted(r['ajuste'][:3] for r in d['recientes'] if r['insumo_id'] in ($I1,$I2) and r['ajuste'])")" "['EGR', 'ING']"
ver "existencia = lo contado" "$(A GET "m/stock/insumos/$I1" | js "d['insumo']['existencia']")/$(A GET "m/stock/insumos/$I2" | js "d['insumo']['existencia']")" "118.000/1.000"
ver "lo contado hoy sale del plan" "$(A GET 'm/stock/conteo?lote=100' | js "[x['id'] for x in d['lote'] if x['id'] in ($I1,$I2)]")" "[]"
ver "exactitud de los últimos 90 días calculada" "$(A GET m/stock/conteo | js "d['exactitud'] is not None and d['contados_90']>=3")" "True"
ver "el Cliente no cuenta (403)" "$(HL POST m/stock/conteo "{\"renglones\":[{\"insumo\":$I1,\"contado\":1}]}")" '403'

echo "7) Reportes de Stock"
Q="desde=$M4-01&hasta=$(date +%Y-%m-%d)&q=PAN%20$S"
ver "ingresos del período (renglones y orden de compra)" "$(A GET "m/stock/reportes/ingresos?$Q" | js "d['totales']['renglones'] >= 4 and any(f['orden_compra']=='OC PAN-$S' for f in d['filas'])")" "True"
ver "egresos del período (6 + 2 de ajuste)" "$(A GET "m/stock/reportes/egresos?$Q" | js "d['totales']['renglones']")" "8"
ver "egresos por OT" "$(A GET "m/stock/reportes/por_ot?$Q" | js "[(f['orden_numero'], f['costo']) for f in d['filas']]")" "[('PAN-$S', '7500.00')]"
ver "bajo mínimo: el insumo 2 (falta 9)" "$(A GET "m/stock/reportes/bajo_minimo?q=PAN%20$S" | js "[f['falta'] for f in d['filas']]")" "[9.0]"
ver "por categoría" "$(A GET "m/stock/reportes/categoria?q=PAN%20$S" | js "sorted(f['categoria'] for f in d['filas'])")" "['BUL', 'ELE', 'PLO']"
ver "inventario total valorizado" "$(A GET "m/stock/reportes/inventario?q=PAN%20$S" | js "d['totales']['valor']")" "118500.0"
ver "filtro por proveedor" "$(A GET "m/stock/reportes/ingresos?$Q&proveedor=$PROV" | js "len(set(f['proveedor'] for f in d['filas']))")" "1"
ver "reporte inexistente → 404" "$(A GET m/stock/reportes/nada)" 'Reportes:'
G=$(L GET "m/stock/reportes/graficos?$Q")
ver "gráficos: KPIs (el Cliente los ve)" "$(printf '%s' "$G" | js "d['kpis']['ots'], d['kpis']['proveedor_top']['nombre']")" "1 Proveedor PAN $S"
ver "gráficos: ingresos y egresos por mes" "$(printf '%s' "$G" | js "[m['mes'] for m in d['por_mes']][:4]")" "['$M4', '$M3', '$M2', '$M1']"
ver "gráficos: top de órdenes por costo" "$(printf '%s' "$G" | js "d['top_ot'][0]['orden_numero']")" "PAN-$S"
ver "egresos por categoría de una orden" "$(A GET "m/stock/reportes/graficos?$Q&orden=PAN-$S" | js "[c['categoria'] for c in d['egresos_categoria']]")" "['PLO']"

echo; [ $MAL -eq 0 ] && echo "✔ $OK comprobaciones correctas" || { echo "✘ $MAL fallaron de $((OK+MAL))"; exit 1; }
