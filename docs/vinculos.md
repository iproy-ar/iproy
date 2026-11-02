# Vínculos institucionales y registro encadenado

Ningún nodo comparte datos con otro sin un **vínculo**: una relación que una parte propone, la otra acepta y ambas firman. *[propuesta — Fase 1]*

> **Estado de la implementación (núcleo 1.0, 02-11-2026).** Ya funcionan:
>
> - la propuesta, la aceptación y la revocación con sobres firmados (ed25519);
> - el tipo, la vigencia y el sha256 del acta;
> - el registro mutuo como clientes PIM, con el rol acordado;
> - el diario encadenado y firmado de cada nodo.
>
> Quedan para la versión 1.1: el alcance por recurso, la representación FHIR (Contract `pim-vinculo`) y el anclaje del sello de tiempo en BFA. Ver `COLMENA.md`.

## Tipos

| Tipo | Partes | Ejemplo |
|---|---|---|
| `local` | Nodos de una misma institución, en el mismo edificio | Mantenimiento e Ingeniería Clínica de un mismo hospital |
| `institucional` | Instituciones distintas | Hospital ↔ empresa contratista; hospital ↔ hospital |
| `central` | Un organismo central y los actores que admite | Ministerio provincial con servidor en la nube |

## Ciclo de vida

1. **Propuesta.** El nodo A arma la propuesta: partes, tipo, alcance (qué recursos y perfiles, en qué dirección), vigencia y base legal. La firma con la clave del nodo.
2. **Revisión.** Los responsables de B la evalúan. Puede pedirse un convenio o un acto administrativo.
3. **Aceptación.** B firma con la clave de su nodo. Las personas autorizadas firman el acta con **firma digital (Ley 25.506)**, o la firman en papel y se adjunta el acta escaneada con su huella.
4. **Activación.** Los nodos intercambian sus claves públicas y se registran mutuamente como clientes, con permisos limitados al alcance aceptado. El vínculo **es** la autorización técnica.
5. **Suspensión o revocación.** Cualquiera de las partes puede suspender o revocar el vínculo con un registro firmado; la sincronización se detiene de inmediato.
6. **Vencimiento.** Al terminar la vigencia, el vínculo deja de autorizar.

## Registro encadenado («blockchain» de vínculos)

Cada evento (propuesta, aceptación, suspensión, revocación) se agrega a un **registro de solo agregado** en el que cada entrada contiene: número, fecha, tipo de evento, identificador del vínculo, huella SHA-256 del acta, firmas de las partes y **la huella de la entrada anterior**. Cambiar una entrada vieja rompe la cadena y se detecta.

- Cada parte guarda una copia completa y las copias se comparan al sincronizar.
- **No hace falta una blockchain pública con minería ni criptomonedas:** las partes se conocen y se identifican, y la validez legal la da la firma digital, no la cadena.
- **Sello de tiempo público (opcional):** la huella diaria del registro puede anclarse en el servicio de sello de tiempo de **Blockchain Federal Argentina (BFA)**, que prueba ante terceros que el registro existía en esa fecha. [verificar condiciones de uso y API vigentes]

## Representación en FHIR

| Elemento | Recurso | Perfil |
|---|---|---|
| Vínculo | Contract | `pim-vinculo` (tipo, partes, firmantes, alcance, vigencia) |
| Acta firmada | DocumentReference | PDF/A con firma digital |
| Entrada del registro | Provenance | Huella encadenada (ya usada por el Servidor PIM v1.0) |
| Dirección técnica del nodo | Endpoint | Directorio de nodos |

## Sincronización con un servidor central

Es un vínculo de tipo `central`: el organismo publica sus condiciones de admisión, el actor propone y el organismo acepta. Los datos siguen siendo de cada institución; el servidor central guarda la copia acordada en el alcance. Nunca se sincronizan datos de pacientes.
