<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Cambios

## 1.1.2 · 03-11-2026 — Acceso directo en el escritorio

- `init` deja un **icono en el escritorio y en el menú de aplicaciones** (Linux: archivo `.desktop` con las acciones «Detener» y «Estado»; Windows: accesos `.lnk` en el escritorio y en el menú Inicio, con «Detener», y un `.ico` generado a partir del logo del producto). Para crearlos o quitarlos después: `acceso-directo` · `acceso-directo --quitar`.
- Nuevo comando `abrir`: si el nodo ya está funcionando abre el navegador; si no, lo inicia. Es lo que ejecuta el icono.
- Linux: `start` corre en su propia sesión (`setsid`), así que cerrar la terminal ya no apaga el nodo.
- Los productos incubados heredan todo con su propio nombre e icono.

## 1.1.1 · 03-11-2026 — Instalación verificada en una PC real y capacitación

Correcciones que salieron de instalar un producto incubado (MantSur) en EndeavourOS, paso a paso con el autor (`docs/INSTALACION.md` §C):
- `tools/bajar-runtime.sh` y `.ps1` crean `descargas/` y `runtime/linux-x64/` (en un producto recién incubado no existían y `curl` fallaba con el error 23).
- `instalar-mariadb` ya no termina en silencio cuando no hay una MariaDB recortada en `descargas/` (la búsqueda sin coincidencias cortaba el script con `set -e`); ahora explica qué copiar.
- La incubadora avisa al final, con instrucciones por sistema operativo, cuando PHP no tiene GD y el producto conserva el logo de iProy; el `LEEME` generado explica que el producto queda al lado del repositorio y los pasos reales del runtime.
- Aplicación web, módulo `pim`: «Nueva solicitud» ofrece la lista de activos por código y nombre (buscador con autocompletado) y explica qué hacer si el activo no existe; «Inventario» muestra la ubicación, permite elegirla al dar de alta un activo y suma la sección **Ubicaciones** (establecimiento › edificio › nivel › local, IHE mCSD) para crear el árbol del hospital desde la pantalla.
- README: `instalar-mariadb` no descarga MariaDB; espera el paquete en `descargas/`.
- Documentación: nuevo `docs/INSTALACION.md` (Linux y Windows, con los problemas de la práctica y su solución) y enlaces por sección a la capacitación en https://iproy.com.ar/pim en todos los documentos.

El Servidor PIM y el contrato OpenAPI no cambian (1.1.0 / 1.2.0).

## 1.1.0 · 02-11-2026 — PIM 0.9.2 alineado con IHE

**Principio rector:** apoyarse en los bordes que IHE ya estandarizó (mCSD, MEM, ACM, ATNA/BALP) y crear solo lo que nadie cubre (ver `docs/IHE.md`).

### Especificación PIM 0.9.2 (`especificacion/`)
- Perfiles de directorio derivados de IHE mCSD 4.0.0; nuevo `pim-establecimiento`; `pim-grupo-activo` y `pim-procedencia` formalizados.
- Marco técnico en formato IHE: actores con CapabilityStatement, transacciones PIM-1 a PIM-11, opciones y la definición de flujo PIM-OT con correspondencia XDW.
- Extensiones con ids `pim-*` (los camelCase de la 0.9.1 se aceptan como alias); nuevas `pim-orden-generada`, `pim-conformidad` e `pim-iru` (Device y RiskAssessment).
- CodeSystems de conformidad (nivel, perfil de integración, actor, opción) y de origen; ValueSet `pim-mem-dmc` con los códigos MDC de PCD-15.
- `pim-sistema` suma COM-LLAM y GAS-NAT (diferencia detectada por la prueba de contrato). SUSHI: 0 errores, 0 advertencias.

### Servidor PIM 1.1
- IHE ITI-90 e ITI-91 sobre Organization y Location; establecimiento como Facility de mCSD.
- `pim-pma` con n.º ANMAT, EUI-64, fechas, pertenencia y clase de riesgo; categoría de alcance A–D.
- `Device/$refes` (JSON y CSV) y rol RE.
- Puntos de medición como DeviceMetric; Observation con valores codificados, texto y booleanos; códigos MDC.
- `reasonReference` entre solicitud, orden y medición.
- Artefactos de la guía servidos tal como los compila la especificación (`herramientas/guia-al-nodo.sh`); Declaración de Integración en `/pim/declaracion`.
- Los nodos creados con el canónico `/pim` pasan solos a `/fhir`.

### Módulo nuevo `mem` (IHE MEM-DMC)
- Actor DMIC: PCD-15 (ORU^R44) por MLLP (proceso residente en los lanzadores `.sh` y `.ps1`) y por HTTP; ACK AA/AE/AR; rechaza PID y PV1.
- Alta automática del PMA por EUI-64, DeviceMetric con el código MDC, ubicación por PL, orden correctiva si la autoprueba falla y valores normalizados para las alarmas.

