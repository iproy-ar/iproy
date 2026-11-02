#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba de integración industrial sobre UN nodo iniciado: telemetría → alarmas ISA-18.2 → orden PIM; preventivos por calendario y por uso;
# Modbus TCP y MQTT si están los simuladores (herramientas/simuladores: modbus_sim.py en :5020 y broker MQTT en :1883).
#   bash pruebas/industrial.sh <carpeta-nodo>
set -u
D="$1"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
A() { curl -s -m 90 -X "$1" -H "X-$P-Tick: $TOK" -H 'Content-Type: application/json' ${3:+-d "$3"} "http://127.0.0.1:$PORT/api/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:300}"; MAL=$((MAL+1)); fi; }
tick() { A POST nucleo/tick >/dev/null; }
S=$RANDOM; COD="IND-$S"
A POST pim/Device "{\"resourceType\":\"Device\",\"identifier\":[{\"system\":\"https://iproy.com.ar/fhir/sid/activo\",\"value\":\"$COD\"}],\"deviceName\":[{\"name\":\"Compresor de prueba $S\",\"type\":\"user-friendly-name\"}],\"status\":\"active\"}" >/dev/null
DEV=$(A GET "pim/Device?identifier=$COD" | grep -o '"resourceType":"Device","id":"[^"]*"' | head -1 | grep -o '[0-9A-Z]\{26\}')
echo "Activo $COD = $DEV"
echo "1) Alarma ISA-18.2"
R=$(A POST m/alarmas/definiciones "{\"etiqueta\":\"PT-$S-H\",\"activo_id\":\"$DEV\",\"punto\":\"PRES-LINEA\",\"tipo\":\"alta\",\"limite\":800,\"banda\":20,\"prioridad\":2,\"mensaje\":\"Presión alta\",\"genera_orden\":true}")
ID=$(printf '%s' "$R" | grep -o '"alarma":{"id":[0-9]*' | grep -o '[0-9]*$'); ver "definición creada" "$R" '"estado":"normal"'
med() { A POST m/telemetria/medicion "{\"activo\":\"$COD\",\"punto\":\"$1\",\"valor\":$2,\"unidad\":\"$3\"}" >/dev/null; tick; }
med PRES-LINEA 700 kPa; med PRES-LINEA 850 kPa
ver "850 kPa → no-reconocida + orden automática" "$(A GET m/alarmas/definiciones)" "\"etiqueta\":\"PT-$S-H\""
ver "orden PIM generada" "$(A GET m/alarmas/definiciones | grep -o "\"etiqueta\":\"PT-$S-H\".*" | head -c 2000)" '"orden":"Task/'
med PRES-LINEA 790 kPa; ver "790 (dentro de la banda) sigue activa" "$(A GET m/alarmas/activas)" "PT-$S-H"
med PRES-LINEA 770 kPa; ver "770 → retorno sin reconocer" "$(A GET m/alarmas/activas)" '"estado":"rtn-no-reconocida"'
ver "reconocer → normal" "$(A POST m/alarmas/$ID/reconocer '{"nota":"prueba"}')" '"estado":"normal"'
ver "archivar sin motivo se rechaza" "$(A POST m/alarmas/$ID/archivar '{"minutos":10}')" '"ok":false'
ver "archivar con motivo" "$(A POST m/alarmas/$ID/archivar '{"minutos":10,"motivo":"calibración"}')" '"estado":"archivada"'
med PRES-LINEA 900 kPa; ver "archivada: la condición se registra pero no se anuncia" "$(A GET m/alarmas/definiciones)" '"estado":"archivada","condicion":1'
ver "restaurar con condición presente → no-reconocida" "$(A POST m/alarmas/$ID/restaurar '{"nota":"fin"}')" '"estado":"no-reconocida"'
ver "indicadores EEMUA 191" "$(A GET m/alarmas/kpi)" '"activaciones_24h"'
echo "2) Preventivos"
R=$(A POST m/preventivos/planes "{\"titulo\":\"Plan $S\",\"activo_id\":\"$COD\",\"cada_dias\":30,\"proxima\":\"$(date +%F)\"}"); PID=$(printf '%s' "$R" | grep -o '"plan":{"id":[0-9]*' | grep -o '[0-9]*$')
ver "plan por calendario" "$R" '"tipo_mant":"preventivo-sistematico"'
ver "vence hoy → orden" "$(A POST tareas/preventivos:programar/ejecutar)" 'órdenes preventivas generadas'
ver "orden abierta en el plan" "$(A GET m/preventivos/planes | grep -o "\"id\":$PID,\"titulo\":\"Plan $S\".*" | head -c 3000)" '"orden_abierta":"'
A POST m/preventivos/planes "{\"titulo\":\"Uso $S\",\"activo_id\":\"$COD\",\"punto\":\"HORAS-MARCHA\",\"cada_valor\":500}" >/dev/null
med HORAS-MARCHA 1000 h; med HORAS-MARCHA 1510 h
ver "plan por uso dispara al pasar 500 h" "$(A GET m/preventivos/generadas)" '"disparo":"uso"'
echo "3) Modbus TCP (simulador :5020)"
if (exec 3<>/dev/tcp/127.0.0.1/5020) 2>/dev/null; then
  A PUT modulos/modbus/config "{\"config\":{\"habilitado\":true,\"equipos\":[{\"nombre\":\"sim\",\"host\":\"127.0.0.1\",\"puerto\":5020,\"unidad\":1,\"cada_s\":10,\"activo\":\"$COD\",\"habilitado\":true,\"registros\":[{\"punto\":\"TENSION-L1\",\"funcion\":4,\"direccion\":0,\"tipo\":\"float32\",\"unidad\":\"V\"},{\"punto\":\"CORRIENTE\",\"funcion\":4,\"direccion\":4,\"tipo\":\"uint16\",\"escala\":0.01,\"unidad\":\"A\"}]}]}}" >/dev/null
  ver "lectura Modbus" "$(A POST tareas/modbus:sondear/ejecutar)" 'lecturas'; sleep 11; tick
  ver "valores en telemetría" "$(A GET m/telemetria/ultimos)" '"punto":"TENSION-L1","valor":221.5'
else echo "  (sin simulador Modbus: se omite)"; fi
echo "4) MQTT (broker :1883)"
if (exec 3<>/dev/tcp/127.0.0.1/1883) 2>/dev/null && command -v amqtt_pub >/dev/null; then
  A PUT modulos/mqtt/config '{"config":{"habilitado":true,"refes":"PRUEBA","segundos_escucha":4}}' >/dev/null
  A POST tareas/mqtt:escuchar/ejecutar >/dev/null   # primera conexión: crea la sesión y la suscripción
  for i in $(seq 1 20); do amqtt_pub --url mqtt://127.0.0.1:1883 -t "pim/v1/PRUEBA/$COD/TEMP-ACEITE" -m '{"v":71.5,"u":"Cel"}' -q 1 >/dev/null 2>&1; sleep 1; done
  tick; tick
  ver "mensajes MQTT → telemetría (proceso residente o worker)" "$(A GET m/telemetria/ultimos)" '"punto":"TEMP-ACEITE","valor":71.5'
else echo "  (sin broker MQTT o sin amqtt_pub: se omite)"; fi
ver "diario íntegro" "$(A POST proc/diario/verificar)" '"ok":true'
echo; [[ $MAL -eq 0 ]] && echo "✔ $OK comprobaciones correctas" || echo "✘ $MAL fallas, $OK correctas"; exit $((MAL > 0))
