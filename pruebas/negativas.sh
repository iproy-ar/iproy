#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Pruebas NEGATIVAS del Servidor PIM sobre UN nodo iniciado (1.6.2): lo que el servidor tiene que rechazar.
# A diferencia de pim.sh (que entra como Gestor por el núcleo), acá cada pedido va directo a /pim/r4 con un token SMART
# de un cliente con su rol: Gestor (GM), Ejecutor (EJ) de dos empresas y un cliente registrado SIN rol.
# Cubre: If-Match obligatorio en $transicion y en PUT (428 y 412), DELETE (405), cliente sin rol, Bundle vacío sin entry,
# transiciones inválidas, lo que el Ejecutor no puede ver ni hacer, y el orden del historial.
#   bash pruebas/negativas.sh <carpeta-nodo>        (necesita curl y openssl)
# Crea sus propios datos ficticios (prefijo NEG-) y sus clientes de prueba, que borra al terminar.
set -u
D="$1"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; set +a; eval "PORT=\$${P}_PUERTO"
B="http://127.0.0.1:$PORT/pim/r4"; NS=https://iproy.com.ar/fhir; S=$RANDOM; CL="$D/data/pim/clientes"; TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"; rm -f "$CL"/neg-*-"$S".json' EXIT
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:300}"; MAL=$((MAL+1)); fi; }
no()  { if [[ "$2" != *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:300}"; MAL=$((MAL+1)); fi; }
b64url() { base64 -w0 | tr '+/' '-_' | tr -d '='; }
# cliente <nombre> <rol o ""> <organización> <alcances>: clave RSA propia y registro en data/pim/clientes (como «pim cliente alta»)
cliente() {
  local id="neg-$1-$S" n; openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$TMP/$1.pem" 2>/dev/null
  n="$(openssl rsa -in "$TMP/$1.pem" -noout -modulus | cut -d= -f2 | sed 's/../\\x&/g')"
  # shellcheck disable=SC2059
  printf '{"client_id":"%s","activo":true,%s"organizacion":"%s","scopes":["%s"],"jwks":{"keys":[{"kty":"RSA","kid":"%s","alg":"RS384","use":"sig","e":"AQAB","n":"%s"}]}}\n' \
    "$id" "${2:+\"rol\":\"$2\",}" "$3" "${4// /\",\"}" "$id" "$(printf "$n" | b64url)" > "$CL/$id.json"
}
# token <nombre> <alcances>: aserción RS384 firmada con openssl y canjeada en /pim/auth/token
token() {
  local id="neg-$1-$S" aud="http://127.0.0.1:$PORT/pim/auth/token" h p f
  h="$(printf '{"alg":"RS384","typ":"JWT","kid":"%s"}' "$id" | b64url)"
  p="$(printf '{"iss":"%s","sub":"%s","aud":"%s","exp":%d,"jti":"%s"}' "$id" "$id" "$aud" $(($(date +%s) + 240)) "$S-$RANDOM-$(date +%N)" | b64url)"
  f="$(printf '%s.%s' "$h" "$p" | openssl dgst -sha384 -sign "$TMP/$1.pem" | b64url)"
  curl -s -m 30 -X POST "$aud" --data-urlencode grant_type=client_credentials --data-urlencode client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer \
       --data-urlencode "client_assertion=$h.$p.$f" --data-urlencode "scope=$2" | grep -o '"access_token":"[^"]*"' | cut -d'"' -f4
}
# R <token> <método> <ruta> [cuerpo] [If-Match]: devuelve el cuerpo y, en el último renglón, «HTTP <código>»
R() { curl -s -m 60 -w '\nHTTP %{http_code}' -X "$2" -H "Authorization: Bearer $1" -H 'Content-Type: application/fhir+json' ${4:+-d "$4"} ${5:+-H "If-Match: $5"} "$B/$3"; }
id_de() { grep -o "\"resourceType\":\"$1\",\"id\":\"[^\"]*\"" | head -1 | grep -o '[0-9A-Z]\{26\}'; }
tarea_de() { grep -o '"reference":"Task/[^"]*"' | head -1 | grep -o '[0-9A-Z]\{26\}'; }
ver_de() { printf 'W/"%s"' "$(R "$TGM" GET "Task/$1" | grep -o '"versionId":"[0-9]*"' | head -1 | grep -o '[0-9]*')"; }
par() { printf '{"resourceType":"Parameters","parameter":[{"name":"estado","valueCode":"%s"}%s]}' "$1" "${2:+,$2}"; }
sol() { printf '{"resourceType":"ServiceRequest","status":"active","intent":"order","subject":{"reference":"Device/%s"},"code":{"text":"Negativa NEG %s"}%s}' "$DEV" "$1" "${2:-}"; }

cliente gm GM "" 'system/*.cruds'; cliente ej EJ "NEG-EMP-$S" 'system/*.rs system/Task.u'; cliente ej2 EJ "NEG-OTRA-$S" 'system/*.rs system/Task.u'; cliente sinrol "" "" 'system/*.cruds'
TGM=$(token gm 'system/*.cruds'); TEJ=$(token ej 'system/*.rs system/Task.u'); TEJ2=$(token ej2 'system/*.rs system/Task.u'); TSR=$(token sinrol 'system/*.cruds')
[[ -n "$TGM" && -n "$TEJ" && -n "$TEJ2" && -n "$TSR" ]] || { echo "✘ el Servidor PIM no entregó los tokens de prueba (¿el nodo está iniciado?)"; exit 1; }

echo "0) Datos de base (como Gestor, con token SMART)"
org() { R "$TGM" POST Organization "{\"resourceType\":\"Organization\",\"identifier\":[{\"system\":\"$NS/sid/empresa\",\"value\":\"$1\"}],\"name\":\"Empresa $1\",\"type\":[{\"coding\":[{\"system\":\"http://terminology.hl7.org/CodeSystem/organization-type\",\"code\":\"bus\"}]}]}" | id_de Organization; }
EMP=$(org "NEG-EMP-$S"); OTRA=$(org "NEG-OTRA-$S")
X=$(R "$TGM" POST Device "{\"resourceType\":\"Device\",\"identifier\":[{\"system\":\"$NS/sid/activo\",\"value\":\"NEG-ACT-$S\"}],\"status\":\"active\",\"deviceName\":[{\"name\":\"Activo NEG $S\",\"type\":\"user-friendly-name\"}]}")
DEV=$(printf '%s' "$X" | id_de Device); ver "alta de dos empresas y un activo" "$X ${EMP:+e1}${OTRA:+e2}" 'HTTP 201 e1e2'
X=$(R "$TGM" POST ServiceRequest "$(sol 1 ',"note":[{"text":"Prueba negativa"}]')"); SR1=$(printf '%s' "$X" | id_de ServiceRequest); T1=$(printf '%s' "$X" | tarea_de)
X=$(R "$TGM" POST ServiceRequest "$(sol 2)"); SR2=$(printf '%s' "$X" | id_de ServiceRequest); T2=$(printf '%s' "$X" | tarea_de)
ver "ServiceRequest sin note se acepta (note es opcional en pim-solicitud)" "$X" 'HTTP 201'
RESP="{\"name\":\"responsable\",\"valueReference\":{\"reference\":\"Organization/$EMP\"}}"; RESP2="{\"name\":\"responsable\",\"valueReference\":{\"reference\":\"Organization/$OTRA\"}}"

echo "1) Concurrencia: If-Match obligatorio"
ver "\$transicion sin If-Match → 428" "$(R "$TGM" POST "Task/$T1/\$transicion" "$(par asignada "$RESP")")" 'HTTP 428'
ver "\$transicion con If-Match viejo → 412" "$(R "$TGM" POST "Task/$T1/\$transicion" "$(par asignada "$RESP")" 'W/"99"')" 'HTTP 412'
ver "la orden no cambió con los pedidos rechazados" "$(R "$TGM" GET "Task/$T1")" '"versionId":"1"'
ver "\$transicion con la versión vigente → 200" "$(R "$TGM" POST "Task/$T1/\$transicion" "$(par asignada "$RESP")" "$(ver_de "$T1")")" 'HTTP 200'
ver "repetir con la versión que ya no es la vigente → 412" "$(R "$TGM" POST "Task/$T1/\$transicion" "$(par anulada '{"name":"motivo","valueString":"x"}')" 'W/"1"')" 'HTTP 412'
X=$(R "$TGM" GET "Task/$T1" | sed '$d')
ver "PUT Task sin If-Match → 428" "$(R "$TGM" PUT "Task/$T1" "$X")" 'HTTP 428'
ver "PUT Task con If-Match viejo → 412" "$(R "$TGM" PUT "Task/$T1" "$X" 'W/"1"')" 'HTTP 412'

echo "2) Máquina de estados (11 estados)"
ver "transición inválida (solicitada → conformada) → 422" "$(R "$TGM" POST "Task/$T2/\$transicion" "$(par conformada)" "$(ver_de "$T2")")" 'HTTP 422'
ver "estado desconocido → 422" "$(R "$TGM" POST "Task/$T2/\$transicion" "$(par terminada)" "$(ver_de "$T2")")" 'HTTP 422'
ver "pasar a rechazada sin motivo → 422" "$(R "$TGM" POST "Task/$T2/\$transicion" "$(par rechazada)" "$(ver_de "$T2")")" 'HTTP 422'
ver "asignar sin responsable → 422" "$(R "$TGM" POST "Task/$T2/\$transicion" "$(par asignada)" "$(ver_de "$T2")")" 'HTTP 422'
ver "DELETE → 405 (PIM no borra)" "$(R "$TGM" DELETE "Task/$T2")" 'HTTP 405'
R "$TGM" POST "Task/$T2/\$transicion" "$(par asignada "$RESP2")" "$(ver_de "$T2")" >/dev/null     # T2 queda a cargo de la otra empresa

echo "3) Roles"
ver "sin token → 401" "$(curl -s -m 30 -w '\nHTTP %{http_code}' "$B/Device")" 'HTTP 401'
ver "cliente sin rol: no da de alta (opera como CI, nunca como GM) → 403" "$(R "$TSR" POST Organization "{\"resourceType\":\"Organization\",\"identifier\":[{\"system\":\"$NS/sid/empresa\",\"value\":\"NEG-SINROL-$S\"}],\"name\":\"Sin rol\"}")" 'El rol CI no puede'
ver "cliente sin rol: no cambia órdenes → 403" "$(R "$TSR" POST "Task/$T1/\$transicion" "$(par anulada '{"name":"motivo","valueString":"x"}')" "$(ver_de "$T1")")" 'HTTP 403'
ver "cliente sin rol: sí consulta" "$(R "$TSR" GET "Device/$DEV")" 'HTTP 200'
ver "el Ejecutor no hace una transición del Gestor (anulada) → 403" "$(R "$TEJ" POST "Task/$T1/\$transicion" "$(par anulada '{"name":"motivo","valueString":"x"}')" "$(ver_de "$T1")")" 'HTTP 403'
ver "el Ejecutor no ve la orden de otra empresa → 404" "$(R "$TEJ" GET "Task/$T2")" 'HTTP 404'
ver "el Ejecutor no opera la orden de otra empresa → 404" "$(R "$TEJ" POST "Task/$T2/\$transicion" "$(par aceptada)" "$(ver_de "$T2")")" 'HTTP 404'
ver "el Ejecutor ve la solicitud de su orden" "$(R "$TEJ" GET "ServiceRequest/$SR1")" 'HTTP 200'
ver "el Ejecutor no ve la solicitud de una orden ajena → 404" "$(R "$TEJ" GET "ServiceRequest/$SR2")" 'HTTP 404'
X=$(R "$TEJ" GET "ServiceRequest?_count=200")
ver "el Ejecutor busca solicitudes: solo las de sus órdenes" "$X" "\"id\":\"$SR1\""; no "… y no las ajenas" "$X" "\"id\":\"$SR2\""
ver "el Ejecutor acepta su orden → 200" "$(R "$TEJ" POST "Task/$T1/\$transicion" "$(par aceptada)" "$(ver_de "$T1")")" 'HTTP 200'
ver "el Gestor puede hacer una transición del Ejecutor, en su nombre (documentado) → 200" "$(R "$TGM" POST "Task/$T1/\$transicion" "$(par en-ejecucion)" "$(ver_de "$T1")")" 'HTTP 200'

echo "4) Formato FHIR"
X=$(R "$TGM" GET "Device?identifier=NO-EXISTE-$S")
ver "búsqueda sin resultados: total 0" "$X" '"total":0'; no "… y el Bundle no trae entry" "$X" '"entry"'
no "historial sin cambios: el Bundle no trae entry" "$(R "$TGM" GET "Task/_history?_since=2999-01-01T00:00:00-03:00")" '"entry"'
X=$(R "$TGM" POST Device "{\"resourceType\":\"Device\",\"meta\":{\"profile\":[\"http://ejemplo.invalid/StructureDefinition/otro\",\"$NS/StructureDefinition/pim-sistema\"]},\"identifier\":[{\"system\":\"$NS/sid/activo\",\"value\":\"NEG-O2-$S\"}],\"status\":\"active\",\"type\":{\"coding\":[{\"system\":\"$NS/CodeSystem/pim-sistema\",\"code\":\"GAS-O2\"}]},\"deviceName\":[{\"name\":\"Red NEG $S\"}]}")
ver "Device: el perfil se reconoce aunque no sea el primero de meta.profile" "$X" "\"profile\":[\"$NS/StructureDefinition/pim-sistema\"]"
ver "Location?type=facility → el establecimiento" "$(R "$TGM" GET 'Location?type=facility&_summary=count')" '"total":1'
ver "Location?type con un system ajeno no coincide" "$(R "$TGM" GET 'Location?type=http://ejemplo.invalid/cs%7Cfacility&_summary=count')" '"total":0'
no "el CapabilityStatement no publica la extensión oauth-uris incompleta" "$(curl -s -m 30 "$B/metadata")" 'oauth-uris'
h() { R "$TGM" GET "Task/$T1/_history$1" | grep -o '"versionId":"[0-9]*"' | grep -o '[0-9]*' | tr '\n' ' '; }
ver "historial: orden ascendente por defecto (PIM-8)" "$(h '')" '1 2 3 4 '
ver "historial: _sort=-_lastUpdated da el orden de FHIR R4 (lo más nuevo primero)" "$(h '?_sort=-_lastUpdated')" '4 3 2 1 '

echo; [[ $MAL -eq 0 ]] && echo "✔ $OK comprobaciones correctas" || echo "✘ $MAL fallas, $OK correctas"; exit $((MAL > 0))
