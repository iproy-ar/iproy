<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Cambios

## 1.5.1 · 04-11-2026 — Auditoría del estándar antes de las pruebas de colmena (PIM 0.9.4, API 1.3.1)

Resultado de una revisión técnica independiente de la guía, la API y el código, previa a las pruebas de interoperabilidad entre tres nodos:

- **PIM-8 / ITI-91 corregido:** `_history` devolvía los cambios más nuevos primero y sin paginar, así que un nodo que sincronizaba con `_since` perdía los más viejos cuando había más de `_count`. Ahora va en orden cronológico ascendente, con `total` real y `link next` (`_offset`). La guía acota la promesa a los recursos versionados (Organization, Location, Device, ServiceRequest y Task).
- **IRU normativo (PIM 0.9.4):** la fórmula, los rangos (F 1–10, R 1–5, M 1–5, A −2..+2), la derivación de R desde NFPA 99, las bandas y los ajustes quedaron escritos en la guía y en la extensión `pim-iru` (`minValue`/`maxValue`). `pim-iru` distingue **banda base** de **banda ajustada** (con su motivo) y publica la **prioridad sugerida**; el mapa banda → `request-priority` es el ConceptMap `pim-banda-a-prioridad`. Así dos implementaciones sugieren lo mismo y la decisión humana se puede auditar.
- **Lo que un cliente descubre ahora coincide con lo que el servidor hace:** `SearchParameter` publicados para `decision-prioridad`, `banda` y `categoria-alcance`; la OperationDefinition `$transicion` incluye `prioridad`, `nota`, `decisionPrioridad` y `motivoPrioridad` (y `estado` pasa a opcional); el CapabilityStatement del Gestor suma Group y DiagnosticReport; el nodo sirve ConceptMap y SearchParameter en `/pim/r4/`.
- **IHE, declarado = implementado:** BALP y el puente ACM figuran como **planificados**; MEM-DMC toma el EUI-64 también de `67972 MDC_ATTR_SYS_ID`, registra si falta `IHE_PCD_015` en MSH-21, responde `ERR 100` al rechazar PID/PV1 y la guía documenta la ruta real `POST /api/m/mem/pcd15` y la opción `aceptar_r01`. La vuelta `finalizada → en-ejecucion` queda explicada como extensión del PIM respecto de XDW.
- **API 1.3.1:** DeviceMetric deja de figurar como modificable por PUT (el servidor nunca lo permitió).
- N.º de producto ANMAT: se acepta `PM-N-N` con 1 a 5 dígitos por grupo [supuesto, a confirmar con el padrón].
- `herramientas/contrato.py` ya no exceptúa recursos: mide de verdad que todo lo que sirve el servidor esté en `pim-gm`.
- Pruebas: `pim.sh` 69, `mem.sh` 28, `colmena.sh` 20 (intranet + hub de relevo local), unitarias 47.
- Nuevo `docs/PRUEBA_COLMENA_3_NODOS.md`: plan paso a paso de la primera prueba de interoperabilidad real (A ↔ B en la red local, C en otra institución por el hub), con fallas provocadas y criterio de éxito.
- Sitio de capacitación: la página de instalación no mostraba la introducción en pantallas comunes (el efecto de aparición no se disparaba en bloques más altos que la ventana); las capturas del instalador pasan a una galería compacta.

## 1.5.0 · 03-11-2026 — Instalador gráfico universal (Linux y Windows)

