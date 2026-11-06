#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
#
# Desinstalador de @@PROD@@ · generado por el instalador de iProy @@VERSION@@ el @@FECHA@@
# Instalado en: @@RAIZ@@
#
# Uso (con tu usuario, sin sudo):  bash @@NOMBRE@@
#   Sin opciones es interactivo: muestra qué va a hacer, pregunta si guardás una copia de los datos y pide confirmación.
# Opciones avanzadas (para no preguntar):
#   --si                  no pide confirmación
#   --sin-datos           no guarda la copia de data/ (se pierden los datos)
#   --destino-respaldo D  carpeta donde dejar la copia de los datos (por defecto, tu carpeta personal)
#   --cache               borra también la caché del instalador (@@CACHE@@): descargas que sirven para reinstalar
#   --conservar-firewall  no toca el firewall aunque el instalador haya abierto un puerto
#
# Qué hace, en orden: detiene el nodo · guarda data/ comprimida y verificada con SHA-256 · quita los accesos directos ·
# cierra el puerto del firewall (pide permiso) · borra la carpeta del producto · deja un registro en tu carpeta personal.
# Qué NO deshace: librerías del sistema y la sincronización de hora, porque las usan otros programas.
set -u
PROD='@@PROD@@'; RAIZ='@@RAIZ@@'; LANZ='@@LANZ@@'; BASE_PROD='@@BASE_PROD@@'; CACHE='@@CACHE@@'
SLUG='@@SLUG@@'; FW_CERRAR='@@FW_CERRAR@@'; PUERTO='@@PUERTO@@'; INSTALADO='@@FECHA@@'; VERSION='@@VERSION@@'
ARCHIVOS_BASE=(@@ARCHIVOS_BASE@@)   # lo que el instalador dejó junto a la carpeta del nodo (se borra solo si no hay nada más)

SI=0; SIN_DATOS=0; CACHE_SI=0; FW_SI=1; DEST="$HOME"
while [[ $# -gt 0 ]]; do case "$1" in
  --si) SI=1;; --sin-datos) SIN_DATOS=1;; --cache) CACHE_SI=1;; --conservar-firewall) FW_SI=0;;
  --destino-respaldo) DEST="${2:-}"; shift;; -h|--help) sed -n '5,20p' "$0"; exit 0;; *) echo "Opción desconocida: $1 (ver --help)"; exit 2;; esac; shift; done

C_OK='\033[32m'; C_AV='\033[33m'; C_ER='\033[31m'; C_0='\033[0m'; [[ -t 1 ]] || { C_OK=''; C_AV=''; C_ER=''; C_0=''; }
REG=(); ok() { echo -e "  ${C_OK}✔${C_0} $1"; REG+=("OK  $1"); }; av() { echo -e "  ${C_AV}⚠${C_0} $1"; REG+=("AV  $1"); }; er() { echo -e "  ${C_ER}✘${C_0} $1"; REG+=("ER  $1"); }
preguntar() { local r; [[ $SI == 1 ]] && return 0; read -rp "$1 [s/N] " r; [[ "$r" =~ ^[sS]$ ]]; }

paso_resumen() {
  echo "Desinstalador de $PROD (instalado el $INSTALADO con iProy $VERSION)"
  echo "  Carpeta:        $RAIZ"
  [[ -d "$RAIZ/data" ]] && echo "  Datos:          $(du -sh "$RAIZ/data" 2>/dev/null | cut -f1) en data/ (base de datos, claves, configuración)"
  echo "  Accesos:        escritorio y menú de aplicaciones ($SLUG.desktop)"
  [[ -n "$FW_CERRAR" ]] && echo "  Firewall:       el instalador abrió el puerto; se ofrece cerrarlo"
  echo "  Caché:          $CACHE $( [[ $CACHE_SI == 1 ]] && echo '(se borra)' || echo '(se conserva: sirve para reinstalar sin volver a bajar; --cache para borrarla)')"
  echo
  if [[ ! -d "$RAIZ" ]]; then er "La carpeta $RAIZ ya no existe: no hay nada que desinstalar (se quitan solo los accesos directos)."; fi
}

paso_datos() {
  [[ -d "$RAIZ/data" ]] || { av "No hay carpeta data/ para guardar"; return; }
  if [[ $SIN_DATOS == 1 ]]; then av "Los datos NO se guardan (--sin-datos)"; return; fi
  if [[ $SI == 0 ]]; then
    local r; read -rp "¿Guardo una copia de los datos antes de borrar? (recomendado) [S/n] " r
    [[ "$r" =~ ^[nN]$ ]] && { av "Los datos NO se guardan por tu elección"; return; }
  fi
  mkdir -p "$DEST" || { er "No puedo escribir en $DEST"; return 1; }
  local f="$DEST/${PROD}_datos_$(date +%Y-%m-%d_%H%M).tar.gz"
  if tar czf "$f" -C "$RAIZ" data 2>/dev/null && tar tzf "$f" >/dev/null 2>&1; then
    (cd "$DEST" && sha256sum "$(basename "$f")" > "$f.sha256")
    ok "Datos guardados en $f ($(du -h "$f" | cut -f1)) · SHA-256 en $f.sha256"
    echo "     Para volver a usarlos: instalá $PROD de nuevo, detenelo, reemplazá su carpeta data/ por la de esta copia e inicialo."
  else er "No se pudo guardar la copia de los datos: no sigo (nada se borró)"; return 1; fi
}