### Incubadora
- `--version`; la guía (`conf/pim-guia`) se copia sin renombrar; `ar.iproy.pim` protegido; la plantilla `gestion-mantenimiento` incluye `mem`. Colmen 0.2.0 regenerado.

### Contrato y pruebas
- OpenAPI 1.2.0. `herramientas/contrato.py` compara la guía compilada con el código: sin diferencias no declaradas. El CI lo exige.
- Publicado en https://github.com/iproy-ar/iproy; la guía FHIR pasa el validador oficial de HL7 (0 errores) y se publica en https://iproy-ar.github.io/iproy/.
- Pruebas: unitarias 47 · `pim.sh` 51 · `mem.sh` 19 · `colmena.sh` 20 · `industrial.sh` 19, en iProy y en Colmen.
- Publicación en GitHub: organización `iproy-ar`, contacto admin@iproy.com.ar (`herramientas/fijar-github.sh`) y guía de publicación.

## 1.0.0 · 02-11-2026 — núcleo modular, colmena e incubadora

### Agregado

**Núcleo** (`nodo/app/nucleo`)
- Módulos cargables con manifiesto, ABI versionado y niveles de estabilidad.
- Dependencias con orden topológico y migraciones SQL con sha256.
- Marca de nodo «contaminado» cuando hay módulos con licencia no libre.
- Bus de eventos con comodines MQTT y un cursor por suscriptor.
- Planificador con presupuesto de tiempo y un bloqueo por tarea.
- Diario encadenado y firmado con ed25519.
- Usuarios con roles PIM más el administrador (AD), y capacidades por módulo.
- Introspección `/api/proc` y proxy PIM en el mismo proceso con el rol del usuario.

**Colmena** `colmena/1`
- Identidad propia de cada nodo (ed25519, X25519 y ES384) y huella para comparar fuera de banda.
- Vínculos con aceptación mutua, revocables, con el rol PIM que se le otorga al otro nodo.
- Sobres firmados y sellados.
- RPC sobre la API PIM del otro nodo, con aserción SMART dirigida a ese nodo.
- Eventos remotos y transporte por la intranet o por el hub.

**Hub de relevo** (`hub/`)
- Un único PHP para hosting común, con SQLite y libsodium.
- Funciona como buzón de guardar y reenviar, con autenticación firmada, nonce, límite de pedidos y TOFU.

**Módulos**
- `pim`: vigía de cambios que publica en el bus, y pestañas de órdenes, solicitudes e inventario.
- `telemetria`: resolución del activo, envío por excepción a PIM, serie corta y gráfico.
- `alarmas`: ciclo de vida ISA-18.2 completo, órdenes automáticas e indicadores EEMUA 191.
- `preventivos`: por calendario y por uso, con reprogramación desde la ejecución real.
- `mqtt`: cliente 3.1.1, con proceso residente y publicación de alarmas.
- `modbus`: TCP FC3/FC4, agrupación de registros y tipos de 16 y 32 bits.

**Aplicación web** (`/app/`)
- Una sola página que arma sus pestañas con lo que aporta cada módulo.

**Incubadora**
- `nuevo`: renombrado completo del producto, logo, plantillas y aviso de derivación.
- `modulo`: genera un CRUD completo con API, interfaz, diario y bus.
- `verificar`: controla licencias, cabeceras SPDX, secretos y datos institucionales.

**Lanzadores**
- Comandos `nucleo`, `modulo`, `usuario` y `colmena`.
- Residente MQTT.
- Windows (`.ps1`) con los mismos comandos.

**Pruebas**
- Unitarias (32), integración de la colmena (19) e integración industrial (19).

### Agregado el 02-11-2026, tarde

- **Especificación PIM 0.9.1** en `especificacion/`: FSH compilado con SUSHI 3.21.0, 0 errores y 0 advertencias.
- Contrato OpenAPI 1.1.0 en `api/`.
- Documentos de la especificación: dominio canónico, vínculos, pruebas y publicación.
- `CITATION.cff` y CI: guía, pruebas unitarias, `verificar` y contrato.
- `herramientas/contrato.py`: tabla de diferencias entre la especificación y la implementación (L-329).
- **Vínculos según `docs/vinculos.md`:**
  - tipo (`local`, `institucional` o `central`);
  - vigencia: al vencer, el vínculo deja de autorizar y el cliente PIM del otro nodo se da de baja;
  - sha256 del acta firmada.
- **Proxy de salida** para la colmena, para redes con proxy (L-291): el tráfico a la LAN y al propio equipo nunca pasa por el proxy.
- Rango `nucleo` en el manifiesto, que se verifica. Esquema unificado en `docs/modulo.schema.json`.

### Cambiado

- El Servidor PIM publica en el canónico de la especificación, `https://iproy.com.ar/fhir`, y acepta `https://iproy.com.ar/pim` como alias.
- El lanzador detecta la IP de la LAN también en Arch y EndeavourOS, donde `hostname` no acepta `-I`.

- La distribución pública no trae datos ni configuración institucional:
  - organismo genérico;
  - sin empresas precargadas;
  - prefijo `legado_` para las bases heredadas.
