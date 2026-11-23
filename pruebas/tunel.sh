#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba del «túnel» de la colmena (1.7.0) entre DOS nodos iniciados y un hub 1.1.0 propio (no el publicado):
# espera larga (un pedido por el hub tarda segundos, no 15-45 s), vuelta automática a 15 s con un hub sin espera,
# hora corregida contra el hub (reloj corrido 400 s) y diagnóstico «Probar camino».
#   HUB_DIR=<carpeta del hub> [HUB_CODIGO=…] bash pruebas/tunel.sh <nodo-A> <nodo-B> <url-del-hub>
# El hub de prueba tiene que ser de uso exclusivo: la prueba cambia su config.php (espera_max, desfase_prueba_s) y lo deja como estaba.
set -u
cfg() { local d; d="$(cd "$1" && pwd)"; local env; env="$(ls "$d"/data/*.env | grep -v secrets | head -1)"; local p; p="$(basename "$env" .env | tr a-z A-Z)"
  ( set -a; . "$env"; . "$d/data/secrets.env"; eval "echo \$${p}_PUERTO \$${p}_TICK_TOKEN $p" ); }
read PA TA NA < <(cfg "$1"); read PB TB NB < <(cfg "$2"); HUB="$3"; HD="${HUB_DIR:?HUB_DIR: carpeta del hub de prueba}"
A() { curl -s -m 120 -X "$1" -H "X-$NA-Tick: $TA" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "http://127.0.0.1:$PA/api/$2"; }
B() { curl -s -m 120 -X "$1" -H "X-$NB-Tick: $TB" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "http://127.0.0.1:$PB/api/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:300}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
CFG="$HD/config.php"; [[ -f "$CFG" ]] || { echo "Falta $CFG"; exit 1; }; cp "$CFG" "$CFG.prueba-tunel"
hubcfg() { python3 - "$CFG" "$1" "$2" <<'PY'
import re, sys
p, k, v = sys.argv[1:]; s = open(p).read()
s = re.sub(r"\s*'%s'\s*=>[^\n]*\n" % k, "\n", s)
s = s.replace('return [', "return [\n    '%s' => %s," % (k, v), 1); open(p, 'w').write(s)
PY
}
IA=$(A GET colmena/identidad | js "d['node_id']"); IB=$(B GET colmena/identidad | js "d['node_id']")
fin() { cp "$CFG.prueba-tunel" "$CFG"; rm -f "$CFG.prueba-tunel"; A POST colmena/vinculos/$IB/via '{"via":"auto"}' >/dev/null; B POST colmena/vinculos/$IA/via '{"via":"auto"}' >/dev/null
  modo A malla; modo B malla; otros malla
  A GET colmena/diagnostico >/dev/null; B GET colmena/diagnostico >/dev/null; }
# 1.7.0 (malla): esta prueba es del camino por el HUB CENTRAL entre dos nodos. Los pone en modo «central» (sin directo ni hubs de
# nodos) mientras dura. Si hay otros nodos vinculados con los dos, pasalos en OTROS="<carpeta> <carpeta>": se los aísla durante
# la prueba; si no, la malla encuentra otro camino por ellos (que es justamente lo que tiene que hacer) y la prueba no mide lo suyo.
modo() { $1 PUT colmena/config "{\"malla\":{\"modo\":\"$2\"}}" >/dev/null; }
otros() { local d P PORT TOK; for d in ${OTROS:-}; do read PORT TOK P < <(cfg "$d")
  curl -s -m 30 -X PUT -H "X-$P-Tick: $TOK" -H 'Content-Type: application/json' -d "{\"malla\":{\"modo\":\"$1\"}}" "http://127.0.0.1:$PORT/api/colmena/config" >/dev/null; done; }
trap fin EXIT
otros aislado; modo A central; modo B central
rpc_s() { local t0 t1 r; t0=$(date +%s.%N); r=$(A POST colmena/rpc "{\"par\":\"$IB\",\"metodo\":\"GET\",\"ruta\":\"metadata\",\"esperar\":60}"); t1=$(date +%s.%N)
  echo "$(echo "$r" | js "d.get('rpc',d).get('estado','?')") $(echo "$r" | js "d.get('rpc',d).get('via','?')") $(python3 -c "print(round($t1-$t0,1))")"; }

echo "1) Hub central: hora y espera larga"
R=$(curl -s -D - "$HUB/index.php?r=estado")
ver "versión 1.2.0" "$R" '"version":"1.2.0"'; ver "anuncia la espera larga" "$R" '"espera_max":25'; ver "manda su hora en la cabecera" "$R" 'X-Colmena-Hora:'

echo "2) Los dos nodos con el hub y el vínculo forzado por el hub"
for n in A B; do $n PUT colmena/config "{\"hub\":{\"url\":\"$HUB\",\"activo\":true,\"codigo\":\"${HUB_CODIGO:-}\"}}" >/dev/null; done
ver "A registrado" "$(A POST colmena/hub/registrar)" '"registrado"'; ver "B registrado" "$(B POST colmena/hub/registrar)" '"registrado"'
ver "hay vínculo activo A→B" "$(A GET colmena/vinculos)" "\"par\":\"$IB\""
A POST colmena/vinculos/$IB/via '{"via":"hub"}' >/dev/null; B POST colmena/vinculos/$IA/via '{"via":"hub"}' >/dev/null
A GET colmena/diagnostico >/dev/null; B GET colmena/diagnostico >/dev/null; sleep 20   # el worker de cada nodo arranca su escucha

echo "3) Espera larga: un pedido A→B por el hub tarda segundos"
T=(); for k in 1 2 3; do read e v s < <(rpc_s); T+=("$s"); ver "pedido $k respondido por el hub ($s s)" "$e $v" "respondido hub"; done
MED=$(printf '%s\n' "${T[@]}" | sort -n | sed -n 2p); ver "mediana ${MED} s: menos de 6 s (antes, 15 a 45 s)" "$(python3 -c "print('si' if $MED < 6 else 'no')")" 'si'
ver "B: la escucha está activa" "$(B GET colmena/diagnostico)" '"clave":"escucha","titulo":"Escucha (túnel)","estado":"ok"'

echo "4) Hub sin espera (como un 1.0.0): vuelve solo a mirar cada 15 s y no se pierde nada"
hubcfg espera_max 0; A GET colmena/diagnostico >/dev/null; B GET colmena/diagnostico >/dev/null; sleep 16
read e v s < <(rpc_s); ver "pedido respondido igual ($s s)" "$e $v" "respondido hub"
ver "el diagnóstico avisa que el hub no espera" "$(A GET colmena/diagnostico)" 'Sin espera larga'
hubcfg espera_max 25; A GET colmena/diagnostico >/dev/null; B GET colmena/diagnostico >/dev/null; sleep 16

echo "5) Reloj corrido 400 s respecto del hub (más que los ±5 min que acepta): se corrige solo"
hubcfg desfase_prueba_s 400; sleep 2
read e v s < <(rpc_s); ver "el pedido sale y vuelve ($s s)" "$e $v" "respondido hub"
D=$(A GET colmena/diagnostico); ver "el diagnóstico mide el desfase" "$D" 'atrasado 40'; ver "y dice que se corrige solo" "$D" 'Se corrige solo'
hubcfg desfase_prueba_s 1200; sleep 3; D=$(A GET colmena/diagnostico)
ver "con 20 min de diferencia: falla y pide poner en hora" "$D" 'Poné en hora el equipo'
hubcfg desfase_prueba_s 0; sleep 3; A GET colmena/diagnostico >/dev/null; B GET colmena/diagnostico >/dev/null

echo "6) Probar camino con un pedido de prueba al vínculo"
D=$(A GET 'colmena/diagnostico?vinculos=1')
ver "camino bien" "$D" '"camino":"ok"'; ver "vínculo respondido por el hub" "$D" 'respondido por el hub'
ver "registro en el hub" "$D" '"clave":"registro","titulo":"Registro en el hub","estado":"ok"'
echo; echo "tunel: $OK bien, $MAL mal"; [[ $MAL -eq 0 ]]
