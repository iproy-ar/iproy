#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
# Compila la especificación PIM (SUSHI) y copia sus artefactos computables al nodo (nodo/conf/pim-guia/).
# El Servidor PIM sirve CodeSystems, ValueSets, StructureDefinitions, CapabilityStatements de actores y OperationDefinitions
# desde esa carpeta: no mantiene una copia propia de las terminologías (lección L-329).
#   bash herramientas/guia-al-nodo.sh            (necesita SUSHI: npm install -g fsh-sushi)
set -euo pipefail
R="$(cd "$(dirname "$0")/.." && pwd)"
if command -v sushi >/dev/null; then sushi build "$R/especificacion" | tail -3; else echo "sin SUSHI: uso fsh-generated tal como está"; fi
G="$R/especificacion/fsh-generated/resources"; D="$R/nodo/conf/pim-guia"
[[ -d "$G" ]] || { echo "falta $G (compilá la especificación)"; exit 1; }
mkdir -p "$D"; rm -f "$D"/*.json
n=0; for t in CodeSystem ValueSet ConceptMap StructureDefinition CapabilityStatement OperationDefinition SearchParameter ImplementationGuide; do
  for f in "$G"/$t-*.json; do [[ -e "$f" ]] || continue; cp "$f" "$D/"; n=$((n+1)); done
done
echo "✔ $n artefactos en nodo/conf/pim-guia"
