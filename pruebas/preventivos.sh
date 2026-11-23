#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba del módulo preventivos 1.3.0 sobre UN nodo iniciado: plan por visita con su lista de tareas, registro por tarea de la orden
# (hecha / no se hizo con motivo / no aplica), permisos (el Ejecutor solo en órdenes de su empresa), reprogramación por mes calendario
# y habilitar en bloque llevando los vencidos a la próxima fecha de su ciclo.
#   bash pruebas/preventivos.sh <carpeta-nodo>
# Crea sus propios datos (prefijo PRV) y deja los planes de prueba en pausa para que no generen órdenes después.
set -u
D="$(cd "$1" && pwd)"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
B="http://127.0.0.1:$PORT/api"; T=$(mktemp -d); CJ="$T/cj"; CJ2="$T/cj2"; trap 'rm -rf "$T"' EXIT
A() { curl -s -m 90 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
U() { curl -s -m 60 -b "$CJ" -c "$CJ" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }     # Ejecutor de la empresa del plan
V() { curl -s -m 60 -b "$CJ2" -c "$CJ2" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }   # Ejecutor de otra empresa
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
S=$RANDOM; NS=https://iproy.com.ar/fhir; rnd() { head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16; }

echo "1) Datos: dos empresas, un activo y un plan mensual con tres tareas"
org() { A POST pim/Organization "{\"resourceType\":\"Organization\",\"identifier\":[{\"system\":\"$NS/sid/empresa\",\"value\":\"$1\"}],\"name\":\"Empresa $1\",\"type\":[{\"coding\":[{\"system\":\"http://terminology.hl7.org/CodeSystem/organization-type\",\"code\":\"bus\"}]}]}" >/dev/null; }
org "PRV-UNO-$S"; org "PRV-DOS-$S"
A POST pim/Device "{\"resourceType\":\"Device\",\"identifier\":[{\"system\":\"$NS/sid/activo\",\"value\":\"PRV-$S\"}],\"deviceName\":[{\"name\":\"Bomba PRV $S\",\"type\":\"user-friendly-name\"}],\"status\":\"active\"}" >/dev/null
TAR='Motor / Rodamientos: verificar temperatura\nMotor / Bobinado: prueba de aislamiento\nFiltro de succión: limpieza'
R=$(A POST m/preventivos/planes "{\"titulo\":\"Visita mensual PRV $S\",\"activo_id\":\"PRV-$S\",\"cada_dias\":30,\"proxima\":\"$(date +%F)\",\"responsable\":\"PRV-UNO-$S\",\"tareas\":\"$TAR\"}")
PL=$(printf '%s' "$R" | js "d['plan']['id']"); ver "plan creado con sus tres tareas" "$(printf '%s' "$R" | js "len(d['plan']['tareas'].splitlines())")" "3"; [[ "$PL" =~ ^[0-9]+$ ]] || { echo "  ✘ no se pudo crear el plan → ${R:0:300}"; exit 1; }
CL="Pv-$(rnd)"; UE="prvej$S"; UO="prvot$S"
A POST nucleo/usuario "{\"usuario\":\"$UE\",\"nombre\":\"Ejecutor PRV\",\"rol\":\"EJ\",\"organizacion\":\"PRV-UNO-$S\",\"clave\":\"$CL\"}" >/dev/null
A POST nucleo/usuario "{\"usuario\":\"$UO\",\"nombre\":\"Otro ejecutor PRV\",\"rol\":\"EJ\",\"organizacion\":\"PRV-DOS-$S\",\"clave\":\"$CL\"}" >/dev/null
U POST sesion "{\"usuario\":\"$UE\",\"clave\":\"$CL\"}" >/dev/null; V POST sesion "{\"usuario\":\"$UO\",\"clave\":\"$CL\"}" >/dev/null

echo "2) Una orden por visita, con el registro por tarea"
R=$(A POST "m/preventivos/planes/$PL/generar"); ver "genera la orden en PIM" "$R" '"orden":"'
GEN=$(A GET "m/preventivos/generadas?plan=$PL" | js "d['generadas'][0]['id']")
ver "la orden trae las 3 tareas del plan, pendientes" "$(A GET "m/preventivos/generadas/$GEN/tareas" | js "(d['resumen']['total'], d['resumen']['pendiente'], d['tareas'][1]['tarea'])")" "(3, 3, 'Motor / Bobinado: prueba de aislamiento')"
ver "una tarea que no se hizo sin motivo → 422" "$(A PUT "m/preventivos/generadas/$GEN/tareas" '{"tareas":[{"n":2,"resultado":"no_hecha"}]}')" 'necesita el motivo'
ver "resultado inválido → 422" "$(A PUT "m/preventivos/generadas/$GEN/tareas" '{"tareas":[{"n":1,"resultado":"tal-vez"}]}')" 'resultado:'
ver "el Ejecutor de la empresa asignada registra: 2 hechas, 1 no (con motivo)" "$(U PUT "m/preventivos/generadas/$GEN/tareas" '{"tareas":[{"n":1,"resultado":"hecha"},{"n":2,"resultado":"no_hecha","nota":"Falta megóhmetro"},{"n":3,"resultado":"hecha","nota":"Filtro sucio"}]}' | js "(d['resumen']['hecha'], d['resumen']['no_hecha'], d['cambios'])")" "(2, 1, 3)"
ver "queda quién y cuándo" "$(A GET "m/preventivos/generadas/$GEN/tareas" | js "(d['tareas'][1]['usuario'], d['tareas'][1]['nota'], d['tareas'][1]['actualizado'] is not None)")" "('$UE', 'Falta megóhmetro', True)"
ver "repetir lo mismo no cambia nada" "$(U PUT "m/preventivos/generadas/$GEN/tareas" '{"tareas":[{"n":1,"resultado":"hecha"}]}' | js "d['cambios']")" "0"
ver "el Ejecutor de otra empresa no la ve (403)" "$(V GET "m/preventivos/generadas/$GEN/tareas")" 'otra empresa'
ver "ni la registra" "$(V PUT "m/preventivos/generadas/$GEN/tareas" '{"tareas":[{"n":1,"resultado":"no_aplica"}]}')" '"ok":false'
ver "el resumen por orden en el plan: 2 de 3 hechas, 1 sin hacer" "$(A GET "m/preventivos/generadas?plan=$PL" | js "(d['generadas'][0]['tareas'], d['generadas'][0]['hechas'], d['generadas'][0]['no_hechas'])")" "(3, 2, 1)"

echo "3) Fechas por mes calendario (el 31/01 → 28/02 está en las pruebas unitarias)"
P2=$(A POST m/preventivos/planes "{\"titulo\":\"Bimestral PRV $S\",\"activo_id\":\"PRV-$S\",\"cada_dias\":60,\"proxima\":\"$(date -d '+3 days' +%F)\"}" | js "d['plan']['id']")
ver "proyección: dos meses calendario (mismo día)" "$(A GET 'm/preventivos/calendario?dias=130' | js "[c['fecha'][8:] for c in d['calendario'] if c['plan']==$P2]")" "['$(date -d '+3 days' +%d)', '$(date -d '+3 days' +%d)'"

echo "4) Habilitar en bloque con vencidos"
A PUT "m/preventivos/planes/$P2" '{"habilitado":false,"proxima":"2025-01-09"}' >/dev/null; A PUT "m/preventivos/planes/$PL" '{"habilitado":false}' >/dev/null
R=$(A POST m/preventivos/planes/habilitar "{\"ids\":[$P2],\"reprogramar_vencidas\":true}")
ver "habilita y lleva el vencido a la próxima fecha de su ciclo" "$R" '"habilitados":1,"reprogramadas":1'
ver "la nueva fecha no está vencida y conserva el día 09" "$(A GET m/preventivos/planes | js "[(p['proxima'] >= '$(date +%F)', p['proxima'][8:]) for p in d['planes'] if p['id']==$P2]")" "[(True, '09')]"
A PUT "m/preventivos/planes/$P2" '{"habilitado":false}' >/dev/null   # que la prueba no deje planes que generen órdenes
A PUT "usuarios/$UE" '{"activo":0}' >/dev/null; A PUT "usuarios/$UO" '{"activo":0}' >/dev/null

echo; echo "preventivos: $OK bien, $MAL mal"; [ "$MAL" -eq 0 ]
