#!/usr/bin/env bash
# Baja FrankenPHP v1.13.3 de GitHub y verifica el sha256 fijado (registrado el 28-10-2026).
# Uso: tools/bajar-runtime.sh [linux|windows|todo]   (por defecto: linux)
set -euo pipefail
RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
URL=https://github.com/php/frankenphp/releases/download/v1.13.3
SHA_LINUX=f88db9972ddac5e4c097c8b703e585255e181411687e5a1f79ca4b6328526e0b   # frankenphp-linux-x86_64 (175484880 B)
SHA_WIN=89b99aed53fbf8bfe718a816a987001d499bf92e2e1288e882285a8d940ba00e     # frankenphp-windows-x86_64.zip (60415937 B)
mkdir -p "$RAIZ/descargas" "$RAIZ/runtime/linux-x64"   # en un producto recién incubado todavía no existen (1.1.1)
bajar() { # nombre sha destino
  local tmp="$RAIZ/descargas/$1.part"
  curl -fL --retry 3 -C - -o "$tmp" "$URL/$1"
  echo "$2  $tmp" | sha256sum -c --quiet || { echo "✘ sha256 distinto en $1: NO se instala"; rm -f "$tmp"; exit 1; }
  mv "$tmp" "$3"; echo "✔ $1 verificado ($(echo "$2" | cut -c1-16))"
}
que="${1:-linux}"
if [[ $que == linux || $que == todo ]]; then
  bajar frankenphp-linux-x86_64 $SHA_LINUX "$RAIZ/runtime/linux-x64/frankenphp"; chmod +x "$RAIZ/runtime/linux-x64/frankenphp"
fi
if [[ $que == windows || $que == todo ]]; then
  bajar frankenphp-windows-x86_64.zip $SHA_WIN "$RAIZ/descargas/frankenphp-windows-x86_64.zip"
  mkdir -p "$RAIZ/runtime/win-x64/frankenphp"
  (cd "$RAIZ/runtime/win-x64/frankenphp" && unzip -q -o "$RAIZ/descargas/frankenphp-windows-x86_64.zip")
  echo "✔ descomprimido en runtime/win-x64/frankenphp"
fi