- **Instalador gráfico** (`instalador/`): se abre en el navegador y guía en 4 pantallas: revisión de la PC, opciones, instalación con barra de avance e informe final. Se distribuye como un solo archivo por sistema (`instalar-iproy-linux.sh`, `Instalador-iProy-Windows.zip`), armado con `instalador/armar.sh` y publicado en la versión de GitHub.
  - **Sin Git, sin PHP, sin winget:** baja el código como ZIP de la etiqueta y corre la incubadora con el PHP de FrankenPHP. Así se evitan los cuatro errores de la primera instalación en Windows (Paso 114).
  - **Descargas verificadas con SHA-256:** FrankenPHP y MariaDB oficiales, con avance en MB y MB/s, reintentos y caché para no volver a bajar.
  - **Permiso de administrador solo cuando hace falta**, con un aviso que explica para qué: firewall en modo red, sincronizar la hora, librerías que faltan en Linux (`pkexec` o el comando para pegar en una terminal) y Visual C++ en Windows (UAC; el instalador se verifica con su firma de Microsoft). Cada uno se puede saltar.
  - **Informe final** (HTML, JSON y registro técnico en la carpeta del producto): datos de la PC, componentes con su SHA-256, resultado y tiempo de cada paso, herramientas extra recomendadas para esa PC y comandos del día a día. No contiene contraseñas.
  - **Seguridad:** el servidor del instalador escucha solo en 127.0.0.1, con un token por sesión; la contraseña del administrador pasa al lanzador por una variable de entorno de ese proceso y nunca se escribe en disco ni en el registro.
- **Lanzadores:** `usuario alta` y `usuario clave` aceptan la contraseña por `<PRODUCTO>_CLAVE_NUEVA` (para el instalador); sin esa variable siguen preguntando como siempre.
- **Textos para hospitales y centros de salud:** la aplicación dice «La de la institución» y «El de la institución» (vista y fondo heredados), «Red de instituciones» en el menú, y los textos de ayuda nombran al hospital o centro de salud.

## 1.4.0 · 03-11-2026 — Canal cifrado, batería crítica y decisión humana (PIM 0.9.3, API 1.3.0)

Cierra los huecos encontrados al contrastar iProy con los prácticos de Informática Médica (IHE MEM-DMC y priorización por riesgo):

- **MLLP sobre TLS 1.2+** (módulo `mem` 1.1.0): con `"tls": true` el canal de IHE PCD-15 va cifrado con un certificado propio del nodo (se crea solo, EC P-256) o con el de la institución (`tls_cert`, `tls_clave`); con `tls_cliente` + `tls_ca` exige certificado al gateway. Sigue la lista de IP permitidas.
- **Batería crítica → orden correctiva**: si un equipo informa carga de batería igual o menor al umbral (`bateria_critica`, 20 % por defecto), se pide la orden sin necesidad de configurar una alarma. Una sola abierta por equipo y motivo; la prioridad la pone el IRU del activo.
- **Decisión humana sobre la prioridad** (extensión `pim-decision-prioridad`, PIM 0.9.3): la orden guarda la prioridad que sugiere el IRU y su fundamento («IRU = F + R + M + A = n · banda»), y registra si el Gestor la **aceptó**, la **cambió** o la **rechazó** (las dos últimas con motivo obligatorio), con autor y fecha. Si saca la orden de «solicitada» sin decidir, se registra la decisión implícita. `GET Task?decision-prioridad=cambiada` mide el sesgo de automatización.
- **Control de hora** (IHE ITI CT, parcial): `status` (Linux y Windows) y `diagnostico` (Windows) comparan la hora de la PC con una referencia HTTPS y avisan si el desfase supera 60 s; en Linux también muestran si NTP está sincronizado.
- **PCD-16 (MEM-LS) marcada como planificada** en la guía: la ubicación de los equipos llega por PCD-15 (atributo 68513) y el receptor PCD-16 propio sigue pendiente, como ya decía `docs/IHE.md`.
- **API 1.3.0** (`api/openapi_pim_v1_3_0.yaml`; la 1.2.0 pasa a `anteriores/`): parámetro de búsqueda `decision-prioridad` y parámetros `decisionPrioridad` y `motivoPrioridad` en `$transicion`.
- Pruebas: `pim.sh` 61 comprobaciones (10 nuevas de decisión), `mem.sh` 27 (8 nuevas de batería y TLS).

## 1.3.1 · 03-11-2026 — Primera instalación real en Windows

Correcciones encontradas instalando un nodo en Windows 10 desde cero:

