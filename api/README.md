# API iProy (contrato OpenAPI 3.1, versión 1.2.0)

- **Contrato:** `openapi_pim_v1_2_0.yaml` (HL7 FHIR R4 con perfiles PIM 0.9.2, alineada con IHE). La versión anterior está en `anteriores/`.
- **Visor:** abrí `index.html` en un navegador o publicalo en GitHub Pages.
- **Pruebas manuales:** importá `coleccion_iproy.postman_collection.json` en Postman o Bruno y configurá `base` y `token`.
- **Pruebas automáticas:** `pruebas/pim.sh` y `pruebas/mem.sh` del repositorio, sobre un nodo iniciado.

| Operación | Ruta | Rol | IHE |
|---|---|---|---|
| Capacidades y conformidad | `GET /pim/r4/metadata` | público | Integration Statement (extensión pim-conformidad) |
| Declaración de Integración PIM | `GET /pim/declaracion` | público | IHE Integration Statement |
| Directorio: establecimiento y locales | `GET /pim/r4/Location?type=facility` · `?partof=…&organization=…` | cualquiera autorizado | mCSD ITI-90 |
| Cambios del directorio | `GET /pim/r4/Location/_history?_since=…` | cualquiera autorizado | mCSD ITI-91 |
| Buscar PMA, sistemas, activos | `GET /pim/r4/Device?categoria-alcance=A` | cualquiera autorizado | — |
| Datos del REFES | `GET /pim/r4/Device/$refes?_format=csv` | GM, RE, CI | — |
| Nueva solicitud | `POST /pim/r4/ServiceRequest` | GM, SO | — |
| Cambiar el estado de una orden | `PUT /pim/r4/Task/{id}` con `If-Match` o `POST Task/{id}/$transicion` | GM, EJ | patrón XDW |
| Enviar una medición | `POST /pim/r4/Observation` | FT | — |
| Estado de equipos médicos | PCD-15 por MLLP (puerto 2575 del módulo mem) o `POST /api/m/mem/pcd15` | — | MEM-DMC (DMIC) |
| Guía | `GET /pim/r4/{CodeSystem|ValueSet|StructureDefinition|CapabilityStatement|OperationDefinition}/{id}` | público | — |

Autenticación: OAuth 2.0 SMART Backend Services (`private_key_jwt`, RS384/ES384). Ningún recurso contiene datos de pacientes.

**Estado:** el Servidor PIM 1.1 (iProy 1.1.0) cumple el contrato 1.2.0. Las diferencias con la especificación se miden por máquina en `especificacion/DIFERENCIAS_IMPLEMENTACION.md`.
