### Marco técnico del PIM (formato IHE)

Desde la versión 0.9.2 el PIM se describe con la estructura de un marco técnico IHE: **actores**, **transacciones** numeradas, **perfiles de integración** que agrupan transacciones, **opciones** y **agrupamientos** con perfiles IHE existentes. El principio rector es apoyarse en los bordes que IHE ya estandarizó y crear solo lo que nadie cubre: el flujo del mantenimiento entre organismos y las instalaciones hospitalarias vinculadas al riesgo para el paciente.

#### Capas (modelo IHE XDW)

| Capa | En IHE XDW | En el PIM |
|---|---|---|
| 1. Infraestructura de flujo | Documento de flujo de trabajo sobre XDS | FHIR Task + `_history` + Provenance encadenado |
| 2. Contenido | Tareas con estado, responsable, entradas y salidas | `pim-orden` (Task), `pim-solicitud` (ServiceRequest), `pim-informe-ensayo` (DiagnosticReport) |
| 3. Definición del flujo | XBeR-WD, XTB-WD… | **PIM-OT**, **PIM-SOL** y **PIM-ENS**: definiciones de flujo de trabajo de mantenimiento |

#### Actores

| Código | Actor | Rol en la colmena | Agrupamiento IHE |
|---|---|---|---|
| GM | Gestor de mantenimiento (servidor PIM) | — | mCSD Directory (ITI-90); con la opción `mcsd-update`, ITI-91. Con PIM-EQM: MEM-DMC DMIC y MEM-LS LOC |
| SO | Solicitante | SO | — |
| EJ | Ejecutor | EJ | — |
| FT | Fuente de telemetría | FT | — |
| CI | Consumidor de información e indicadores | CI | mCSD Query Client |
| DA | Destinatario de alarmas | — | ACM Alert Communicator |
| RE | Registro estatal | RE | — |
| DI | Directorio | — | mCSD Directory (el GM puede cumplirlo agrupado) |

Cada actor tiene su CapabilityStatement en esta guía: `pim-gm`, `pim-so`, `pim-ej`, `pim-ft`, `pim-ci`, `pim-re`.

#### Transacciones

| N.º | Transacción | Iniciador → Respondedor | FHIR | Relación con IHE |
|---|---|---|---|---|
| PIM-1 | Consultar inventario | CI, SO, EJ → GM | `GET Organization`, `Location`, `Device`, `DeviceMetric` | Organization y Location responden como ITI-90 |
| PIM-2 | Actualizar inventario | GM (otro nodo, consola) → GM | `POST`/`PUT Device`, `Location`, `Organization` con `If-Match` | — |
| PIM-3 | Registrar solicitud | SO → GM | `POST ServiceRequest` (devuelve la orden en `pim-orden-generada`) | — |
| PIM-4 | Consultar órdenes | SO, EJ, CI → GM | `GET Task?business-status=…&owner=…` | — |
| PIM-5 | Actualizar orden | EJ, GM → GM | `PUT Task` con `If-Match` o `POST Task/{id}/$transicion` | Patrón de tareas de XDW |
| PIM-6 | Enviar mediciones | FT → GM | `POST Observation` o `Bundle` batch; opción MQTT | — |
| PIM-7 | Consultar riesgo | CI → GM | `GET RiskAssessment?subject=Group/{activo}-g` | — |
| PIM-8 | Sincronizar cambios | GM → GM | `GET [tipo]/_history?_since=…` y `Provenance?target=…` | Semántica de ITI-91, extendida a todos los recursos |
| PIM-9 | Informar al registro estatal | RE → GM | `GET Device/$refes` | — |
| PIM-10 | Informar ensayo | EJ → GM | `POST DiagnosticReport` (`pim-informe-ensayo`) | — |
| PIM-11 | Exportar indicadores | CI → GM | `MeasureReport` o `$export` | — |
| PCD-15 | Communicate DMIO | Equipo médico (DMIR) → GM (DMIC) | HL7 v2 ORU^R44 por MLLP → Device + DeviceMetric + Observation | IHE DEV MEM-DMC, sin cambios |
| PCD-16 | Location Observation | Sistema de localización (LOR) → GM (LOC) | HL7 v2 ORU^R45 → Location | IHE DEV MEM-LS, sin cambios |

