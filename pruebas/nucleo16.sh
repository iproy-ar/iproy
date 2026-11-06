#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Prueba de lo nuevo del núcleo 1.6.0 sobre UN nodo iniciado: catálogos editables (listas desplegables), recuperación de la contraseña
# sin Internet (códigos de un solo uso y clave temporal del administrador) y el módulo stock (códigos, renglones, existencia, permisos).
#   bash pruebas/nucleo16.sh <carpeta-nodo>
# Crea sus propios datos ficticios (prefijo EJ / ej) y no usa datos reales. Las contraseñas de prueba se generan al azar y no se muestran.
set -u
D="$1"; env="$(ls "$D"/data/*.env | grep -v secrets | head -1)"; P="$(basename "$env" .env | tr a-z A-Z)"
set -a; . "$env"; . "$D/data/secrets.env"; set +a; eval "PORT=\$${P}_PUERTO TOK=\$${P}_TICK_TOKEN"
B="http://127.0.0.1:$PORT/api"; T=$(mktemp -d); CJ="$T/cj"; CJ2="$T/cj2"; trap 'rm -rf "$T"' EXIT
A() { curl -s -m 60 -X "$1" -H "X-$P-Tick: $TOK" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }          # lanzador (AD)
U() { curl -s -m 60 -b "$CJ" -c "$CJ" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }              # usuario de prueba
H() { curl -s -m 60 -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" -X "$1" -H 'X-Nucleo: 1' -H 'Content-Type: application/json' ${3:+-d "$3"} "$B/$2"; }
OK=0; MAL=0; ver() { if [[ "$2" == *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:300}"; MAL=$((MAL+1)); fi; }
no()  { if [[ "$2" != *"$3"* ]]; then echo "  ✔ $1"; OK=$((OK+1)); else echo "  ✘ $1 → ${2:0:300}"; MAL=$((MAL+1)); fi; }
js() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null; }
S=$RANDOM; rnd() { head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16; }

echo "1) Catálogos editables"
R=$(A GET 'catalogos?valores=unidad,frecuencia,modo_falla')
ver "lista de unidades (SI)" "$R" '"codigo":"kg"'
ver "frecuencias: el código son los días" "$R" '"codigo":"365"'
ver "modos de falla ISO 14224 (FTS)" "$R" '"codigo":"FTS"'
ver "catálogos del módulo stock sembrados" "$(A GET catalogos)" '"clave":"stock_subcategoria"'
R=$(A POST catalogos/fabricante "{\"texto\":\"Marca EJ $S Ñandú\"}"); COD=$(printf '%s' "$R" | js "d['valor']['codigo']")
ver "alta de un valor con tilde (código sin tildes)" "$COD" "MARCA-EJ-$S-NANDU"
ver "el mismo texto no se duplica" "$(A POST catalogos/fabricante "{\"texto\":\"marca ej $S ñandú\"}")" '"existia":true'
ver "desactivar (no se borra)" "$(A PUT "catalogos/fabricante/$COD" '{"activo":0}')" '"ok":true'
no "desactivado: no se ofrece" "$(A GET catalogos/fabricante)" "\"$COD\""
ver "desactivado: sigue en la lista completa" "$(A GET 'catalogos/fabricante?todos=1')" "\"$COD\""
ver "subcategoría exige su categoría" "$(A POST catalogos/stock_subcategoria '{"texto":"Sin padre"}')" '"ok":false'

echo "2) Usuario de prueba y códigos de recuperación"
ORG="EJ-ORG-$S"; UU="ej$S"; C1="Ej-$(rnd)"
A POST nucleo/usuario "{\"usuario\":\"$UU\",\"nombre\":\"Prueba $S\",\"rol\":\"EJ\",\"organizacion\":\"$ORG\",\"clave\":\"$C1\"}" >/dev/null
ver "ingreso del usuario de prueba" "$(U POST sesion "{\"usuario\":\"$UU\",\"clave\":\"$C1\"}")" '"ok":true'
ver "opciones de recuperación (pública)" "$(curl -s "$B/recuperar/opciones")" '"codigo":true'
R=$(U POST sesion/codigos "{\"actual\":\"$C1\"}"); COD1=$(printf '%s' "$R" | js "d['codigos'][0]")
ver "genera 8 códigos" "$(printf '%s' "$R" | js "len(d['codigos'])")" "8"
cp "$CJ" "$CJ2"
C2="Ej-$(rnd)"
ver "código equivocado: rechazado" "$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"usuario\":\"$UU\",\"codigo\":\"AAAAA-AAAAA\",\"nueva\":\"$C2\"}" "$B/recuperar/codigo")" '"ok":false'
ver "código correcto: cambia la contraseña" "$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"usuario\":\"$UU\",\"codigo\":\"$COD1\",\"nueva\":\"$C2\"}" "$B/recuperar/codigo")" '"ok":true'
ver "el mismo código no sirve dos veces" "$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"usuario\":\"$UU\",\"codigo\":\"$COD1\",\"nueva\":\"Ej-$(rnd)\"}" "$B/recuperar/codigo")" '"ok":false'
ver "la sesión anterior quedó cerrada" "$(curl -s -b "$CJ2" "$B/sesion")" '"ok":false'
ver "ingresa con la contraseña nueva" "$(U POST sesion "{\"usuario\":\"$UU\",\"clave\":\"$C2\"}")" '"ok":true'
ver "quedan 7 códigos" "$(U GET sesion/codigos)" '"libres":7'

