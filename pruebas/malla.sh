#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba de la MALLA de la colmena (núcleo 1.7.0): cada nodo es hub, puente, emisor y receptor.
#   HUB_DIR=<carpeta del hub> [HUB_CODIGO=…] bash pruebas/malla.sh <nodo-A> <nodo-B> <nodo-C> <url-del-hub-central>
# Los tres nodos tienen que estar iniciados en este equipo (usa el token del worker de cada uno) y el hub tiene que ser de uso
# exclusivo: la prueba cambia su config.php (mantenimiento, demora_prueba_ms) y lo deja como estaba.
# Topología que arma: A—B y B—C se ven directo. A y C «no se ven» (vía «hub» en los dos: solo por terceros): B queda en el medio.
# En modo LAN se puede vincular por la dirección de la red: URL_B=https://<IP>:<puerto TLS de B> URL_C=https://<IP>:<puerto TLS de C>.
set -u
A_DIR="$1"; B_DIR="$2"; C_DIR="$3"; HUB="${4%/}"; CFG="${HUB_DIR:?Falta HUB_DIR (carpeta del hub de pruebas)}/config.php"
cargar() { local d="$1" env; env="$(ls "$d"/data/*.env | grep -v secrets | head -1)"; local P; P="$(basename "$env" .env | tr a-z A-Z)"
  set -a; . "$env"; . "$d/data/secrets.env"; set +a; eval "echo \"$P \$${P}_PUERTO \$${P}_TICK_TOKEN\""; }
read -r PA PORT_A TOK_A <<< "$(cargar "$A_DIR")"; read -r PB PORT_B TOK_B <<< "$(cargar "$B_DIR")"; read -r PC PORT_C TOK_C <<< "$(cargar "$C_DIR")"
pedir() { local P=$1 PORT=$2 TOK=$3; shift 3; curl -s --noproxy '*' -m 100 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "http://127.0.0.1:$PORT/api/$2"; }
A() { pedir "$PA" "$PORT_A" "$TOK_A" "$@"; }; B() { pedir "$PB" "$PORT_B" "$TOK_B" "$@"; }; C() { pedir "$PC" "$PORT_C" "$TOK_C" "$@"; }
js() { python3 -c "import sys,json
try: d=json.load(sys.stdin)
except Exception: d={}
try: print($1)
except Exception as e: print('?')"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:400}"; MAL=$((MAL+1)); fi; }
hubcfg() { python3 - "$CFG" "$1" "$2" <<'PY'
import re, sys
p, k, v = sys.argv[1:]; s = open(p).read()
s = re.sub(r"\s*'%s'\s*=>[^\n]*\n" % k, "\n", s)
s = s.replace('return [', "return [\n    '%s' => %s," % (k, v), 1); open(p, 'w').write(s)
PY
sleep 3; }   # el hub de pruebas (php -S con opcache) tarda hasta 2 s en ver el cambio
# espera hasta $1 s a que el comando $2… imprima algo que contenga $3
esperar() { local n=$1 q=$2 x; shift 2; for _ in $(seq 1 "$n"); do x="$("$@" 2>/dev/null)"; [[ "$x" == *"$q"* ]] && { echo "$x"; return 0; }; sleep 1; done; echo "$x"; return 1; }
IA=$(A GET colmena/identidad | js "d['node_id']"); IB=$(B GET colmena/identidad | js "d['node_id']"); IC=$(C GET colmena/identidad | js "d['node_id']")
echo "Nodo A: $IA (:$PORT_A) · Nodo B: $IB (:$PORT_B) · Nodo C: $IC (:$PORT_C) · hub central: $HUB"
cp "$CFG" "$CFG.prueba-malla"
fin() { cp "$CFG.prueba-malla" "$CFG"; rm -f "$CFG.prueba-malla"
  for n in A B C; do $n PUT colmena/config "{\"hub\":{\"url\":\"$HUB\",\"activo\":true},\"malla\":{\"hub_propio\":true,\"modo\":\"malla\"}}" >/dev/null; done; }
trap fin EXIT
# RPC de $1 a $2: imprime «estado via segundos id»
rpc_s() { local t0 t1 r; t0=$(date +%s.%N); r=$($1 POST colmena/rpc "{\"par\":\"$2\",\"metodo\":\"GET\",\"ruta\":\"metadata\",\"esperar\":${3:-40}}"); t1=$(date +%s.%N)
  echo "$(echo "$r" | js "d.get('rpc',d).get('estado','?')") $(echo "$r" | js "d.get('rpc',d).get('via','?')") $(python3 -c "print(round($t1-$t0,1))") $(echo "$r" | js "d.get('rpc',d).get('id','?')")"; }
