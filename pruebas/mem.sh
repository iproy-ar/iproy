#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba del módulo «mem» (IHE MEM-DMC, actor DMIC) sobre UN nodo iniciado:
# PCD-15 (ORU^R44) por MLLP y por HTTP → ACK, Device por EUI-64, DeviceMetric MDC, Observation, ubicación, orden por autoprueba,
# rechazo de tipos no soportados y de mensajes con PID. Los mensajes son ficticios, con la forma del ejemplo del suplemento Rev. 1.5.
#   bash pruebas/mem.sh <carpeta-nodo> [puerto-mllp]
set -u
D="$1"; PM="${2:-2575}"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
A() { curl -s -m 90 -X "$1" -H "X-$P-Tick: $TOK" -H 'Content-Type: application/json' ${3:+-d "$3"} "http://127.0.0.1:$PORT/api/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:300}"; MAL=$((MAL+1)); fi; }
NS=https://iproy.com.ar/fhir; S=$RANDOM; EUI=$(printf '001A01%010X' $((RANDOM * 32768 + S))); TS=$(date +%Y%m%d%H%M%S)-0300
# envía un mensaje por MLLP (0x0B … 0x1C 0x0D) y devuelve el ACK con \r → \n
mllp() { python3 -c "
import socket,sys
s=socket.create_connection(('127.0.0.1',int(sys.argv[1])),timeout=30); s.sendall(b'\x0b'+sys.stdin.buffer.read().replace(b'\n',b'\r')+b'\x1c\r')
b=b''
while not b.endswith(b'\x1c\r'):
    x=s.recv(65536)
    if not x: break
    b+=x
print(b.strip(b'\x0b\x1c\r').decode().replace('\r','\n'))" "$PM"; }
pcd15() {  # $1 control · $2 alimentación · $3 resultado de autoprueba · $4 local (PL componente 9)
cat <<EOF
MSH|^~\&|Fabricante EJ^$EUI^EUI-64||Cliente EJ||$TS||ORU^R44^ORU_R44|$1|P|2.6|||AL|NE||UNICODE UTF-8|en^English^ISO639||IHE_PCD_015^IHE PCD^1.3.6.1.4.1.19376.1.6.1.15.1^ISO
OBR|1|$EUI^Bomba EJ^$EUI^EUI-64|$EUI^Bomba EJ^$EUI^EUI-64|69135^MDC_OBS_MEM^MDC|||$TS
OBX|1|ST|69985^MDC_DEV_PUMP_INFUS_MDS^MDC|1.0.0.0|||||||F
OBX|2|ST|67880^MDC_ATTR_ID_MODEL^MDC|1.0.0.1|manufacturer=Fabricante EJ model=BI-4000||$TS||||F
OBX|3|ST|67972^MDC_ATTR_SYS_ID^MDC|1.0.0.2|$EUI^Bomba EJ^$EUI^EUI-64||$TS||||F
OBX|4|CWE|67925^MDC_ATTR_POWER_STAT^MDC|1.0.0.3|^$2||$TS||||F|||||||$EUI^Bomba EJ^$EUI^EUI-64
OBX|5|NM|0^MDCX_ATTR_BATT_CHARGE^MDC|1.0.0.4|87|0^MDC_DIM_PERCENT^MDC|||||F|||$TS
OBX|6|ST|0^MDCX_ATTR_SELF_TEST_RESULT^MDC|1.0.0.5|$3||||||F|||$TS
OBX|7|ST|68512^MDC_ATTR_LS_NAME^MDC|LOC|Bomba EJ $S||||||F|||$TS
OBX|8|PL|68513^MDC_ATTR_LS_LOCATION^MDC|LOC|^^^Hospital EJ^^^Edificio EJ^Planta baja^$4||||||F|||$TS||||000000000001^RTLS-EJ^^L
EOF
}
echo "0) Configuración: módulo habilitado en 127.0.0.1:$PM y un local para la ubicación"
A PUT modulos/mem/config "{\"config\":{\"habilitado\":true,\"host\":\"127.0.0.1\",\"puerto\":$PM}}" >/dev/null
A POST pim/Location "{\"resourceType\":\"Location\",\"identifier\":[{\"system\":\"$NS/sid/local\",\"value\":\"EJ-UCI-$S\"}],\"status\":\"active\",\"name\":\"UCI EJ $S\",\"physicalType\":{\"coding\":[{\"system\":\"http://terminology.hl7.org/CodeSystem/location-physical-type\",\"code\":\"ro\"}]}}" >/dev/null
for i in $(seq 1 45); do (exec 3<>/dev/tcp/127.0.0.1/$PM) 2>/dev/null && break; sleep 1; done
ver "el residente escucha MLLP en :$PM" "$( (exec 3<>/dev/tcp/127.0.0.1/$PM) 2>/dev/null && echo abierto)" 'abierto'

echo "1) PCD-15 por MLLP: equipo nuevo, en red, autoprueba correcta"
R=$(pcd15 "EJ$S-1" "AC" "PASS" "UCI EJ $S" | mllp)
ver "ACK AA con el control del mensaje" "$R" "MSA|AA|EJ$S-1"
DEV=$(A GET "pim/Device?identifier=$NS/sid/eui64%7C$EUI" | grep -o '"resourceType":"Device","id":"[^"]*"' | head -1 | grep -o '[0-9A-Z]\{26\}')
R=$(A GET "pim/Device/$DEV")
ver "Device dado de alta por EUI-64 (pim-pma, etiqueta mem-dmc)" "$R" '"code":"mem-dmc"'
ver "fabricante y modelo de MDC_ATTR_ID_MODEL" "$R" '"modelNumber":"BI-4000"'
ver "ubicación desde MDC_ATTR_LS_LOCATION (PL)" "$R" "\"display\":\"UCI EJ $S\""
ver "DeviceMetric con el código MDC del atributo" "$(A GET "pim/DeviceMetric?source=Device/$DEV")" '"code":"67925"'
ver "Observation con valor codificado" "$(A GET "pim/Observation?device=Device/$DEV&code=67925")" '"valueCodeableConcept":{"text":"AC"}'
ver "Observation numérica sin código asignado (MDCX)" "$(A GET "pim/Observation?device=Device/$DEV&_count=50")" '"text":"MDCX_ATTR_BATT_CHARGE"'

echo "2) Pasa a batería y la autoprueba falla → orden correctiva"
R=$(pcd15 "EJ$S-2" "BATT" "FAIL" "UCI EJ $S" | mllp)
ver "ACK AA" "$R" "MSA|AA|EJ$S-2"
R=$(A GET "pim/Task?focus=Device/$DEV")
ver "orden correctiva por autoprueba con falla" "$R" 'Autoprueba con falla'
ver "Task.reasonReference → la Observation de la autoprueba" "$R" '"reasonReference":{"reference":"Observation/'
pcd15 "EJ$S-3" "BATT" "FAIL" "UCI EJ $S" | mllp >/dev/null
ver "no duplica la orden mientras está abierta" "$(A GET "pim/Task?focus=Device/$DEV&_summary=count")" '"total":1'
for i in 1 2 3 4 5; do A POST nucleo/tick >/dev/null; [[ "$(A GET m/telemetria/ultimos)" == *'"punto":"ALIMENTACION","valor":1'* ]] && break; sleep 1; done
ver "valores normalizados en telemetría (ALIMENTACION = 1)" "$(A GET m/telemetria/ultimos)" '"punto":"ALIMENTACION","valor":1'

echo "3) Rechazos y vía HTTP"
R=$(printf 'MSH|^~\\&|X||Y||%s||ADT^A01|EJ%s-4|P|2.6\nEVN|A01|%s\n' "$TS" "$S" "$TS" | mllp)
ver "tipo no soportado → AR" "$R" "MSA|AR|EJ$S-4"
R=$(pcd15 "EJ$S-5" "AC" "PASS" "x" | sed '2i PID|1||123' | mllp)
ver "PCD-15 con PID (datos de paciente) → AR" "$R" "MSA|AR|EJ$S-5"
R=$(pcd15 "EJ$S-6" "AC" "PASS" "x" | sed 's/69135^MDC_OBS_MEM/12345^OTRO/' | mllp)
ver "OBR-4 distinto de MDC_OBS_MEM → AE" "$R" "MSA|AE|EJ$S-6"
M=$(pcd15 "EJ$S-7" "AC" "PASS" "x" | python3 -c 'import json,sys; print(json.dumps({"mensaje": sys.stdin.read()}))')
ver "PCD-15 por HTTP (POST /api/m/mem/pcd15)" "$(A POST m/mem/pcd15 "$M")" "MSA|AA|EJ$S-7"
ver "registro de mensajes" "$(A GET m/mem/mensajes)" "\"control\":\"EJ$S-7\""
ver "historial y Provenance del Device" "$(A GET "pim/Provenance?target=Device/$DEV")" 'application/x-pim-huella'

echo; [[ $MAL -eq 0 ]] && echo "✔ $OK comprobaciones correctas" || echo "✘ $MAL fallas, $OK correctas"; exit $((MAL > 0))
