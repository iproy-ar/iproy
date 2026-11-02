#!/usr/bin/env bash
# =====================================================================
#  iProy-Control · iProy Portable «Colmena» · v1.0.0 · 02-11-2026 (núcleo modular + colmena + Servidor PIM)
#  Lanzador del nodo para Linux x64. No requiere permisos de administrador.
#
#  Uso:  ./iProy-Control.sh <comando> [opciones]
#    init [--nombre "Pañol Central"] [--modo local|lan] [--puerto 8080] [--puerto-db 3407]
#                         primer arranque: crea data/, claves, base y config
#    start [--no-abrir]   inicia MariaDB + FrankenPHP + worker y abre el navegador
#    stop                 detiene todo en orden inverso
#    status               estado de cada proceso y prueba HTTP
#    instalar-mariadb     descomprime descargas/mariadb-11.4.*-linux-*.tar.gz en runtime/ y verifica su sha256
#    importar-app         copia descargas/iProy → app/iProy (sin *.local.php ni respaldos; no usa GestionMant)
#    importar-datos       carga descargas/sql/*.sql en las bases locales
#    respaldo             volcado completo fechado + sha256 (rotación 7 diarios)
#    restaurar [archivo]  rehace la base del nodo desde el último respaldo (la actual queda en data/mariadb.anterior_*)
#    verificar            compara sha256 de runtime/ app/ conf/ tools/ con MANIFEST.sha256
#    confiar-ca           agrega la CA del nodo al almacén NSS del usuario (Chrome/Firefox)
#    fhir-cliente alta <id> <jwks.json> ["system/*.read"]   registra un sistema externo (API FHIR)
#    fhir-cliente baja <id> | lista | demo | token <id> <clave_privada.pem>
#    pim importar         refleja ahora iProy en el Servidor PIM (el worker lo hace solo cada 5 min)
#    pim cliente alta <id> <jwks.json> <GM|EJ|SO|FT|CI|RE> [organización] ["system/*.cruds"]   registra un sistema en PIM
#    pim cliente baja <id> | lista · pim token <id> <clave_privada.pem> [alcances] · pim prueba · pim verificar · pim consola
#    pim remoto https://<IP>:8443/pim/r4 <client_id> <clave.pem>   prueba la API PIM de otro nodo de la colmena
#    Consola PIM: http://localhost:<puerto>/pim/  ·  API: /pim/r4/metadata  (manual: docs/pim/)
#    red [estado]         conexión con los servidores centrales, cola de cambios e IP del nodo
#    red modo local|lan   solo este equipo o visible en la red local (aplica al reiniciar)
#    nombre "PC Taller"   cambia el nombre visible de este equipo en la colmena
#    precargar            con Internet: guarda en el nodo librerías, APIs y archivos que usa iProy (también al iniciar)
#    clave-auth           carga (oculta) la clave de autenticación para el ingreso sin red · clave-auth --borrar
#    nucleo estado|arranque|tick|modulos|tareas|diario [n]|verificar|bus|ejecutar <modulo:tarea>   núcleo e introspección (/api/proc)
#    modulo activar|desactivar <id> · modulo config <id> [archivo.json]
#    usuario alta <u> <AD|GM|EJ|SO|FT|CI> [organización] · usuario clave|baja <u> · usuario token <u> [días] · usuario lista
#    colmena identidad|lista · colmena vincular <https://IP:8443 | ID> · colmena aceptar <par> [rol] · colmena revocar|ping <par>
#    colmena via <par> auto|hub · colmena rpc <par> GET <ruta FHIR> · colmena hub <https://hosting/colmena-hub> [código] · colmena url <url>
#    Aplicación: http://localhost:<puerto>/app/ · API del núcleo: /api · Panel de red: /_iproy/red.php
#
#  Reglas: nada de secretos en el paquete; todo lo generado vive en data/.
# =====================================================================
set -euo pipefail
umask 077

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RT="$RAIZ/runtime/linux-x64"
FP="$RT/frankenphp"
MDB="${IPROY_MARIADB_DIR:-$RT/mariadb}"          # bintar oficial descomprimido (bin/ scripts/ share/)
DATA="${IPROY_DATA_DIR:-$RAIZ/data}"
APP="$RAIZ/app"
RUN="$DATA/run"
VER="1.0.0"
ALFABETO="23456789ABCDEFGHJKMNPQRSTUVWXYZ"     # sin 0 O 1 I L (P-1)
BASES="legado_iproy legado_requerimientos legado_stock_mant legado_iot legado_user_gestion legado_analiticas legado_preventivos iproy"

log()  { printf '[IPROY %s] %s\n' "$(date +%H:%M:%S)" "$*"; }
fallo(){ printf '[IPROY] ✘ %s\n' "$*" >&2; exit 1; }
aleatorio() { head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "${1:-32}"; }
hexa() { head -c "${1:-32}" /dev/urandom | od -An -tx1 | tr -d ' \n'; }
b64url() { base64 -w0 | tr '+/' '-_' | tr -d '='; }
nodo_id() {
  local id
  while :; do
    id=""
    for b in $(head -c 3 /dev/urandom | od -An -tu1); do id+="${ALFABETO:$((b % 31)):1}"; done
    [[ "$id" != "HUB" && "$id" != "IPROY" ]] && { echo "$id"; return; }
  done
}
cargar_env() {
  [[ -f "$DATA/iproy.env" ]] || fallo "falta data/iproy.env: corré primero «init»"
  # shellcheck disable=SC1091
  set -a; . "$DATA/iproy.env"; . "$DATA/secrets.env"; set +a
  export IPROY_APP="$APP" IPROY_DATA="$DATA" PHPRC="$DATA/php"
  # el socket Unix admite como máximo 107 caracteres: va a una ruta corta, no dentro de data/ (L-293)
  SOCK="${XDG_RUNTIME_DIR:-/tmp}/iproy-${IPROY_NODE}-$(id -u).sock"
}
mdb_bin() { local n; for n in "$@"; do [[ -x "$MDB/bin/$n" ]] && { echo "$MDB/bin/$n"; return; }; done; fallo "no está $1 en $MDB/bin"; }
cliente() { "$(mdb_bin mariadb mysql)" --defaults-file="$DATA/my.cnf" --socket="$SOCK" -uroot -p"$IPROY_DB_ROOT_PASS" "$@"; }
# ¿Proceso vivo Y es el nuestro? Tras un reinicio el PID guardado puede pertenecer a otro programa (L-347):
# sin este control, start creía que FrankenPHP ya corría y decía «Listo» sin servidor.
vivo() {
  local p pat; [[ -f "$RUN/$1.pid" ]] || return 1; p="$(cat "$RUN/$1.pid")"; kill -0 "$p" 2>/dev/null || return 1
  case "$1" in mariadb) pat=mariadbd;; frankenphp) pat=frankenphp;; residente) pat='residente.php mqtt';; residente_mem) pat='residente.php mem';; *) pat=Control;; esac
  [[ -r "/proc/$p/cmdline" ]] || return 0
  tr '\0' ' ' < "/proc/$p/cmdline" | grep -q "$pat"
}
como_root() { [[ "$(id -u)" == 0 ]] && echo "--user=root" || true; }

