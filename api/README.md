# API iProy (contrato OpenAPI 3.1, versión 1.1.0)

- **Contrato:** `openapi_pim_v1_1_0.yaml` (HL7 FHIR R4 con perfiles PIM v0.9.1).
- **Visor:** abrí `index.html` en un navegador o publicalo en GitHub Pages.
- **Pruebas manuales:** importá `coleccion_iproy.postman_collection.json` en Postman o Bruno y configurá `base` y `token`.

| Operación | Ruta | Rol |
|---|---|---|
| Capacidades del servidor | `GET /pim/r4/metadata` | público |
| Buscar PMA, sistemas, activos | `GET /pim/r4/Device?categoria-alcance=A` | cualquiera autorizado |
| Datos del REFES | `GET /pim/r4/Device/$refes?_format=csv` | GM, RE |
| Nueva solicitud | `POST /pim/r4/ServiceRequest` | GM, SO |
| Cambiar el estado de una orden | `PUT /pim/r4/Task/{id}` con `If-Match` | GM, EJ |
| Enviar una medición | `POST /pim/r4/Observation` | FT |
| Catálogos | `GET /pim/r4/CodeSystem/{id}` · `GET /pim/r4/ValueSet/{id}` | público |

Autenticación: OAuth 2.0 SMART Backend Services (`private_key_jwt`, RS384/ES384). Ningún recurso contiene datos de pacientes.

**Estado:** el servidor de referencia 1.0 cumple el contrato 1.0.0; los cambios de 1.1.0 están listados en `x-pim-cambios` y se implementan en la Fase 1.
