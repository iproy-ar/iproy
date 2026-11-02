# Bitácora de iProy / PIM

Registro de versiones y decisiones. Las fechas siguen ISO 8601.

| Fecha | Versión | Cambio |
|---|---|---|
| 2026-10-28 | API /fhir 0.3 | Primera API FHIR R4 de lectura probada con datos reales en una implementación local |
| 2026-10-29 | Estándar 0.9 | Propuesta completa: 6 partes, 19 perfiles, 9 sistemas de códigos, 11 perfiles de integración |
| 2026-10-30 | Servidor PIM 1.0 | Escritura de órdenes con máquina de estados, SMART Backend Services, trazabilidad encadenada; 72/72 pruebas en nodo de prueba |
| 2026-10-31 | Estándar 0.9.1 · API 1.1.0 | Enfoque argentino; productos médicos activos (PMA); categorías A–D; perfil pim-pma con los datos del REFES; DeviceMetric para telemetría; 11 sistemas de códigos; perfil de integración PIM-REG |
| 2026-10-31 | Repositorio | Guía en FSH (SUSHI 0 errores), núcleo, vínculos, licencias AGPL-3.0 y CC BY-SA 4.0 |
| 2026-11-01 | 0.9.1 rev. 1 | Nombre del proyecto: **iProy**; espacio de nombres `https://iproy.com.ar/fhir`; ejemplos con prefijo ficticio EJ- |
| 2026-11-02 | Publicación inicial | Repositorio preparado para su publicación en GitHub |

## Pendientes conocidos

- Implementar el contrato 1.1.0 en el servidor de referencia (DeviceMetric, sistemas de códigos, `Device/$refes`).
- Versión 0.9.2 del estándar: valores de extensiones con sistema auxiliar (`pim-valores`), perfil `pim-vinculo`.
- Kit de pruebas sobre Inferno.
