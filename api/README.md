# API iProy (contrato OpenAPI 3.1, versión 1.2.0)

> 🎓 **Capacitación PIM:** este documento tiene su versión guiada, con diagramas, ejemplos y foro, en [iproy.com.ar/pim](https://iproy.com.ar/pim/) (en particular [esta página](https://iproy.com.ar/pim/api)). El código fuente vive en [github.com/iproy-ar/iproy](https://github.com/iproy-ar/iproy).


- **Contrato:** `openapi_pim_v1_2_0.yaml` (HL7 FHIR R4 con perfiles PIM 0.9.2, alineada con IHE). La versión anterior está en `anteriores/`.
- **Versiones:** la API (1.2.0), el estándar que implementa (PIM 0.9.2) y el software de referencia (iProy 1.2.0) se numeran por separado. Ver «Tres versiones, tres cosas distintas» en el [README](../README.md).
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


---

<sub>🎓 ¿Algo no quedó claro? [Preguntá en el foro de la capacitación PIM](https://iproy.com.ar/pim/foro) · [Dejá una propuesta de mejora](https://iproy.com.ar/pim/resenas) · Repositorio: https://github.com/iproy-ar/iproy</sub>