# ---------------------------------------------------------------- init
nombre_limpio() {
  local n="${1//[^[:alnum:] ._()áéíóúÁÉÍÓÚñÑüÜ-]/}"
  n="${n#"${n%%[! ]*}"}"; n="${n%"${n##*[! ]}"}"; n="${n:0:40}"
  printf '%s' "${n:-Nodo IPROY}"
}
cmd_nombre() {
  cargar_env
  local n="${1:-}"
  [[ -n "$n" ]] || { read -r -p "Nombre nuevo de este equipo (ahora: $IPROY_NOMBRE): " n || true; }
  n="$(nombre_limpio "$n")"
  sed -i "s/^IPROY_NOMBRE=.*/IPROY_NOMBRE=\"$n\"/" "$DATA/iproy.env"
  sed -i "s/\"nombre\":\"[^\"]*\"/\"nombre\":\"$n\"/" "$DATA/node.json"
  cargar_env; generar_config_php
  log "✔ el nodo $IPROY_NODE ahora se llama «$n». Se ve en la app al recargar; la base se actualiza en el próximo start."
}
# mariadb-install-db (script de MariaDB) no tolera espacios en las rutas («Sistemas WEB»: L-326).
# Se le pasan enlaces sin espacios en el directorio temporal del usuario, y las opciones necesarias sin my.cnf.
instalar_base_vacia() {
  chmod u+x "$MDB"/bin/* "$MDB"/scripts/* 2>/dev/null || true
  local inst="$MDB/scripts/mariadb-install-db"; [[ -x "$inst" ]] || inst="$(mdb_bin mariadb-install-db mysql_install_db)"
  local L="${XDG_RUNTIME_DIR:-/tmp}/iproy-inst-$$"
  mkdir -p "$L" "$DATA/mariadb"
  ln -sfn "$MDB" "$L/mdb"; ln -sfn "$DATA/mariadb" "$L/datos"
  local rc=0
  "$L/mdb/scripts/$(basename "$inst")" --no-defaults --basedir="$L/mdb" --datadir="$L/datos" --lower-case-table-names=1 \
          --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci \
          --auth-root-authentication-method=normal --skip-test-db $(como_root) > "$DATA/logs/install-db.log" 2>&1 || rc=$?
  rm -f "$L/mdb" "$L/datos"; rmdir "$L" 2>/dev/null || true
  [[ $rc -eq 0 ]] || { tail -20 "$DATA/logs/install-db.log"; fallo "mariadb-install-db falló (detalle en data/logs/install-db.log)"; }
}

# Base recién creada por mariadb-install-db: contraseña de root, root por TCP (pendrive Windows/Linux, L-299),
# usuario de la app y las 8 bases. La usan «init» y «restaurar» (mismas contraseñas de data/secrets.env).
crear_usuarios_base() {
  # root sin contraseña recién creado (solo socket local) → se le pone la generada
  "$(mdb_bin mariadb mysql)" --defaults-file="$DATA/my.cnf" --socket="$SOCK" -uroot <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED BY '$IPROY_DB_ROOT_PASS';
DELETE FROM mysql.global_priv WHERE User='' OR (User='root' AND Host NOT IN ('localhost','127.0.0.1','::1'));
FLUSH PRIVILEGES;
-- root también por TCP (127.0.0.1 y ::1): así la misma data/ funciona si el pendrive pasa a Windows (L-299)
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '$IPROY_DB_ROOT_PASS';
CREATE USER IF NOT EXISTS 'root'@'::1' IDENTIFIED BY '$IPROY_DB_ROOT_PASS';
ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY '$IPROY_DB_ROOT_PASS';
ALTER USER 'root'@'::1' IDENTIFIED BY '$IPROY_DB_ROOT_PASS';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON *.* TO 'root'@'::1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
  local sql="CREATE USER IF NOT EXISTS 'iproy_app'@'127.0.0.1' IDENTIFIED BY '$IPROY_DB_APP_PASS';
CREATE USER IF NOT EXISTS 'iproy_app'@'localhost' IDENTIFIED BY '$IPROY_DB_APP_PASS';"
  for b in $BASES; do
    sql+="CREATE DATABASE IF NOT EXISTS \`$b\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,CREATE VIEW,SHOW VIEW,CREATE TEMPORARY TABLES,LOCK TABLES,EXECUTE,REFERENCES,TRIGGER ON \`$b\`.* TO 'iproy_app'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,CREATE VIEW,SHOW VIEW,CREATE TEMPORARY TABLES,LOCK TABLES,EXECUTE,REFERENCES,TRIGGER ON \`$b\`.* TO 'iproy_app'@'localhost';"
  done
  cliente -e "$sql FLUSH PRIVILEGES;"
}

cmd_init() {
  local nombre="Nodo IPROY" modo="local" puerto="8080" puerto_db="3407"
  while [[ $# -gt 0 ]]; do case "$1" in
    --nombre) nombre="$2"; shift 2;; --modo) modo="$2"; shift 2;; --puerto) puerto="$2"; shift 2;;
    --puerto-db) puerto_db="$2"; shift 2;;   # dos nodos en el mismo equipo (pruebas de colmena)
    *) fallo "opción desconocida: $1";; esac; done
  [[ -e "$DATA/iproy.env" ]] && fallo "data/ ya existe (nodo inicializado). Para reinstalar, mové data/ a otro lado."
  # v0.4.5: el nombre del equipo se pide al instalar (se ve en la app: «Nodo ABC · PC Taller»)
  if [[ "$nombre" == "Nodo IPROY" && -t 0 ]]; then
    local sug; sug="$(hostname 2>/dev/null || echo 'Nodo IPROY')"
    read -r -p "Nombre de este equipo para la colmena (ej. PC Taller) [$sug]: " nombre || true
    [[ -n "$nombre" ]] || nombre="$sug"
  fi
  nombre="$(nombre_limpio "$nombre")"
  [[ -x "$FP" ]] || fallo "falta FrankenPHP en $FP"
  [[ -d "$MDB/bin" ]] || fallo "falta MariaDB en $MDB (descomprimí el bintar oficial ahí; ver INSTALAR.md)"
  mkdir -p "$DATA"/{run,logs,backups,outbox,inbox,adjuntos,caddy,php/sesiones,mariadb}
  local id; id="$(nodo_id)"
  log "nodo nuevo: $id · «$nombre» · modo $modo · puerto $puerto"

  cat > "$DATA/iproy.env" <<EOF
IPROY_NODE=$id
IPROY_NOMBRE="$nombre"
IPROY_MODO=$modo
IPROY_PUERTO=$puerto
IPROY_PUERTO_TLS=$((puerto + 363))
IPROY_DB_PORT=$puerto_db
IPROY_VERSION=$VER
EOF
  cat > "$DATA/secrets.env" <<EOF
IPROY_DB_ROOT_PASS=$(aleatorio 32)
IPROY_DB_APP_PASS=$(aleatorio 32)
IPROY_TICK_TOKEN=$(aleatorio 40)
IPROY_SESSION_SECRET=$(aleatorio 48)
EOF
  chmod 600 "$DATA/secrets.env"
  printf '{"node_id":"%s","nombre":"%s","confirmado":false,"alta_utc":"%s","version":"%s"}\n' \
    "$id" "$nombre" "$(date -u +%FT%TZ)" "$VER" > "$DATA/node.json"
  cargar_env

  escribir_config

  log "creando la base de datos (mariadb-install-db)…"
  instalar_base_vacia
  iniciar_mariadb
  crear_usuarios_base
  cliente iproy < "$RAIZ/conf/sql/01_ddl_sync.sql"
  cliente iproy -e "INSERT INTO sync_nodo (node_id,nombre,local_actual) VALUES ('$IPROY_NODE','$(printf %s "$nombre" | sed "s/'/''/g")',1);"
  generar_config_php
  detener_mariadb
  log "✔ nodo $IPROY_NODE listo. Siguiente: bash iProy-Control.sh start · después: bash iProy-Control.sh usuario alta admin AD"
}

# my.cnf y php.ini se regeneran en cada init/start con las rutas ACTUALES: así data/ sirve aunque la
# carpeta cambie de lugar (pendrive con otra letra o punto de montaje) o pase de Windows a Linux.
escribir_config() {
  cat > "$DATA/my.cnf" <<EOF
[mariadbd]
lower_case_table_names=1
basedir=$MDB
datadir=$DATA/mariadb
socket=$SOCK
pid-file=$RUN/mariadb.pid
bind-address=127.0.0.1
port=$IPROY_DB_PORT
log-error=$DATA/logs/mariadb.err
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
default-time-zone=-03:00
innodb_buffer_pool_size=256M
max_allowed_packet=64M
skip-name-resolve
[client]
socket=$SOCK
port=$IPROY_DB_PORT
default-character-set=utf8mb4
EOF
  sed -e "s|@DATA@|$DATA|g" -e "s|@APP@|$APP|g" "$RAIZ/conf/php.ini.tpl" > "$DATA/php/php.ini"
}

generar_config_php() {
  # Las credenciales quedan en data/php/nodo.php (600). La app las lee por los *.local.php
  # que se generan dentro de app/ y que SOLO contienen un require relativo (sin secretos).
  cat > "$DATA/php/nodo.php" <<EOF
<?php // generado por iProy-Control $VER — NO copiar a otro nodo
return [
  'node_id' => '$IPROY_NODE', 'nombre' => '$(printf %s "$IPROY_NOMBRE" | sed "s/'/\\\\'/g")', 'modo' => '$IPROY_MODO',
  'db_host' => '127.0.0.1;port=$IPROY_DB_PORT', 'db_user' => 'iproy_app', 'db_pass' => '$IPROY_DB_APP_PASS',
  'tick_token' => '$IPROY_TICK_TOKEN', 'session_secret' => '$IPROY_SESSION_SECRET',
];
EOF
  chmod 600 "$DATA/php/nodo.php"
  instalar_local_php
}

instalar_local_php() {
  # Solo si existe la copia de iProy. Se regeneran en cada start (app/ puede reemplazarse al actualizar).
  local api="$APP/iProy/api"; [[ -d "$api" ]] || return 0
  local cab="<?php // generado por iProy-Control (nodo portable). Sin secretos: los lee de data/php/nodo.php
\$N = require dirname(__DIR__, 3) . '/data/php/nodo.php';"
  printf '%s\n%s\n' "$cab" "return ['db_host'=>\$N['db_host'],'db_name'=>'legado_iproy','db_user'=>\$N['db_user'],'db_pass'=>\$N['db_pass'],
        'host'=>\$N['db_host'],'db'=>'legado_iproy','user'=>\$N['db_user'],'pass'=>\$N['db_pass'],'sync_token'=>\$N['tick_token']];" > "$api/db.local.php"
  local b="" k v
  for kv in req:legado_requerimientos stock:legado_stock_mant iot:legado_iot user:legado_user_gestion ana:legado_analiticas prev:legado_preventivos; do
    k="${kv%%:*}"; v="${kv#*:}"
    b+="  '$k' => ['db_host'=>\$N['db_host'],'db_name'=>'$v','db_user'=>\$N['db_user'],'db_pass'=>\$N['db_pass'],'db'=>'$v','user'=>\$N['db_user'],'pass'=>\$N['db_pass']],
"
  done
  printf '%s\nreturn [\n%s];\n' "$cab" "$b" > "$api/bases.local.php"
  [[ -f "$api/config.local.php" ]] || printf '%s\n%s\n' "$cab" "return ['modo' => 'mysql', 'sync_ahora' => false, 'nodo_portable' => \$N['node_id']];" > "$api/config.local.php"
}

# ---------------------------------------------------------------- API FHIR de interoperabilidad (v0.3)
# Usuario de base SOLO LECTURA sobre las vistas iproy_fhir.* (sin datos personales) + clave propia de tokens.
# Se ejecuta en cada start: actualiza solos los nodos v0.2 y recrea las vistas si se reimportaron datos.
asegurar_fhir() {
  mkdir -p "$DATA/fhir/clientes" "$DATA/fhir/run"; chmod 700 "$DATA/fhir"
  grep -q '^IPROY_FHIR_DB_PASS=' "$DATA/secrets.env" || echo "IPROY_FHIR_DB_PASS=$(aleatorio 32)" >> "$DATA/secrets.env"
  grep -q '^IPROY_FHIR_TOKEN_KEY=' "$DATA/secrets.env" || echo "IPROY_FHIR_TOKEN_KEY=$(hexa 32)" >> "$DATA/secrets.env"
  set -a; . "$DATA/secrets.env"; set +a
  [[ -f "$DATA/fhir/config.json" ]] || cp "$RAIZ/conf/fhir-config.json" "$DATA/fhir/config.json"
  cliente --force < "$RAIZ/conf/sql/02_fhir_vistas.sql" > "$DATA/logs/fhir_vistas.log" 2>&1 || log "⚠ alguna vista FHIR no se creó (¿faltan bases legado_*?): ver data/logs/fhir_vistas.log"
  local u sql=""
  for u in "'iproy_fhir'@'127.0.0.1'" "'iproy_fhir'@'localhost'"; do
    sql+="CREATE USER IF NOT EXISTS $u IDENTIFIED BY '$IPROY_FHIR_DB_PASS'; ALTER USER $u IDENTIFIED BY '$IPROY_FHIR_DB_PASS'; GRANT SELECT ON \`iproy_fhir\`.* TO $u;"
  done
  cliente -e "$sql FLUSH PRIVILEGES;"
  printf "<?php // generado por iProy-Control %s — NO copiar a otro nodo\nreturn ['db_host' => '127.0.0.1;port=%s', 'db_user' => 'iproy_fhir', 'db_pass' => '%s', 'token_key' => '%s'];\n" \
    "$VER" "$IPROY_DB_PORT" "$IPROY_FHIR_DB_PASS" "$IPROY_FHIR_TOKEN_KEY" > "$DATA/php/fhir.php"
  chmod 600 "$DATA/php/fhir.php"
}

# ---------------------------------------------------------------- Servidor PIM v1.0 (sistema paralelo, v0.5)
# Base propia iproy_pim. Usuario iproy_pim: lectura/escritura SOLO en iproy_pim y lectura de las vistas iproy_fhir (puente desde iProy).
# Nunca tiene permisos sobre las bases de iProy (legado_*, iproy).
asegurar_pim() {
  mkdir -p "$DATA/pim/clientes" "$DATA/pim/run" "$DATA/pim/consola"; chmod 700 "$DATA/pim"
  grep -q '^IPROY_PIM_DB_PASS=' "$DATA/secrets.env" || echo "IPROY_PIM_DB_PASS=$(aleatorio 32)" >> "$DATA/secrets.env"
  grep -q '^IPROY_PIM_TOKEN_KEY=' "$DATA/secrets.env" || echo "IPROY_PIM_TOKEN_KEY=$(hexa 32)" >> "$DATA/secrets.env"
  set -a; . "$DATA/secrets.env"; set +a
  [[ -f "$DATA/pim/config.json" ]] || cp "$RAIZ/conf/pim-config.json" "$DATA/pim/config.json"
  rm -f "$DATA/pim/run/consola_token.json"
  cliente < "$RAIZ/conf/sql/04_pim.sql" > "$DATA/logs/pim_sql.log" 2>&1 || { log "⚠ no se creó la base del Servidor PIM: ver data/logs/pim_sql.log"; return 0; }
  local u sql=""
  for u in "'iproy_pim'@'127.0.0.1'" "'iproy_pim'@'localhost'"; do
    sql+="CREATE USER IF NOT EXISTS $u IDENTIFIED BY '$IPROY_PIM_DB_PASS'; ALTER USER $u IDENTIFIED BY '$IPROY_PIM_DB_PASS';"
    sql+="GRANT SELECT, INSERT, UPDATE, DELETE ON \`iproy_pim\`.* TO $u; GRANT SELECT ON \`iproy_fhir\`.* TO $u;"
  done
  cliente -e "$sql FLUSH PRIVILEGES;"
  printf "<?php // generado por iProy-Control %s — NO copiar a otro nodo\nreturn ['db_host' => '127.0.0.1;port=%s', 'db_user' => 'iproy_pim', 'db_pass' => '%s', 'token_key' => '%s'];\n" \
    "$VER" "$IPROY_DB_PORT" "$IPROY_PIM_DB_PASS" "$IPROY_PIM_TOKEN_KEY" > "$DATA/php/pim.php"
  chmod 600 "$DATA/php/pim.php"
}
pim_importar() { curl -s -m 600 -X POST -H "X-IPROY-Tick: $IPROY_TICK_TOKEN" "http://127.0.0.1:$IPROY_PUERTO/pim/admin/importar.php"; }

cmd_pim() {
  cargar_env; mkdir -p "$DATA/pim/clientes"
  local acc="${1:-}"; shift || true
  case "$acc" in
    importar)
      log "reflejando iProy en el Servidor PIM (solo lectura de iProy)…"
      local j; j="$(pim_importar)" || fallo "el nodo no responde (start primero)"
      echo "$j" > "$DATA/logs/pim_importacion.json"
      echo "$j" | grep -q '"ok":true' || fallo "importación con error: $j"
      log "✔ $(echo "$j" | grep -o '"segundos":[0-9.]*') · detalle en data/logs/pim_importacion.json"
      echo "$j" | grep -o '"[A-Za-z]*":{"nuevo":[0-9]*,"cambio":[0-9]*,"igual":[0-9]*' | sed 's/^/   /';;
    cliente) cmd_pim_cliente "$@";;
    token) pim_token "${1:-}" "${2:-}" "${3:-system/*.read}";;
    prueba)  # cliente de prueba (solo lectura, rol CI) con clave RSA propia: muestra que la API PIM responde
      command -v openssl >/dev/null || fallo "falta openssl"
      local d="$DATA/pim/demo"; mkdir -p "$d"
      [[ -f "$d/clave_privada.pem" ]] || openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$d/clave_privada.pem" 2>/dev/null
      chmod 600 "$d/clave_privada.pem"
      local n; n="$(openssl rsa -in "$d/clave_privada.pem" -noout -modulus | cut -d= -f2 | sed 's/../\\x&/g')"
      # shellcheck disable=SC2059
      printf '{"keys":[{"kty":"RSA","kid":"prueba-1","alg":"RS384","use":"sig","e":"AQAB","n":"%s"}]}\n' "$(printf "$n" | b64url)" > "$d/jwks_publico.json"
      cmd_pim_cliente alta prueba-local "$d/jwks_publico.json" CI "" "system/*.read" >/dev/null
      local t; t="$(pim_token prueba-local "$d/clave_privada.pem" "system/*.read")" || fallo "no se obtuvo token (¿el nodo está iniciado?)"
      local b="http://127.0.0.1:$IPROY_PUERTO/pim/r4" r
      printf '  %-34s %s\n' "metadata" "$(curl -s "$b/metadata" | grep -o '"version":"[^"]*"' | head -1)"
      for r in "Organization?_summary=count" "Location?_summary=count" "Device?_summary=count" "ServiceRequest?_summary=count" "Task?_summary=count" "Observation?_summary=count"; do
        printf '  %-34s %s\n' "$r" "$(curl -s -H "Authorization: Bearer $t" "$b/$r" | grep -o '"total":[0-9]*')"
      done
      log "✔ Servidor PIM operativo. Consola: http://localhost:$IPROY_PUERTO/pim/";;
    remoto)  # remoto <https://IP:8443/pim/r4> <client_id> <clave_privada.pem>: prueba la API PIM de OTRO nodo de la colmena
      local base="${1:-}" id="${2:-}" clave="${3:-}"
      [[ "$base" =~ ^https?://[^/]+/pim/r4$ ]] || fallo "uso: pim remoto https://<IP>:8443/pim/r4 <client_id> <clave_privada.pem>"
      [[ -f "$clave" ]] || fallo "falta la clave privada $clave"
      local k=(); [[ "$base" == https* ]] && k=(-k) && log "⚠ prueba: no se verifica el certificado del otro nodo (su CA propia); para uso real, confiá su CA"
      local aud="${base%/r4}/auth/token" ahora; ahora=$(date +%s)
      local h p f t
      h="$(printf '{"alg":"RS384","typ":"JWT"}' | b64url)"
      p="$(printf '{"iss":"%s","sub":"%s","aud":"%s","exp":%d,"jti":"%s"}' "$id" "$id" "$aud" $((ahora + 240)) "$(hexa 16)" | b64url)"
      f="$(printf '%s.%s' "$h" "$p" | openssl dgst -sha384 -sign "$clave" | b64url)"
      t="$(curl -s "${k[@]}" -m 15 -X POST "$aud" --data-urlencode grant_type=client_credentials \
           --data-urlencode client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer \
           --data-urlencode "client_assertion=$h.$p.$f" --data-urlencode "scope=system/*.read")"
      printf '  %-34s %s\n' "metadata" "$(curl -s "${k[@]}" -m 10 "$base/metadata" | grep -o '"implementation":{"description":"[^"]*"' || echo 'no responde')"
      t="$(printf '%s' "$t" | grep -o '"access_token":"[^"]*"' | cut -d'"' -f4)"
      [[ -n "$t" ]] || fallo "el otro nodo no entregó token (¿registró este cliente con «pim cliente alta $id <jwks> CI»?)"
      local r; for r in "Organization?_summary=count" "Location?_summary=count" "Device?_summary=count" "Task?_summary=count"; do
        printf '  %-34s %s\n' "$r" "$(curl -s "${k[@]}" -m 10 -H "Authorization: Bearer $t" "$base/$r" | grep -o '"total":[0-9]*')"
      done
      log "✔ interoperabilidad con $base: token SMART y lecturas FHIR correctas";;
    verificar)  # recalcula la cadena de huellas de todas las versiones (detecta alteraciones directas en la base)
      local j; j="$(curl -s -m 300 -X POST -H "X-IPROY-Tick: $IPROY_TICK_TOKEN" "http://127.0.0.1:$IPROY_PUERTO/pim/admin/verificar.php")" || fallo "el nodo no responde"
      echo "$j" | grep -q '"ok":true' && log "✔ cadena íntegra: $(echo "$j" | grep -o '"versiones":[0-9]*,"recursos":[0-9]*')" || fallo "¡alteración detectada! $j";;
    consola) log "Consola PIM: http://localhost:$IPROY_PUERTO/pim/  (solo desde este equipo; ver docs/pim/MANUAL)";;
    *) fallo "uso: pim importar | cliente alta|baja|lista | token | prueba | remoto | verificar | consola";;
  esac
}
cmd_pim_cliente() {
  local acc="${1:-}"; shift || true
  case "$acc" in
    alta)
      local id="${1:-}" jwks="${2:-}" rol="${3:-}" org="${4:-}" scopes="${5:-}"
      [[ "$id" =~ ^[A-Za-z0-9._-]{3,64}$ ]] || fallo "client_id inválido (3-64 caracteres: letras, números, . _ -)"
      [[ "$id" == "consola-local" ]] && fallo "consola-local es el cliente interno de la Consola"
      [[ "$rol" =~ ^(GM|EJ|SO|FT|CI|RE)$ ]] || fallo "rol PIM: GM (gestor) | EJ (ejecutor) | SO (solicitante) | FT (telemetría) | CI (consulta) | RE (registro estatal)"
      [[ "$rol" == "EJ" && -z "$org" ]] && fallo "un Ejecutor necesita su organización (código de la empresa o área, p. ej. \"MANT\")"
      { [[ -f "$jwks" ]] && grep -q '"keys"' "$jwks"; } || fallo "falta el archivo JWKS con las claves públicas del sistema"
      grep -q '"d"' "$jwks" && fallo "el JWKS contiene una clave PRIVADA (campo d): pedí solo la pública"
      if [[ -z "$scopes" ]]; then case "$rol" in
        GM) scopes="system/*.cruds";; CI) scopes="system/*.rs";;
        EJ) scopes="system/*.rs system/Task.u";; SO) scopes="system/*.rs system/ServiceRequest.c";;
        FT) scopes="system/Device.rs system/Observation.crs";; esac; fi
      local lista; lista="$(printf '%s' "$scopes" | tr ' ' '\n' | sed '/^$/d; s/.*/"&"/' | paste -sd, -)"
      printf '{\n  "client_id": "%s",\n  "activo": true,\n  "alta": "%s",\n  "rol": "%s",\n  "organizacion": "%s",\n  "scopes": [%s],\n  "jwks": %s\n}\n' \
        "$id" "$(date -Iseconds)" "$rol" "${org//\"/}" "$lista" "$(cat "$jwks")" > "$DATA/pim/clientes/$id.json"
      log "✔ cliente PIM $id · rol $rol${org:+ · $org} · alcances: $scopes";;
    baja)
      local id="${1:-}"; [[ -f "$DATA/pim/clientes/$id.json" ]] || fallo "no existe el cliente $id"
      mkdir -p "$DATA/pim/clientes/_bajas"; mv "$DATA/pim/clientes/$id.json" "$DATA/pim/clientes/_bajas/$id.$(date +%Y%m%d_%H%M%S).json"
      log "✔ cliente $id dado de baja (archivo en data/pim/clientes/_bajas/)";;
    lista)
      local f; for f in "$DATA"/pim/clientes/*.json; do [[ -f "$f" ]] || continue
        printf '  %-24s %-4s %s\n' "$(basename "$f" .json)" "$(grep -o '"rol": *"[A-Z]*"' "$f" | grep -o '[A-Z]*"$' | tr -d '"')" "$(grep -o '"scopes": *\[[^]]*\]' "$f")"; done;;
    *) fallo "uso: pim cliente alta <id> <jwks.json> <rol> [organización] [alcances] | baja <id> | lista";;
  esac
}
# Aserción RS384 firmada con openssl y canjeada por un access_token del Servidor PIM.
pim_token() {
  local id="$1" clave="$2" scope="${3:-system/*.read}"
  [[ -f "$clave" ]] || fallo "falta la clave privada $clave"
  [[ -f "$DATA/pim/clientes/$id.json" ]] || fallo "no existe el cliente PIM $id"
  local aud="http://127.0.0.1:$IPROY_PUERTO/pim/auth/token" ahora; ahora=$(date +%s)
  local kid; kid="$(grep -o '"kid": *"[^"]*"' "$DATA/pim/clientes/$id.json" | head -1 | sed 's/.*"\([^"]*\)"$/\1/')"
  local h p f
  h="$(printf '{"alg":"RS384","typ":"JWT","kid":"%s"}' "$kid" | b64url)"
  p="$(printf '{"iss":"%s","sub":"%s","aud":"%s","exp":%d,"jti":"%s"}' "$id" "$id" "$aud" $((ahora + 240)) "$(hexa 16)" | b64url)"
  f="$(printf '%s.%s' "$h" "$p" | openssl dgst -sha384 -sign "$clave" | b64url)"
  curl -s -X POST "$aud" --data-urlencode grant_type=client_credentials \
       --data-urlencode client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer \
       --data-urlencode "client_assertion=$h.$p.$f" --data-urlencode "scope=$scope" \
    | grep -o '"access_token":"[^"]*"' | cut -d'"' -f4 | grep . || { echo "[IPROY] ✘ el Servidor PIM rechazó la aserción" >&2; return 1; }
}

# ---------------------------------------------------------------- modo autónomo y red (v0.4)
# Tablas de login local, caché y cola (idempotente) + IP actuales del equipo para el panel de Redes.
asegurar_offline() {
  mkdir -p "$DATA/cdn"; chmod 700 "$DATA/cdn"
  cliente iproy < "$RAIZ/conf/sql/03_offline.sql" > "$DATA/logs/offline_sql.log" 2>&1 || log "⚠ no se crearon las tablas del modo autónomo: ver data/logs/offline_sql.log"
  escribir_ips
  cliente iproy -e "UPDATE sync_nodo SET nombre = '$(printf %s "$IPROY_NOMBRE" | sed "s/'/''/g")' WHERE local_actual = 1;" 2>/dev/null || true
  # versión del paquete en uso (un nodo creado con una versión anterior queda al día al iniciar)
  sed -i "s/^IPROY_VERSION=.*/IPROY_VERSION=$VER/" "$DATA/iproy.env"; IPROY_VERSION="$VER"
  sed -i "s/\"version\":\"[^\"]*\"/\"version\":\"$VER\"/" "$DATA/node.json"
}
escribir_ips() {
  local l="" ip
  local ips; ips="$(hostname -I 2>/dev/null || ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 || true)"   # Arch/EndeavourOS: hostname sin -I
  for ip in $ips; do [[ "$ip" == *:* ]] || l+="\"$ip\","; done
  printf '{"ipv4":[%s],"ts":"%s"}\n' "${l%,}" "$(date -u +%FT%TZ)" > "$DATA/run/ips.json"
}
# El modo elegido en el panel (data/red.json) tiene prioridad sobre el de iproy.env
modo_red() {
  local m=""
  [[ -f "$DATA/red.json" ]] && m="$(grep -o '"modo"[[:space:]]*:[[:space:]]*"[a-z]*"' "$DATA/red.json" | head -1 | sed 's/.*"\([a-z]*\)"$/\1/' || true)"
  [[ "$m" == "lan" || "$m" == "local" ]] && IPROY_MODO="$m"
  return 0
}
cmd_precargar() {
  cargar_env
  log "descargando al nodo las librerías y archivos que usa iProy (hace falta Internet)…"
  local j; j="$(curl -s -m 300 -X POST -H "X-IPROY-Tick: $IPROY_TICK_TOKEN" "http://127.0.0.1:$IPROY_PUERTO/_iproy/precargar.php")" || fallo "el nodo no responde (start primero)"
  echo "$j" > "$DATA/logs/precarga.json"
  log "resultado: $(echo "$j" | grep -o '"total":[0-9]*,"listos":[0-9]*' || echo "$j") (detalle: data/logs/precarga.json)"
}
cmd_red() {
  cargar_env
  case "${1:-estado}" in
    estado)
      modo_red; escribir_ips 2>/dev/null || true
      printf 'Nodo %s · modo %s · HTTP %s · HTTPS %s\n' "$IPROY_NODE" "$IPROY_MODO" "$IPROY_PUERTO" "$IPROY_PUERTO_TLS"
      printf '  IP          %s\n' "$(hostname -I 2>/dev/null || echo '—')"
      printf '  central     %s\n' "$(curl -s -m 5 "http://127.0.0.1:$IPROY_PUERTO/_iproy/red.php?estado=1" || echo 'nodo detenido')"
      printf '  panel       http://localhost:%s/_iproy/red.php\n' "$IPROY_PUERTO";;
    modo)
      local m="${2:-}"; [[ "$m" == "local" || "$m" == "lan" ]] || fallo "uso: red modo local|lan"
      if [[ -f "$DATA/red.json" ]] && grep -q '"modo"' "$DATA/red.json"; then
        sed -i "s/\"modo\"[[:space:]]*:[[:space:]]*\"[a-z]*\"/\"modo\": \"$m\"/" "$DATA/red.json"
      elif [[ -f "$DATA/red.json" ]]; then sed -i "0,/{/s/{/{\"modo\": \"$m\", /" "$DATA/red.json"
      else printf '{"modo": "%s"}\n' "$m" > "$DATA/red.json"; fi
      log "modo $m guardado. Reiniciá: ./iProy-Control.sh stop && ./iProy-Control.sh start";;
    *) fallo "uso: red [estado] | red modo local|lan";;
  esac
}
# Clave de autenticación (pepper) del servicio de usuarios: permite el PRIMER ingreso sin red verificando contra la base
# replicada. Se escribe oculta, queda solo en data/php/auth_pepper.txt (600) y nunca se muestra ni se copia a otro lado.
cmd_clave_auth() {
  cargar_env
  local f="$DATA/php/auth_pepper.txt"
  if [[ "${1:-}" == "--borrar" ]]; then [[ -f "$f" ]] && { : > "$f"; rm -f "$f" 2>/dev/null || true; }; log "clave de autenticación quitada del nodo"; return 0; fi
  [[ -t 0 ]] || fallo "ejecutalo en una terminal: la clave se escribe sin mostrarse"
  local a b; read -r -s -p "Clave de autenticación (no se muestra): " a; echo; read -r -s -p "Repetila: " b; echo
  [[ -n "$a" && "$a" == "$b" ]] || fallo "vacía o no coinciden; no se guardó nada"
  ( umask 077; printf '%s' "$a" > "$f" ); unset a b
  log "✔ clave guardada en data/php/auth_pepper.txt (solo este nodo). Los usuarios replicados ya pueden ingresar sin red."
}

