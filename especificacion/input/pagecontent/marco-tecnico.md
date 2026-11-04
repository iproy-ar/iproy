### Marco técnico del PIM (formato IHE)

<sub>🎓 [Conocé PIM: principio rector y alineación con IHE →](https://iproy.com.ar/pim/conoce)</sub>

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
| PIM-8 | Sincronizar cambios | GM → GM | `GET [tipo]/_history?_since=…&_count=…&_offset=…` (orden cronológico, `link next`) y `Provenance?target=…` | Semántica de ITI-91, para los recursos versionados: Organization, Location, Device, ServiceRequest y Task |
| PIM-9 | Informar al registro estatal | RE → GM | `GET Device/$refes` | — |
| PIM-10 | Informar ensayo | EJ → GM | `POST DiagnosticReport` (`pim-informe-ensayo`) | — |
| PIM-11 | Exportar indicadores | CI → GM | `MeasureReport` o `$export` | — |
| PCD-15 | Communicate DMIO | Equipo médico (DMIR) → GM (DMIC) | HL7 v2 ORU^R44 por MLLP (opcionalmente sobre TLS 1.2+) → Device + DeviceMetric + Observation | IHE DEV MEM-DMC, sin cambios |
| PCD-16 | Location Observation | Sistema de localización (LOR) → GM (LOC) | HL7 v2 → Location (opción `mem-ls`, **planificada**) | IHE DEV MEM-LS, sin cambios |

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
| PIM-ALR Alarmas técnicas | alarmas ISA-18.2 del nodo; el puente con IHE DEV ACM (PCD-04/PCD-06) está **planificado** | Avanzado |
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

El motivo es obligatorio para pasar a en-espera, rechazada, anulada y en-revision. La vuelta `finalizada → en-ejecucion` (no conforme) es una extensión del PIM: XDW no sale de COMPLETED; en FHIR el `Task.status` vuelve de `completed` a `in-progress` y queda la versión y la Provenance de la reapertura. Cada transición genera una versión nueva del Task y su Provenance encadenado (equivale al historial de eventos de tarea de XDW). El PIM es un superconjunto de XDW: agrega aceptación, rechazo, espera y anulación, que el mantenimiento entre organismos necesita.

#### Traducción de IHE PCD-15 a FHIR (PIM-EQM)

| PCD-15 (ORU^R44) | FHIR |
|---|---|
| MSH-3 / OBX-18 (EUI-64) | `Device.identifier` con system `…/sid/eui64` (se crea el Device si no existe, con etiqueta `mem-dmc`) |
| OBX-3 `67880 MDC_ATTR_ID_MODEL` | `Device.manufacturer` y `Device.modelNumber` |
| OBX-3 `67972 MDC_ATTR_SYS_ID` | Fuente del EUI-64 si no vino en OBX-18, OBR-3, OBR-2 ni MSH-3 (en ese orden) |
| Cada otro OBX-3 | `DeviceMetric` (`pim-punto`, tipo = código MDC) + `Observation` (`pim-medicion`) |
| OBX-2 NM / CWE / ST | `valueQuantity` (unidad OBX-6) / `valueCodeableConcept` / `valueString` |
| OBX-14 (o OBX-7 en el ejemplo del suplemento) | `effectiveDateTime` |
| `68513 MDC_ATTR_LS_LOCATION` (PL) | `Device.location` si el local existe (búsqueda por nombre en la cadena establecimiento › edificio › piso › sala) |
| Autoprueba con resultado de falla | `ServiceRequest` + orden correctiva automática (`pim-orden`, `reasonReference` = la Observation) |
| Carga de batería (NM, %) ≤ umbral configurable (20 % por defecto) | `ServiceRequest` + orden correctiva «Batería crítica» (una abierta por equipo) |
| Alimentación (67925), carga de batería y autoprueba | Mediciones normalizadas `ALIMENTACION`, `BATERIA` y `AUTOPRUEBA` publicadas en `telemetria/medicion`; una alarma ISA-18.2 las evalúa si está configurada (PIM-ALR) |

La respuesta es un ACK de HL7 v2 (MSA-1 = AA, AE o AR) en el mismo canal MLLP. Un mensaje con PID o PV1 se rechaza con AR: PCD-15 no lleva datos de pacientes.

**Canal y seguridad.** MLLP sobre TCP, con lista de IP permitidas; con la opción de configuración `tls`, MLLP sobre TLS 1.2 o 1.3 (certificado propio del nodo o el que indique la institución y, con `tls_cliente`, certificado de cliente del gateway firmado por una autoridad conocida). También se acepta por HTTPS (`POST /api/m/mem/pcd15`, cuerpo `{"mensaje": "MSH|…"}`) con un usuario autorizado (capacidad `mem.recibir`). `MSH-21` debería traer `IHE_PCD_015`: si falta, el mensaje se procesa igual y queda anotado en el registro (gateways anteriores a la Rev. 1.5). Con la opción `aceptar_r01` se admite también `ORU^R01` sin PID/PV1 (Rev. 1.3). Un mensaje con PID o PV1 se rechaza con `AR` y `ERR 100` (segmento no esperado); un tipo no soportado, con `AR` y `ERR 200`.

#### Índice de Riesgo Unificado (PIM-RSG)

**IRU = F + R + M + A**, con la estructura de Fennigkoh y Smith (1989), extendida a instalaciones:

| Componente | Rango | Cómo se obtiene |
|---|---|---|
| F · función asistencial | 1 a 10 | Soporte vital o local del grupo 2b (AEA 90364-7-710) = 10 |
| R · riesgo ante la falla | 1 a 5 | En instalaciones se deriva de la categoría NFPA 99: 1 → 5, 2 → 4, 3 → 2, 4 → 1 |
| M · requisitos de mantenimiento | 1 a 5 | |
| A · antecedentes | −2 a +2 | Historial de incidentes |

| Banda base (del total) | Prioridad sugerida (`request-priority`) |
|---|---|
| A · IRU ≥ 18 | `stat` |
| B · 14 a 17 | `asap` |
| C · 10 a 13 | `urgent` |
| D · < 10 | `routine` |

**Ajustes** (dan la banda vigente, `pim-iru.bandaAjustada`): sube un nivel si el activo está **fuera de servicio sin contingencia** (lo declara la solicitud, `pim-estado-declarado`); baja un nivel si tiene **redundancia N+1 o 2N**. El mapa banda → prioridad es el ConceptMap `pim-banda-a-prioridad` y es normativo: dos implementaciones distintas tienen que sugerir la misma prioridad para el mismo activo. El RiskAssessment (PIM-7) publica los cuatro componentes, el total, la banda base y, si corresponde, la ajustada con su motivo.

#### Decisión humana sobre la prioridad (PIM-RSG)

La orden guarda la prioridad que **sugiere** el IRU del activo y su fundamento, y la extensión `pim-decision-prioridad` registra qué hizo el Gestor: **aceptada**, **cambiada** o **rechazada** (las dos últimas con motivo obligatorio), con autor y fecha. Si el Gestor saca la orden de «solicitada» sin decidir, se registra la decisión implícita. `GET Task?decision-prioridad=cambiada&_summary=count` mide cuántas sugerencias se modifican: es el control del sesgo de automatización. La sugerencia nunca reemplaza la decisión humana.


---

<sub>🎓 ¿Algo no quedó claro? [Preguntá en el foro de la capacitación PIM](https://iproy.com.ar/pim/foro) · [Dejá una propuesta de mejora](https://iproy.com.ar/pim/resenas) · Repositorio: https://github.com/iproy-ar/iproy</sub>