paso_detener() {
  if [[ -f "$LANZ" ]]; then bash "$LANZ" stop >/dev/null 2>&1 && ok "Nodo detenido" || av "El lanzador no pudo detener el nodo (quizá ya estaba parado)"; fi
  local p; for p in "$RAIZ"/data/run/*.pid; do
    [[ -f "$p" ]] || continue; local pid; pid=$(cat "$p" 2>/dev/null) || continue
    if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null && tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null | grep -q "$RAIZ"; then kill "$pid" 2>/dev/null; sleep 1; kill -9 "$pid" 2>/dev/null; av "Proceso $(basename "$p" .pid) ($pid) terminado a la fuerza"; fi
  done
  rm -f "${XDG_RUNTIME_DIR:-/tmp}"/*"$(printf '%s' "$PROD" | tr '[:upper:]' '[:lower:]')"*.sock 2>/dev/null || true
}

paso_accesos() {
  [[ -f "$LANZ" ]] && bash "$LANZ" acceso-directo --quitar >/dev/null 2>&1 || true
  local esc; esc="$(command -v xdg-user-dir >/dev/null && xdg-user-dir DESKTOP 2>/dev/null || true)"; [[ -n "$esc" && -d "$esc" ]] || esc="$HOME/Escritorio"; [[ -d "$esc" ]] || esc="$HOME/Desktop"
  rm -f "$HOME/.local/share/applications/$SLUG.desktop" "$esc/$SLUG.desktop" 2>/dev/null
  command -v update-desktop-database >/dev/null && update-desktop-database "$HOME/.local/share/applications" 2>/dev/null || true
  ok "Accesos directos quitados (escritorio y menú)"
}

paso_firewall() {
  [[ -n "$FW_CERRAR" ]] || { ok "Firewall: el instalador no abrió puertos"; return; }
  [[ $FW_SI == 1 ]] || { av "Firewall: queda abierto por tu elección (--conservar-firewall)"; return; }
  echo "  El instalador abrió el puerto del nodo en el firewall. Para cerrarlo hace falta permiso de administrador:"
  echo "     sudo $FW_CERRAR"
  if [[ $SI == 1 ]]; then av "Firewall: cerralo con el comando de arriba"; return; fi
  if command -v pkexec >/dev/null && [[ -n "${DISPLAY:-}${WAYLAND_DISPLAY:-}" ]]; then
    if preguntar "¿Cierro el puerto ahora (se abre la ventana de contraseña del sistema)?"; then
      pkexec sh -c "$FW_CERRAR" >/dev/null 2>&1 && ok "Puerto cerrado en el firewall" || av "No se cerró el puerto: hacelo con el comando de arriba"
    else av "Firewall: queda abierto; podés cerrarlo con el comando de arriba"; fi
  else av "Firewall: pegá el comando de arriba en una terminal para cerrar el puerto"; fi
}

paso_borrar() {
  [[ -d "$RAIZ" ]] || return 0
  case "$RAIZ" in "$HOME"|/|/home|/usr|/etc|"") er "Ruta sospechosa, no se borra: $RAIZ"; return 1;; esac
  rm -rf "$RAIZ" && ok "Carpeta borrada: $RAIZ" || { er "No se pudo borrar $RAIZ por completo (¿algún archivo en uso?)"; return 1; }
  # la carpeta de arriba (base/Producto) se borra solo si quedaron únicamente los archivos que dejó el instalador
  if [[ -n "$BASE_PROD" && -d "$BASE_PROD" ]]; then
    local resto=0 f
    while IFS= read -r -d '' f; do
      local n; n="$(basename "$f")"; local conocido=0
      for k in "${ARCHIVOS_BASE[@]}"; do [[ "$n" == "$k" || "$n" == informe_instalacion_* || "$n" == uninstall-* ]] && conocido=1; done
      [[ $conocido == 1 ]] || resto=1
    done < <(find "$BASE_PROD" -mindepth 1 -maxdepth 1 -print0)
    if [[ $resto == 0 ]]; then rm -rf "$BASE_PROD" && ok "Carpeta borrada: $BASE_PROD"; else av "Se conserva $BASE_PROD porque tiene otros archivos tuyos"; fi
  fi
}

paso_cache() {
  [[ $CACHE_SI == 1 ]] || return 0
  [[ -d "$CACHE" ]] && rm -rf "$CACHE" && ok "Caché del instalador borrada ($CACHE)" || av "No había caché para borrar"
}

main() {
  echo; paso_resumen
  if [[ $SI == 0 ]]; then preguntar "¿Desinstalar $PROD? Se va a borrar la carpeta de arriba" || { echo "Cancelado. No se tocó nada."; exit 0; }; fi
  echo; echo "1) Detener"; paso_detener
  echo "2) Datos"; paso_datos || exit 1
  echo "3) Accesos directos"; paso_accesos
  echo "4) Firewall"; paso_firewall
  echo "5) Borrar"; paso_borrar; paso_cache
  local reg="$HOME/desinstalacion_${PROD}_$(date +%Y-%m-%d_%H%M).log"
  { echo "Desinstalación de $PROD · $(date '+%Y-%m-%d %H:%M') · instalado el $INSTALADO (iProy $VERSION) en $RAIZ"; printf '%s\n' "${REG[@]}"
    echo "No se deshacen: librerías del sistema que se hayan instalado (libaio, libxcrypt-compat…) ni la sincronización de hora (NTP)."; } > "$reg"
  echo; echo "Listo. Registro: $reg"
  echo "Para instalar de nuevo: https://iproy.com.ar/pim/instalar"
}
main "$@"
