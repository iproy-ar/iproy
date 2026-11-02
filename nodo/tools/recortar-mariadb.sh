#!/usr/bin/env bash
# =====================================================================
# Arma el runtime RECORTADO de MariaDB 11.4 a partir de los archivos OFICIALES
# (verificados contra el sha256 publicado por mariadb.org). Reproducible.
#   tools/recortar-mariadb.sh <oficial.tar.gz|oficial.zip> <sha256 oficial> <salida-dir>
# Quita: suite de pruebas, encabezados, man, símbolos de depuración (.pdb / strip --strip-debug),
# herramientas de backup/binlog/aria y motores que el iProy no usa (RocksDB, Mroonga, Spider,
# CONNECT, OQGraph, Sphinx, S3, FederatedX, HandlerSocket). El servidor, el cliente, mariadb-dump,
# mariadb-admin, mariadb-install-db, las tablas del sistema y los plugins de autenticación quedan.
# =====================================================================
set -euo pipefail
src="$1"; sha="$2"; out="$3"
echo "$sha  $src" | sha256sum -c --quiet || { echo "✘ sha256 del archivo oficial no coincide"; exit 1; }
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
case "$src" in  # ⚠ sin coincidencia = error
  *.tar.gz|*.tgz) tar -xzf "$src" -C "$tmp" --strip-components=1 --exclude='*/mariadb-test' --exclude='*/mysql-test' --exclude='*/sql-bench' ;;
  *.zip)    unzip -q "$src" -d "$tmp/z" && mv "$tmp"/z/*/* "$tmp"/ && rm -rf "$tmp/z" ;;
  *) echo "✘ formato no soportado: $src"; exit 1;;
esac
rm -rf "$tmp"/{include,man,mariadb-test,mysql-test,sql-bench,lib/pkgconfig,lib/galera}
find "$tmp" -type f \( -name '*.pdb' -o -name '*.lib' -o -name '*.a' \) -delete
for x in ha_rocksdb ha_mroonga ha_spider ha_connect ha_oqgraph ha_sphinx ha_s3 ha_federatedx handlersocket ha_test_sql_discovery; do
  rm -f "$tmp/lib/plugin/$x".so "$tmp/lib/plugin/$x".dll
done
rm -f "$tmp"/lib/libgalera_smm.so
# binarios: se conserva solo lo que usa iProy-Control
keep='^(mariadbd|mysqld|mariadb|mysql|mariadb-admin|mysqladmin|mariadb-dump|mysqldump|mariadb-check|mariadb-upgrade|mysql_upgrade|my_print_defaults|resolveip|mariadb-tzinfo-to-sql|mariadb-install-db|mysql_install_db|server|libmariadb)(\.exe|\.dll)?$'
for f in "$tmp"/bin/*; do
  b="$(basename "$f")"
  [[ "$b" =~ $keep || "$b" == *.dll ]] || rm -f "$f"
done
# en Windows las DLL de motores no usados también se van; las de runtime (libmariadb, server) quedan
if command -v strip >/dev/null; then
  for f in "$tmp"/bin/* "$tmp"/lib/*.so* "$tmp"/lib/plugin/*.so; do
    [[ -f "$f" && ! -L "$f" ]] && file "$f" | grep -q ELF && strip --strip-debug "$f" || true
  done
fi
mkdir -p "$out"; cp -a "$tmp"/. "$out"/
{ echo "origen: $(basename "$src")"; echo "sha256_oficial: $sha"; echo "recortado: $(date -u +%FT%TZ) con tools/recortar-mariadb.sh"; } > "$out/VERSION"
( cd "$out" && find . -type f ! -name MANIFEST.sha256 -print0 | sort -z | xargs -0 sha256sum ) > "$out/MANIFEST.sha256"
echo "✔ runtime recortado en $out ($(du -sh "$out" | cut -f1))"
