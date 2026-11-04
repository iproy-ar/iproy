### Alineación con IHE

<sub>🎓 [Conocé PIM: principio rector y alineación con IHE →](https://iproy.com.ar/pim/conoce)</sub>

**Principio rector (PIM 0.9.2).** IHE confirma el problema que dio origen al PIM —lo diagnosticó en 2009 para los equipos médicos, en el white paper MEM— y confirma que sigue sin resolverse. Lo que IHE ofrece son bordes bien estandarizados (MEM, mCSD, ACM, ATNA/BALP) y un método de conformidad. El PIM se apoya en esos bordes en lugar de reinventarlos, y concentra lo nuevo en lo que nadie cubre: el flujo del mantenimiento entre organismos y las instalaciones hospitalarias vinculadas al riesgo para el paciente.

| Perfil IHE | Dominio | Qué toma el PIM | Cómo |
|---|---|---|---|
| mCSD 4.0.0 | ITI | Directorio de organismos, establecimientos y locales; actualizaciones incrementales | `pim-organismo` deriva de FacilityOrganization, `pim-establecimiento` de FacilityLocation, `pim-local` de Location y `pim-empresa` de Organization. El Gestor responde ITI-90 y, con la opción `mcsd-update`, ITI-91 |
| XDW | ITI | Patrón de flujo de trabajo entre organizaciones | Capas de infraestructura, contenido y definición. PIM-OT, PIM-SOL y PIM-ENS son definiciones de flujo sobre FHIR Task. No se usa XDS |
| MEM-DMC (Rev. 1.5) | DEV | Estado de los equipos médicos hacia el CMMS | El Gestor se agrupa con el actor DMIC y recibe PCD-15 (ORU^R44) por MLLP |
| MEM-LS (Rev. 1.5) | DEV | Ubicación de los equipos médicos | Opción `mem-ls`: el Gestor se agrupa con el actor LOC (PCD-16). **Planificada:** iProy todavía no la implementa; la ubicación llega por PCD-15 (atributo 68513) |
| ACM | DEV | Avisos y escalamiento | PIM-ALR cubre las alarmas técnicas dentro del nodo; el puente ACM (PCD-04/PCD-06) está **planificado** |
| CT, ATNA, BALP, IUA | ITI | Hora, auditoría y autorización | Auditoría interna por cliente y operación (la publicación como AuditEvent según BALP está **planificada**); autorización SMART Backend Services. Hora: el nodo usa la del sistema operativo (que debe sincronizarse por NTP) y `status`/`diagnostico` avisan si se desfasa más de 60 s; no es un actor CT completo |
| Integration Statement y Connectathon | — | Método de conformidad | Declaración de Integración PIM y jornada de pruebas con kit propio |

#### Lo que IHE no cubre y el PIM sí

- Solicitud, orden de trabajo y su máquina de estados entre organismos.
- Planes preventivos, ensayos reglamentarios e indicadores.
- Riesgo explicable (IRU) que vincula la falla de una instalación con el riesgo para el paciente.
- Activos de instalaciones: gases medicinales, red IT médica, climatización de áreas críticas, agua para diálisis, esterilización, energía de emergencia.

#### Doble vía de estandarización

El PIM sigue como guía FHIR abierta, registrable en la Red Nacional de Interoperabilidad (Res. 115/2019), escrita desde la 0.9.2 con el formato IHE. Así sus perfiles PIM-EQM y PIM-OT pueden proponerse más adelante al dominio IHE Devices sin depender de ese proceso para avanzar.


---

<sub>🎓 ¿Algo no quedó claro? [Preguntá en el foro de la capacitación PIM](https://iproy.com.ar/pim/foro) · [Dejá una propuesta de mejora](https://iproy.com.ar/pim/resenas) · Repositorio: https://github.com/iproy-ar/iproy</sub>
