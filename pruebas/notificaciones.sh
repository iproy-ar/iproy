#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba del módulo notificaciones (1.7.0) sobre UN nodo iniciado: reglas, condiciones, cola, reintentos, silencio y los tres canales
# (correo HTML, Telegram y webhook) contra un sumidero local (pruebas/sumidero.py): no sale ningún mensaje a Internet.
#   bash pruebas/notificaciones.sh <carpeta-nodo>
# Guarda y repone la configuración de correo y los secretos del módulo que tuviera el nodo. Datos ficticios (prefijo NTF / ntf).
set -u
D="$(cd "$1" && pwd)"; AQUI="$(cd "$(dirname "$0")" && pwd)"
env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
B="http://127.0.0.1:$PORT/api"; T=$(mktemp -d); CJ="$T/cj"
PH=$((20000 + RANDOM % 9000)); PS=$((PH + 1)); S=$RANDOM
A() { curl -s -m 90 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
U() { curl -s -m 60 -b "$CJ" -c "$CJ" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
no()  { if [[ "$2" != *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
tick() { A POST nucleo/tick >/dev/null; }
enviar() { A POST tareas/notificaciones:enviar/ejecutar; }
rnd() { head -c 30 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"; }
cuenta() { [[ -f "$T/$1" ]] && grep -c "$2" "$T/$1" || echo 0; }

# --- preparación: sumidero local y configuración de prueba (se repone al salir)
DM="$D/data/nucleo/modulos"
mkdir -p "$DM" "$T/resp"
for f in "$D/data/nucleo/correo.json" "$DM/notificaciones.json" "$DM/notificaciones.secretos.json"; do [[ -f "$f" ]] && cp -p "$f" "$T/resp/$(basename "$f")"; done
python3 "$AQUI/sumidero.py" "$T" "$PH" "$PS" & SUM=$!
reponer() { kill "$SUM" 2>/dev/null
  for f in "$D/data/nucleo/correo.json" "$DM/notificaciones.json" "$DM/notificaciones.secretos.json"; do if [[ -f "$T/resp/$(basename "$f")" ]]; then cp -p "$T/resp/$(basename "$f")" "$f"; else rm -f "$f"; fi; done
  for r in $(A GET m/notificaciones/reglas | python3 -c "import sys,json; print(' '.join(str(r['id']) for r in json.load(sys.stdin)['reglas'] if r['nombre'].startswith('NTF-$S')))" 2>/dev/null); do A POST "m/notificaciones/reglas/$r/borrar" '{}' >/dev/null; done
  for r in ${OTRAS:-}; do A PUT "m/notificaciones/reglas/$r" '{"activa":true}' >/dev/null; done
  for u in "${UE:-}" "${UC:-}"; do [[ -n "$u" ]] && A PUT "usuarios/$u" '{"activo":0}' >/dev/null; done
  rm -rf "$T"; }
trap reponer EXIT; [[ -n "${NTF_GUARDAR:-}" ]] && trap "cp $T/*.jsonl $NTF_GUARDAR/ 2>/dev/null; reponer" EXIT
sleep 1
# las reglas que ya tuviera el nodo se pausan durante la prueba (para contar solo las propias) y se reactivan al salir
OTRAS=$(A GET m/notificaciones/reglas | python3 -c "import sys,json; print(' '.join(str(r['id']) for r in json.load(sys.stdin)['reglas'] if r['activa']))" 2>/dev/null)
for r in $OTRAS; do A PUT "m/notificaciones/reglas/$r" '{"activa":false}' >/dev/null; done
A POST nucleo/tick >/dev/null; A POST tareas/notificaciones:enviar/ejecutar >/dev/null   # lo que estuviera en cola sale antes de empezar a contar
printf '{"activo":true,"host":"127.0.0.1","puerto":%s,"seguridad":"ninguna","usuario":"","clave":"","remitente":"nodo@ejemplo.test","nombre":"Nodo de prueba","url_app":"http://127.0.0.1:%s"}\n' "$PS" "$PORT" > "$D/data/nucleo/correo.json"
A PUT modulos/notificaciones/config "{\"config\":{\"telegram_api\":\"http://127.0.0.1:$PH\",\"reintentos_min\":[0,0,0,0],\"max_intentos\":3}}" >/dev/null

echo "1) Fuentes, validaciones y canales"
R=$(A GET m/notificaciones/fuentes)
ver "fuente: alarmas" "$R" '"tema":"alarmas/cambio"'; ver "fuente: orden que cambia de estado" "$R" '"tema":"pim/Task/update"'
regla() { A POST m/notificaciones/reglas "$1"; }
ver "correo inválido → 422" "$(regla "{\"nombre\":\"NTF-$S x\",\"tema\":\"alarmas/cambio\",\"canal\":\"correo\",\"destino\":\"no-es-correo\"}")" 'no es un correo'
ver "tema desconocido → 422" "$(regla "{\"nombre\":\"NTF-$S x\",\"tema\":\"otro/tema\",\"canal\":\"correo\",\"destino\":\"a@ejemplo.test\"}")" '"ok":false'
ver "valor de condición inválido → 422" "$(regla "{\"nombre\":\"NTF-$S x\",\"tema\":\"alarmas/cambio\",\"condicion\":{\"evento\":[\"explota\"]},\"canal\":\"correo\",\"destino\":\"a@ejemplo.test\"}")" 'inválido'
ver "chat de Telegram inválido → 422" "$(regla "{\"nombre\":\"NTF-$S x\",\"tema\":\"alarmas/cambio\",\"canal\":\"telegram\",\"destino\":\"juan\"}")" 'Telegram'
ver "webhook que no es http → 422" "$(regla "{\"nombre\":\"NTF-$S x\",\"tema\":\"alarmas/cambio\",\"canal\":\"webhook\",\"destino\":\"ftp://x/y\"}")" 'http'
ver "token de bot mal formado → 422" "$(A PUT m/notificaciones/config '{"telegram_token":"cualquiera"}')" 'BotFather'
TG="$((100000 + RANDOM)):$(rnd 35)"; SEC="$(rnd 32)"
R=$(A PUT m/notificaciones/config "{\"telegram_token\":\"$TG\",\"webhook_secreto\":\"$SEC\"}")
ver "token y clave de firma cargados" "$R" '"telegram":{"listo":true}'; ver "webhook firmado" "$R" '"firmado":true'
no "la API del módulo no devuelve el token" "$(A GET m/notificaciones/config)$(A GET m/notificaciones/resumen)" "${TG#*:}"
no "la configuración que ve el administrador tampoco" "$(A GET modulos/notificaciones/config)" "${TG#*:}"
ver "el archivo de secretos es solo del dueño" "$(stat -c %a "$DM/notificaciones.secretos.json")" '600'

echo "2) Alarma → webhook firmado, correo HTML y Telegram"
RW=$(regla "{\"nombre\":\"NTF-$S webhook\",\"tema\":\"alarmas/cambio\",\"condicion\":{\"evento\":[\"activada\"],\"prioridad\":[\"1\",\"2\"]},\"canal\":\"webhook\",\"destino\":\"http://127.0.0.1:$PH/gancho-$S\"}" | js "d['regla']['id']")
RC=$(regla "{\"nombre\":\"NTF-$S correo\",\"tema\":\"alarmas/cambio\",\"condicion\":{\"evento\":[\"activada\"],\"texto\":\"NTF-$S\"},\"canal\":\"correo\",\"destino\":\"guardia@ejemplo.test, jefe@ejemplo.test\",\"silencio_min\":60}" | js "d['regla']['id']")
RT=$(regla "{\"nombre\":\"NTF-$S telegram\",\"tema\":\"alarmas/cambio\",\"condicion\":{\"evento\":[\"activada\",\"retorno\"]},\"canal\":\"telegram\",\"destino\":\"-100$S\"}" | js "d['regla']['id']")
RP=$(regla "{\"nombre\":\"NTF-$S solo criticas\",\"tema\":\"alarmas/cambio\",\"condicion\":{\"prioridad\":[\"1\"]},\"canal\":\"webhook\",\"destino\":\"http://127.0.0.1:$PH/criticas-$S\"}" | js "d['regla']['id']")
ver "cuatro reglas creadas" "$RW/$RC/$RT/$RP" "$RW/$RC/$RT/$RP"; [[ "$RW" =~ ^[0-9]+$ && "$RP" =~ ^[0-9]+$ ]] || { echo "  ✘ no se pudieron crear las reglas"; exit 1; }
ver "la regla describe cuándo avisa" "$(A GET m/notificaciones/reglas)" 'Alarma: Se activó · 1 · Crítica o 2 · Alta'
COD="NTF-$S"
A POST pim/Device "{\"resourceType\":\"Device\",\"identifier\":[{\"system\":\"https://iproy.com.ar/fhir/sid/activo\",\"value\":\"$COD\"}],\"deviceName\":[{\"name\":\"Compresor NTF $S\",\"type\":\"user-friendly-name\"}],\"status\":\"active\"}" >/dev/null
DEV=$(A GET "pim/Device?identifier=$COD" | grep -o '"resourceType":"Device","id":"[^"]*"' | head -1 | grep -o '[0-9A-Z]\{26\}')
A POST m/alarmas/definiciones "{\"etiqueta\":\"NTF-$S-H\",\"activo_id\":\"$DEV\",\"punto\":\"PRES-LINEA\",\"tipo\":\"alta\",\"limite\":800,\"banda\":20,\"prioridad\":2,\"mensaje\":\"Presión alta en línea Ñ\"}" >/dev/null
tick   # las suscripciones nuevas empiezan a leer desde acá
med() { A POST m/telemetria/medicion "{\"activo\":\"$COD\",\"punto\":\"PRES-LINEA\",\"valor\":$1,\"unidad\":\"kPa\"}" >/dev/null; tick; tick; tick; tick; tick; }
med 850
R=$(A GET "m/notificaciones/envios?q=NTF-$S-H")
ver "la activación dejó avisos en cola" "$R" '"estado":"pendiente"'
ver "uno por destinatario (4: webhook, 2 correos, telegram)" "$(printf '%s' "$R" | js "len(d['envios'])")" '4'
no "la regla de solo críticas no se disparó (prioridad 2)" "$R" 'solo criticas'
ver "tarea enviar" "$(enviar)" '4 enviados'
ver "todo enviado" "$(A GET "m/notificaciones/envios?q=NTF-$S-H&estado=enviado" | js "d['total']")" '4'
ver "webhook: llegó con el título" "$(grep "gancho-$S" "$T/http.jsonl")" "Alarma NTF-$S-H: se activ"
ver "webhook: firma HMAC-SHA256 correcta" "$(python3 - "$T/http.jsonl" "gancho-$S" "$SEC" <<'PY'
import sys, json, hmac, hashlib
for l in open(sys.argv[1], encoding='utf-8'):
    d = json.loads(l)
    if sys.argv[2] in d['ruta']:
        print('firma-ok' if hmac.compare_digest(d['cabeceras'].get('x-iproy-firma', ''), 'sha256=' + hmac.new(sys.argv[3].encode(), d['cuerpo'].encode(), hashlib.sha256).hexdigest()) else 'firma-mal')
PY
)" 'firma-ok'
ver "correo: dos mensajes (uno por destinatario)" "$(cuenta smtp.jsonl "ejemplo.test")" '2'
CORREO=$(python3 - "$T/smtp.jsonl" <<'PY'
import sys, json, email
from email import policy
d = json.loads(open(sys.argv[1], encoding='utf-8').readline())
m = email.message_from_string(d['datos'], policy=policy.default)
print('tipo=' + m.get_content_type(), 'asunto=' + str(m['subject']), 'para=' + ','.join(d['para']))
for p in m.iter_parts(): print('parte=' + p.get_content_type(), p.get_content().replace('\n', ' '))
PY
)
ver "correo: multipart/alternative" "$CORREO" 'tipo=multipart/alternative'
ver "correo: asunto con tilde bien codificado" "$CORREO" "asunto=Alarma NTF-$S-H: se activó"
ver "correo: parte de texto" "$CORREO" 'parte=text/plain'; ver "correo: parte HTML con el dato" "$CORREO" 'Presión alta en línea Ñ</td>'
ver "correo: botón a la aplicación" "$CORREO" 'Abrir iProy'
ver "telegram: mensaje al chat de la regla" "$(grep "/bot$TG/sendMessage" "$T/http.jsonl")" "\\\"chat_id\\\":\\\"-100$S\\\""
ver "enviar otra vez no repite nada" "$(enviar)" '0 enviados'; ver "sigue habiendo un solo webhook" "$(cuenta http.jsonl "gancho-$S")" '1'

echo "3) Silencio y eventos que no cumplen la condición"
med 700     # retorno: solo la regla de Telegram escucha «retorno»
enviar >/dev/null
ver "el retorno avisó por Telegram" "$(grep -c "/bot$TG/sendMessage" "$T/http.jsonl")" '2'
ver "el retorno no avisó por webhook ni por correo" "$(cuenta http.jsonl "gancho-$S")/$(cuenta smtp.jsonl "ejemplo.test")" '1/2'
med 860     # se activa de nuevo dentro de la ventana de silencio de la regla de correo (60 min)
enviar >/dev/null
ver "segunda activación: el webhook avisa de nuevo" "$(cuenta http.jsonl "gancho-$S")" '2'
ver "segunda activación: el correo queda silenciado" "$(cuenta smtp.jsonl "ejemplo.test")" '2'
ver "y queda registrado como silenciado" "$(A GET "m/notificaciones/envios?regla=$RC&estado=descartado")" 'Silenciado: ya se avis'

echo "4) Fallas, reintentos y prueba manual"
RF=$(regla "{\"nombre\":\"NTF-$S falla\",\"tema\":\"alarmas/cambio\",\"condicion\":{\"evento\":[\"retorno\"]},\"canal\":\"webhook\",\"destino\":\"http://127.0.0.1:$PH/falla-$S\"}" | js "d['regla']['id']")
med 700; enviar >/dev/null
R=$(A GET "m/notificaciones/envios?regla=$RF")
ver "falla: queda en cola con el motivo" "$R" '"error":"Webhook: respuesta 500"'; ver "falla: primer intento contado" "$R" '"intentos":1'
enviar >/dev/null; enviar >/dev/null
R=$(A GET "m/notificaciones/envios?regla=$RF"); EF=$(printf '%s' "$R" | js "d['envios'][0]['id']")
ver "al agotar los 3 intentos pasa a «con falla»" "$R" '"estado":"fallido"'
ver "no se reintenta más solo" "$(enviar)" '0 enviados, 0 con falla'
ver "queda asentado en el diario" "$(A GET 'proc/diario?n=40')" 'aviso.fallido'
A PUT "m/notificaciones/reglas/$RF" "{\"destino\":\"http://127.0.0.1:$PH/arreglado-$S\"}" >/dev/null
ver "reintento manual sobre el aviso viejo: sigue yendo a su destino original" "$(A POST "m/notificaciones/envios/$EF/reintentar" '{}')" '"estado":"pendiente"'
R=$(A POST "m/notificaciones/reglas/$RF/probar" '{}')
ver "probar la regla corregida: llega" "$R" '"bien":1'; ver "la prueba se distingue" "$(grep "arreglado-$S" "$T/http.jsonl")" 'PRUEBA · Alarma'
A PUT "m/notificaciones/reglas/$RC" '{"destino":"rechazado@ejemplo.test, guardia@ejemplo.test"}' >/dev/null
R=$(A POST "m/notificaciones/reglas/$RC/probar" '{}')
ver "probar correo: uno llega y el otro no" "$R" '"bien":1,"mal":1'; ver "con el motivo que dio el servidor" "$R" '550'
ver "reintentar uno ya enviado → 409" "$(A POST "m/notificaciones/envios/$(A GET "m/notificaciones/envios?estado=enviado&regla=$RW" | js "d['envios'][0]['id']")/reintentar" '{}')" 'ya se envi'
A PUT "m/notificaciones/reglas/$RW" '{"activa":false}' >/dev/null
med 870; enviar >/dev/null
ver "regla pausada: no avisa" "$(cuenta http.jsonl "gancho-$S")" '2'
ver "resumen con los contadores" "$(A GET m/notificaciones/resumen)" '"reglas_activas":'

echo "5) Solicitudes y órdenes PIM"
RS=$(regla "{\"nombre\":\"NTF-$S solicitudes\",\"tema\":\"pim/ServiceRequest/create\",\"canal\":\"webhook\",\"destino\":\"http://127.0.0.1:$PH/solicitud-$S\"}" | js "d['regla']['id']")
RO=$(regla "{\"nombre\":\"NTF-$S ordenes\",\"tema\":\"pim/Task/create\",\"canal\":\"webhook\",\"destino\":\"http://127.0.0.1:$PH/orden-$S\"}" | js "d['regla']['id']")
A POST tareas/pim:vigia/ejecutar >/dev/null; tick
A POST pim/ServiceRequest "{\"resourceType\":\"ServiceRequest\",\"status\":\"active\",\"intent\":\"order\",\"subject\":{\"reference\":\"Device/$DEV\"},\"code\":{\"text\":\"Revisión NTF $S\"},\"note\":[{\"text\":\"Pierde aire\"}]}" >/dev/null
sleep 1; A POST tareas/pim:vigia/ejecutar >/dev/null; tick; tick; enviar >/dev/null
ver "solicitud nueva → aviso" "$(grep "solicitud-$S" "$T/http.jsonl")" 'Solicitud nueva'
ver "orden nueva → aviso" "$(grep "orden-$S" "$T/http.jsonl")" 'Orden nueva'

echo "6) Permisos y limpieza"
UE="ntfej$S"; UC="ntfci$S"; CL="Nt-$(rnd 16)"
A POST nucleo/usuario "{\"usuario\":\"$UE\",\"nombre\":\"Ejecutor NTF\",\"rol\":\"EJ\",\"organizacion\":\"NTF-ORG-$S\",\"clave\":\"$CL\"}" >/dev/null; A POST nucleo/usuario "{\"usuario\":\"$UC\",\"nombre\":\"Consulta NTF\",\"rol\":\"CI\",\"clave\":\"$CL\"}" >/dev/null
U POST sesion "{\"usuario\":\"$UE\",\"clave\":\"$CL\"}" >/dev/null
ver "ejecutor: no ve las reglas" "$(U GET m/notificaciones/reglas)" '"ok":false'
rm -f "$CJ"; U POST sesion "{\"usuario\":\"$UC\",\"clave\":\"$CL\"}" >/dev/null
ver "consulta: ve las reglas" "$(U GET m/notificaciones/reglas)" "NTF-$S webhook"
ver "consulta: no puede crear" "$(U POST m/notificaciones/reglas "{\"nombre\":\"NTF-$S y\",\"tema\":\"alarmas/cambio\",\"canal\":\"correo\",\"destino\":\"a@ejemplo.test\"}")" '"ok":false'
ver "consulta: no puede cambiar los canales" "$(U PUT m/notificaciones/config '{"borrar_telegram_token":true}')" '"ok":false'
ver "borrar una regla" "$(A POST "m/notificaciones/reglas/$RP/borrar" '{}')" '"borrada"'
ver "regla inexistente → 404" "$(A POST "m/notificaciones/reglas/$RP/probar" '{}')" 'No existe'
ver "altas y bajas en el diario" "$(A GET 'proc/diario?n=80')" 'aviso.regla.baja'

echo; echo "notificaciones: $OK bien, $MAL mal"; [[ $MAL -eq 0 ]]