# Registro de sistemas externos. Cada uno entrega su clave PÚBLICA (JWKS); su clave privada nunca sale de su servidor.
cmd_fhir_cliente() {
  cargar_env; mkdir -p "$DATA/fhir/clientes"
  local acc="${1:-}"; shift || true
  case "$acc" in
    alta)
      local id="${1:-}" jwks="${2:-}" scopes="${3:-system/*.read}"
      [[ "$id" =~ ^[A-Za-z0-9._-]{3,64}$ ]] || fallo "client_id inválido (3-64 caracteres: letras, números, . _ -)"
      { [[ -f "$jwks" ]] && grep -q '"keys"' "$jwks"; } || fallo "falta el archivo JWKS con las claves públicas del sistema externo"
      grep -q '"d"' "$jwks" && fallo "el JWKS contiene una clave PRIVADA (campo d): pedile al organismo solo la pública"
      local lista; lista="$(printf '%s' "$scopes" | tr ' ' '\n' | sed '/^$/d; s/.*/"&"/' | paste -sd, -)"
      printf '{\n  "client_id": "%s",\n  "activo": true,\n  "alta": "%s",\n  "scopes": [%s],\n  "jwks": %s\n}\n' \
        "$id" "$(date -Iseconds)" "$lista" "$(cat "$jwks")" > "$DATA/fhir/clientes/$id.json"
      log "✔ cliente $id registrado con alcances: $scopes";;
    baja)
      local id="${1:-}"; [[ -f "$DATA/fhir/clientes/$id.json" ]] || fallo "no existe el cliente $id"
      mkdir -p "$DATA/fhir/clientes/_bajas"; mv "$DATA/fhir/clientes/$id.json" "$DATA/fhir/clientes/_bajas/$id.$(date +%Y%m%d_%H%M%S).json"
      log "✔ cliente $id dado de baja (archivo en clientes/_bajas/)";;
    lista)
      local f; for f in "$DATA"/fhir/clientes/*.json; do [[ -f "$f" ]] || continue
        printf '  %-24s %s\n' "$(basename "$f" .json)" "$(grep -o '"scopes": \[[^]]*\]' "$f")"; done;;
    token)  # token <id> <clave_privada.pem> → imprime un access_token de 5 min (para probar con curl)
      fhir_token "${1:-}" "${2:-}";;
    demo)   # cliente local de prueba con clave RSA propia; muestra que la API responde (no imprime el token)
      command -v openssl >/dev/null || fallo "falta openssl"
      local d="$DATA/fhir/demo"; mkdir -p "$d"
      [[ -f "$d/clave_privada.pem" ]] || openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$d/clave_privada.pem" 2>/dev/null
      chmod 600 "$d/clave_privada.pem"
      local n; n="$(openssl rsa -in "$d/clave_privada.pem" -noout -modulus | cut -d= -f2 | sed 's/../\\x&/g')"
      # shellcheck disable=SC2059
      printf '{"keys":[{"kty":"RSA","kid":"demo-1","alg":"RS384","use":"sig","e":"AQAB","n":"%s"}]}\n' "$(printf "$n" | b64url)" > "$d/jwks_publico.json"
      cmd_fhir_cliente alta demo-local "$d/jwks_publico.json" "system/*.read"
      local t; t="$(fhir_token demo-local "$d/clave_privada.pem")" || fallo "no se obtuvo token (¿el nodo está iniciado?)"
      local b="http://127.0.0.1:$IPROY_PUERTO/fhir/r4" r
      for r in "Organization?_summary=count" "Location?_summary=count" "Device?_summary=count" "Task?_summary=count"; do
        printf '  %-28s %s\n' "$r" "$(curl -s -H "Authorization: Bearer $t" "$b/$r" | grep -o '"total":[0-9]*')"
      done
      log "✔ API FHIR operativa. Token para curl: ./iProy-Control.sh fhir-cliente token demo-local data/fhir/demo/clave_privada.pem";;
    *) fallo "uso: fhir-cliente alta|baja|lista|token|demo";;
  esac
}

# Aserción RS384 (SMART Backend Services) firmada con openssl y canjeada por un access_token.
fhir_token() {
  local id="$1" clave="$2"
  [[ -f "$clave" ]] || fallo "falta la clave privada $clave"
  [[ -f "$DATA/fhir/clientes/$id.json" ]] || fallo "no existe el cliente $id"
  local aud="http://127.0.0.1:$IPROY_PUERTO/fhir/auth/token" ahora; ahora=$(date +%s)
  local kid; kid="$(grep -o '"kid": *"[^"]*"' "$DATA/fhir/clientes/$id.json" | head -1 | sed 's/.*"\([^"]*\)"$/\1/')"
  local h p f
  h="$(printf '{"alg":"RS384","typ":"JWT","kid":"%s"}' "$kid" | b64url)"
  p="$(printf '{"iss":"%s","sub":"%s","aud":"%s","exp":%d,"jti":"%s"}' "$id" "$id" "$aud" $((ahora + 240)) "$(hexa 16)" | b64url)"
  f="$(printf '%s.%s' "$h" "$p" | openssl dgst -sha384 -sign "$clave" | b64url)"
  curl -s -X POST "$aud" --data-urlencode grant_type=client_credentials \
       --data-urlencode client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer \
       --data-urlencode "client_assertion=$h.$p.$f" --data-urlencode "scope=system/*.read" \
    | grep -o '"access_token":"[^"]*"' | cut -d'"' -f4 | grep . || { echo "[IPROY] ✘ el nodo rechazó la aserción" >&2; return 1; }
}

# ---------------------------------------------------------------- procesos
# Binarios ejecutables (L-324): al copiar o al montar un disco NTFS/exFAT sin permisos, se pierde el permiso «x».
# Se intenta restablecer una vez; si el disco no lo admite (montaje noexec), se explica qué hacer en lugar de reintentar 240 veces.
asegurar_ejecutables() {
  local f faltan=()
  for f in "$FP" "$MDB/bin/mariadbd" "$MDB/bin/mariadb" "$MDB/bin/mariadb-admin" "$MDB/bin/mariadb-dump"; do
    [[ -f "$f" ]] || continue
    [[ -x "$f" ]] || chmod u+x "$f" 2>/dev/null || true
    [[ -x "$f" ]] || faltan+=("$f")
  done
  [[ ${#faltan[@]} -eq 0 ]] && return 0
  printf '[IPROY] ✘ sin permiso de ejecución: %s\n' "${faltan[@]}" >&2
  printf '[IPROY]   El disco está montado sin permisos de ejecución (%s).\n' "$(findmnt -no FSTYPE,OPTIONS -T "$RAIZ" 2>/dev/null || echo '¿?')" >&2
  printf '[IPROY]   Opción 1: remontarlo con exec (ntfs3/ntfs-3g: opciones exec y uid/gid de tu usuario).\n' >&2
  printf '[IPROY]   Opción 2: copiar runtime/linux-x64 a un disco Linux y exportar IPROY_MARIADB_DIR (ver INSTALAR §8).\n' >&2
  exit 1
}

iniciar_mariadb() {
  vivo mariadb && return 0
  # tamaño previo de los logs: si MariaDB muere se muestra SOLO lo de este intento (no errores viejos)
  local o0 e0; o0=$(stat -c%s "$DATA/logs/mariadb.out" 2>/dev/null || echo 0); e0=$(stat -c%s "$DATA/logs/mariadb.err" 2>/dev/null || echo 0)
  "$(mdb_bin mariadbd mysqld)" --defaults-file="$DATA/my.cnf" $(como_root) >> "$DATA/logs/mariadb.out" 2>&1 &
  echo $! > "$RUN/mariadb.pid"
  for _ in $(seq 1 240); do   # hasta 120 s: en pendrives y carpetas compartidas lentas tarda (L-300)
    [[ -S "$SOCK" ]] && "$(mdb_bin mariadb-admin mysqladmin)" --socket="$SOCK" -uroot ${IPROY_DB_ROOT_PASS:+-p"$IPROY_DB_ROOT_PASS"} ping >/dev/null 2>&1 && { log "MariaDB en 127.0.0.1:${IPROY_DB_PORT:-3407} ✔"; return 0; }
    "$(mdb_bin mariadb-admin mysqladmin)" --socket="$SOCK" -uroot ping >/dev/null 2>&1 && return 0
    # si el proceso ya terminó (p. ej. falta una biblioteca del sistema), no tiene sentido esperar 120 s (L-324)
    if ! kill -0 "$(cat "$RUN/mariadb.pid")" 2>/dev/null; then
      local nuevo; nuevo="$(tail -c +$((o0 + 1)) "$DATA/logs/mariadb.out" 2>/dev/null; tail -c +$((e0 + 1)) "$DATA/logs/mariadb.err" 2>/dev/null)"
      printf '%s\n' "$nuevo" | grep -E 'ERROR|error|Warning' | tail -8
      printf '%s' "$nuevo" | grep -q 'privilege tables' && log "La base del nodo está incompleta (faltan tablas del sistema, p. ej. mysql/db.MAI). Rehacela desde el respaldo: bash iProy-Control.sh restaurar"
      printf '%s' "$nuevo" | grep -q 'libcrypt.so.1' && log "Falta libcrypt.so.1: instalá el paquete de compatibilidad (Arch/Manjaro: sudo pacman -S libxcrypt-compat · Fedora: sudo dnf install libxcrypt-compat · openSUSE: sudo zypper install libcrypt1)"
      rm -f "$RUN/mariadb.pid"; fallo "MariaDB se cerró al iniciar (ver arriba)"
    fi
    sleep 0.5
  done
  tail -20 "$DATA/logs/mariadb.err" 2>/dev/null; fallo "MariaDB no respondió en 120 s"
}
detener_mariadb() {
  vivo mariadb || return 0
  "$(mdb_bin mariadb-admin mysqladmin)" --socket="$SOCK" -uroot -p"$IPROY_DB_ROOT_PASS" shutdown >/dev/null 2>&1 || kill "$(cat "$RUN/mariadb.pid")"
  for _ in $(seq 1 40); do vivo mariadb || break; sleep 0.5; done
  rm -f "$RUN/mariadb.pid"; log "MariaDB detenido"
}

# ---------------------------------------------------------------- Núcleo v1.0 (módulos, bus, diario, colmena)
# Llamada a la API del nodo como «sistema» (solo desde este equipo, con el token del worker). Cuerpo JSON por stdin.
api() {
  local metodo="$1" ruta="$2" cuerpo="${3:-}"
  if [[ -n "$cuerpo" ]]; then
    printf '%s' "$cuerpo" | curl -s -m 180 -X "$metodo" -H "X-IPROY-Tick: $IPROY_TICK_TOKEN" -H 'Content-Type: application/json' --data-binary @- "http://127.0.0.1:$IPROY_PUERTO/api/$ruta"
  else curl -s -m 180 -X "$metodo" -H "X-IPROY-Tick: $IPROY_TICK_TOKEN" "http://127.0.0.1:$IPROY_PUERTO/api/$ruta"; fi
}
ver() { "$FP" php-cli "$RAIZ/tools/ver.php"; }
json_txt() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }
asegurar_nucleo() {
  [[ -f "$APP/nucleo/index.php" ]] || return 0
  local j; j="$(api POST nucleo/arranque)" || true
  if printf '%s' "$j" | grep -q '"ok":true'; then
    log "núcleo ✔ módulos: $(printf '%s' "$j" | grep -o '"estado":{[^}]*}' | sed 's/"estado"://; s/[{}"]//g; s/,/ · /g')"
  else log "⚠ el núcleo no arrancó: $(printf '%s' "$j" | head -c 300)"; fi
  if [[ -z "$(api GET usuarios 2>/dev/null | grep -o '"usuario":"' | head -1)" ]]; then
    log "Todavía no hay usuarios. Creá el administrador: bash iProy-Control.sh usuario alta admin AD"
  fi
}
cmd_nucleo() {
  cargar_env
  case "${1:-estado}" in
    estado)     api GET proc/sistema | ver;;
    arranque)   api POST nucleo/arranque | ver;;
    tick)       api POST nucleo/tick | ver;;
    modulos)    api GET proc/sistema | grep -o '"estado":{[^}]*}' | sed 's/"estado"://; s/[{}"]//g' | tr ',' '\n' | sed 's/:/ → /; s/^/  /';;
    tareas)     api GET proc/tareas | ver;;
    diario)     api GET "proc/diario?n=${2:-20}" | ver;;
    verificar)  api POST proc/diario/verificar | ver;;
    bus)        api GET "bus/eventos?desde=${2:-0}&n=50" | ver;;
    ejecutar)   [[ -n "${2:-}" ]] || fallo "uso: nucleo ejecutar <modulo:tarea>"; api POST "tareas/$2/ejecutar" | ver;;
    *) fallo "uso: nucleo estado | arranque | tick | modulos | tareas | diario [n] | verificar | bus [desde] | ejecutar <modulo:tarea>";;
  esac
}
cmd_modulo() {
  cargar_env
  local acc="${1:-}" id="${2:-}"
  case "$acc" in
    activar|desactivar) [[ -n "$id" ]] || fallo "uso: modulo $acc <id>"; api POST "modulos/$id/$acc" | ver;;
    config)
      [[ -n "$id" ]] || fallo "uso: modulo config <id> [archivo.json]"
      if [[ -n "${3:-}" ]]; then [[ -f "$3" ]] || fallo "no existe $3"; api PUT "modulos/$id/config" "{\"config\": $(cat "$3")}" | ver
      else api GET "modulos/$id/config" | ver; fi;;
    *) fallo "uso: modulo activar|desactivar <id> | modulo config <id> [archivo.json]";;
  esac
}
leer_clave() {
  local a b
  read -r -s -p "Contraseña (mínimo 10 caracteres): " a; echo >&2
  read -r -s -p "Repetila: " b; echo >&2
  [[ "$a" == "$b" ]] || fallo "no coinciden"
  printf '%s' "$a"
}
cmd_usuario() {
  cargar_env
  local acc="${1:-lista}"; shift || true
  case "$acc" in
    alta)
      local u="${1:-}" rol="${2:-}" org="${3:-}" nombre="${NOMBRE:-${1:-}}"
      [[ -n "$u" && -n "$rol" ]] || fallo "uso: usuario alta <usuario> <AD|GM|EJ|SO|FT|CI> [organización]   (NOMBRE=\"Ana Pérez\" para el nombre visible)"
      local c; c="$(leer_clave)"
      api POST nucleo/usuario "{\"usuario\":\"$(json_txt "$u")\",\"rol\":\"$rol\",\"organizacion\":\"$(json_txt "$org")\",\"nombre\":\"$(json_txt "$nombre")\",\"clave\":\"$(json_txt "$c")\"}" | ver;;
    clave)
      local u="${1:-}"; [[ -n "$u" ]] || fallo "uso: usuario clave <usuario>"
      local c; c="$(leer_clave)"
      api PUT "usuarios/$u" "{\"clave\":\"$(json_txt "$c")\"}" | ver;;
    baja)  api PUT "usuarios/${1:?usuario}" '{"activo":false}' | ver;;
    token) api POST "usuarios/${1:?usuario}/token" "{\"dias\":${2:-90}}" | ver;;
    lista) api GET usuarios | ver;;
    *) fallo "uso: usuario alta <u> <rol> [org] | clave <u> | baja <u> | token <u> [días] | lista";;
  esac
}
cmd_colmena() {
  cargar_env
  local acc="${1:-lista}"; shift || true
  case "$acc" in
    identidad) api GET colmena/identidad | ver;;
    lista)     api GET colmena/vinculos | ver;;
    vincular)  [[ -n "${1:-}" ]] || fallo "uso: colmena vincular <http(s)://IP:puerto | ID del nodo (por el hub)> [local|institucional|central]"
               api POST colmena/vincular "{\"destino\":\"$(json_txt "$1")\",\"tipo\":\"${2:-institucional}\"}" | ver;;
    aceptar)   # aceptar <par> [rol] [vence AAAA-MM-DD] [acta.pdf firmada: se guarda su sha256]
               local sha=""; [[ -n "${4:-}" ]] && { [[ -f "$4" ]] || fallo "no existe $4"; sha="$(sha256sum "$4" | cut -d' ' -f1)"; }
               api POST "colmena/vinculos/${1:?par}/aceptar" "{\"rol\":\"${2:-CI}\",\"vence\":\"${3:-}\",\"acta_sha\":\"$sha\"}" | ver;;
    rol)       api POST "colmena/vinculos/${1:?par}/rol" "{\"rol\":\"${2:?rol}\"}" | ver;;
    revocar)   api POST "colmena/vinculos/${1:?par}/revocar" | ver;;
    ping)      api POST "colmena/vinculos/${1:?par}/ping" | ver;;
    via)       api POST "colmena/vinculos/${1:?par}/via" "{\"via\":\"${2:-auto}\"}" | ver;;
    rpc)       # rpc <par> <GET|POST|PUT> <ruta FHIR> [cuerpo.json]
               local cu="null"; [[ -n "${4:-}" ]] && cu="$(cat "$4")"
               api POST colmena/rpc "{\"par\":\"${1:?par}\",\"metodo\":\"${2:-GET}\",\"ruta\":\"$(json_txt "${3:-metadata}")\",\"cuerpo\":$cu,\"esperar\":${ESPERAR:-60}}" | ver;;
    evento)    api POST colmena/evento "{\"par\":\"${1:?par}\",\"tema\":\"${2:?tema}\",\"datos\":${3:-{\}}}" | ver;;
    hub)       [[ -n "${1:-}" ]] || fallo "uso: colmena hub <https://tu-hosting/colmena-hub> [código de registro] | colmena hub apagar"
               if [[ "$1" == "apagar" ]]; then api PUT colmena/config '{"hub":{"activo":false}}' | ver
               else api PUT colmena/config "{\"hub\":{\"url\":\"$(json_txt "$1")\",\"activo\":true,\"codigo\":\"$(json_txt "${2:-}")\"}}" | ver && api POST colmena/hub/registrar | ver; fi;;
    url)       api PUT colmena/config "{\"url_publica\":\"$(json_txt "${1:-}")\"}" | ver;;
    *) fallo "uso: colmena identidad | lista | vincular <url|ID> | aceptar <par> [rol] | rol <par> <rol> | revocar <par> | ping <par> | via <par> auto|hub | rpc <par> <método> <ruta> [cuerpo.json] | evento <par> <tema> [json] | hub <url> [código] | hub apagar | url <url pública>";;
  esac
}

cmd_start() {
  local abrir=1; [[ "${1:-}" == "--no-abrir" ]] && abrir=0
  cargar_env; mkdir -p "$RUN"
  asegurar_ejecutables
  escribir_config
  instalar_local_php
  iniciar_mariadb
  asegurar_fhir
  asegurar_pim
  asegurar_offline
  modo_red; echo "$IPROY_MODO" > "$RUN/modo_activo"
  if ! vivo frankenphp; then
    export IPROY_BIND
    export IPROY_TLS_SITIOS="https://localhost:$IPROY_PUERTO_TLS, https://127.0.0.1:$IPROY_PUERTO_TLS"
    if [[ "$IPROY_MODO" == "lan" ]]; then
      IPROY_BIND="0.0.0.0"
      local ip; for ip in $(hostname -I 2>/dev/null || ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1); do [[ "$ip" == *:* ]] || IPROY_TLS_SITIOS+=", https://$ip:$IPROY_PUERTO_TLS"; done
      IPROY_TLS_SITIOS+=", https://nodo-${IPROY_NODE,,}.local:$IPROY_PUERTO_TLS"
    else IPROY_BIND="127.0.0.1"; fi
    "$FP" run --config "$RAIZ/conf/Caddyfile" --adapter caddyfile >> "$DATA/logs/frankenphp.out" 2>&1 &
    echo $! > "$RUN/frankenphp.pid"
    for _ in $(seq 1 40); do curl -s -o /dev/null "http://127.0.0.1:$IPROY_PUERTO/_iproy/ping.php" && break; sleep 0.5; done
    curl -s -o /dev/null "http://127.0.0.1:$IPROY_PUERTO/_iproy/ping.php" || { tail -20 "$DATA/logs/frankenphp.out"; fallo "FrankenPHP no respondió"; }
    log "FrankenPHP en http://localhost:$IPROY_PUERTO ✔"
  fi
  if ! vivo worker; then
    # cada 15 s el tick de sincronización; cada 5 min (20 vueltas) el puente iProy → Servidor PIM (v0.5)
    ( i=0; while :; do curl -s -m 120 -o /dev/null -H "X-IPROY-Tick: $IPROY_TICK_TOKEN" "http://127.0.0.1:$IPROY_PUERTO/_iproy/tick.php" || true
        (( i % 20 == 0 )) && { pim_importar > "$DATA/logs/pim_importacion.json" 2>/dev/null || true; }; i=$((i + 1)); sleep 15; done ) \
      >/dev/null 2>&1 &
    echo $! > "$RUN/worker.pid"; log "worker (tick cada 15 s) ✔"
  fi
  # proceso residente MQTT (conexión permanente con el broker; si el módulo está deshabilitado, espera sin consumir)
  if [[ -d "$RAIZ/modulos/mqtt" ]] && ! vivo residente; then
    "$FP" php-cli "$RAIZ/tools/residente.php" mqtt >> "$DATA/logs/residente.log" 2>&1 &
    echo $! > "$RUN/residente.pid"; log "residente MQTT ✔"
  fi
  # servidor MLLP de IHE PCD-15 (módulo mem; si está deshabilitado, espera sin abrir el puerto)
  if [[ -d "$RAIZ/modulos/mem" ]] && ! vivo residente_mem; then
    "$FP" php-cli "$RAIZ/tools/residente.php" mem >> "$DATA/logs/residente_mem.log" 2>&1 &
    echo $! > "$RUN/residente_mem.pid"; log "residente MLLP (IHE PCD-15) ✔"
  fi
  asegurar_nucleo
  probar_iproy
  # Con red, deja en el nodo librerías, APIs y archivos estáticos que usa iProy (en segundo plano)
  ( curl -s -m 300 -o "$DATA/logs/precarga.json" -X POST -H "X-IPROY-Tick: $IPROY_TICK_TOKEN" "http://127.0.0.1:$IPROY_PUERTO/_iproy/precargar.php" >/dev/null 2>&1 & ) || true
  local url="http://localhost:$IPROY_PUERTO/"
  log "Listo · nodo $IPROY_NODE · $url"
  [[ $abrir == 1 ]] && command -v xdg-open >/dev/null && (xdg-open "$url" >/dev/null 2>&1 &) || true
}

cmd_stop() {
  cargar_env
  if vivo worker; then kill "$(cat "$RUN/worker.pid")" 2>/dev/null || true; rm -f "$RUN/worker.pid"; log "worker detenido"; fi
  if vivo residente; then kill "$(cat "$RUN/residente.pid")" 2>/dev/null || true; rm -f "$RUN/residente.pid"; log "residente MQTT detenido"; fi
  if vivo residente_mem; then kill "$(cat "$RUN/residente_mem.pid")" 2>/dev/null || true; rm -f "$RUN/residente_mem.pid"; log "residente MLLP detenido"; fi
  if vivo frankenphp; then kill "$(cat "$RUN/frankenphp.pid")"; for _ in $(seq 1 20); do vivo frankenphp || break; sleep 0.5; done; rm -f "$RUN/frankenphp.pid"; log "FrankenPHP detenido"; fi
  cmd_respaldo --silencioso || true
  detener_mariadb
}

cmd_status() {
  cargar_env
  printf 'Nodo %s · «%s» · modo %s · v%s\n' "$IPROY_NODE" "$IPROY_NOMBRE" "$IPROY_MODO" "$IPROY_VERSION"
  for p in mariadb frankenphp worker residente residente_mem; do printf '  %-13s %s\n' "$p" "$(vivo $p && echo "● activo (pid $(cat "$RUN/$p.pid"))" || echo '○ detenido')"; done
  printf '  http        %s\n' "$(curl -s -m 5 "http://127.0.0.1:$IPROY_PUERTO/_iproy/estado.php?fmt=txt" || echo 'sin respuesta')"
}

# ---------------------------------------------------------------- app y datos
cmd_importar_app() {
  # El nodo usa SOLO iProy (v0.4.2). Lo que la app todavía pida a /GestionMant/… lo sirve el alias del Caddyfile
  # desde app/iProy/; acá solo se informa qué referencias no tienen su archivo dentro de iProy.
  local src="$RAIZ/descargas/iProy" dst="$APP/iProy"
  [[ -d "$src" ]] || fallo "no está $src (copiá ahí la carpeta iProy del servidor)"
  [[ -d "$dst" ]] && { mv "$dst" "$APP/iProy.prev_$(date +%Y%m%d_%H%M%S)"; log "copia anterior guardada en app/iProy.prev_*"; }
  mkdir -p "$dst"
  # Se excluyen: configuración con secretos de producción, estado interno y respaldos fechados.
  ( cd "$src" && find . -type f \
      ! -name '*.local.php' ! -path './api/_estado/*' ! -path '*/_estado/*' \
      ! -name '*_bk_*' ! -regex '.*_[0-9][0-9]-[0-9][0-9]-20[0-9][0-9][a-z]?\.\(php\|html\|js\)' \
      ! -name '.htaccess' ! -name '*.log' -print0 ) | while IFS= read -r -d '' f; do
        mkdir -p "$dst/$(dirname "$f")"; cp -p "$src/$f" "$dst/$f"; done
  ( cd "$dst" && find . -type f -print0 | sort -z | xargs -0 sha256sum ) > "$APP/iProy.sha256" 2>/dev/null || true
  log "✔ iProy copiado: $(find "$dst" -type f | wc -l) archivos (sin *.local.php, _estado/, .htaccess ni respaldos). Lista con sha: app/iProy.sha256"
  revisar_gestionmant
  [[ -f "$DATA/php/nodo.php" ]] && instalar_local_php || true
}
# Prueba real (no por el texto del archivo): ¿la portada de iProy responde sin GestionMant?
probar_iproy() {
  [[ -f "$APP/iProy/index.php" ]] || return 0
  local c t; t="$(mktemp)"; c="$(curl -s -m 20 -o "$t" -w '%{http_code}' "http://127.0.0.1:$IPROY_PUERTO/iProy/" || echo 000)"
  if [[ "$c" == "200" ]] && ! grep -qs "No se encontr" "$t"; then log "iProy responde en /iProy/ ✔ ($(wc -c < "$t") B)"
  else log "⚠ /iProy/ respondió HTTP $c$(grep -qs 'GestionMant' "$t" && echo ': su index.php busca GestionMant/index.php'). Ver data/logs/php_errors.log"; fi
  rm -f "$t"
}
revisar_gestionmant() {
  local inf="$DATA/logs/gestionmant_referencias.txt" n=0 falta=0 r
  mkdir -p "$DATA/logs"; : > "$inf"
  while IFS= read -r r; do
    n=$((n + 1))
    if [[ -e "$APP/iProy/${r#/GestionMant/}" ]]; then echo "ok     $r" >> "$inf"; else echo "FALTA  $r" >> "$inf"; falta=$((falta + 1)); fi
  done < <(grep -rhoE "/GestionMant/[A-Za-z0-9_./-]+\.(js|css|php|html|png|jpg|svg|json|webmanifest|ico)" "$APP/iProy" 2>/dev/null | sort -u || true)
  if [[ $n -eq 0 ]]; then log "iProy no referencia archivos de /GestionMant/ ✔"
  elif [[ $falta -eq 0 ]]; then log "iProy nombra $n archivo(s) de /GestionMant/: todos existen dentro de iProy y el nodo los sirve desde ahí ✔"
  else log "⚠ iProy nombra $n archivo(s) de /GestionMant/; $falta no están dentro de iProy (lista: data/logs/gestionmant_referencias.txt). Esas partes no cargarán en el nodo."; fi
}

cmd_importar_datos() {
  local solo="$*"   # opcional: importar solo esas bases (p. ej. importar-datos legado_iproy legado_iot)
  cargar_env; iniciar_mariadb
  local dir="$RAIZ/descargas/sql" n=0
  [[ -d "$dir" ]] || fallo "no está $dir (dejá ahí los .sql exportados de phpMyAdmin)"
  # Formatos aceptados: sql/<base>.sql[.gz] (una base por archivo) o sql/<base>/<tabla>.sql[.gz] (una tabla por archivo)
  verificar_sha() { # archivo
    [[ -f "$dir/EXPORTACION.sha256" ]] || return 0
    local esperado; esperado="$(awk -v n="$(basename "$1")" '$2==n{print $1}' "$dir/EXPORTACION.sha256")"
    [[ -z "$esperado" ]] && return 0
    [[ "$(sha256sum "$1" | cut -d' ' -f1)" == "$esperado"* ]] || fallo "$(basename "$1"): el sha256 no coincide con EXPORTACION.sha256"
    log "sha256 OK $(basename "$1")"
  }
  vaciar() { # base: importar REEMPLAZA la base entera (los permisos de iproy_app se conservan)
    cliente -e "DROP DATABASE IF EXISTS \`$1\`; CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  }
  cargar() { # archivo base
    # DEFINER de producción (legado_x@%) no existe en el nodo: se quita para que las vistas funcionen.
    # FOREIGN_KEY_CHECKS=0 como en cualquier restauración: producción tiene filas huérfanas (L-303).
    { echo "SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0;"; if [[ "$1" == *.gz ]]; then gzip -dc "$1"; else cat "$1"; fi; } \
      | sed -E '/^(CREATE DATABASE|USE) /d; s/DEFINER=`[^`]+`@`[^`]+`//g' | cliente "$2" || fallo "error importando $(basename "$1") en $2"
    n=$((n + 1))
  }
  es_vista() { { if [[ "$1" == *.gz ]]; then gzip -dc "$1"; else cat "$1"; fi; } | grep -qE '^CREATE (ALGORITHM|VIEW|OR REPLACE)'; }
  local f base
  for f in "$dir"/*.sql "$dir"/*.sql.gz; do
    [[ -f "$f" ]] || continue
    base="$(basename "$f")"; base="${base%.gz}"; base="${base%.sql}"
    [[ " $BASES " == *" $base "* ]] || { log "⚠ $(basename "$f"): no es el nombre de una base ($BASES) → se saltea (si es una tabla, va en sql/<base>/)"; continue; }
    [[ -n "$solo" && " $solo " != *" $base "* ]] && continue
    verificar_sha "$f"; log "importando $(basename "$f") → $base (reemplaza la base) …"; vaciar "$base"; cargar "$f" "$base"
  done
  local d
  for d in "$dir"/*/; do
    [[ -d "$d" ]] || continue
    base="$(basename "$d")"
    [[ " $BASES " == *" $base "* ]] || { log "⚠ carpeta $base: no es el nombre de una base → se saltea"; continue; }
    [[ -n "$solo" && " $solo " != *" $base "* ]] && continue
    local tablas=() vistas=()
    for f in "$d"*.sql "$d"*.sql.gz; do [[ -f "$f" ]] || continue; if es_vista "$f"; then vistas+=("$f"); else tablas+=("$f"); fi; done
    log "importando $base: ${#tablas[@]} tablas y ${#vistas[@]} vistas (reemplaza la base) …"; vaciar "$base"
    for f in "${tablas[@]}" "${vistas[@]}"; do verificar_sha "$f"; cargar "$f" "$base"; done
  done
  log "✔ $n archivos importados"
  cliente -e "SELECT table_schema base, SUM(table_type='BASE TABLE') tablas, SUM(table_type='VIEW') vistas, SUM(table_rows) filas_aprox FROM information_schema.tables WHERE table_schema LIKE 'legado_%' OR table_schema='iproy' GROUP BY table_schema;"
}

