<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Investigación: qué se tomó de cada proyecto, y por qué

Investigación del 02-11-2026 previa al diseño de la versión 1.0. Criterio: *si existe, se aplica; si hay algo cercano, se adapta; si no hay nada, se crea*. Las marcas indican la certeza: \[verificado\] significa que se leyó la fuente en la fecha indicada; \[supuesto\], que es inferencia o fuente secundaria.

## Linux (núcleo, ABI, gobierno, seguridad)

| Práctica | Fuente | Qué se aplicó en iProy |
|---|---|---|
| Niveles del ABI: *stable* (al menos 2 años de compatibilidad), *testing*, *obsolete*, *removed*. Kconfig y los símbolos internos no son ABI | Documentation/ABI, README · https://docs.kernel.org/admin-guide/abi.html \[verificado\] | `"abi"` y `"estabilidad"` en cada `modulo.json`; el núcleo rechaza un ABI distinto; política de 2 años en GOBERNANZA.md |
| Kernel *tainted* por módulos no libres | Documentation/admin-guide/tainted-kernels | Estado «contaminado» del nodo, visible en `/api` y `/api/proc` |
| `MAINTAINERS` por área | Archivo MAINTAINERS del árbol de Linux | `MAINTAINERS` con el formato M:, S:, F: |
| El kernel es CNA desde febrero de 2024: asigna CVE recién cuando la corrección está en una versión estable, y solo para versiones con soporte | https://docs.kernel.org/process/cve.html \[verificado\] | SEGURIDAD.md: divulgación coordinada; una vulnerabilidad se cierra con la corrección publicada en una versión con soporte |
| DCO en lugar de CLA | https://developercertificate.org/ | CONTRIBUIR.md: `Signed-off-by` |
| Ramas estables y LTS | kernel.org/category/releases | Una LTS anual con 2 años de seguridad |

## Industria e IoT

| Proyecto o norma | Hallazgo | Decisión |
|---|---|---|
| **EdgeX Foundry 4.0 «Odesa»** (LF Edge) | Publicada el 01-04-2025, versión LTS. MQTT como bus de mensajes por defecto, Core Keeper, PostgreSQL, OpenBao, enfoque *zero-trust* \[verificado\] · https://docs.edgexfoundry.org/ | Bus interno con temas de estilo MQTT; la integración con EdgeX es por MQTT (sus servicios de dispositivo publican y el nodo escucha). No se embebe EdgeX, que es pesado para un nodo portable |
| **Sparkplug 3.0 = ISO/IEC 20237** (Eclipse) | Define el espacio de temas, la gestión de estado (birth/death) y la carga útil sobre MQTT \[verificado\] · https://sparkplug.eclipse.org/ | Hoja de ruta: plantilla Sparkplug B en el módulo mqtt (la extensión protobuf ya está disponible) |
| **MQTT 3.1.1** (OASIS, también ISO/IEC 20922) | Protocolo liviano con QoS y sesiones persistentes · https://docs.oasis-open.org/mqtt/mqtt/v3.1.1/ | Cliente propio sin dependencias, con proceso residente |
| **Modbus Application Protocol 1.1b3** | FC3 y FC4, MBAP, excepciones · https://modbus.org/specs.php | Cliente TCP de solo lectura (seguridad OT) |
| **ANSI/ISA-18.2 · IEC 62682** | Ciclo de vida de la alarma y sus estados (normal, no reconocida, reconocida, RTN sin reconocer, enclavada, archivada, suprimida por diseño, fuera de servicio); racionalización; indicadores de desempeño \[verificado: 9 estados del diagrama, incluidas variantes\] · https://www.isa.org/ | Módulo alarmas con esos estados y las acciones separadas por rol |
| **EEMUA 191** | Referencias de carga: alrededor de 1 alarma por 10 min es manejable y más de 10 en 10 min es avalancha \[supuesto: cifras de uso habitual en la industria\] | Indicadores en `/api/m/alarmas/kpi` |
| **EN 13306 · ISO 14224** | Tipos de mantenimiento y modos de falla | Ya en PIM (TIPOS_MANT, modo de falla al cerrar un correctivo) |

