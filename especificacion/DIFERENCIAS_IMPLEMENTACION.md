<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Diferencias entre la especificación y la implementación de referencia

Generado por `herramientas/contrato.py` el 02-11-2026. Es la verificación por máquina de la lección L-329.

- **Especificación:** guía FSH 0.9.1 (SUSHI 3.21.0: 0 errores, 0 advertencias).
- **Implementación:** Servidor PIM 1.0, `nodo/app/pim`, que cumple el contrato OpenAPI 1.0.0.

El contrato 1.1.0 (`api/openapi_pim_v1_1_0.yaml`) es la meta de la versión 1.1 (ver `docs/HOJA_DE_RUTA.md`).

**Espacio canónico:** las dos usan `https://iproy.com.ar/fhir`. Desde el 02-11-2026 el servidor publica en ese espacio y acepta `https://iproy.com.ar/pim` (el de la versión 1.0) como alias.

**Resumen:**

| Tipo de artefacto | Especificación | Servidor | En común |
|---|---|---|---|
| CodeSystems | 13 | 12 referenciados (7 publicados en `/CodeSystem`) | 8 |
| Extensiones | 12 (ids camelCase) | 16 (StructureDefinition por nombre) | 0 |

Recursos del servidor: Device, Group, Location, Observation, Organization, Provenance, RiskAssessment, ServiceRequest, Task.


### CodeSystems

| Artefacto | Especificación 0.9.1 | Servidor PIM 1.0 |
|---|---|---|
| `pim-banda-iru` | — | ✔ |
| `pim-categoria` | — | ✔ |
| `pim-categoria-alcance` | ✔ | — |
| `pim-categoria-riesgo` | ✔ | ✔ |
| `pim-clase-riesgo-pm` | ✔ | — |
| `pim-ensayo` | ✔ | — |
| `pim-estado-declarado` | — | ✔ |
| `pim-estado-orden` | ✔ | ✔ |
| `pim-grupo-local` | ✔ | ✔ |
| `pim-metodo-riesgo` | ✔ | ✔ |
| `pim-modo-falla` | ✔ | ✔ |
| `pim-origen` | — | ✔ |
| `pim-punto` | ✔ | ✔ |
| `pim-sistema` | ✔ | ✔ |
| `pim-tipo-mantenimiento` | ✔ | ✔ |
| `pim-tipo-vinculo` | ✔ | — |
| `pim-valores` | ✔ | — |


### Extensiones y perfiles referenciados por el servidor (sd)

| Artefacto | Especificación 0.9.1 | Servidor PIM 1.0 |
|---|---|---|
| `ambienteControlado` | ✔ | — |
| `categoriaAlcance` | ✔ | — |
| `categoriaRiesgo` | ✔ | — |
| `claseRiesgoPM` | ✔ | — |
| `componentesIRU` | ✔ | — |
| `estadoDeclarado` | ✔ | — |
| `grupoUsoMedico` | ✔ | — |
| `modoFalla` | ✔ | — |
| `pertenencia` | ✔ | — |
| `pim-activo` | ✔ | ✔ |
| `pim-ambiente-controlado` | — | ✔ |
| `pim-categoria-riesgo` | — | ✔ |
| `pim-conformidad` | — | ✔ |
| `pim-empresa` | ✔ | — |
| `pim-estado-declarado` | — | ✔ |
| `pim-grupo-activo` | — | ✔ |
| `pim-grupo-uso-medico` | — | ✔ |
| `pim-informe-ensayo` | ✔ | — |
| `pim-iru` | — | ✔ |
| `pim-local` | ✔ | — |
| `pim-medicion` | ✔ | ✔ |
| `pim-modo-falla` | — | ✔ |
| `pim-orden` | ✔ | — |
| `pim-orden-generada` | — | ✔ |
| `pim-organismo` | ✔ | — |
| `pim-pma` | ✔ | — |
| `pim-procedencia` | — | ✔ |
| `pim-punto` | ✔ | — |
| `pim-punto-medicion` | — | ✔ |
| `pim-redundancia` | — | ✔ |
| `pim-riesgo` | ✔ | ✔ |
| `pim-sistema` | ✔ | — |
| `pim-solicitud` | ✔ | — |
| `pim-tipo-mantenimiento` | — | ✔ |
| `pim-vinculo` | ✔ | — |
| `puestaEnMarcha` | ✔ | — |
| `redundancia` | ✔ | — |
| `tipoMantenimiento` | ✔ | — |


### ValueSets de la especificación (el servidor 1.0 no publica ValueSets)

`pim-banda-iru`, `pim-categoria-alcance`, `pim-categoria-riesgo`, `pim-clase-riesgo-pm`, `pim-conformidad`, `pim-ensayo`, `pim-estado-declarado`, `pim-estado-orden`, `pim-grupo-local`, `pim-metodo-riesgo`, `pim-modo-falla`, `pim-pertenencia`, `pim-presion-relativa`, `pim-punto`, `pim-redundancia`, `pim-sistema`, `pim-tipo-mantenimiento`, `pim-tipo-vinculo`


### Decisiones para la versión 1.1

1. **Ids de extensiones.** El servidor adopta los ids de la especificación (camelCase: `estadoDeclarado`, `tipoMantenimiento`, `categoriaRiesgo`, …). Durante 2 años sigue aceptando los nombres 1.0 (`pim-estado-declarado`, `pim-categoria-riesgo`, …) como alias de entrada.
2. **Puntos de medición.** El perfil `pim-punto` (DeviceMetric) reemplaza a la extensión `pim-punto-medicion` dentro de Device (L-331).
3. **Catálogos.** `pim-banda-iru` y `pim-estado-declarado` pasan a ser ValueSets sobre `pim-valores`, como dice la especificación (L-341). Se publican los 13 CodeSystems y los 18 ValueSets.
4. **Recursos nuevos.** Contract (`pim-vinculo`), DiagnosticReport (`pim-informe-ensayo`), operación `Device/$refes` y rol RE.