# Rehace la base del nodo desde un respaldo (L-325): para una data/mariadb incompleta o dañada
# (p. ej. copiada mientras MariaDB estaba encendido en otra PC). NO borra nada: la base actual pasa a data/mariadb.anterior_<fecha>.
cmd_restaurar() {
  cargar_env
  vivo mariadb && fallo "MariaDB está encendido: corré primero «stop»"
  local f="${1:-}"
  [[ -n "$f" ]] || f="$(ls -1t "$DATA"/backups/iproy_*.sql.gz 2>/dev/null | head -1 || true)"
  [[ -f "$f" ]] || fallo "no hay respaldo (data/backups/iproy_*.sql.gz). Uso: restaurar [archivo.sql.gz]"
  if [[ -f "$f.sha256" ]]; then
    [[ "$(sha256sum "$f" | cut -d' ' -f1)" == "$(cut -d' ' -f1 "$f.sha256")" ]] || fallo "el sha256 de $(basename "$f") no coincide: respaldo dañado"
    log "respaldo verificado: $(basename "$f") ✔"
  else log "⚠ $(basename "$f") no tiene .sha256: se usa sin verificar"; fi
  gzip -t "$f" || fallo "$(basename "$f") está incompleto (gzip)"
  [[ -t 0 ]] && { local r; read -r -p "Se rehace la base del nodo $IPROY_NODE con $(basename "$f"). La actual se guarda aparte. ¿Seguir? [s/N] " r; [[ "$r" =~ ^[sS]$ ]] || fallo "cancelado"; }
  local ant="$DATA/mariadb.anterior_$(date +%Y%m%d_%H%M%S)"
  [[ -d "$DATA/mariadb" ]] && mv "$DATA/mariadb" "$ant" && log "base anterior guardada en data/$(basename "$ant")"
  mkdir -p "$DATA/mariadb" "$RUN"; rm -f "$RUN/mariadb.pid"
  escribir_config
  log "creando una base nueva (mariadb-install-db)…"
  instalar_base_vacia
  iniciar_mariadb
  crear_usuarios_base
  log "cargando el respaldo (puede tardar unos minutos)…"
  zcat "$f" | cliente || fallo "falló la carga del respaldo (la base anterior sigue en data/$(basename "$ant"))"
  local n; n="$(cliente -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema IN ('legado_iproy','legado_requerimientos','legado_stock_mant','legado_iot','legado_user_gestion','legado_analiticas','legado_preventivos','iproy')")"
  log "tablas cargadas: $n · órdenes: $(cliente -N -e 'SELECT COUNT(*) FROM legado_requerimientos.req_iproy' 2>/dev/null || echo '¿?')"
  detener_mariadb
  log "✔ base restaurada. Siguiente: start (recrea las vistas FHIR y la base del Servidor PIM)"
}

