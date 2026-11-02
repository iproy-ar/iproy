<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Diferencias entre la especificación y la implementación de referencia

Generado por `herramientas/contrato.py` el 02-11-2026. Es la verificación por máquina de la lección L-329.

| | Versión |
|---|---|
| Especificación (guía FSH compilada por SUSHI) | **0.9.2** |
| Servidor PIM (`nodo/app/pim`) | **1.1.0** (declara PIM 0.9.2) |
| Contrato OpenAPI (`api/openapi_pim_v1_2_0.yaml`) | **1.2.0** |

**Cómo se mide:** toda extensión, perfil y CodeSystem que el código del servidor (y del módulo `mem`) emite o lee debe existir en la guía compilada;
todo recurso del servidor debe estar en el CapabilityStatement del actor Gestor (`pim-gm`). Además, desde la 1.1 el servidor **no tiene copia
propia** de las terminologías: sirve los artefactos de la guía (`nodo/conf/pim-guia`), y `pruebas/unitarias.php` comprueba que sus constantes
(estados de la orden, tipos de mantenimiento, sistemas, grupos de local, valores de extensiones) coinciden código por código con la guía.

**Resultado:** ✔ sin diferencias no declaradas.

| Tipo | Artefacto | En la guía 0.9.2 | En el servidor 1.1.0 | Estado | Nota |
|---|---|---|---|---|---|
| StructureDefinition | `pim-activo` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-ambiente-controlado` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-categoria-alcance` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-categoria-riesgo` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-clase-riesgo-pm` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-conformidad` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-establecimiento` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-estado-declarado` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-grupo-activo` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-grupo-uso-medico` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-iru` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-medicion` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-modo-falla` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-orden-generada` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-pertenencia` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-pma` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-procedencia` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-puesta-en-marcha` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-punto` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-punto-medicion` | — | ✔ | declarada | Extensión de la 1.0 que el servidor sigue ACEPTANDO al recibir un Device para configurar el rango de un punto. No se emite: los puntos son DeviceMetric (L-331). Se retira en la 2.0. |
| StructureDefinition | `pim-redundancia` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-riesgo` | ✔ | ✔ | ✔ |  |
| StructureDefinition | `pim-tipo-mantenimiento` | ✔ | ✔ | ✔ |  |
| Alias de entrada | `ambienteControlado → pim-ambiente-controlado` | ✔ | ✔ | ✔ |  |
| Alias de entrada | `categoriaAlcance → pim-categoria-alcance` | ✔ | ✔ | ✔ |  |
| Alias de entrada | `categoriaRiesgo → pim-categoria-riesgo` | ✔ | ✔ | ✔ |  |
| Alias de entrada | `claseRiesgoPM → pim-clase-riesgo-pm` | ✔ | ✔ | ✔ |  |
| Alias de entrada | `componentesIRU → pim-iru` | ✔ | ✔ | ✔ |  |
| Alias de entrada | `estadoDeclarado → pim-estado-declarado` | ✔ | ✔ | ✔ |  |
| Alias de entrada | `grupoUsoMedico → pim-grupo-uso-medico` | ✔ | ✔ | ✔ |  |
| Alias de entrada | `modoFalla → pim-modo-falla` | ✔ | ✔ | ✔ |  |
| Alias de entrada | `pertenencia → pim-pertenencia` | ✔ | ✔ | ✔ |  |
| Alias de entrada | `pim-equipo-medico → pim-pma` | ✔ | ✔ | ✔ |  |
| Alias de entrada | `puestaEnMarcha → pim-puesta-en-marcha` | ✔ | ✔ | ✔ |  |
| Alias de entrada | `redundancia → pim-redundancia` | ✔ | ✔ | ✔ |  |
| Alias de entrada | `tipoMantenimiento → pim-tipo-mantenimiento` | ✔ | ✔ | ✔ |  |
| CodeSystem | `pim-categoria-alcance` | ✔ | ✔ | ✔ |  |
| CodeSystem | `pim-categoria-riesgo` | ✔ | ✔ | ✔ |  |
| CodeSystem | `pim-clase-riesgo-pm` | ✔ | ✔ | ✔ |  |
| CodeSystem | `pim-estado-orden` | ✔ | ✔ | ✔ |  |
| CodeSystem | `pim-grupo-local` | ✔ | ✔ | ✔ |  |
| CodeSystem | `pim-metodo-riesgo` | ✔ | ✔ | ✔ |  |
| CodeSystem | `pim-modo-falla` | ✔ | ✔ | ✔ |  |
| CodeSystem | `pim-origen` | ✔ | ✔ | ✔ |  |
| CodeSystem | `pim-punto` | ✔ | ✔ | ✔ |  |
| CodeSystem | `pim-sistema` | ✔ | ✔ | ✔ |  |
| CodeSystem | `pim-tipo-mantenimiento` | ✔ | ✔ | ✔ |  |
| CodeSystem | `pim-valores` | ✔ | ✔ | ✔ |  |
| Recurso (actor GM) | `Device` | ✔ | ✔ | ✔ |  |
| Recurso (actor GM) | `DeviceMetric` | ✔ | ✔ | ✔ |  |
| Recurso (actor GM) | `Group` | ✔ | ✔ | ✔ |  |
| Recurso (actor GM) | `Location` | ✔ | ✔ | ✔ |  |
| Recurso (actor GM) | `Observation` | ✔ | ✔ | ✔ |  |
| Recurso (actor GM) | `Organization` | ✔ | ✔ | ✔ |  |
| Recurso (actor GM) | `Provenance` | ✔ | ✔ | ✔ |  |
| Recurso (actor GM) | `RiskAssessment` | ✔ | ✔ | ✔ |  |
| Recurso (actor GM) | `ServiceRequest` | ✔ | ✔ | ✔ |  |
| Recurso (actor GM) | `Task` | ✔ | ✔ | ✔ |  |

## Pendiente de implementación (declarado en la Declaración de Integración del servidor)

| Perfil de integración | Recursos | Nivel |
|---|---|---|
| PIM-PRV | PlanDefinition, Questionnaire, QuestionnaireResponse | Intermedio |
| PIM-ENS | DiagnosticReport (pim-informe-ensayo) | Intermedio |
| PIM-KPI | Measure, MeasureReport | Intermedio |

Con PRV, ENS y KPI pendientes, el servidor declara el nivel **Básico** completo más REG, RSG, TEL, FED, ALR y EQM.

## Lo que no se puede verificar acá

- La validación de las instancias contra los perfiles con el validador oficial de HL7: la ejecuta el flujo `.github/workflows/guia.yml` al publicar.
- La conformidad de los mensajes HL7 v2 de PCD-15 contra el perfil de conformidad de IHE: `pruebas/mem.sh` usa mensajes con la forma del ejemplo del suplemento Rev. 1.5.