elegido() { $1 GET colmena/malla | js "[c['camino'] for v in d['caminos'] if v['par']=='$2' for c in v['caminos'] if c['elegido']][0]"; }
caminos() { $1 GET colmena/malla | js "' '.join(c['camino']+('(pausa)' if c['en_pausa'] else '') for v in d['caminos'] if v['par']=='$2' for c in v['caminos'])"; }
vinc() { $1 GET colmena/vinculos | js "[v['estado'] for v in d['vinculos'] if v['par']=='$2'][0]"; }

echo "0) Preparación: los tres nodos en malla, con hub propio y hub central"
hubcfg mantenimiento false; hubcfg demora_prueba_ms 0
for n in A B C; do $n PUT colmena/config "{\"hub\":{\"url\":\"$HUB\",\"activo\":true,\"codigo\":\"${HUB_CODIGO:-}\"},\"malla\":{\"hub_propio\":true,\"modo\":\"malla\"}}" >/dev/null; $n POST colmena/hub/registrar >/dev/null; done
ver "los tres quedan en modo malla con hub propio" "$(A GET colmena/config)$(B GET colmena/config)$(C GET colmena/config)" '"hub_propio":true,"modo":"malla"'
# A—C se arma más abajo, sin hub central: si quedó de una corrida anterior, se revoca
A POST colmena/vinculos/$IC/revocar >/dev/null 2>&1; C POST colmena/vinculos/$IA/revocar >/dev/null 2>&1
enlazar() { local x=$1 y=$2 ix=$3 iy=$4 puerto=$5   # x pide a y por su dirección ($5); y acepta
  [[ "$(vinc $x $iy)" == activo && "$(vinc $y $ix)" == activo ]] && return 0
  $x POST colmena/vinculos/$iy/revocar >/dev/null 2>&1; $y POST colmena/vinculos/$ix/revocar >/dev/null 2>&1
  $x POST colmena/vincular "{\"destino\":\"$puerto\",\"tipo\":\"institucional\"}" >/dev/null; $y POST colmena/vinculos/$ix/aceptar '{"rol":"CI"}' >/dev/null; }
enlazar A B "$IA" "$IB" "${URL_B:-http://127.0.0.1:$PORT_B}"; enlazar B C "$IB" "$IC" "${URL_C:-http://127.0.0.1:$PORT_C}"
A POST colmena/vinculos/$IB/via '{"via":"auto"}' >/dev/null; B POST colmena/vinculos/$IA/via '{"via":"auto"}' >/dev/null
B POST colmena/vinculos/$IC/via '{"via":"auto"}' >/dev/null; C POST colmena/vinculos/$IB/via '{"via":"auto"}' >/dev/null
ver "vínculo A—B activo" "$(vinc A $IB) $(vinc B $IA)" 'activo activo'; ver "vínculo B—C activo" "$(vinc B $IC) $(vinc C $IB)" 'activo activo'
for n in A B C; do $n POST colmena/malla/sondear >/dev/null; done

echo "1) Cada nodo es hub: mismo protocolo que el hub central, solo para sus vinculados"
R=$(curl -s --noproxy '*' "http://127.0.0.1:$PORT_B/colmena-hub/index.php?r=estado")
ver "B ofrece su hub propio" "$R" '"servicio":"colmena-hub"'; ver "dice de quién es" "$R" "\"nodo\":\"$IB\""; ver "con espera larga" "$R" '"espera_max":25'
ver "un pedido sin firma se rechaza (401)" "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT_B/colmena-hub/index.php?r=buzon")" '401'
EXTRA=$(php -r '$k = sodium_crypto_sign_keypair(); $sec = sodium_crypto_sign_secretkey($k); $ts = time(); $n = bin2hex(random_bytes(12)); $r = "buzon";
  $f = base64_encode(sodium_crypto_sign_detached("GET\n$r\n$ts\n$n\n" . hash("sha256", "") . "\n" . $argv[1], $sec));
  echo "-H\nX-Colmena-Nodo: ZZ9\n-H\nX-Colmena-Hub: $argv[1]\n-H\nX-Colmena-Ts: $ts\n-H\nX-Colmena-Nonce: $n\n-H\nX-Colmena-Firma: $f";' "$IB")
mapfile -t CAB <<< "$EXTRA"
ver "un nodo sin vínculo, aunque firme bien, no entra (403)" "$(curl -s --noproxy '*' "${CAB[@]}" "http://127.0.0.1:$PORT_B/colmena-hub/index.php?r=buzon")" 'solo atiende a los nodos con vínculo activo'

echo "2) Escucha en varios hubs a la vez"
ver "A escucha en el hub de B" "$(esperar 70 "True" eval "A GET colmena/malla | js \"[h['escucha'] for h in d['hubs'] if h['clave']=='$IB'][0]\"")" 'True'
ver "A escucha también en el hub central" "$(esperar 40 "True" eval "A GET colmena/malla | js \"[h['escucha'] for h in d['hubs'] if h['clave']=='central'][0]\"")" 'True'
ver "en el hub de B escuchan A y C" "$(esperar 70 "$IA $IC" eval "B GET colmena/malla | js \"' '.join(sorted(d['hub_propio']['oyentes'], key=lambda x: x != '$IA'))\"")" "$IA $IC"
ver "A—B: el camino elegido es el directo" "$(elegido A $IB)" 'directo'

