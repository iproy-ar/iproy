# iProy

> 🎓 **Capacitación PIM:** este documento tiene su versión guiada, con diagramas, ejemplos y foro, en [iproy.com.ar/pim](https://iproy.com.ar/pim/). El código fuente vive en [github.com/iproy-ar/iproy](https://github.com/iproy-ar/iproy).


**Núcleo libre para sistemas de gestión de mantenimiento e infraestructura crítica.** iProy funciona como un kernel: un núcleo pequeño y estable que carga módulos, una red de nodos («colmena») que se vinculan por acuerdo mutuo, y una incubadora que deja un sistema propio funcionando en minutos.

- **Interoperable por diseño.** Servidor PIM: HL7 FHIR R4, SMART Backend Services, Provenance encadenado. Alineado con IHE: directorio mCSD (ITI-90/91) y equipos médicos por MEM-DMC (PCD-15).
- **Industrial.** Incluye MQTT 3.1.1, Modbus TCP, alarmas ANSI/ISA-18.2 con indicadores EEMUA 191 y mantenimiento EN 13306.
- **Libre para siempre.** Código bajo AGPL-3.0-or-later, especificación bajo CC BY-SA 4.0. Las contribuciones se firman con DCO y no hay CLA.

> **Repositorio:** https://github.com/iproy-ar/iproy

### Tres versiones, tres cosas distintas

| Pieza | Versión | Qué es |
|---|---|---|
| **Estándar PIM** | **0.9.4** (borrador) | La especificación (`especificacion/`): perfiles FHIR, estados, terminologías. Es lo que debe cumplir cualquier software compatible. |
| **API PIM** | **1.3.1** | El contrato OpenAPI (`api/`). Implementa el estándar 0.9.4. |
| **Software iProy** | **1.6.1** | El nodo, la incubadora y la aplicación (`nodo/`, `incubadora/`). Implementa la API. |

El estándar sigue en 0.9.x a propósito: la 1.0 de un estándar significa «estable, no cambia sin un proceso formal», y llega cuando estén completos los perfiles del nivel Intermedio (PRV, ENS, KPI) y lo hayan probado otros hospitales. El software ya está en 1.x porque funciona y pasa todas las pruebas. Explicado para no técnicos: https://iproy.com.ar/pim/conoce#tres-versiones-tres-cosas-distintas
>

> Proyecto personal de su autor, publicado como software libre. No es una publicación oficial de ninguna institución. No contiene datos, documentos ni configuraciones de ninguna institución: cada nodo nace vacío.

## Qué hay en el repositorio

<sub>🎓 [Rutas de aprendizaje por rol →](https://iproy.com.ar/pim/aprende)</sub>

```
iproy/
├── instalador/      instalador gráfico universal (navegador + motor Python/PowerShell, informe final)
├── nodo/            distribución base: un nodo completo, portable (Linux y Windows), sin instalar nada en el sistema
│   ├── app/nucleo/  el núcleo: módulos, bus, planificador, diario firmado, usuarios, /api/proc, colmena
│   ├── app/pim/     Servidor PIM (FHIR R4) · app/app/ aplicación web · app/_iproy/ utilidades del nodo
│   ├── modulos/     pim · stock · telemetria · alarmas · preventivos · mqtt · modbus · mem (IHE MEM-DMC)  (cada uno con su modulo.json)
│   ├── conf/pim-guia/  artefactos de la guía PIM tal como los compila la especificación (los sirve el Servidor PIM)
│   ├── conf/ tools/ sdk/   Caddyfile, SQL, residente MQTT, SDK PHP y JS
│   └── iProy-Control.sh / .ps1 / .bat   lanzador: init, start, stop, usuario, modulo, nucleo, colmena, pim…
├── incubadora/      incubar.php: crea un software derivado (nombre, logo, plantilla) o un módulo nuevo; verifica licencias
├── hub/             hub de relevo de la colmena para Internet: un PHP para cualquier hosting (SQLite + libsodium)
├── especificacion/  estándar PIM 0.9.4 en FSH (HL7 FHIR R4, perfiles de directorio sobre IHE mCSD) · api/ contrato OpenAPI 1.3.1, visor y colección
├── pruebas/         unitarias.php · pim.sh (Servidor PIM, mCSD, REFES) · mem.sh (IHE PCD-15) · colmena.sh · industrial.sh
├── herramientas/    contrato.py (especificación ↔ servidor) · guia-al-nodo.sh · fijar-github.sh · simuladores Modbus y MQTT
├── docs/            arquitectura, protocolo colmena/1, guía de módulos, integración industrial, hoja de ruta, investigación
└── LICENSE, LICENSES/, CONTRATO_SOCIAL.md, CONTRIBUIR.md, GOBERNANZA.md, MAINTAINERS, SEGURIDAD.md, CHANGELOG.md, REUSE.toml
```

## En 5 minutos

> **¿Primera vez? Usá el instalador gráfico** (Windows y Linux, sin Git ni PHP): [Windows](https://github.com/iproy-ar/iproy/releases/latest/download/Instalador-iProy-Windows.zip) · [Linux](https://github.com/iproy-ar/iproy/releases/latest/download/instalar-iproy-linux.sh) · [cómo funciona](instalador/LEEME.md).
>
> Instalación a mano. Requisitos: `git`, `curl` y `php` (8.1+). En Arch/EndeavourOS también `libxcrypt-compat`. Tutorial completo para **Linux y Windows**, con los errores típicos resueltos: [docs/INSTALACION.md](docs/INSTALACION.md).

<sub>🎓 [Tutorial: instalar un sistema PIM en Linux y Windows →](https://iproy.com.ar/pim/instalar)</sub>

```bash
cd nodo
bash tools/bajar-runtime.sh                 # FrankenPHP (PHP 8.5), verificado por sha256
bash iProy-Control.sh instalar-mariadb      # MariaDB 11.4: antes copiá a descargas/ el paquete oficial de mariadb.org (o el recortado de otro nodo); lo verifica y recorta
bash iProy-Control.sh init --nombre "Pañol central" --modo lan
bash iProy-Control.sh start                 # MariaDB, servidor web, worker y residente MQTT
bash iProy-Control.sh usuario alta admin AD # te pide la contraseña (no queda en el historial)
# abrí http://localhost:8080/  → Tablero, Órdenes, Solicitud, Inventario, Preventivos, Alarmas, Telemetría, Colmena, Sistema
```

En Windows se usa `iProy-Control.bat init -Nombre "Pañol central" -Modo lan`, después `start`. El resto de los comandos se escribe igual.

## Tu propio sistema, en minutos

<sub>🎓 [Lección: tu propio software con la incubadora →](https://iproy.com.ar/pim/aprende/tu-propio-software)</sub>

```bash
php incubadora/incubar.php nuevo --nombre Colmen --sigla CLM --color "#0277bd" --plantilla gestion-mantenimiento
php incubadora/incubar.php modulo calibraciones --nombre "Calibraciones" \
    --campos "instrumento:texto!,fecha:fecha!,resultado:opcion(apto|no-apto|ajustado)!,error_pct:numero" \
    --destino ../Colmen/Colmen-Portable/modulos
```

La incubadora renombra todo el producto (archivos, bases, variables, lanzador) y genera el logo. Elegís una de cuatro plantillas: `gestion-mantenimiento`, `mantenimiento-basico`, `monitoreo-industrial` o `minima`.

Hay cosas que nunca cambia:

- la licencia;
- los avisos de copyright del original;
- el espacio de nombres canónico del estándar PIM;
- el protocolo `colmena/1`.

Por eso un nodo *Colmen* y un nodo *iProy* se vinculan y se entienden sin adaptaciones.

## Colmena: nodos que se vinculan

<sub>🎓 [Lección: conectá tu hospital con otros (la colmena) →](https://iproy.com.ar/pim/aprende/federar-hospitales)</sub>

```bash
# en el nodo A
bash iProy-Control.sh colmena vincular https://192.168.1.20:8443     # intranet (o el ID del otro nodo, por el hub)
# en el nodo B, después de comparar la huella por teléfono
bash Colmen-Control.sh colmena aceptar <ID-de-A> CI                  # CI consulta · SO solicitante · GM gestor
bash iProy-Control.sh colmena rpc <ID-de-B> GET "Task?status=requested"
```

Cada mensaje entre nodos va **firmado (ed25519)** y **sellado (X25519)** de extremo a extremo. Para Internet sin IP fija ni puertos abiertos, `hub/` es un buzón de relevo que se instala en un hosting PHP común. El hub no puede leer ni alterar lo que transporta.

La autorización la da el vínculo, y los permisos los aplica el Servidor PIM del nodo que recibe, según el rol que su administrador le dio al otro nodo. Cada operación queda en el Provenance con el nodo como autor.

## Estado (v1.6.1 · 06-11-2026)

<sub>🎓 [Plan de divulgación y novedades →](https://iproy.com.ar/pim/plan)</sub>

| Prueba | iProy 1.1.0 | Colmen 0.2.0 |
|---|---|---|
| `pruebas/unitarias.php`: bus, JSON canónico, Modbus, MQTT, alarmas, libsodium, manifiestos, HL7 v2 de PCD-15 y **contrato servidor ↔ guía código por código** | 47/47 | — |
| `pruebas/pim.sh`: guía y conformidad IHE, directorio mCSD (ITI-90/91), PMA con REFES y EUI-64, `Device/$refes`, DeviceMetric + Observation, solicitud → orden | 51/51 | 51/51 |
| `pruebas/mem.sh`: PCD-15 por MLLP y HTTP, ACK, alta por EUI-64, códigos MDC, ubicación, orden por autoprueba, rechazos | 19/19 | 19/19 |
| `pruebas/colmena.sh`: nodo iProy ↔ nodo Colmen por la intranet y por el hub local | 20/20 | |
| `pruebas/industrial.sh`: alarmas ISA-18.2, preventivos, Modbus TCP y MQTT con simuladores | 19/19 | 19/19 |
| `pruebas/nucleo16.sh` (1.6.0): listas desplegables, recuperación de la contraseña sin Internet (códigos y clave temporal), Ejecutor → responsable y stock del pañol | 45/45 | |
| `herramientas/contrato.py`: especificación 0.9.4 ↔ Servidor PIM 1.1 ↔ OpenAPI 1.3.1 | sin diferencias no declaradas | |
| Especificación PIM 0.9.4 (`sushi build especificacion`) | 0 errores, 0 advertencias | |
| **Validador oficial de HL7** (IG Publisher con tx.fhir.org, en el CI): guía publicada en https://iproy-ar.github.io/iproy/ | **0 errores**, 132 advertencias (OID y marca «experimental» de los ValueSets, perfiles sin ejemplo) | |
| Recorrido de la aplicación web y la consola PIM en Chromium | sin errores de consola | |
| `incubar.php verificar` | sin secretos, sin datos institucionales, licencias libres | ídem |

Resultados anteriores (v1.0.0): la colmena por Internet con el hub en un hosting real dio 20/20, y la distribución en una ruta con espacios con HTTPS en la LAN, 17/17.

Lo pendiente y los límites conocidos están en [docs/HOJA_DE_RUTA.md](docs/HOJA_DE_RUTA.md).

## Documentación

<sub>🎓 [Rutas de aprendizaje por rol →](https://iproy.com.ar/pim/aprende)</sub>

- **[docs/PRUEBA_COLMENA_3_NODOS.md](docs/PRUEBA_COLMENA_3_NODOS.md): plan de la primera prueba de interoperabilidad real** (dos nodos en una red y uno en otra institución por el hub de relevo).
- **[docs/INSTALACION.md](docs/INSTALACION.md): tutorial de instalación en Linux y Windows**, basado en una práctica real; versión guiada en https://iproy.com.ar/pim/instalar.
- **Sitio de capacitación y divulgación:** https://iproy.com.ar/pim — lecciones por rol, manuales, planos, casos, API, foro y reseñas.
- [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md): el núcleo explicado con analogías de Linux.
- [docs/COLMENA.md](docs/COLMENA.md): especificación del protocolo `colmena/1` y del hub.
- [docs/MODULOS.md](docs/MODULOS.md): cómo escribir un módulo, con el manifiesto, el ABI y los puntos de enganche.
- [docs/INDUSTRIAL.md](docs/INDUSTRIAL.md): MQTT, Modbus, alarmas ISA-18.2, OPC UA y Sparkplug.
- [docs/IHE.md](docs/IHE.md): alineación con IHE (mCSD, XDW, MEM-DMC, Integration Statement) y lo que queda pendiente.
- [docs/INVESTIGACION.md](docs/INVESTIGACION.md): qué se tomó de IHE, Linux, EdgeX, Sparkplug, la CRA europea, openMAINT y Atlas CMMS, y por qué.
- **Guía FHIR publicada:** https://iproy-ar.github.io/iproy/ (se regenera con cada cambio en `main`).
- [docs/publicar-en-github.md](docs/publicar-en-github.md): cómo se publicó y qué queda por configurar.
- [nodo/INSTALAR.md](nodo/INSTALAR.md): instalación detallada y comandos del lanzador.

## Licencia

<sub>🎓 [Conocé PIM: qué gana un hospital y por qué es libre →](https://iproy.com.ar/pim/conoce)</sub>

- Código: **GNU AGPL-3.0-or-later** ([LICENSE](LICENSE)). Quien ofrezca iProy o un derivado por red tiene que ofrecer el código fuente correspondiente, incluidas sus modificaciones. El enlace «código fuente» del pie de la aplicación y `GET /api/fuente` cumplen esa función.
- Especificación y documentación: **CC BY-SA 4.0**.
- Cada archivo lleva su cabecera `SPDX-License-Identifier`, según la convención [REUSE](https://reuse.software/).

Los compromisos del proyecto están en el [Contrato social](CONTRATO_SOCIAL.md).


---

<sub>🎓 ¿Algo no quedó claro? [Preguntá en el foro de la capacitación PIM](https://iproy.com.ar/pim/foro) · [Dejá una propuesta de mejora](https://iproy.com.ar/pim/resenas) · Repositorio: https://github.com/iproy-ar/iproy</sub>
