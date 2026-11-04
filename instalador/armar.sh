#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Arma los instaladores gráficos de un solo archivo (la interfaz va embebida en base64):
#   dist/instalar-iproy-linux.sh          (bash + Python 3, que traen todas las distribuciones de escritorio)
#   dist/Instalador-iProy-Windows.zip     (Instalar-iProy.bat + Instalar-iProy.ps1 con BOM para PowerShell 5.1)
#   dist/SHA256SUMS.txt
set -euo pipefail
A="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; D="$A/dist"; rm -rf "$D"; mkdir -p "$D"
UI="$(base64 -w0 "$A/ui.html")"
VER="$(grep -m1 "^VERSION = " "$A/instalador.py" | cut -d"'" -f2)"

# ---- Linux: un .sh que guarda el Python en la caché del usuario y lo ejecuta
PY="$(sed "s|UI_EMBEBIDA = '__UI_HTML_B64__'|UI_EMBEBIDA = '$UI'|" "$A/instalador.py" | base64 -w0)"
cat > "$D/instalar-iproy-linux.sh" <<EOF
#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Instalador gráfico de iProy $VER para Linux. Uso: bash instalar-iproy-linux.sh   (con tu usuario, sin sudo)
set -euo pipefail
if [[ \$(id -u) == 0 ]]; then echo "No lo ejecutes como root ni con sudo: usá tu usuario normal. Cuando haga falta, el instalador pide permiso."; exit 1; fi
if ! command -v python3 >/dev/null; then
  echo "Falta Python 3. Instalalo con el gestor de paquetes de tu distribución y volvé a ejecutar este archivo:"
  echo "  Debian/Ubuntu/Mint: sudo apt install python3     Fedora: sudo dnf install python3     Arch/EndeavourOS: sudo pacman -S python"
  exit 1
fi
C="\${XDG_CACHE_HOME:-\$HOME/.cache}/iproy-instalador"; mkdir -p "\$C"
base64 -d > "\$C/instalador.py" <<'PY'
$PY
PY
exec python3 "\$C/instalador.py" "\$@"
EOF
chmod +x "$D/instalar-iproy-linux.sh"

# ---- Windows: .bat (doble clic) + .ps1 con BOM (PowerShell 5.1 lee como ANSI los archivos sin BOM)
W="$D/w"; mkdir -p "$W"
{ printf '\xEF\xBB\xBF'; sed "s|\$UI_B64 = '__UI_HTML_B64__'|\$UI_B64 = '$UI'|" "$A/instalador.ps1" | sed '1s/^\xEF\xBB\xBF//'; } > "$W/Instalar-iProy.ps1"
printf '@echo off\r\nrem Instalador grafico de iProy %s - doble clic para empezar\r\ntitle Instalador de iProy\r\npowershell.exe -NoProfile -ExecutionPolicy Bypass -File "%%~dp0Instalar-iProy.ps1" %%*\r\nif errorlevel 1 pause\r\n' "$VER" > "$W/Instalar-iProy.bat"
printf 'Instalador de iProy %s para Windows 10/11\r\n\r\n1. Descomprimi esta carpeta (boton derecho > Extraer todo).\r\n2. Doble clic en Instalar-iProy.bat (con tu usuario de siempre, no como Administrador).\r\n3. Se abre el navegador: segui los pasos. Cuando haga falta, Windows pide permiso.\r\n\r\nNo hace falta instalar Git ni PHP. Tutorial: https://iproy.com.ar/pim/instalar\r\n' "$VER" > "$W/LEEME.txt"
(cd "$W" && zip -q -X "$D/Instalador-iProy-Windows.zip" Instalar-iProy.bat Instalar-iProy.ps1 LEEME.txt)
cp "$W/Instalar-iProy.ps1" "$D/"; rm -rf "$W"
(cd "$D" && sha256sum instalar-iproy-linux.sh Instalador-iProy-Windows.zip Instalar-iProy.ps1 > SHA256SUMS.txt)
ls -la "$D"; cat "$D/SHA256SUMS.txt"
