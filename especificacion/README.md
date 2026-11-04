<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Especificación PIM 0.9.4: guía de implementación HL7 FHIR R4 alineada con IHE

**PIM** es el Protocolo de Interoperabilidad de Mantenimiento en Salud. Esta carpeta contiene su guía de implementación, escrita en FSH:

- 16 perfiles (los de directorio derivan de IHE mCSD 4.0.0);
- 15 extensiones;
- 19 CodeSystems y 26 ValueSets;
- 6 CapabilityStatements de actores (GM, SO, EJ, FT, CI, RE) y 2 OperationDefinitions (`$transicion`, `$refes`);
- 13 ejemplos;
- páginas: marco técnico (formato IHE), alineación con IHE y Declaración de Integración PIM.

| Dato | Valor |
|---|---|
| Canónico | `https://iproy.com.ar/fhir` |
| Paquete | `ar.iproy.pim` |
| Licencia | CC BY-SA 4.0 |

```bash
npm install -g fsh-sushi        # SUSHI 3.21.0
sushi build especificacion      # verificado el 02-11-2026: 0 errores, 0 advertencias (descarga ihe.iti.mcsd 4.0.0)
bash herramientas/guia-al-nodo.sh   # copia los artefactos al nodo (nodo/conf/pim-guia)
```

El workflow `.github/workflows/guia.yml` publica la guía con el HL7 IG Publisher.

- **Contrato de la API:** `../api/openapi_pim_v1_3_1.yaml`.
- **Diferencias con la implementación de referencia (Servidor PIM 1.1):** `DIFERENCIAS_IMPLEMENTACION.md` (sin diferencias no declaradas). Se regenera con `python3 herramientas/contrato.py`.
- **Cambio del dominio canónico:** `../docs/dominio-canonico.md`.
