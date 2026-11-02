# Pruebas de conformidad

Se reutilizan dos herramientas abiertas en lugar de construir un marco propio:

| Herramienta | Qué hace | Licencia |
|---|---|---|
| **HL7 FHIR Validator** (`validator_cli.jar`) | Valida cada recurso contra los perfiles PIM: cardinalidades, códigos, invariantes | Apache 2.0 |
| **Inferno Framework** (ONC, EE. UU.: `inferno-core`) | Ejecuta conjuntos de pruebas contra un servidor real: pide, crea, cambia estados y verifica respuestas. Corre en Docker y genera un informe | Apache 2.0 |

La licencia Apache 2.0 es compatible con la GPLv3 y la AGPLv3, de modo que el kit de pruebas del PIM puede publicarse con AGPL.

## Kit de pruebas PIM (`pruebas/`)

- **Nivel Básico:** CapabilityStatement; búsqueda y lectura de Device, Location y Organization; alta de ServiceRequest y creación de su Task; todas las transiciones válidas e inválidas de la Tabla 9-3; If-Match; ausencia de datos de pacientes.
- **Nivel Intermedio:** operación `Device/$refes` y completitud de los 5 datos del REFES; RiskAssessment con sus componentes; DiagnosticReport con PDF firmado.
- **Nivel Avanzado:** telemetría por REST y MQTT; historial incremental; vínculos y registro encadenado.

Cada prueba cita el requisito del estándar que verifica. El informe de Inferno es la evidencia de conformidad para pliegos y para el registro de implementaciones.