#### Perfiles de integración

| Perfil | Transacciones | Nivel |
|---|---|---|
| PIM-INV Inventario | PIM-1, PIM-2 (+ ITI-90) | Básico |
| PIM-SOL Solicitud | PIM-3 | Básico |
| PIM-OT Orden de trabajo | PIM-4, PIM-5 | Básico |
| PIM-REG Registro estatal | PIM-9 | Intermedio |
| PIM-RSG Riesgo | PIM-7 | Intermedio |
| PIM-PRV Preventivo | PlanDefinition y Questionnaire | Intermedio |
| PIM-ENS Ensayo reglamentario | PIM-10 | Intermedio |
| PIM-KPI Indicadores | PIM-11 | Intermedio |
| PIM-TEL Telemetría | PIM-6 | Avanzado |
| PIM-ALR Alarmas técnicas | puente con IHE DEV ACM | Avanzado |
| PIM-FED Federación | PIM-8 (+ ITI-91) | Avanzado |
| PIM-EQM Equipos médicos | PCD-15, PCD-16 | Avanzado |

**Opciones:** `mqtt` (PIM-6), `colmena` (PIM-5 y PIM-8 entre nodos por `colmena/1`), `mem-dmc` y `mem-ls` (PIM-EQM), `mcsd-update` (ITI-91), `transicion` (PIM-5).

#### Definición de flujo PIM-OT

| Estado PIM (`businessStatus`) | `Task.status` | Estado XDW | Transiciones permitidas (quién) |
|---|---|---|---|
| solicitada | requested | CREATED | en-revision, asignada, rechazada, anulada (GM) |
| en-revision | requested | CREATED | asignada, rechazada, anulada (GM) |
| asignada | received | CREATED | aceptada, en-revision (EJ); anulada (GM) |
| aceptada | accepted | — | lista, en-ejecucion, en-espera (EJ); anulada (GM) |
| lista | ready | READY | en-ejecucion, en-espera (EJ); anulada (GM) |
| en-ejecucion | in-progress | IN_PROGRESS | en-espera, finalizada (EJ) |
| en-espera | on-hold | — | en-ejecucion, aceptada (EJ); anulada (GM) |
| finalizada | completed | COMPLETED | conformada, en-ejecucion (GM) |
| conformada | completed | COMPLETED | — |
| rechazada | rejected | FAILED | — |
| anulada | cancelled | — | — |

El motivo es obligatorio para pasar a en-espera, rechazada, anulada y en-revision. Cada transición genera una versión nueva del Task y su Provenance encadenado (equivale al historial de eventos de tarea de XDW). El PIM es un superconjunto de XDW: agrega aceptación, rechazo, espera y anulación, que el mantenimiento entre organismos necesita.

#### Traducción de IHE PCD-15 a FHIR (PIM-EQM)

| PCD-15 (ORU^R44) | FHIR |
|---|---|
| MSH-3 / OBX-18 (EUI-64) | `Device.identifier` con system `…/sid/eui64` (se crea el Device si no existe, con etiqueta `mem-dmc`) |
| OBX-3 `67880 MDC_ATTR_ID_MODEL` | `Device.manufacturer` y `Device.modelNumber` |
| OBX-3 `67972 MDC_ATTR_SYS_ID` | `Device.identifier` (EUI-64) |
| Cada otro OBX-3 | `DeviceMetric` (`pim-punto`, tipo = código MDC) + `Observation` (`pim-medicion`) |
| OBX-2 NM / CWE / ST | `valueQuantity` (unidad OBX-6) / `valueCodeableConcept` / `valueString` |
| OBX-14 (o OBX-7 en el ejemplo del suplemento) | `effectiveDateTime` |
| `68513 MDC_ATTR_LS_LOCATION` (PL) | `Device.location` si el local existe (búsqueda por nombre en la cadena establecimiento › edificio › piso › sala) |
| Autoprueba con resultado de falla | `ServiceRequest` + orden correctiva automática (`pim-orden`, `reasonReference` = la Observation) |
| Paso a batería | Evento `alarmas/cambio` (PIM-ALR) |

La respuesta es un ACK de HL7 v2 (MSA-1 = AA, AE o AR) en el mismo canal MLLP.
