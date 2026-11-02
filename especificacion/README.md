<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Especificación PIM 0.9.1: guía de implementación HL7 FHIR R4

**PIM** es el Protocolo de Interoperabilidad de Mantenimiento en Salud. Esta carpeta contiene su guía de implementación, escrita en FSH:

- 13 perfiles;
- 12 extensiones;
- 13 CodeSystems;
- 18 ValueSets;
- 7 ejemplos.

| Dato | Valor |
|---|---|
| Canónico | `https://iproy.com.ar/fhir` |
| Paquete | `ar.iproy.pim` |
| Licencia | CC BY-SA 4.0 |

```bash
npm install -g fsh-sushi        # SUSHI 3.21.0
sushi build especificacion      # verificado el 02-11-2026: 0 errores, 0 advertencias
```

El workflow `.github/workflows/guia.yml` publica la guía con el HL7 IG Publisher.

- **Contrato de la API:** `../api/openapi_pim_v1_1_0.yaml`.
- **Diferencias con la implementación de referencia (Servidor PIM 1.0):** `DIFERENCIAS_IMPLEMENTACION.md`. Se regenera con `python3 herramientas/contrato.py`.
- **Cambio del dominio canónico:** `../docs/dominio-canonico.md`.