cmd_respaldo() {
  local silencioso=0; [[ "${1:-}" == "--silencioso" ]] && silencioso=1
  cargar_env; vivo mariadb || { [[ $silencioso == 1 ]] && return 0; fallo "MariaDB no está corriendo (start primero)"; }
  # incluye iproy_pim (datos propios del Servidor PIM: solicitudes, órdenes, mediciones e historial nativos)
  local f="$DATA/backups/iproy_${IPROY_NODE}_$(date +%F_%H%M%S).sql.gz"
  "$(mdb_bin mariadb-dump mysqldump)" --defaults-file="$DATA/my.cnf" --socket="$SOCK" -uroot -p"$IPROY_DB_ROOT_PASS" \
     --single-transaction --routines --triggers --databases $BASES $(cliente -N -e "SHOW DATABASES LIKE 'iproy_pim'" 2>/dev/null) 2>>"$DATA/logs/respaldo.log" | gzip -6 > "$f"
  sha256sum "$f" | sed "s|$DATA/backups/||" > "$f.sha256"
  mkdir -p "$DATA/backups/viejos"
  ls -1t "$DATA"/backups/iproy_*.sql.gz 2>/dev/null | tail -n +8 | while read -r v; do mv "$v" "$v.sha256" "$DATA/backups/viejos/" 2>/dev/null || true; done
  log "respaldo: $(basename "$f") ($(stat -c%s "$f") B, sha $(cut -c1-16 "$f.sha256"))"
}

