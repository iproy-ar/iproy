# Licencias de los componentes redistribuidos · iProy-Portable v1.0.0

| Componente | Versión | Licencia | Texto en este paquete | Estado |
|---|---|---|---|---|
| FrankenPHP | v1.13.3 | MIT | 🔲 falta: copiar `LICENSE` de https://github.com/php/frankenphp/blob/v1.13.3/LICENSE | 🟡 licencia por verificar (GitHub bloqueado desde el entorno de armado) |
| Caddy (dentro de FrankenPHP) | v2.11.7 | Apache-2.0 | 🔲 falta: `LICENSE` de https://github.com/caddyserver/caddy | 🔵 Apache-2.0 (paquete de Arch) |
| PHP (dentro de FrankenPHP) | 8.5.12 | PHP License v3.01 | `PHP-LICENSE-3.01.txt` (viene en el ZIP de Windows) | 🔵 |
| MariaDB Server | 11.4.x | GPLv2 | Viene `COPYING` dentro del ZIP o tarball oficial (`runtime/*/mariadb/`). **Oferta de código fuente:** https://mariadb.org/download/ (versión exacta en `runtime/*/mariadb/VERSION`) | 🔵 |
| Bibliotecas del ZIP de Windows (ICU, OpenSSL, libsodium, brotli, glib…) | — | Varias | `runtime/win-x64/frankenphp/extras/sbom/*.json` (SBOM SPDX y CycloneDX de PHP) | 🔵 |

El código propio del nodo (`iProy-Control.*`, `app/`, `modulos/`, `conf/`, `tools/`, `sdk/`) es software libre: **GNU AGPL-3.0-or-later** (texto completo en `LICENSE`).
Copyright © 2023-2026 Proyecto iProy y colaboradores. Cada archivo lleva su cabecera `SPDX-License-Identifier`.
