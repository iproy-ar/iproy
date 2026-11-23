#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba de «actualizar sobre lo instalado» (1.7.0) sobre UN producto iniciado que ya tiene el actualizador (iProy o uno incubado):
# paquete armado desde este repositorio, subida en partes, instalación desde la API con el sistema que se detiene y vuelve, datos intactos,
# paquete igual / anterior / dañado / ajeno, versión nueva que no arranca (vuelve sola a la anterior), «volver a la versión anterior»,
# la terminal (<Producto>-Control.sh actualizar) y permisos.
#   bash pruebas/actualizacion.sh <carpeta-del-producto>          (tarda unos 2 minutos: el producto se reinicia 5 veces)
# Crea un administrador y un gestor de prueba (los desactiva al terminar). Deja el producto con el código de este repositorio.
set -u
R="$(cd "$(dirname "$0")/.." && pwd)"; D="$(cd "$1" && pwd)"
env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
B="http://127.0.0.1:$PORT/api"; T=$(mktemp -d); LANZ="$(ls "$D"/*-Control.sh | head -1)"
A() { curl -s -m 60 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
S() { local cj="$T/$1"; shift; curl -s -m 120 -b "$cj" -c "$cj" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:500}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
R0=$RANDOM; AD="actad$R0"; GM="actgm$R0"; CL="U1-$(head -c 30 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16)"
trap 'for u in $AD $GM; do A PUT "usuarios/$u" "{\"activo\":0}" >/dev/null; done; rm -rf "$T"' EXIT
A POST nucleo/usuario "{\"usuario\":\"$AD\",\"nombre\":\"Admin Act\",\"rol\":\"AD\",\"clave\":\"$CL\"}" >/dev/null
A POST nucleo/usuario "{\"usuario\":\"$GM\",\"nombre\":\"Gestor Act\",\"rol\":\"GM\",\"clave\":\"$CL\"}" >/dev/null
entrar() { S ad POST sesion "{\"usuario\":\"$AD\",\"clave\":\"$CL\"}" >/dev/null; S ad POST sesion/config "{\"clave\":\"$CL\"}" >/dev/null; }
entrar; S gm POST sesion "{\"usuario\":\"$GM\",\"clave\":\"$CL\"}" >/dev/null
# paquete del árbol actual del repositorio (commit suelto, fuera de la rama); variantes: BUILD con otra fecha, Caddyfile roto
paquete() {
  local idx t c; idx=$(mktemp); cp "$R/.git/index" "$idx"
  ( cd "$R" && GIT_INDEX_FILE="$idx" git add -A && t=$(GIT_INDEX_FILE="$idx" git write-tree) && \
    c=$(GIT_AUTHOR_NAME=Prueba GIT_AUTHOR_EMAIL=prueba@iproy.invalid GIT_COMMITTER_NAME=Prueba GIT_COMMITTER_EMAIL=prueba@iproy.invalid git commit-tree "$t" -p HEAD -m prueba) && \
    git archive --format=zip --prefix=iproy/ -o "$1" "$c" ); rm -f "$idx"
}
variante() {   # variante <origen.zip> <destino.zip> <fecha-ISO> [roto]
  python3 - "$@" <<'EOF'
import zipfile, sys, hashlib
src, dst, fecha = sys.argv[1:4]; roto = len(sys.argv) > 4
h = hashlib.sha1((fecha + str(roto)).encode()).hexdigest()
with zipfile.ZipFile(src) as a, zipfile.ZipFile(dst, 'w', zipfile.ZIP_DEFLATED) as b:
    for i in a.infolist():
        d = a.read(i)
        if i.filename.endswith('nodo/BUILD'): d = f'{h} {fecha}\n'.encode()
        if roto and i.filename.endswith('nodo/conf/Caddyfile'): d = b'{ esto no es un Caddyfile\n'
        b.writestr(i, d)
EOF
}
subir() {   # subir <archivo.zip> → JSON de la última parte (partes de 600 KB para probar la subida en partes)
  local f="$1" id; id="p$(date +%s)$RANDOM"; rm -f "$T"/parte_*; split -b 614400 -d "$f" "$T/parte_"; local n i=0 r; n=$(ls "$T"/parte_* | wc -l)
  for x in "$T"/parte_*; do i=$((i+1)); r=$(curl -s -m 60 -b "$T/ad" -c "$T/ad" -X POST -H 'X-Nucleo: 1' -H 'Content-Type: application/octet-stream' --data-binary @"$x" "$B/actualizacion/subir?id=$id&parte=$i&total=$n&nombre=$(basename "$f")"); done
  echo "$r"
}
esperar() {   # hasta que el curso termine (el producto se detiene y vuelve)
  local k r; sleep 3
  for k in $(seq 1 60); do r=$(S ad GET actualizacion 2>/dev/null | js "(d['curso'] or {}).get('activo')"); [[ "$r" == False ]] && return 0; sleep 3; done; return 1
}
comp() { S ad GET actualizacion | js "d['instalado']['compilacion']"; }
cuenta() { S ad GET 'pim/Device?_summary=count' | js "d['total']"; echo -n ' '; A GET usuarios | js "len(d['usuarios'])"; }

echo "0) Estado y permisos"
C0="$(comp)"; N0="$(cuenta)"
ver "el estado responde y tiene el lanzador" "$(S ad GET actualizacion | js "(d['lanzador'], d['repositorio'])")" "(True, 'iproy-ar/iproy')"
ver "un gestor no ve las actualizaciones" "$(S gm GET actualizacion)" '"ok":false'
ver "ni puede subir un paquete" "$(curl -s -b "$T/gm" -X POST -H 'X-Nucleo: 1' -H 'Content-Type: application/octet-stream' --data-binary 'x' "$B/actualizacion/subir?id=abcdefgh1&parte=1&total=1&nombre=x.zip")" '"ok":false'

echo "1) Paquetes que no sirven"
echo "no soy un zip" > "$T/basura.zip"
ver "un archivo que no es .zip → 422" "$(subir "$T/basura.zip")" "no es un .zip"
python3 -c "import zipfile; z=zipfile.ZipFile('$T/ajeno.zip','w'); z.writestr('x/LEEME.txt','hola'); z.close()"
ver "un .zip que no es de iProy → 422" "$(subir "$T/ajeno.zip")" "No es un paquete de actualización"

echo "2) Versión nueva desde la pantalla (subida en partes)"
paquete "$T/nuevo.zip"; C1="$(unzip -p "$T/nuevo.zip" iproy/nodo/BUILD | cut -c1-12)"
ver "el paquete se revisa: otra compilación" "$(subir "$T/nuevo.zip" | js "d['paquete']['comparacion']['tipo']")" "otra-compilacion"
ver "«Actualizar ahora» lanza el lanzador" "$(S ad POST actualizacion/aplicar '{}')" '"lanzado":true'
esperar; entrar
ver "el producto volvió con la compilación nueva" "$(comp)" "$C1"
ver "el historial lo registra con su respaldo" "$(S ad GET actualizacion | js "(d['historial'][0]['resultado'], d['historial'][0]['respaldo'] is not None, d['paquete'])")" "('ok', True, None)"
ver "los datos siguen iguales (activos, usuarios)" "$(cuenta)" "$N0"
ver "el diario firmado lo registra" "$(A GET 'proc/diario?n=40')" "actualizacion.aplicar"
ver "el mismo paquete otra vez: ya instalado" "$(subir "$T/nuevo.zip" | js "d['paquete']['comparacion']['tipo']")" "igual"
ver "y no se instala" "$(S ad POST actualizacion/aplicar '{}')" "Ya está instalada"

echo "3) Una compilación más vieja pide confirmación"
variante "$T/nuevo.zip" "$T/viejo.zip" "2020-01-01T00:00:00+00:00"
ver "se reconoce como anterior" "$(subir "$T/viejo.zip" | js "d['paquete']['comparacion']['tipo']")" "anterior"
ver "sin confirmar no se instala" "$(S ad POST actualizacion/aplicar '{}')" "versión anterior"
S ad DELETE actualizacion/paquete >/dev/null

echo "4) Una versión que no arranca vuelve sola a la anterior"
variante "$T/nuevo.zip" "$T/roto.zip" "$(date -u +%Y-%m-%dT%H:%M:%S+00:00)" roto
ver "el paquete dañado se revisa (el daño no se ve hasta arrancar)" "$(subir "$T/roto.zip" | js "d['paquete']['comparacion']['tipo']")" "otra-compilacion"
S ad POST actualizacion/aplicar '{}' >/dev/null; esperar; entrar
ver "el producto responde con la compilación de antes" "$(comp)" "$C1"
ver "el historial dice que se volvió a la anterior" "$(S ad GET actualizacion | js "(d['historial'][0]['resultado'], d['historial'][0]['mensaje'][:40])")" "('error', 'La versión nueva no arrancó: se volvió a"
S ad DELETE actualizacion/paquete >/dev/null

echo "5) Volver a la versión anterior"
ver "hay una versión anterior guardada" "$(S ad GET actualizacion | js "d['puede_volver']")" "True"
S ad POST actualizacion/volver '{}' >/dev/null; esperar; entrar
ver "vuelve la compilación de antes de la prueba" "$(comp)" "$C0"
ver "el historial lo registra" "$(S ad GET actualizacion | js "d['historial'][0]['resultado']")" "vuelto"
ver "los datos siguen iguales" "$(cuenta)" "$N0"

echo "6) Desde la terminal"
ver "«actualizar <paquete.zip>» instala la versión nueva" "$(bash "$LANZ" actualizar "$T/nuevo.zip" 2>&1 | tail -3)" "listo (ok)"
entrar; ver "con la compilación del paquete" "$(comp)" "$C1"
ver "los datos siguen iguales" "$(cuenta)" "$N0"

echo "7) GitHub (si hay salida a Internet)"
G="$(S ad POST actualizacion/buscar)"
if [[ "$G" == *'"encontrada"'* ]]; then ver "lee la última versión publicada" "$(echo "$G" | js "d['encontrada']['version'] != ''")" "True"
else echo "  · sin salida a GitHub desde este equipo: $(echo "$G" | js "d.get('error','')" | cut -c1-80)"; fi

echo; echo "Resultado: $OK bien, $MAL mal"; [ "$MAL" -eq 0 ]