- **Windows, `iProy-Control.bat`:** pasaba solo 7 argumentos al `.ps1`, así que `init -Nombre … -Modo … -Puerto … -Vista menu` perdía el último («Falta un argumento para el parámetro Vista»); ahora pasa todos.
- **Windows, `init`:** sugiere `start`; `importar-app` e `importar-datos` solo hacen falta al migrar desde Google Sheets.
- **Tutorial de Windows** (`docs/INSTALACION.md`, B1–B7): winget con `--scope machine`, reparación del error `0x8a15000f`, activación de `mbstring` y `gd`, Administrador solo para instalar, `pwd` antes de cada paso, pruebas con el bash de Git (no el de WSL) y tabla de problemas.
- **Windows, `tools\bajar-runtime.ps1`:** fallaba con «falta el parámetro Proxy» en Windows PowerShell 5.1; ahora usa el proxy del sistema solo si existe.
- **Incubadora:** al arrancar comprueba que PHP tenga `mbstring` y `json`; si falta alguna, explica cómo activarla en `php.ini` en lugar de cortar con un error fatal (detectado en la primera prueba en Windows, donde el PHP oficial trae `mbstring` desactivada).

## 1.3.0 · 03-11-2026 — Dos vistas: «widgets» y «menú»

- **Vista menú** (recupera la de la 1.1, con más color): encabezado con degradé, logo y nombre de la institución, y **todos los módulos arriba** con ícono; al pasar el mouse la pestaña se eleva, el ícono gira y aparece una línea del color de su categoría; la pestaña activa queda marcada con ese color. El inicio («Tablero») muestra el saludo y el resumen.
- **Vista widgets** (la de la 1.2): inicio con resumen y accesos rápidos por categoría, menú lateral.
- **Se elige al instalar**: `init` pregunta «1) widgets 2) menú» (o `--vista widgets|menu`; en Windows `-Vista`). Después se cambia para todo el nodo en **Configuración → Identidad** o con `iProy-Control.sh vista menu`, y **cada usuario puede elegir la suya** en «Mi perfil» (o «La del hospital»).
- **Resumen más compacto** (tarjetas de indicadores más bajas) y **plegable** con «Ocultar / Mostrar» (se recuerda en el navegador).
- La personalización (logo, fondos, transparencia, widgets) funciona igual en las dos vistas.

## 1.2.0 · 03-11-2026 — Tablero nuevo, identidad institucional y perfil

Rediseño de la aplicación a partir del sistema original del autor (GestionMant), sin perder nada de lo que ya hacía:
- **Inicio**: saludo con el nombre, la fecha y la institución; «Resumen» con tarjetas de indicadores (íconos, degradé suave, efecto al pasar el mouse); **accesos rápidos por categoría** (Mantenimiento, Planificación, Monitoreo, Red de hospitales, Administración) con buscador (tecla «/» y Enter), **recientes** por usuario y baldosas animadas.
- **Barra superior** con título y ubicación (categoría / módulo), botones atrás, inicio y adelante, y el usuario; **menú lateral** con todos los módulos agrupados.
- **Identidad institucional** (Configuración, solo administrador, con la contraseña confirmada y una sesión de configuración de 2 horas): logo propio en el ingreso, el menú y la barra; nombre y lema de la institución; fondo de la pantalla de ingreso; fondo del tablero para todos; orden y visibilidad de los widgets.
- **Mi perfil** (cada usuario): nombre visible, **fondo propio como los temas del correo** (11 temas sin Internet o una foto), transparencia del tablero sobre el fondo, widgets que quiere ver y cambio de contraseña.
- Imágenes: se achican en el navegador y el núcleo las vuelve a codificar con GD (PNG ≤ 512 px para logos, JPEG ≤ 2560 px para fondos); se rechaza todo lo que no sea PNG, JPG o WebP; se borran las que dejan de usarse.
- API nueva: `GET /api/apariencia` (pública), `GET|PUT /api/perfil`, `POST /api/perfil/fondo`, `POST /api/sesion/config`, `GET|PUT /api/config/apariencia`, `POST /api/config/imagen/{logo|fondo_login|fondo_tablero}`.
- Para los módulos: `Nucleo.tablero(fn, {id, titulo})`, `Nucleo.pestana({…, grupo, icono, desc})`, `Nucleo.kpis()` y `Nucleo.icono()`. Compatibles con los módulos anteriores.
- Telemetría, Usuarios, Colmena y Sistema se conservan igual, ahora como accesos del tablero.

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
