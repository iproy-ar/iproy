#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Reemplaza los marcadores que quedan para completar al publicar:
#   CUENTA-GITHUB     → usuario u organización de GitHub (p. ej. iproy)
#   CORREO-SEGURIDAD  → correo para reportar vulnerabilidades (p. ej. seguridad@iproy.com.ar)
#   bash herramientas/fijar-github.sh <cuenta> [correo-de-seguridad]
set -euo pipefail
R="$(cd "$(dirname "$0")/.." && pwd)"; cd "$R"
C="${1:?uso: fijar-github.sh <cuenta-de-github> [correo-de-seguridad]}"; M="${2:-}"
[[ "$C" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,37}[A-Za-z0-9])?$ ]] || { echo "Cuenta de GitHub inválida: $C"; exit 1; }
[[ -z "$M" || "$M" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$ ]] || { echo "Correo inválido: $M"; exit 1; }
n=0
while IFS= read -r f; do
  [[ -f "$f" ]] || continue
  grep -qE 'CUENTA-GITHUB|CORREO-SEGURIDAD' "$f" 2>/dev/null || continue
  [[ "$f" == herramientas/fijar-github.sh || "$f" == docs/publicar-en-github.md ]] && continue
  sed -i "s/CUENTA-GITHUB/$C/g" "$f"
  if [[ -n "$M" ]]; then sed -i "s/CORREO-SEGURIDAD/$M/g; s/ (⚠ se completa al publicar con \`herramientas\/fijar-github.sh\`)//" "$f"; fi
  grep -q 'CUENTA-GITHUB\|CORREO-SEGURIDAD' "$f" || sed -i '/marcador-publicacion/d' "$f"
  echo "  · $f"; n=$((n+1))
done < <(git ls-files 2>/dev/null || find . -type f -not -path './.git/*')
echo "✔ $n archivos actualizados"
q=$(git grep -l -E 'CUENTA-GITHUB|CORREO-SEGURIDAD' -- ':!herramientas/fijar-github.sh' ':!docs/publicar-en-github.md' 2>/dev/null || true)
[[ -z "$q" ]] && echo "✔ no quedan marcadores" || { echo "Quedan marcadores en:"; echo "$q"; }
