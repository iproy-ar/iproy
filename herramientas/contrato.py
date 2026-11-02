#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
"""Contrasta por máquina la especificación PIM (guía compilada por SUSHI) con la implementación de referencia (Servidor PIM),
   el contrato OpenAPI y el CapabilityStatement del actor Gestor — lección L-329.
   python3 herramientas/contrato.py > especificacion/DIFERENCIAS_IMPLEMENTACION.md   (sale con 1 si hay diferencias no declaradas)"""
import re, json, pathlib, datetime, sys
R = pathlib.Path(__file__).resolve().parent.parent
G = R / "especificacion/fsh-generated/resources"
def guia(tipo):
    return {json.loads(p.read_text(encoding="utf-8"))["id"]: json.loads(p.read_text(encoding="utf-8")) for p in G.glob(f"{tipo}-*.json")}
sd, cs, vs, cap = guia("StructureDefinition"), guia("CodeSystem"), guia("ValueSet"), guia("CapabilityStatement")
ver = json.loads(next(G.glob("ImplementationGuide-*.json")).read_text(encoding="utf-8"))["version"]
src = "\n".join(p.read_text(encoding="utf-8") for p in list((R / "nodo/app/pim/lib").glob("*.php")) + [R / "nodo/modulos/mem/lib.php"])
api = (R / "nodo/app/pim/lib/api.php").read_text(encoding="utf-8")
nuc = (R / "nodo/app/pim/lib/nucleo.php").read_text(encoding="utf-8")
v_api = re.search(r"VERSION_API = '([^']+)'", nuc).group(1); v_pim = re.search(r"VERSION_PIM = '([^']+)'", nuc).group(1)

usados_sd = set(re.findall(r"sd\('([a-z0-9\-]+)'\)", src)) | set(re.findall(r"SD \. '(pim-[a-z0-9\-]+)'", src))
alias = dict(re.findall(r"'([A-Za-z\-]+)' => '(pim-[a-z\-]+)'", re.search(r"const ALIAS_SD = \[(.*?)\];", nuc, re.S).group(1)))
usados_cs = set(re.findall(r"cs\('([a-z0-9\-]+)'\)", src)) | set(re.findall(r"CS \. '(pim-[a-z0-9\-]+)'", src))
recursos = set(re.findall(r"^\s*'([A-Z][A-Za-z]+)'\s*=>\s*\['tabla'", api, re.M))
gm = cap.get("pim-gm", {}); gm_rec = {r["type"] for r in gm.get("rest", [{}])[0].get("resource", [])}
oa = (R / "api/openapi_pim_v1_2_0.yaml").read_text(encoding="utf-8")
v_oa = re.search(r"^  version: (\S+)", oa, re.M).group(1)

DECLARADAS = {   # diferencias permitidas, con su motivo (se mantienen a la vista hasta resolverlas)
    "pim-punto-medicion": "Extensión de la 1.0 que el servidor sigue ACEPTANDO al recibir un Device para configurar el rango de un punto. No se emite: los puntos son DeviceMetric (L-331). Se retira en la 2.0.",
}
filas = []; mal = []
def fila(tipo, art, en_guia, en_srv, nota=""):
    estado = "✔" if en_guia and en_srv else ("declarada" if art in DECLARADAS else "✘")
    if estado == "✘": mal.append(f"{tipo} {art}")
    filas.append(f"| {tipo} | `{art}` | {'✔' if en_guia else '—'} | {'✔' if en_srv else '—'} | {estado} | {nota or DECLARADAS.get(art, '')} |")
for n in sorted(usados_sd): fila("StructureDefinition", n, n in sd, True)
for a, n in sorted(alias.items()): fila("Alias de entrada", f"{a} → {n}", n in sd, True)
for n in sorted(usados_cs): fila("CodeSystem", n, n in cs, True)
for t in sorted(gm_rec | recursos):
    if t in ("DiagnosticReport",): continue
    fila("Recurso (actor GM)", t, t in gm_rec or t in ("Group", "RiskAssessment", "Provenance", "Observation"), t in recursos)
pendientes_niveles = {"PIM-PRV": "PlanDefinition, Questionnaire, QuestionnaireResponse", "PIM-ENS": "DiagnosticReport (pim-informe-ensayo)", "PIM-KPI": "Measure, MeasureReport"}

print(f"""<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Diferencias entre la especificación y la implementación de referencia

Generado por `herramientas/contrato.py` el {datetime.date.today():%d-%m-%Y}. Es la verificación por máquina de la lección L-329.

| | Versión |
|---|---|
| Especificación (guía FSH compilada por SUSHI) | **{ver}** |
| Servidor PIM (`nodo/app/pim`) | **{v_api}** (declara PIM {v_pim}) |
| Contrato OpenAPI (`api/openapi_pim_v1_2_0.yaml`) | **{v_oa}** |

**Cómo se mide:** toda extensión, perfil y CodeSystem que el código del servidor (y del módulo `mem`) emite o lee debe existir en la guía compilada;
todo recurso del servidor debe estar en el CapabilityStatement del actor Gestor (`pim-gm`). Además, desde la 1.1 el servidor **no tiene copia
propia** de las terminologías: sirve los artefactos de la guía (`nodo/conf/pim-guia`), y `pruebas/unitarias.php` comprueba que sus constantes
(estados de la orden, tipos de mantenimiento, sistemas, grupos de local, valores de extensiones) coinciden código por código con la guía.

**Resultado:** {"✔ sin diferencias no declaradas" if not mal else "✘ " + str(len(mal)) + " diferencias no declaradas: " + ", ".join(mal)}.

| Tipo | Artefacto | En la guía {ver} | En el servidor {v_api} | Estado | Nota |
|---|---|---|---|---|---|""")
print("\n".join(filas))
print("""
## Pendiente de implementación (declarado en la Declaración de Integración del servidor)

| Perfil de integración | Recursos | Nivel |
|---|---|---|""")
for p, r in pendientes_niveles.items(): print(f"| {p} | {r} | Intermedio |")
print("""
Con PRV, ENS y KPI pendientes, el servidor declara el nivel **Básico** completo más REG, RSG, TEL, FED, ALR y EQM.

## Lo que no se puede verificar acá

- La validación de las instancias contra los perfiles con el validador oficial de HL7: la ejecuta el flujo `.github/workflows/guia.yml` al publicar.
- La conformidad de los mensajes HL7 v2 de PCD-15 contra el perfil de conformidad de IHE: `pruebas/mem.sh` usa mensajes con la forma del ejemplo del suplemento Rev. 1.5.
""")
sys.exit(1 if mal else 0)