echo "3) Sin hub central: A y C se vinculan y trabajan por el hub de B (la colmena se sostiene sola)"
hubcfg mantenimiento true
ver "el hub central no responde (503)" "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$HUB/index.php?r=estado")" '503'
R=$(A POST colmena/vincular "{\"destino\":\"$IC\",\"tipo\":\"institucional\"}")
ver "A pide vínculo a C por su identificador: lo encuentra en el directorio del hub de B" "$R" '"estado":"saliente"'
ver "la solicitud le llega a C por el hub de B" "$(esperar 30 entrante vinc C $IA)" 'entrante'
C POST colmena/vinculos/$IA/aceptar '{"rol":"CI"}' >/dev/null
ver "C acepta y A queda activo" "$(esperar 40 activo vinc A $IC)" 'activo'
A POST colmena/vinculos/$IC/via '{"via":"hub"}' >/dev/null; C POST colmena/vinculos/$IA/via '{"via":"hub"}' >/dev/null   # A y C «no se ven»: solo por terceros
read e v s id < <(rpc_s A $IC); ver "pedido A→C respondido por el hub de B ($s s)" "$e $v" 'respondido hub'
read e v s id < <(rpc_s C $IA); ver "pedido C→A respondido por el hub de B ($s s)" "$e $v" 'respondido hub'
read e v s id < <(rpc_s A $IB); ver "pedido A→B directo ($s s)" "$e $v" 'respondido lan'
ver "B contó lo que pasó por su buzón" "$(B GET colmena/malla | js "d['hub_propio']['trabajo'].get('buzon',0) >= 2")" 'True'

echo "4) Además se apaga el hub de B: lo de A→C espera en la cola y sale solo cuando el hub vuelve"
B PUT colmena/config '{"malla":{"hub_propio":false}}' >/dev/null
ver "el hub de B contesta apagado (503)" "$(curl -s --noproxy '*' "http://127.0.0.1:$PORT_B/colmena-hub/index.php?r=estado")" '"activo":false'
read e v s id < <(rpc_s A $IC 5); ver "sin ningún camino, el pedido A→C queda en cola" "$e $v" 'en-cola cola'
read e2 v2 s2 id2 < <(rpc_s A $IB); ver "A→B sigue andando directo: apagar el hub no corta al nodo" "$e2 $v2" 'respondido lan'
B PUT colmena/config '{"malla":{"hub_propio":true}}' >/dev/null
ver "vuelve el hub de B y el pedido en cola se entrega y se responde solo" "$(esperar 200 respondido eval "A GET colmena/rpc/$id | js \"d['rpc']['estado']\"")" 'respondido'

echo "5) Vuelve el hub central: elegir el camino más rápido"
hubcfg mantenimiento false; hubcfg demora_prueba_ms 1500
for n in A B C; do $n POST colmena/hub/registrar >/dev/null; done; sleep 20
for k in 1 2; do A POST colmena/malla/sondear >/dev/null; C POST colmena/malla/sondear >/dev/null; sleep 6; done
ver "A conoce dos caminos a C: el hub de B y el hub central" "$(caminos A $IC)" "hub:$IB"; ver "… y el central" "$(caminos A $IC)" 'hub:*'
M=$(A GET colmena/malla | js "' '.join(c['camino']+'='+str(c['ms']) for v in d['caminos'] if v['par']=='$IC' for c in v['caminos'])")
ver "con el hub central lento (1,5 s por envío), elige el hub de B [$M]" "$(elegido A $IC)" "hub:$IB"
read e v s id < <(rpc_s A $IC); ver "pedido A→C por el camino rápido ($s s)" "$e $v" 'respondido hub'
ver "… tardó menos que por el central" "$(python3 -c "print('si' if $s < 2.5 else 'no')")" 'si'
hubcfg demora_prueba_ms 0

