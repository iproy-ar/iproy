#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Actualiza un producto ya instalado (iProy o uno propio, como «Nodo1») con el código del ZIP que está al lado de este archivo,
# sin tocar sus datos: respaldo de la base → detener → código nuevo (incubar.php actualizar) → iniciar.
#   bash actualizar-linux.sh [carpeta-del-producto]        (sin sudo)
#   SUMAR=migracion,reportes bash actualizar-linux.sh …     módulos que se activan además de los que ya tiene (por defecto: migracion)
# El código anterior queda al lado del producto, en <carpeta>.codigo-anterior-<fecha>; el respaldo de la base, en data/backups/.
set -euo pipefail
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ZIP="$(ls "$AQUI"/iProy*-codigo-*.zip 2>/dev/null | head -1 || true)"
[ -n "$ZIP" ] || { echo "✖ Falta el ZIP del código (iProy-<versión>-codigo-<compilación>.zip) en esta carpeta."; exit 1; }
command -v python3 >/dev/null || { echo "✖ Hace falta Python 3 (el mismo que usó el instalador)."; exit 1; }

PROD="${1:-}"
if [ -z "$PROD" ]; then
  mapfile -t C < <(find "$HOME" -maxdepth 5 -path '*-Portable/producto.json' -not -path '*codigo-anterior*' -not -path '*codigo-descartado*' -not -path '*/.incubar-*' 2>/dev/null \
                   | while read -r f; do d="$(dirname "$f")"; [ -d "$d/data" ] && echo "$d"; done | sort -u)
  if [ "${#C[@]}" -eq 0 ]; then echo "✖ No encontré ningún producto instalado en tu carpeta personal."; echo "  Indicá la carpeta: bash actualizar-linux.sh /ruta/Nodo1/Nodo1-Portable"; exit 1; fi
  if [ "${#C[@]}" -gt 1 ]; then echo "Encontré varios productos instalados:"; printf '  %s\n' "${C[@]}"; echo "Indicá cuál: bash actualizar-linux.sh <carpeta>"; exit 1; fi
  PROD="${C[0]}"
fi
PROD="$(cd "$PROD" && pwd)"
[ -f "$PROD/producto.json" ] || { echo "✖ $PROD no tiene producto.json"; exit 1; }
NOMBRE="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["nombre"])' "$PROD/producto.json")"
CTL="$PROD/$NOMBRE-Control.sh"; FP="$PROD/runtime/linux-x64/frankenphp"
[ -f "$CTL" ] || { echo "✖ No encuentro $CTL"; exit 1; }
[ -x "$FP" ] || { echo "✖ No encuentro $FP (el producto no está instalado completo)"; exit 1; }

echo "Producto: $NOMBRE"
echo "Carpeta:  $PROD"
echo "Código:   $(basename "$ZIP")"
echo "Se hace un respaldo de la base, se detiene $NOMBRE unos segundos, se cambia el código y se vuelve a iniciar."
echo "Los datos (activos, órdenes, usuarios, vínculos de la colmena) no se tocan."
read -r -p "¿Seguimos? [s/N] " r; [[ "$r" =~ ^[sS] ]] || { echo "Cancelado."; exit 0; }

# 1.7.0 en adelante: el lanzador del producto ya sabe actualizarse (respaldo, detener, código nuevo, iniciar y volver solo si no arranca)
if grep -q '^cmd_actualizar()' "$CTL"; then exec bash "$CTL" actualizar "$ZIP"; fi
TMP="$(mktemp -d "$AQUI/.codigo-XXXXXX")"; trap 'rm -rf "$TMP"' EXIT
python3 -m zipfile -e "$ZIP" "$TMP"
COD="$(dirname "$(find "$TMP" -maxdepth 3 -type f -path '*/incubadora/incubar.php' | head -1)")"; COD="$(dirname "$COD")"
[ -f "$COD/incubadora/incubar.php" ] || { echo "✖ El ZIP no trae incubadora/incubar.php"; exit 1; }

echo; echo "1/4 Respaldo de la base"
bash "$CTL" respaldo || echo "  (no estaba funcionando: el respaldo lo hace «stop»)"
echo; echo "2/4 Detener $NOMBRE"
bash "$CTL" stop
echo; echo "3/4 Código nuevo"
if ! "$FP" php-cli "$COD/incubadora/incubar.php" actualizar "$PROD" --sumar "${SUMAR:-migracion}"; then
  echo "✖ No se pudo cambiar el código. $NOMBRE quedó como estaba: inicialo con «bash $CTL start»."; exit 1
fi
echo; echo "4/4 Iniciar $NOMBRE (aplica las migraciones de la base)"
bash "$CTL" start
echo; bash "$CTL" status || true
echo; echo "✔ Listo. Abrí $NOMBRE. Desde ahora las versiones nuevas se instalan desde $NOMBRE: Configuración › Actualizaciones."
echo "  Si algo no anda: el código anterior está en $PROD.codigo-anterior-* (se vuelve atrás cambiando las carpetas) y el respaldo de la base en $PROD/data/backups/."
