#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
"""Contrasta por máquina la especificación (FSH) con la implementación de referencia (Servidor PIM) — lección L-329.
   python3 herramientas/contrato.py > especificacion/DIFERENCIAS_IMPLEMENTACION.md"""
import re, pathlib, datetime
R = pathlib.Path(__file__).resolve().parent.parent
fsh = "\n".join(p.read_text(encoding="utf-8") for p in sorted((R / "especificacion/input/fsh").glob("*.fsh")))
def ids(tipo):
    out = []
    for blk in re.split(r"\n(?=(?:Profile|Extension|CodeSystem|ValueSet|Instance):)", "\n" + fsh):
        m = re.match(rf"\s*{tipo}:\s*(\S+)", blk)
        if m:
            i = re.search(r"^Id:\s*(\S+)", blk, re.M)
            out.append(i.group(1) if i else m.group(1))
    return sorted(set(out))
srv = (R / "nodo/app/pim/lib").glob("*.php")
src = "\n".join(p.read_text(encoding="utf-8") for p in srv)
cs_srv = sorted(set(re.findall(r"'(pim-[a-z\-]+)' => \['", (R / "nodo/app/pim/lib/metadata.php").read_text(encoding="utf-8"))))
cs_srv += sorted(set(re.findall(r"cs\('(pim-[a-z\-]+)'\)", src)) - set(cs_srv))
sd_srv = sorted(set(re.findall(r"sd\('(pim-[a-z\-]+)'\)", src)))
recursos = sorted(set(re.findall(r"^\s*'([A-Z][A-Za-z]+)'\s*=>\s*\['tabla'", (R / "nodo/app/pim/lib/api.php").read_text(encoding="utf-8"), re.M)))
cs_f, ext_f, prof_f, vs_f = ids("CodeSystem"), ids("Extension"), ids("Profile"), ids("ValueSet")
def tabla(titulo, esp, imp):
    filas = [f"| `{x}` | {'✔' if x in esp else '—'} | {'✔' if x in imp else '—'} |" for x in sorted(set(esp) | set(imp))]
    return f"\n### {titulo}\n\n| Artefacto | Especificación 0.9.1 | Servidor PIM 1.0 |\n|---|---|---|\n" + "\n".join(filas) + "\n"
print(f"""<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Diferencias entre la especificación y la implementación de referencia

Generado por `herramientas/contrato.py` el {datetime.date.today():%d-%m-%Y}. Es la verificación por máquina de la lección L-329.

- **Especificación:** guía FSH 0.9.1 (SUSHI 3.21.0: 0 errores, 0 advertencias).
- **Implementación:** Servidor PIM 1.0, `nodo/app/pim`, que cumple el contrato OpenAPI 1.0.0.

El contrato 1.1.0 (`api/openapi_pim_v1_1_0.yaml`) es la meta de la versión 1.1 (ver `docs/HOJA_DE_RUTA.md`).

**Espacio canónico:** las dos usan `https://iproy.com.ar/fhir`. Desde el 02-11-2026 el servidor publica en ese espacio y acepta `https://iproy.com.ar/pim` (el de la versión 1.0) como alias.

**Resumen:**

| Tipo de artefacto | Especificación | Servidor | En común |
|---|---|---|---|
| CodeSystems | {len(cs_f)} | {len(cs_srv)} referenciados (7 publicados en `/CodeSystem`) | {len(set(cs_f) & set(cs_srv))} |
| Extensiones | {len(ext_f)} (ids camelCase) | {len(sd_srv)} (StructureDefinition por nombre) | {len(set(ext_f) & set(sd_srv))} |

Recursos del servidor: {', '.join(recursos)}.
""")
print(tabla("CodeSystems", cs_f, cs_srv))
print(tabla("Extensiones y perfiles referenciados por el servidor (sd)", ext_f + prof_f, sd_srv))
print("\n### ValueSets de la especificación (el servidor 1.0 no publica ValueSets)\n\n" + ", ".join(f"`{v}`" for v in vs_f) + "\n")
print("""
### Decisiones para la versión 1.1

1. **Ids de extensiones.** El servidor adopta los ids de la especificación (camelCase: `estadoDeclarado`, `tipoMantenimiento`, `categoriaRiesgo`, …). Durante 2 años sigue aceptando los nombres 1.0 (`pim-estado-declarado`, `pim-categoria-riesgo`, …) como alias de entrada.
2. **Puntos de medición.** El perfil `pim-punto` (DeviceMetric) reemplaza a la extensión `pim-punto-medicion` dentro de Device (L-331).
3. **Catálogos.** `pim-banda-iru` y `pim-estado-declarado` pasan a ser ValueSets sobre `pim-valores`, como dice la especificación (L-341). Se publican los 13 CodeSystems y los 18 ValueSets.
4. **Recursos nuevos.** Contract (`pim-vinculo`), DiagnosticReport (`pim-informe-ensayo`), operación `Device/$refes` y rol RE.
""")