## CMMS libres

| Proyecto | Hallazgo | Lección |
|---|---|---|
| **openMAINT** (sobre CMDBuild) | Licencia GNU AGPL; la app móvil y el portal son propietarios \[verificado\] · https://www.openmaint.org/ | Riesgo del «núcleo abierto, extras cerrados». El Contrato social de iProy lo prohíbe: no hay ediciones recortadas |
| **Atlas CMMS** | Núcleo AGPLv3 más licencia comercial; Spring Boot + React \[verificado\] · https://github.com/Grashjs/cmms | El licenciamiento dual exige un CLA; iProy usa DCO para que nadie pueda volver a licenciar |

## Licencias y cumplimiento

| Tema | Hallazgo | Decisión |
|---|---|---|
| ¿Una licencia puede obligar a publicar las modificaciones privadas? | Ninguna licencia libre obliga a publicar modificaciones de uso privado. La AGPL es la más cercana: obliga a ofrecer la fuente a quienes usan el programa **por red** \[verificado: AGPL-3.0 §13\] | AGPL-3.0-or-later, más `/api/fuente` y el enlace en el pie de la aplicación. La incubadora crea derivados que ya cumplen |
| **CRA**, Reglamento (UE) 2024/2847 | Figura del *steward* de software libre: una persona jurídica que sostiene software libre destinado a actividades comerciales, con obligaciones livianas (art. 24: política pública, cooperación, reportes). Reporte de fabricantes desde el 11-09-2026; aplicación plena desde el 11-12-2027 \[verificado\] · https://eur-lex.europa.eu/eli/reg/2024/2847/oj. Un desarrollador individual sin actividad comercial no sería *steward* \[a verificar con asesoría legal\] | SEGURIDAD.md, SBOM CycloneDX (`herramientas/sbom.php`), divulgación coordinada |
| **REUSE** (FSFE) | Licencia y copyright legibles por máquina en cada archivo · https://reuse.software/ | Cabeceras SPDX y `REUSE.toml`; `incubar.php verificar` las controla en el núcleo y los módulos |
| **Contrato Social de Debian** | Compromisos públicos e irrevocables · https://www.debian.org/social_contract | CONTRATO_SOCIAL.md |

## Criptografía y seguridad de la colmena

| Elemento | Fuente | Uso |
|---|---|---|
| ed25519 y *sealed boxes* X25519 | libsodium · https://doc.libsodium.org/public-key_cryptography/sealed_boxes | Sobres firmados y sellados; el hub no puede leerlos |
| SMART Backend Services (RFC 7523, aserciones RS384 o ES384) | https://hl7.org/fhir/smart-app-launch/backend-services.html | RPC entre nodos con aserciones dirigidas al nodo destinatario |
| Huellas para comparar fuera de banda | Práctica de las mensajerías cifradas («números de seguridad») | `huella` en la identidad del nodo; la aceptación del vínculo es manual |

## Antecedentes propios integrados

- **PIM v0.9 / 0.9.1** (estándar de interoperabilidad de mantenimiento sobre FHIR R4) y **Servidor PIM 1.0**: es el núcleo de datos del dominio. El repositorio de la especificación (`pim-repositorio_v0.9.1c`, FSH, OpenAPI 1.1.0) se incorporará en `especificacion/` (pendiente).
- **Portable «colmena»**: lanzador, modo autónomo, CA propia, `/colmena/ping` neutral (lección L-349), todo lo que dejaron los Pasos 87 a 105.
- **Lecciones de la memoria de aprendizaje** que se aplicaron directamente al diseño:
  - L-321: proxy en el mismo proceso;
  - L-319: nada de DDL en la ruta de escritura;
  - L-347: `vivo()` por línea de comando;
  - L-348: normalización de alias;
  - L-350: tareas reanudables;
  - L-329: contrato contra texto.
