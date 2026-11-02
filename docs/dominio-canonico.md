# Dominio canónico y cómo cambiarlo sin romper nada

**Hoy:** `https://iproy.com.ar/fhir` es la base canónica de todos los perfiles, sistemas de códigos y extensiones (nombre del proyecto: **iProy**, adoptado el 01-11-2026). El cambio desde el nombre anterior se hizo antes de la primera publicación, así que **no consume ninguna de las migraciones previstas**. La implementación de referencia acepta como alias, durante la transición, el espacio de nombres interno que usaba antes.

**Previsión:** como máximo dos cambios, por ejemplo a un dominio provincial y luego a uno nacional (DNSIS).

## Reglas

1. **Un solo lugar de configuración:** `canonical` en `especificacion/sushi-config.yaml` y `PIM_CANONICAL` en el núcleo. Ningún código escribe el dominio a mano.
2. **Cambiar solo en una versión MENOR o MAYOR**, nunca en un parche, con aviso público previo.
3. **Período de convivencia de al menos 2 años:** el núcleo acepta el dominio viejo y el nuevo como equivalentes (mecanismo de alias ya implementado en el Servidor PIM v1.0).
4. **El dominio viejo no se abandona:** se renueva siempre y redirige (HTTP 301) a la nueva ubicación de cada artefacto.
5. **Identificador permanente:** cada artefacto lleva además un `identifier` con un OID, que no depende de ningún dominio. Se obtiene pidiendo gratis un Private Enterprise Number a IANA (`urn:oid:1.3.6.1.4.1.<PEN>…`). [trámite pendiente]
6. **Tabla de correspondencias publicada** en cada cambio: URL vieja → URL nueva.
