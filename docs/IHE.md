<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Alineación con IHE

**Principio rector (PIM 0.9.2, iProy 1.1).** IHE confirma el problema que dio origen al PIM —lo diagnosticó en 2009 para los equipos médicos, en el white paper «Medical Equipment Management»— y confirma que sigue sin resolverse. Lo que IHE ofrece son bordes bien estandarizados (MEM, mCSD, ACM, ATNA/BALP) y un método de conformidad. El PIM se apoya en esos bordes en lugar de reinventarlos, y concentra lo nuevo en lo que nadie cubre: el flujo del mantenimiento entre organismos y las instalaciones hospitalarias vinculadas al riesgo para el paciente.

## Qué se implementó

| Perfil IHE | Dominio | En iProy 1.1 | Dónde |
|---|---|---|---|
| mCSD 4.0.0 (ITI-90) | ITI | El Servidor PIM responde como Directory: Organization y Location con `identifier`, `name` (`:exact`, `:contains`), `partof`, `organization`, `type`, `status`, `active` | `nodo/app/pim/lib/api.php` · `pruebas/pim.sh` §2 |
| mCSD, opción Update (ITI-91) | ITI | `GET Organization/_history?_since=` y `Location/_history?_since=` (Bundle history) | ídem |
| mCSD FacilityOrganization / FacilityLocation / Location / Organization | ITI | Bases de `pim-organismo`, `pim-establecimiento`, `pim-local` y `pim-empresa` | `especificacion/input/fsh/03_perfiles.fsh` |
| XDW (modelo de tres capas) | ITI | PIM-OT, PIM-SOL y PIM-ENS como definiciones de flujo sobre FHIR Task; correspondencia de estados | `especificacion/input/pagecontent/marco-tecnico.md` |
| MEM-DMC Rev. 1.5 (PCD-15) | DEV | Módulo `mem`: actor DMIC; MLLP (residente) y HTTP; ACK; traducción a Device + DeviceMetric + Observation | `nodo/modulos/mem` · `pruebas/mem.sh` |
| MEM-LS (PCD-16) | DEV | Se usa la ubicación PL que llega en PCD-15 (68513); el receptor PCD-16 propio queda pendiente | — |
| ACM | DEV | Puente PIM-ALR: alarmas ISA-18.2 del módulo `alarmas` | `nodo/modulos/alarmas` |
| Integration Statement | — | `GET /pim/declaracion` y extensión `pim-conformidad` del CapabilityStatement | `nodo/app/pim/lib/metadata.php` |
| Actores con CapabilityStatement | — | `pim-gm`, `pim-so`, `pim-ej`, `pim-ft`, `pim-ci`, `pim-re` (formato de las guías IHE en FHIR R4) | `especificacion/input/fsh/05_actores.fsh` |

## Lo que queda pendiente

- Receptor PCD-16 (MEM-LS) propio y opción Feed de mCSD (ITI-130).
- Auditoría con los perfiles de IHE BALP (hoy: auditoría interna equivalente).
- Validar los mensajes de PCD-15 con las herramientas de prueba de IHE/NIST, y las instancias FHIR con el validador oficial (lo corre el flujo de publicación).
- Proponer PIM-EQM y PIM-OT al dominio IHE Devices (doble vía de estandarización).

## Fuentes

- IHE DEV, suplemento MEM-DMC Rev. 1.5 (19-09-2024): https://www.ihe.net/uploadedFiles/Documents/PCD/IHE_Suppl_PCD_MEM-DMC.pdf
- IHE PCD White Paper MEM (2009): https://wiki.ihe.net/index.php/PCD_Detailed_Profile_Proposal_2009_MEM_WP
- IHE ITI mCSD 4.0.0: https://profiles.ihe.net/ITI/mCSD/
- IHE ITI XDW (Rev. 20.0): https://profiles.ihe.net/ITI/TF/Volume1/ch-30