cmd_verificar() {
  [[ -f "$RAIZ/MANIFEST.sha256" ]] || fallo "falta MANIFEST.sha256"
  ( cd "$RAIZ" && sha256sum --quiet -c MANIFEST.sha256 ) && log "✔ todos los archivos coinciden con el manifiesto" || fallo "hay archivos alterados o faltantes (ver arriba)"
}

cmd_confiar_ca() {
  cargar_env
  local ca; ca="$(find "$DATA/caddy/pki/authorities" -name root.crt 2>/dev/null | head -1)"
  [[ -f "$ca" ]] || fallo "todavía no hay CA (se crea al primer start en modo lan)"
  if command -v certutil >/dev/null; then
    mkdir -p "$HOME/.pki/nssdb"; certutil -d "sql:$HOME/.pki/nssdb" -A -t "C,," -n "IPROY Colmena $IPROY_NODE" -i "$ca" && log "✔ CA agregada al almacén del usuario (Chrome). Firefox: importala desde Preferencias › Certificados"
  else
    log "no está certutil (paquete nss-tools / libnss3-tools). CA: $ca"
  fi
}

cmd_instalar_mariadb() {
  # 1.º opción: runtime ya recortado (armado con tools/recortar-mariadb.sh desde el oficial verificado)
  local pre; pre="$(ls -1 "$RAIZ"/descargas/runtime-mariadb-11.4.*-linux-x64-recortado.tar.xz 2>/dev/null | sort -V | tail -1)"
  if [[ -f "$pre" && -f "$RAIZ/descargas/sha256sums.txt" ]]; then
    local hp; hp="$(sha256sum "$pre" | cut -d' ' -f1)"
    grep -q "$hp" "$RAIZ/descargas/sha256sums.txt" || fallo "el sha256 de $(basename "$pre") NO figura en sha256sums.txt"
    log "runtime recortado verificado ✔ ($(echo "$hp" | cut -c1-16))"
    [[ -d "$MDB/bin" ]] && mv "$MDB" "$MDB.prev_$(date +%Y%m%d_%H%M%S)"
    mkdir -p "$MDB"; tar -xJf "$pre" -C "$MDB"
    "$MDB/bin/mariadbd" --version || fallo "el binario no arranca en esta distribución (revisar glibc)"
    ( cd "$MDB" && sha256sum --quiet -c MANIFEST.sha256 ) && log "✔ MariaDB instalado y verificado archivo por archivo" || fallo "MANIFEST del runtime no coincide"
    return 0
  fi
  local tgz; tgz="$(ls -1 "$RAIZ"/descargas/mariadb-11.4.*-linux-*x86_64.tar.gz 2>/dev/null | sort -V | tail -1)"
  [[ -f "$tgz" ]] || fallo "no está descargas/mariadb-11.4.x-linux-…-x86_64.tar.gz (bajalo de https://mariadb.org/download/)"
  local h; h="$(sha256sum "$tgz" | cut -d' ' -f1)"
  if [[ -f "$RAIZ/descargas/sha256sums.txt" ]]; then
    grep -q "$h" "$RAIZ/descargas/sha256sums.txt" && log "sha256 verificado contra sha256sums.txt ✔ ($(echo "$h" | cut -c1-16))" \
      || fallo "el sha256 de $(basename "$tgz") NO figura en sha256sums.txt: no se instala"
  else log "⚠ sin sha256sums.txt: sha $(echo "$h" | cut -c1-16) (anotalo y compará con el de la página de descarga)"; fi
  [[ -d "$MDB/bin" ]] && mv "$MDB" "$MDB.prev_$(date +%Y%m%d_%H%M%S)"
  # se descomprime en el disco temporal y se copia solo el runtime recortado (~108 MB en vez de ~860 MB)
  bash "$RAIZ/tools/recortar-mariadb.sh" "$tgz" "$h" "$MDB"
  "$MDB/bin/mariadbd" --version || fallo "el binario no arranca en esta distribución (revisar glibc)"
  log "✔ MariaDB instalado en runtime/linux-x64/mariadb"
}

case "${1:-}" in
  init) shift; cmd_init "$@";;
  start) shift; cmd_start "$@";;
  stop) cmd_stop;;
  status) cmd_status;;
  instalar-mariadb) cmd_instalar_mariadb;;
  importar-app) cmd_importar_app;;
  importar-datos) shift; cmd_importar_datos "$@";;
  respaldo) cmd_respaldo;;
  restaurar) shift; cmd_restaurar "$@";;
  verificar) cmd_verificar;;
  confiar-ca) cmd_confiar_ca;;
  fhir-cliente) shift; cmd_fhir_cliente "$@";;
  pim) shift; cmd_pim "$@";;
  red) shift; cmd_red "$@";;
  precargar) cmd_precargar;;
  nombre) shift; cmd_nombre "$@";;
  nucleo) shift; cmd_nucleo "$@";;
  modulo) shift; cmd_modulo "$@";;
  usuario) shift; cmd_usuario "$@";;
  colmena) shift; cmd_colmena "$@";;
  clave-auth) shift; cmd_clave_auth "$@";;
  *) sed -n '3,34p' "${BASH_SOURCE[0]}";;
esac
