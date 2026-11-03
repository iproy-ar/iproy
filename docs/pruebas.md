# Pruebas de conformidad

> 🎓 **Capacitación PIM:** este documento tiene su versión guiada, con diagramas, ejemplos y foro, en [iproy.com.ar/pim](https://iproy.com.ar/pim/) (en particular [esta página](https://iproy.com.ar/pim/instalar#comprobar)). El código fuente vive en [github.com/iproy-ar/iproy](https://github.com/iproy-ar/iproy).


Se reutilizan dos herramientas abiertas en lugar de construir un marco propio:

| Herramienta | Qué hace | Licencia |
|---|---|---|
| **HL7 FHIR Validator** (`validator_cli.jar`) | Valida cada recurso contra los perfiles PIM: cardinalidades, códigos, invariantes | Apache 2.0 |
| **Inferno Framework** (ONC, EE. UU.: `inferno-core`) | Ejecuta conjuntos de pruebas contra un servidor real: pide, crea, cambia estados y verifica respuestas. Corre en Docker y genera un informe | Apache 2.0 |

La licencia Apache 2.0 es compatible con la GPLv3 y la AGPLv3, de modo que el kit de pruebas del PIM puede publicarse con AGPL.

## Kit de pruebas PIM (`pruebas/`)

<sub>🎓 [Pruebas del estándar: qué comprueban pim.sh y mem.sh →](https://iproy.com.ar/pim/instalar#comprobar)</sub>

- **Nivel Básico:** CapabilityStatement; búsqueda y lectura de Device, Location y Organization; alta de ServiceRequest y creación de su Task; todas las transiciones válidas e inválidas de la Tabla 9-3; If-Match; ausencia de datos de pacientes.
- **Nivel Intermedio:** operación `Device/$refes` y completitud de los 5 datos del REFES; RiskAssessment con sus componentes; DiagnosticReport con PDF firmado.
- **Nivel Avanzado:** telemetría por REST y MQTT; historial incremental; vínculos y registro encadenado.

Cada prueba cita el requisito del estándar que verifica. El informe de Inferno es la evidencia de conformidad para pliegos y para el registro de implementaciones.


---

<sub>🎓 ¿Algo no quedó claro? [Preguntá en el foro de la capacitación PIM](https://iproy.com.ar/pim/foro) · [Dejá una propuesta de mejora](https://iproy.com.ar/pim/resenas) · Repositorio: https://github.com/iproy-ar/iproy</sub>