echo "3) Clave temporal del administrador"
TMP=$(A POST "usuarios/$UU/clave-temporal" '{}' | js "d['clave']")
ver "formato XXXX-XXXX-XXXX" "$TMP" "-"
ver "la contraseña anterior ya no sirve" "$(U POST sesion "{\"usuario\":\"$UU\",\"clave\":\"$C2\"}")" '"ok":false'
ver "ingreso con la temporal pide cambiarla" "$(U POST sesion "{\"usuario\":\"$UU\",\"clave\":\"$TMP\"}")" '"cambiar_clave":true'
ver "con la temporal no se puede usar el sistema (428)" "$(H GET m/stock/resumen)" "428"
C3="Ej-$(rnd)"
ver "cambia la temporal por una propia" "$(U POST sesion/clave "{\"actual\":\"$TMP\",\"nueva\":\"$C3\"}")" '"ok":true'
ver "después del cambio, uso normal" "$(H GET m/stock/resumen)" "200"

echo "4) Módulo stock"
R=$(A POST m/stock/insumos "{\"categoria\":\"ELE\",\"subcategoria\":\"ELE-CAB\",\"descripcion\":\"Cable EJ $S\",\"modelo\":\"2,5 mm²\",\"unidad\":\"m\",\"minimo\":\"100\",\"precio\":\"350,5\",\"existencia_inicial\":\"80\"}")
I1=$(printf '%s' "$R" | js "d['insumo']['id']"); C=$(printf '%s' "$R" | js "d['insumo']['codigo']")
ver "código {n}-ELE-CAB" "$C" "-ELE-CAB"
ver "existencia inicial como ajuste (ING-)" "$R" '"clave":"ING-'
ver "insumo repetido: rechazado" "$(A POST m/stock/insumos "{\"categoria\":\"ELE\",\"subcategoria\":\"ELE-CAB\",\"descripcion\":\"cable ej $S\",\"modelo\":\"2,5 mm²\"}")" 'ya existe'
ver "subcategoría de otra categoría: rechazada" "$(A POST m/stock/insumos '{"categoria":"ELE","subcategoria":"PLO-CAN","descripcion":"X"}')" 'no corresponde'
I2=$(A POST m/stock/insumos "{\"categoria\":\"BUL\",\"subcategoria\":\"BUL-TOR\",\"descripcion\":\"Tornillo EJ $S\",\"unidad\":\"u\"}" | js "d['insumo']['id']")
PROV=$(A POST m/stock/proveedores "{\"nombre\":\"Proveedor EJ $S\",\"especialidad\":\"ELE\"}" | js "d['proveedor']['id']")
R=$(U POST m/stock/movimientos "{\"tipo\":\"ingreso\",\"origen\":\"COMPRA\",\"proveedor\":$PROV,\"comprobante\":\"R EJ-$S\",\"lineas\":[{\"insumo\":$I1,\"cantidad\":\"20\",\"precio\":\"400\"},{\"insumo\":$I2,\"cantidad\":500}]}")
ver "ingreso de dos renglones (Ejecutor)" "$R" '"clave":"ING-'
ver "saldo después del ingreso" "$R" '"saldo":100.0'
R=$(U POST m/stock/movimientos "{\"tipo\":\"egreso\",\"motivo\":\"CONSUMO\",\"lineas\":[{\"insumo\":$I1,\"cantidad\":5},{\"insumo\":$I2,\"cantidad\":9999}]}")
ver "egreso mayor a la existencia: rechazado" "$R" 'No alcanza'
ver "todo o nada: el primer renglón no se descontó" "$(A GET "m/stock/insumos/$I1" | js "d['insumo']['existencia']")" "100.000"
ver "egreso para una orden exige la orden" "$(U POST m/stock/movimientos "{\"tipo\":\"egreso\",\"motivo\":\"OT\",\"lineas\":[{\"insumo\":$I1,\"cantidad\":1}]}")" 'orden de trabajo'
R=$(U POST m/stock/movimientos "{\"tipo\":\"egreso\",\"motivo\":\"CONSUMO\",\"solicitante\":\"Prueba\",\"lineas\":[{\"insumo\":$I1,\"cantidad\":\"30,5\"}]}")
ver "egreso con coma decimal" "$R" '"saldo":69.5'
ver "queda bajo el mínimo (aviso)" "$R" '"bajo_minimo":true'
ver "origen fuera de la lista: rechazado" "$(U POST m/stock/movimientos "{\"tipo\":\"ingreso\",\"origen\":\"NO-EXISTE\",\"lineas\":[{\"insumo\":$I1,\"cantidad\":1}]}")" 'no está en la lista'
ver "el Ejecutor no crea insumos (403)" "$(H POST m/stock/insumos '{"categoria":"ELE","subcategoria":"ELE-CAB","descripcion":"Y"}')" "403"
ver "el Ejecutor agrega una subcategoría («Otro…»)" "$(U POST catalogos/stock_subcategoria "{\"texto\":\"Subcat EJ $S\",\"padre\":\"ELE\"}")" '"sigla":"'
ver "pero no una categoría (la mantiene el administrador)" "$(U POST catalogos/stock_categoria "{\"texto\":\"Cat EJ $S\"}")" '"ok":false'
SUMA=$(A GET "m/stock/insumos/$I1" | js "round(sum((float(m['cantidad']) if m['tipo']=='ingreso' else -float(m['cantidad'])) for m in d['movimientos']),3)")
ver "existencia = suma de los movimientos (ficha)" "$SUMA" "69.5"
ver "resumen: cuenta bajo mínimo" "$(A GET m/stock/resumen)" '"bajo_minimo":'
ver "diario: asientos de stock" "$(A GET 'proc/diario?n=40')" 'stock.egreso'

echo; [ $MAL -eq 0 ] && echo "✔ $OK comprobaciones correctas" || { echo "✘ $MAL fallaron de $((OK+MAL))"; exit 1; }