echo "6) Puente: A sin salida al hub central y C que solo usa el hub central — B une los dos tramos"
T6=$(date +%s); A PUT colmena/config "{\"hub\":{\"url\":\"$HUB\",\"activo\":false}}" >/dev/null
C PUT colmena/config '{"malla":{"modo":"central"}}' >/dev/null
ver "C en modo «solo hub central» no acepta entradas directas (503)" "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' "http://127.0.0.1:$PORT_C/api/colmena/sobre")" '503'
ver "… ni ofrece su hub propio" "$(curl -s --noproxy '*' "http://127.0.0.1:$PORT_C/colmena-hub/index.php?r=estado")" '"activo":false'
sleep 20; B POST colmena/malla/sondear >/dev/null; sleep 5; B POST colmena/malla/sondear >/dev/null
ver "B llega a C solo por el hub central" "$(esperar 60 'hub:*' elegido B $IC)" 'hub:*'
ver "A sabe que por el hub de B se llega a C" "$(esperar 90 "$IC" eval "A GET colmena/malla | js \"' '.join([h for h in d['hubs'] if h['clave']=='$IB'][0]['alcanza'])\"")" "$IC"
T0=$(B GET colmena/malla | js "d['hub_propio']['trabajo'].get('reenviados',0)")
read e v s id < <(rpc_s A $IC 60); ver "pedido A→C: A lo deja en el hub de B, B lo reenvía por el central, y la respuesta vuelve por B ($s s)" "$e $v" 'respondido hub'
T1=$(B GET colmena/malla | js "d['hub_propio']['trabajo'].get('reenviados',0)")
ver "B hizo de puente (reenvíos: $T0 → $T1)" "$(python3 -c "print('si' if $T1 > $T0 else 'no')")" 'si'
F=$((50 - $(date +%s) + T6)); [[ $F -gt 0 ]] && sleep $F   # el hub central da por «escuchando» a quien pidió algo hace menos de 45 s
read e v s id < <(rpc_s C $IA 60); ver "pedido C→A: vuelve por el mismo puente ($s s)" "$e $v" 'respondido hub'
A PUT colmena/config "{\"hub\":{\"url\":\"$HUB\",\"activo\":true}}" >/dev/null; A POST colmena/hub/registrar >/dev/null
C PUT colmena/config '{"malla":{"modo":"malla"}}' >/dev/null

echo "7) Aislar un nodo: nada entra ni sale; lo que sale espera hasta que el administrador lo reconecte"
A PUT colmena/config '{"malla":{"modo":"aislado"}}' >/dev/null
ver "A aislado: no recibe sobres directos (503)" "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' "http://127.0.0.1:$PORT_A/api/colmena/sobre")" '503'
ver "A aislado: su hub propio está cerrado" "$(curl -s --noproxy '*' "http://127.0.0.1:$PORT_A/colmena-hub/index.php?r=estado")" '"activo":false'
ver "A aislado: no muestra su identidad" "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT_A/api/colmena/identidad")" '503'
read e v s id < <(rpc_s A $IB 3); ver "lo que A quiere mandar queda en cola" "$e $v" 'en-cola cola'
ver "el diagnóstico de A lo dice" "$(A GET colmena/diagnostico)" '"camino":"aislado"'
sleep 20; ver "sigue en cola mientras está aislado" "$(A GET colmena/rpc/$id | js "d['rpc']['estado']")" 'en-cola'
A PUT colmena/config '{"malla":{"modo":"malla"}}' >/dev/null
ver "al reconectarlo, lo que estaba en cola sale y se responde" "$(esperar 60 respondido eval "A GET colmena/rpc/$id | js \"d['rpc']['estado']\"")" 'respondido'

echo "8) Apagar solo el hub propio: el nodo sigue en la colmena usando los caminos de los demás"
A PUT colmena/config '{"malla":{"hub_propio":false}}' >/dev/null
ver "A cierra su hub" "$(curl -s --noproxy '*' "http://127.0.0.1:$PORT_A/colmena-hub/index.php?r=estado")" '"activo":false'
read e v s id < <(rpc_s A $IB); ver "A→B sigue andando ($s s)" "$e" 'respondido'
read e v s id < <(rpc_s B $IA); ver "B→A sigue andando ($s s)" "$e" 'respondido'
ver "B registra que el hub de A está apagado" "$(esperar 60 apagado eval "B POST colmena/malla/sondear | js \"[h['estado'] for h in d['hubs'] if h['clave']=='$IA'][0]\"")" 'apagado'
A PUT colmena/config '{"malla":{"hub_propio":true}}' >/dev/null
ver "A lo vuelve a encender" "$(curl -s --noproxy '*' "http://127.0.0.1:$PORT_A/colmena-hub/index.php?r=estado")" '"activo":true'

echo "9) «Probar camino» muestra la malla"
D=$(A GET 'colmena/diagnostico?vinculos=1')
ver "informa el modo de red" "$D" '"clave":"modo","titulo":"Red de la colmena","estado":"ok"'; ver "informa el hub propio" "$D" '"clave":"hub-propio"'
ver "informa el hub de B" "$D" "\"clave\":\"hub-$IB\""; ver "prueba cada vínculo y dice el camino preferido" "$D" 'Camino preferido:'
echo; echo "malla: $OK bien, $MAL mal"; [[ $MAL -eq 0 ]]
