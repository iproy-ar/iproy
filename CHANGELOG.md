<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Cambios

## 1.7.0 · 23-11-2026 — Gestión de mantenimiento completa, colmena en malla y actualizaciones sobre lo instalado

Primera versión publicada después de la 1.6.2: incluye la 1.6.3 (cola de salida de la colmena, nunca publicada sola) y todo lo armado y verificado entre el 20 y el 23-11-2026. Un nodo 1.6.x se actualiza sin perder datos con `instalador/actualizar-linux.sh` (y, desde esta versión, desde la propia aplicación): las migraciones de la base solo agregan.

En corto: paridad con el sistema anterior por olas (widgets y creador de widgets, pedidos y sábanas, auditoría y empresas, pañol y reportes, preventivos por semanas, IoT por áreas), migración de datos de otros sistemas, Inventario con planillas, colmena en malla (cada nodo es hub, puente, emisor y receptor), menú agrupado por tipo y, desde esta versión, **actualizar sobre lo instalado** desde la propia aplicación.

### 23-11-2026 — Menú por tipo y actualizar sobre lo instalado

- **Menú agrupado por tipo** (núcleo): en la vista menú, Mantenimiento, Pañol, Planificación, Monitoreo, Red de instituciones y Administración abren su submenú con el nombre y la descripción de cada acceso; un grupo de un solo acceso va directo. Si los grupos no entran a lo ancho (celular), queda un único botón «Menú» con los grupos en acordeón y el lugar actual a la vista. En la vista widgets, el menú lateral muestra cada grupo plegable y abre el de la página actual. Stock pasa al grupo Pañol, al lado de Mantenimiento.
- **Actualizar sobre lo instalado** (Configuración › Actualizaciones, `GET|POST /api/actualizacion…`, `nucleo/lib/actualizacion.php`, `paquete.php`, `tools/actualizar.php`):
  - El paquete se busca en GitHub (última versión publicada; revisión diaria y aviso a los administradores) o se sube a mano (.zip del código, en partes de 3 MB; sirve sin Internet y para probar antes de publicar).
  - Se revisa antes de instalar: versión nueva, otra compilación, ya instalada, anterior o mayor (piden confirmación) o incompatible (`actualizable_desde` en `producto.json`). Cada paquete dice de qué commit salió (`nodo/BUILD`, completado por `git archive` con export-subst).
  - «Actualizar ahora» (administrador, con la contraseña de configuración): el lanzador hace un respaldo de la base, detiene el sistema, cambia solo el código con `incubar.php actualizar` (datos, runtime y descargas quedan; el producto incubado conserva su identidad, sus módulos y su logo), lo inicia con el lanzador nuevo (las migraciones corren solas) y, si no arranca, vuelve solo a la versión anterior. La pantalla sigue los pasos y se recarga al terminar.
  - «Volver a la versión anterior»; se guardan las 3 últimas versiones de código al lado de la carpeta del producto. Historial con el respaldo de cada actualización y asiento en el diario firmado.
  - Lanzadores: `actualizar <paquete.zip> [--forzar] | --volver` (Linux) y `actualizar <paquete.zip> [forzar] | actualizar volver` (Windows).
- Prueba nueva `pruebas/actualizacion.sh` (25 comprobaciones: subida en partes, datos intactos, igual, anterior, versión que no arranca, volver, terminal y permisos).
- **Migraciones que cambian solo en un comentario** (núcleo): `sql/equivalentes.txt` de cada módulo lista la huella de versiones anteriores que difieren solo en comentarios. Un nodo que ya aplicó la anterior registra la nueva sin anotar «migración alterada» en su diario (asienta `modulo.migracion_comentario` una vez). Se usó en `stock/002_panol.sql` y `preventivos/003_registro_tareas.sql`, cuyos comentarios ya no nombran al sistema anterior.
- **Manual de usuario:** enlace a https://iproy.com.ar/aprende-iproy desde el README y el instalador.

### 23-11-2026 — Inventario: planillas, ubicaciones claras, editar sin romper las órdenes y sacar de circulación sin borrar

Lo pidió el primer uso real (PC1-linux, nodo 1 de la prueba de 3 PC): la carga no era intuitiva, no había cómo importar ni exportar, y las ubicaciones mostraban «Organismo (configurar en data/pim/config.json) › …».

- **Establecimiento:** sin nombre escrito, se muestra el del nodo (nunca más «Organismo (configurar…)», ni en las ubicaciones ni en «Empresa responsable»). Se cambia desde Inventario (`PUT /api/m/pim/establecimiento`) y se refleja en el Organization y la raíz de las ubicaciones.
- **Pestaña Inventario nueva** (pim 1.7.0):
  - Establecimiento arriba; indicadores que filtran (en servicio, fuera de servicio, sin ubicación, ubicaciones); filtros por ubicación (incluye lo que está dentro), clase y estado.
  - **Alta guiada:** primero qué es (instalación, equipo médico u otro activo), después los datos que corresponden: sistema PIM (Anexo B) obligatorio en las instalaciones, N.º ANMAT y clase de riesgo en los equipos médicos, NFPA 99 y puesta en marcha en «Más datos». Código propuesto si se deja vacío.
  - **Ubicaciones en árbol** (edificio › piso › sala): se eligen por su ruta completa, con buscador; «＋ Nueva ubicación» desde el mismo formulario del activo. Tarjeta de ubicaciones con sangría, tipo, código y cantidad de activos.
  - **Ficha del activo:** datos, partes, órdenes (total, abiertas, últimas 10), historial de versiones con el motivo de cada cambio; Editar, Sacar de circulación, Volver a poner en servicio y Pedir un trabajo.
- **Importar** (`POST /api/m/pim/inventario/importar`): `.xlsx` (hojas «Ubicaciones» y «Activos»), `.csv` (separador detectado), texto pegado desde Excel u hoja de Google Sheets por enlace (también OneDrive y Dropbox). **Primero se revisa**: nuevos, que cambian, iguales y errores por fila, con el motivo. Reconoce encabezados de otras planillas (Denominación, Marca, Criticidad…). Una celda vacía no cambia nada; «-» borra. Crea las ubicaciones que falten por la ruta «Edificio > Piso > Sala». «Forma parte de» puede nombrar un activo de la misma planilla. Lotes de 200 por Bundle.
- **Exportar** (`GET …/inventario/exportar?formato=xlsx|csv`) en el mismo formato, para corregir y volver a importar. **Planilla de ejemplo** (`…/plantilla`): Instrucciones, Ubicaciones, Activos con filas de ejemplo y Listas (clases, sistemas del Anexo B, tipos de ubicación, NFPA 99, estados). Lector y escritor de `.xlsx` propios, sin bibliotecas (`nucleo/lib/planilla.php`).
- **Nada se borra:** un activo pasa a fuera de circulación, obsoleto o cargado por error, con motivo obligatorio (`Device.statusReason`, columnas `estado_tipo`, `estado_motivo`, `estado_fecha`). Desde ese momento no admite pedidos nuevos (422), no aparece en las listas para pedir trabajos ni en el plan preventivo (sus tareas y planes se pausan y se reanudan al volver a ponerlo en servicio). Las órdenes que ya tenía siguen su curso.
- **Editar sin romper las órdenes:** las órdenes y las solicitudes llevan el código vigente del activo (`Reference.identifier`); al cambiar el código o el nombre, cada módulo pone al día su copia con el evento `pim/Device/update` (reportes: `rep_orden.activo_codigo`; preventivos, stock, telemetría y paneles: sus nombres). Auditoría busca y muestra por «código · nombre», también cuando el código tiene la forma de un número de orden. Conflicto de versión → 409.
- **Actualizar un producto ya instalado sin perder datos:** `php incubar.php actualizar <carpeta-del-producto> [--sumar migracion]` y `instalador/actualizar-linux.sh`. Reincuba con la misma identidad, guarda el código anterior al lado y respeta `data/`, `runtime/`, `descargas/`, `python/`, los módulos activos y el logo.
- Preventivos: la tarea elige solo activos en servicio («código · nombre · lugar») y completa sistema y ubicación con los del activo.
- Prueba nueva `pruebas/inventario.sh` (65 comprobaciones).

### 22-11-2026 — Colmena en malla: cada nodo es hub, puente, emisor y receptor

La colmena deja de depender de un punto único: los mismos nodos la sostienen.

- **Hub propio en cada nodo** (`<nodo>/colmena-hub/`, `nucleo/lib/malla.php`):
  - El mismo protocolo del hub central, solo para los nodos con vínculo activo y vigente. Sin registro ni código.
  - La firma de cada pedido incluye el identificador del hub: un pedido firmado para un hub no sirve en otro.
  - Buzón en la base del nodo (500 sobres por destinatario, 72 horas) y cupo de esperas largas (`cupo_espera`, 4).
- **Varios hubs a la vez:** la escucha del worker mantiene un pedido abierto con el hub central y con el hub de cada nodo vinculado que alcanza.
- **Caminos por vínculo:** directo, hub propio, hub de otro nodo, hub central y puente.
  - Se elige el más rápido que anda, con la demora medida por `ping`/`pong` cada 5 minutos.
  - Si un camino falla se pasa al siguiente y ese queda en pausa. Si no queda ninguno, cola de salida.
  - El sondeo busca a cada vinculado en sus direcciones y mira si su hub está encendido. Alcanza con que uno de los dos llegue al otro.
- **Puente:** un nodo reenvía sobres entre dos nodos con los que tiene vínculo activo (sobre `relevo`), sin poder leerlos. Un solo puente entre origen y destino.
- **Anuncio:** cada nodo cuenta a sus vinculados sus direcciones, si su hub está encendido, su modo de red y a quiénes llega (sobre `anuncio`).
- **Vincular por identificador** busca en el hub central y en el hub de los nodos ya vinculados: dos nodos se pueden vincular sin hub central.
- **Interruptores** (Colmena › «Red de la colmena», `PUT /api/colmena/config {malla}` y `colmena hub-propio` / `colmena modo`):
  - **Hub propio** encendido / apagado.
  - **Modo de red:** `malla` (todos los caminos), `central` (solo el hub central: nada entra directo) o `aislado` (sin colmena; lo que sale espera en la cola sin contar intentos).
  - Los cambios quedan en el diario (`colmena.malla`).
- **Hub central 1.2.0** (`hub/index.php`): `enviar` responde `escucha` (hace cuánto pidió algo el destinatario) y hay modo `mantenimiento` (503 a todo).
- **Pantalla Colmena:** tarjeta «Red de la colmena» con los interruptores, los hubs que usa el nodo y el camino elegido a cada vinculado, con su demora y las alternativas. «Probar camino» incluye la malla.
- **API:** `GET /api/colmena/malla` y `POST /api/colmena/malla/sondear`. Lanzador: `colmena malla | medir | hub-propio encender|apagar | modo malla|central|aislado`.
- **Servidor web:** `max_threads auto` en el Caddyfile, para que las esperas largas no dejen sin hilos a la aplicación.
- **Cambios de comportamiento:**
  - `via = hub` en un vínculo ahora significa «solo por terceros» (ni directo ni por su hub propio). Los vínculos que lo tenían vuelven a `auto` (migración `007_malla.sql`).
  - Fuera del modo `malla`, `POST /api/colmena/sobre` responde 503.
  - Un envío sin camino por un hub que contesta 4xx (por ejemplo, destinatario no registrado) ahora prueba los otros caminos y, si no hay, va a la cola. Antes fallaba en el acto.
- **Compatibilidad:** un nodo anterior rechaza `relevo` y `anuncio` por tipo desconocido y sigue por directo y hub central. Un hub central 1.0.0 o 1.1.0 sigue sirviendo.
- **Pruebas:** `malla.sh`, nueva, con tres nodos y un hub central (52 verificaciones): hub propio y rechazo de extraños, escucha múltiple, vínculo y pedidos sin hub central, hub apagado y cola, camino más rápido, puente, aislamiento y diagnóstico. `tunel.sh` y `cola.sh` ponen los nodos en modo `central` mientras duran.
- **Documentación:** `docs/COLMENA.md` §2, §5 y §5.2 (nueva), `docs/TUTORIAL_COLMENA.md` Parte 7 (nueva) y `hub/LEEME.md`.
- **Límites conocidos:** un nodo detrás de un router solo es hub de quienes llegan a él (los demás se encuentran en el hub central), y no hay cadenas de más de un puente.

### 22-11-2026 — Túnel de la colmena por el hub: espera larga, hora corregida y «Probar camino»

Para que un nodo se conecte desde cualquier red sin tocar el cortafuegos ni el router (como AnyDesk en modo relevo): cada nodo solo hace salidas HTTPS al hub.

- **Hub 1.1.0** (`hub/index.php`):
  - **Espera larga:** `GET ?r=buzon&espera=N` deja el pedido abierto hasta N s (tope `espera_max`, 25 por defecto) y responde apenas llega un sobre.
  - **Hora:** toda respuesta trae la hora del hub (cabecera `X-Colmena-Hora` y campo `hora`); `estado` anuncia `espera_max`.
  - **Compatibilidad:** los nodos anteriores siguen funcionando igual. Para actualizar un hub instalado se reemplaza solo `index.php`.
- **Núcleo (colmena):**
  - **Escucha:** entre un tick y el siguiente, el worker (Linux y Windows) llama a `/_iproy/escuchar.php`, que mantiene el pedido abierto con el hub y procesa los sobres al llegar. Un pedido entre nodos de redes distintas pasa de 15-45 s a 1-2 s. Con un hub 1.0.0 vuelve solo a mirar cada 15 s.
  - **Hora corregida:** el nodo mide su diferencia con la hora del hub y firma pedidos y sobres con la hora corregida (tope ±15 min). Un rechazo por hora se reintenta una vez. Una PC con el reloj corrido unos minutos ya no queda afuera.
  - **«Probar camino»** (`GET /api/colmena/diagnostico[?vinculos=1]` y botón en Colmena): hub, proxy, nombre, certificado (avisa si parece un proxy que inspecciona HTTPS), versión del hub, hora, registro, escucha y un pedido de prueba a cada vínculo, con la vía y el tiempo. Cada falla trae su remedio.
  - **Colmena (pantalla):** «Configurar el hub de relevo» (dirección, código de registro y registro en un paso). Antes era solo por comando.
- **Pruebas:** `tunel.sh`, nueva, con dos nodos y un hub propio: espera larga, hub sin espera, reloj corrido y diagnóstico.
- **Documentación:** `docs/COLMENA.md` §5 y §6, `docs/TUTORIAL_COLMENA.md` y `hub/LEEME.md` (cómo actualizar un hub).

### 22-11-2026 — Ola 4: Paneles IoT por áreas y fuentes de datos externas

- **`fuentes` 1.0.0 (módulo nuevo): «Fuentes de datos»**, en Configuración.
  - Lee en solo lectura lo que ya junta otro sistema por HTTP/JSON (objeto, lista, filas, series, columnas y eventos) y lo publica en `telemetria/medicion`, sin repetir lecturas.
  - Los eventos y alarmas de los equipos van a `equipos/evento`, sin repetir, y se vuelven a avisar al terminar.
  - El historial se trae a pedido por tramos y variables, en `telemetria/historial`.
  - Sirve para probar sin publicar, ocultar claves de la dirección y exportar o importar paquetes `iproy-fuentes/1`.
- **`telemetria` 1.1.0:** historial en lote (`POST m/telemetria/historial` y el tema `telemetria/historial` → `telemetria/serie`), sin alarmas ni PIM.
  - Descarta solo los momentos que ya están: un historial en varios lotes o con huecos se completa.
  - `telemetria/valor` lleva la etiqueta legible de la señal.
- **`paneles` 2.0.0 «Paneles IoT»:**
  - **portada por áreas** con tarjetas que filtran;
  - **reglas de acceso por área** (rol, perfil, organización, usuario);
  - **cinco tipos de panel:**
    - tarjetas;
    - energía: semáforo de referencias, 13 curvas, períodos de 1 h a 30 días o entre fechas, leyenda al pasar el cursor, marca del momento del mínimo y del máximo, informe para imprimir o en Word;
    - equipos: grilla por sector, detalle que no agranda las otras tarjetas, tendencias, variables y análisis de fallas;
    - sinóptico: plantilla de ósmosis con valores en vivo;
    - eventos: indicadores, filtros, por día, ranking y CSV.
  - **Datos:** historial en dos niveles (detalle 35 días + por hora 400 días), eventos de equipos y paquete `iproy-paneles/1`.
- **`widgets` 1.0.1:** `perfil_de()` para que otros módulos consulten el perfil de un usuario.
- **Núcleo:**
  - `POST docx` y `\Nucleo\docx()`: el armado de Word pasó de reportes al núcleo.
  - `nombre_archivo()`: nombres de descarga sin tildes. Arregla también las certificaciones de empresas con tildes, que en algunos navegadores bajaban como «download».
  - **Bus:**
    - si el código de un módulo no carga, sus eventos esperan, sin descartarse, y los demás módulos siguen recibiendo los suyos;
    - un error del código no cuenta como «evento fallido».
- **Pruebas:**
  - `iot.sh`: 56, nueva, con un servidor JSON simulado. Cubre fuentes, lecturas sin repetir, eventos, historial, áreas y permisos por perfil y organización, los cuatro tipos nuevos, curvas, eventos, informe, paquetes y tareas.
  - `paneles.sh`: 38, sin cambios funcionales.
- **Documentación:** `docs/PANELES_IOT.md`.

### 22-11-2026 — Ola P de puesta en marcha: pedir trabajos a otro nodo, buscar por código y .docx

- **P2 · `pedidos_red` 1.0.0 (módulo nuevo): «Pedidos a otros nodos»**, en Red de instituciones.
  - Se elige el nodo vinculado y se busca allá el equipo o la instalación por nombre o código. Se escribe el trabajo con su prioridad y se crea la solicitud y la orden en el Servidor PIM del otro nodo, que devuelve su número.
  - Cada pedido queda registrado en este nodo (`red_pedido`), con su estado, el motivo y la empresa responsable. Una tarea los consulta cada 10 minutos mientras están abiertos; también al tocarlos.
  - Si el otro nodo no le dio a este el rol SO, el pedido queda «rechazado» y la pantalla explica qué pedir. Si no hay camino, espera en la cola de salida o en el hub y sale solo.
  - Capacidades: `pedidos_red.ver` (GM, SO, CI) y `pedidos_red.pedir` (GM, SO).
  - En B, el texto del pedido dice de qué nodo viene y quién lo pidió (el solicitante PIM es el nodo).
- **Colmena (núcleo, pantalla):** en cada vínculo activo, «Pedirle un trabajo» y «Rol que este nodo le da» (CI consulta / SO solicitante). Antes el rol se cambiaba solo por comando.
- **P4 · Buscar una orden por su código a la vista.** En el buscador del inicio y en Auditoría Mant y Auditor Bio sirven:
  - el número corto (`368`);
  - el código a la vista (`368-APA-HEJa-GAS-001-TAN-1`);
  - el número PIM entero (`4V3-000368`), que busca exacto.

  Va a esa orden sin importar el período. Ruta `GET m/reportes/codigo`.
- **P4 · Word nativo (.docx).** La certificación mensual y el reporte de la empresa se descargan como .docx (Office Open XML, sin bibliotecas: `reportes/docx.php`, ruta `POST m/reportes/docx`). Reemplaza al .doc en HTML.
- **Pruebas:**
  - `pedidos_red.sh`: 20, nueva, con dos nodos. Cubre búsqueda, permisos, rechazo con CI, orden con SO, seguimiento del estado y B apagado.
  - `reportes.sh`: 115, con 8 nuevas de código y .docx.
  - colmena 20 y cola 33 con el hub local.
- **Documentación:** `TUTORIAL_COLMENA.md`, paso 2.8 y roles, desde la app.

### 22-11-2026 — Preventivos 2.1.0: diarias con 7 casillas y marcado en bloque

Decisiones del 22/11: las tareas diarias se registran día por día dentro de la orden semanal; las tareas sin frecuencia se marcan en la grilla.

- **Diarias con 7 casillas** (`005_dias.sql`). En la solapa Semana, cada tarea diaria muestra lunes a domingo con la fecha; se marca cada día en que se hizo.
  - Con todos los días del año marcados pasa sola a «hecha» (fecha real = el último día). Si falta alguno, queda pendiente o va «no se hizo» con el motivo.
  - No se marca un día que todavía no llegó. En la primera y la última semana del año, los días que caen en el otro año no cuentan.
  - Indicador nuevo «Diarias: N de M días hechos». «Todas hechas hoy» marca el día de hoy en las diarias. La orden avisa que hay diarias para marcar cada día.
- **Cronograma: marcar en la grilla las tareas sin frecuencia.**
  - Filtros nuevos «Frecuencia: Sin frecuencia» y «Semanas en el año: Sin marcar / Con semanas marcadas».
  - «Marcar en bloque las N tareas de esta vista» (Gestor): frecuencia (o quitarla) y semana de inicio. Con frecuencia, marca el año; sin frecuencia, marca solo esa semana, como pintar la celda. Las marcas a mano se respetan. Ruta `POST m/preventivos/pmp/tareas/lote`.
- **Pruebas:** `plan_semanal.sh` 57 (13 nuevas), unitarias 94. `pim.sh`: la búsqueda por categoría de alcance ya no depende de la cantidad de equipos del nodo; fallaba con más de 200 de alcance B.

### 22-11-2026 — Plan preventivo por semanas

Pedido del 22/11: planificar los preventivos como el cronograma anual de la planilla (semanas de 7 días), una orden por equipo y semana, y un panel de relevamiento para cargar el plan de un establecimiento nuevo.

- **`preventivos` 2.0.0: plan por semanas** (`004_plan_semanal.sql`, `lib_pmp.php`).
  - Semana 1 = la que contiene el 1 de enero, de lunes a domingo; un año tiene 53 o 54 semanas (la primera y la última pueden ser parciales).
  - Cada **tarea** es una fila como en la planilla: sistema, ubicación, equipo (activo PIM), instalación, sector, categoría, parte, preventivo, predictivo, personal, hoja de vida, inspección, insumos, frecuencia y empresa responsable.
  - **Frecuencias en semanas fijas:** diaria y semanal todas las semanas (la diaria se hace cada día dentro de su orden semanal), quincenal cada 2, mensual cada 4, bimestral cada 8, trimestral cada 13, semestral cada 26, anual cada 52 y cada 2 años cada 104. Se reconocen escritas como en la planilla (Mensual, Trimestral…).
  - **Cronograma anual por año:** «Marcar desde la semana N» según la frecuencia; ajuste a mano semana por semana (las marcas manuales se respetan al volver a marcar); «Copiar al año siguiente» con la misma semana de inicio.
  - **Órdenes:** al empezar cada semana se genera **una orden por equipo** con las tareas marcadas («Realizar entre el lunes … y el domingo …»), asignada a la empresa responsable o en revisión si no tiene. Cada tarea se cierra por separado: hecha (con la **fecha real**, que no puede ser futura), no se hizo (motivo obligatorio) o no aplica. Al cerrarse la orden en PIM se cierra la semana. Indicador «hechas dentro de la semana».
  - **Relevamiento en recorrida** (capacidad nueva `preventivos.relevar`, Gestor y Ejecutor): el operario carga cada unidad con sus tareas a partir de **10 plantillas por tipo de equipo** (motor, bomba, UTA, equipo de frío, tablero, ascensor, plataforma, tanque a presión, caldera, grupo electrógeno), todas editables. **Funciona sin señal:** lo cargado queda en el dispositivo y se envía al volver la conexión. Un Gestor lo **revisa**: aprueba (crea el equipo en PIM si no existe y pasa las tareas al plan, con su semana de inicio) o lo devuelve con una nota.
  - Pantalla nueva con cuatro solapas: **Semana** (KPI, una tarjeta por equipo, «Todas hechas hoy»), **Cronograma anual** (la grilla de 53/54 semanas por meses, con colores por frecuencia, filtros, semana actual marcada e impresión A3 apaisada), **Relevamiento** y **Planes especiales**.
  - Los **planes por visita** de 1.3.0 siguen, ahora como «Planes especiales» (obras, mejorativos o por uso).
- **`migracion` 1.2.0:** destino «Plan preventivo por semanas: tareas», una fila por tarea con las columnas de la planilla; con «Próxima ejecución» marca el cronograma de ese año desde esa semana. Repetible sin duplicar.
- **Pruebas:** `plan_semanal.sh` 44 (nueva), unitarias 92 (6 de semanas: 2024 con 53, 2012 con 54, límites de la semana 1 y 53, mensual y trimestral), `migracion.sh` 115. Pantallas probadas a 1366 y 390 px, sin desborde, incluido el relevamiento sin conexión.

### 22-11-2026 — Revisión de las olas 0 a 3

- **`preventivos` 1.3.0: registro por tarea.** Cada orden preventiva (una por visita) lleva su lista de tareas con resultado: hecha, no se hizo (con motivo obligatorio) o no aplica, con quién y cuándo (`003_registro_tareas.sql`). Lo registra el Gestor o el Ejecutor de la empresa asignada (se consulta al Servidor PIM). En el plan: «Órdenes de este plan» con «2 de 3 hechas · 1 sin hacer». Es el equivalente del Historial del sistema anterior sin multiplicar las órdenes.
- **Fechas por mes calendario.** Mensual, bimestral, trimestral, cuatrimestral, semestral, anual y bienal caen el mismo día del mes (o el último, si no existe) en la reprogramación, la proyección y la primera fecha. Antes sumaban 30, 60… días y se corrían.
- **Habilitar en bloque** con «los vencidos pasan a la próxima fecha de su ciclo» (marcado por defecto): no se genera una orden por cada plan atrasado de golpe.
- **`widgets`:** el id de un perfil admite guion medio (`empresas-bio`, como en el sistema anterior); las mayúsculas pasan a minúsculas.
- **`pim`:** «Nueva solicitud» muestra el número de la orden generada, no su referencia interna (`Task/…`).
- **`stock`:** si el pedido se envía por correo sin «Dirección de la app» configurada, la pantalla avisa que salió sin QR ni enlace.
- **Pruebas:** `preventivos.sh` (14, nueva), `widgets.sh` 85, unitarias 86. `colmena.sh` acepta `HUB_CODIGO` para hubs con código de registro: 20/20 y `cola.sh` 33/33 entre dos nodos y un hub.

### 22-11-2026 — Ola 3 cerrada: planes preventivos traídos de la hoja PMP

- **`migracion` 1.1.0: destino «Planes preventivos».** Desde una hoja de cálculo (CSV o publicada) o MySQL, con la misma unión de columnas y simulación: código del equipo o instalación, tarea, componente, subcomponente, frecuencia (Quincenal, Mensual… o días, según la lista «Frecuencias de mantenimiento») y próxima ejecución (DD/MM/AAAA o AAAA-MM-DD). Reconoce las columnas de la hoja PMP y saltea sus renglones separadores.
- **`preventivos` 1.2.0.**
  - Importación (la llama Migración; también `POST m/preventivos/importar`): un plan por equipo o instalación y frecuencia, con sus tareas como lista de verificación (una orden por visita), o uno por tarea. La próxima fecha del plan es la más cercana del grupo.
  - Los planes entran **en pausa** (un plan vencido generaría su orden en la siguiente pasada); las tareas sin frecuencia quedan siempre en pausa. Repetible sin duplicar (`002_importacion.sql`, clave de origen); si en el origen cambia la frecuencia o se quita una tarea, el plan anterior queda en pausa, no se borra.
  - Pantalla: columna Estado (Habilitado / En pausa), frecuencia, próxima fecha y estado editables en el plan, y «Habilitar los planes en pausa que tienen frecuencia». No se habilita un plan sin frecuencia.
- Pruebas: `migracion.sh` 115 (15 nuevas de planes), unitarias 83.

### 21-11-2026 — Ola 3c: QR del pedido, pronósticos por semana y por tipo de tarea, TimesFM en el instalador

- **`stock` 1.3.1: código QR del pedido de insumos.** Cada pedido tiene un enlace directo (`#panol_insumos?pedido=PED-…`) y su QR: en la ficha, en la hoja impresa (arriba a la derecha) y embebido en el cuerpo del correo (imagen `cid:`, se ve sin abrir adjuntos), junto con el enlace. Al escanearlo se abre la ficha del pedido después de ingresar.
  - Núcleo: generador QR propio (Model 2, versiones 1 a 10, corrección M, hasta 213 bytes; sin bibliotecas externas) en SVG y PNG; ruta `GET qr?texto=…` con sesión. Correo con imágenes en línea (`multipart/related`).
  - El enlace usa la «Dirección de la app» de la configuración de correo; sin ella, la dirección desde la que se abre.
- **`reportes` 1.4.0: pronósticos que faltaban** (como el script de TimesFM del sistema anterior).
  - Tipo de tarea: la clase de sistema PIM de la orden (`CodeSystem pim-sistema`), guardada en la copia (`005_tarea.sql`; las órdenes anteriores se completan una sola vez). Filtro «Tipo de tarea (PIM)» en Tendencias y ruta `GET m/reportes/tareas`.
  - Pronóstico **semanal** (semanas de lunes a domingo, 12 semanas, Holt-Winters con estación de 52 y control con las últimas 8), además del mensual: botones Mensual / Semanal en «Series y pronóstico». No se usan como base la semana en curso ni la primera si el período empieza a mitad de semana.
  - Series para Google TimesFM: total, servicio, empresa, tipo de tarea y servicio × tarea por mes; total, servicio y tipo de tarea por semana.
- **TimesFM en el instalador (Linux y Windows), opcional.** Paso «Pronósticos con Google TimesFM»: deja todo dentro de `<nodo>/python` sin tocar otros Python de la PC (Windows: Python 3.12.10 embebible verificado con SHA-256; Linux: entorno virtual), PyTorch CPU y TimesFM fijados a una versión (cada paso con hasta 3 intentos ante cortes de red), baja el modelo una vez en partes de 300 MB verificadas con SHA-256, reanudables, y unidas al final y programa la corrida semanal (lunes 03:17: Programador de tareas, cron o temporizador de systemd del usuario). El desinstalador quita esa programación. Herramientas en `herramientas/pronosticos/`.
- Pruebas: `panol.sh` 92, `reportes.sh` 107, unitarias 81 (QR).

### 21-11-2026 — Ola 3b: prioridad PIM del pedido, correo con planilla, altas desde el ingreso, traspaso de contrato y TimesFM

- **`stock` 1.3.0.** La prioridad del pedido es la `request-priority` de FHIR sugerida por PIM (prioridad de la orden; si no, IRU del activo; si no, categoría NFPA 99) y elegir otra exige motivo. El número suma la banda PIM: `PED-AAAAMMDD-NNN-L` (ConceptMap `pim-banda-a-prioridad` al revés); `003_prioridad_pedido.sql` convierte los anteriores. Planilla .xlsx propia y envío por correo con adjunto.
- Ingreso de Stock: insumo nuevo y proveedor nuevo sin salir del ingreso.
- **`reportes` 1.3.0.** Traspaso de contrato (heredadas y propias, entrante y saliente) con su configuración; series para Google TimesFM, corridas guardadas (`004_pronostico.sql`) y su vista junto a Holt-Winters.
- Corregido: el anillo de los gráficos de torta fallaba por una línea cortada por un comentario.

### 21-11-2026 — Ola 3: pañol y reportes

- **`stock` 1.2.0.** Reposición (punto de pedido, stock de seguridad, sugerido y ABC como en el sistema anterior), tendencias con Holt, pedidos de insumos con recepción parcial e ingreso al stock, conteo cíclico con ajuste opcional, seis reportes y gráficos. Accesos del grupo Pañol.
- **`reportes` 1.2.0.** Tendencias y Pronóstico (7 indicadores, 5 vistas, Holt-Winters con control) y Reporte General.
- `requerimientos` 1.3.0: Ver Sábanas del pañol y selección de varios meses (Certificación).

### 21-11-2026 — Ola 2 terminada: insumos, certificación, áreas de auditoría y planillas

- **Nota de pañol.** El stock (1.1.0) publica en cada egreso la orden, la fecha, la observación y la descripción de cada renglón. `reportes` (1.1.0) se suscribe y guarda su propia copia (`rep_insumo`, migración `003_insumos.sql`); ruta `GET m/reportes/insumos?ordenes=…`. Las sábanas muestran la columna «Pañol» con lo entregado a cada orden, y la orden impresa lo incluye. Los egresos anteriores a esta versión no aparecen (no se lee la tabla del stock).
- **Reporte de la empresa.**
  - Ranking de insumos entregados a sus órdenes en el período (cantidad, órdenes, costo estimado) con CSV.
  - «Imprimir el reporte».
  - «Certificación mensual»: se elige el mes y arma el documento (resumen, preventivo y correctivo, pendientes, insumos y operarios, con lugar para firmas), para imprimir o guardar en PDF, o descargar para Word (.doc).
- **`auditoria` 1.2.0: áreas de auditoría.** «Empresas del panel» (solo el Gestor): qué empresas o áreas ofrece cada panel; una empresa puede estar en los dos; sin ninguna marcada, todas. Migración `001_areas.sql`.
- **`requerimientos` 1.2.0: operarios.** Sumar un operario a una orden exige que la orden sea de su empresa (se consulta al Servidor PIM). El número de la orden sale de PIM, no del pedido.
- **`widgets`: imprimir la planilla.** El botón Imprimir de una sábana creada saca la planilla de un mes (o del período de la pantalla) con los filtros puestos, todos sus registros y lugar para firmas. Con «Control Diario» equivale a «Imprimir Planillas» de calderas.
- **Corrección en `reportes`.** Cambiar de vista o de empresa mientras cargaba la anterior daba un error en la consola; ahora la respuesta tardía se descarta.
- Pruebas: `auditoria.sh` 37, `requerimientos.sh` 56, `reportes.sh` 65.

### 21-11-2026 — Ola 2, segunda parte: estética de las sábanas, Auditor Bio, Ver Sábanas Bio y operarios

- **Estética de los paneles tipo sábana** (clase `.sab`, usada por «Auditoría Mant», «Auditor Bio», «Ver Sábanas» y «Ver Sábanas Bio»): columnas centradas en pantallas anchas, fila resaltada al pasar el puntero, tarjetas de estado más chicas, sombras suaves y realce en botones y campos. El número de orden se muestra sin «#». En celular las tablas siguen como tarjetas.
- **`auditoria` 1.1.0.** Dos accesos sobre el mismo panel: «Auditoría Mant» (órdenes de instalaciones) y «Auditor Bio» (órdenes de equipamiento médico). Botón «Imprimir la orden» en la ventana de la orden.
- **`reportes`:** la copia de consulta guarda la clase de cada orden (`equipo` o `instalacion`, según el perfil PIM del activo); parámetro `clase` en la consulta de auditoría. Migración `002_clase.sql`.
- **`requerimientos` 1.1.0.**
  - «Ver Sábanas Bio»: la sábana con solo las órdenes de equipamiento médico. Botón «Imprimir la orden» en cada fila de las dos sábanas.
  - **Operarios.** Lista de operarios por empresa o área y su trabajo en cada orden: sumar, iniciar, terminar, duración. Columna «Operarios» en la sábana y reporte «Operarios» (por operario: órdenes, tiempo total, promedio; registro de trabajos; descarga). Un Ejecutor solo ve y carga los de su empresa. Migración `002_operarios.sql`.
- Pruebas: `auditoria.sh` 31, `requerimientos.sh` 52.

### 21-11-2026 — Auditoría Mant (Ola 2, primera parte)

- **Módulo `auditoria` 1.0.0.** Pestaña «Auditoría Mant»: todas las órdenes en un panel.
  - Seis tarjetas con clic (Finalizado, En Proceso, Atención, Revisión, Pendiente, Denegado) y Total; cada grupo reúne estados PIM y la fila muestra los dos.
  - Filtros: período rápido o entre fechas, servicio, empresa o área y búsqueda. Carga de a 50 con «Cargar más». Descarga con los filtros puestos.
  - Ventana «Orden #»: estado (con motivo cuando PIM lo exige), empresa o área, tipo de mantenimiento, modo de falla, prioridad, nota y valoración.
  - **Prioridad e IRU.** La ventana muestra la prioridad que sugiere el IRU del activo con su fundamento (F + R + M + A y banda) y la decisión registrada. Elegir otra prioridad pide el motivo; el Servidor PIM lo exige y lo deja asentado.
  - No guarda datos propios: lee la copia de consulta de `reportes` y escribe por `Task/$transicion` con `If-Match`.
- **`reportes`: consulta de auditoría.** Parámetros nuevos `lugar` (servicio), `estados` (varios a la vez), `limite` + `desde_fila` (páginas) y `con_lugares`. Sin ellos responde como antes.
- **Núcleo:** las lecturas de la API PIM desde la pantalla ya no usan la copia guardada del navegador (después de decidir sobre una orden se veía la versión anterior).
- Pruebas: `auditoria.sh` 27.

### 21-11-2026 — NFPA 99 en instalaciones

En PIM la categoría de riesgo NFPA 99 es propia de las instalaciones (perfil `pim-sistema`; de ella sale la R del IRU). Hasta acá las pantallas la mostraban solo en equipos médicos, y un pedido de instalación no podía nombrar la instalación.

- **«Req. Mantenimiento» y «Req. Ing. Clinica» (instalación):** campo nuevo «Instalación (opcional)». Al elegir la categoría lista las instalaciones cargadas de esa clase, con su código y su categoría NFPA 99. Si se elige una, el pedido va sobre ese `Device` y conserva el servicio que pide y la clase de sistema.
- **«Ver Sábanas»:** la etiqueta NFPA 99 aparece en toda orden unida a una instalación o a un equipo; una orden unida a una instalación sigue figurando como «Instalación». El código a la vista usa el código de la instalación.
- Equivalencia de fábrica D → 4 en la lista «riesgo».
- Pruebas: `requerimientos.sh` 36.

### 21-11-2026 — Sigla del establecimiento y categoría de riesgo

Cada establecimiento codifica sus equipos e instalaciones a su modo; el nodo no trae códigos de ninguno.

- **Sigla del establecimiento.** Ajuste nuevo (Configuración › Migración › Ajustes, `sigla` en `data/pim/config.json`): 4 caracteres, letras o números, empieza con letra. Se antepone al código de todo recurso físico (`HEJa-GAS-001-TAN-1`) al darlo de alta por la API, al traerlo desde otro sistema y en el puente del sistema anterior. Sin sigla, el código entra tal cual.
  - La búsqueda `Device?identifier=` encuentra el recurso con o sin la sigla.
  - El identificador interno sale del código propio: cambiar la sigla y volver a traer los datos no duplica recursos.
  - Una orden traída de otro sistema que nombra el equipo sin sigla queda unida al equipo.
- **Categoría de riesgo al traer equipos y sistemas.** Campo «Criticidad o categoría de riesgo»: acepta 1 a 4 (NFPA 99) o un valor propio que se traduce con la lista de equivalencias nueva «riesgo» (de fábrica A, B, C → 1, 2, 3, a revisar).
- **Vigía: cargas grandes.** Si una carga dejaba más versiones con la misma marca de tiempo que las que entran en una página, el vigía no avanzaba y repetía esa página en cada pasada, sin publicar los cambios siguientes. Ahora lee todas las páginas de la tanda.
- Pruebas: `migracion.sh` 99; `pim.sh` ya no depende de que el nodo tenga menos de 200 empresas ni de que no haya sigla.

### 21-11-2026 — Migración probada con datos reales

Resultado de cargar un sistema completo (24.698 órdenes) en un nodo de prueba.

- **Archivos grandes.** El archivo elegido en Configuración › Migración se envía al nodo en partes (`POST m/migracion/subir`) cuando supera lo que entra en un pedido; lo subido dura un día.
- **Correctivo con prioridad A → correctivo-inmediato.** Equivalencia nueva con variante `|prioridad_a`, editable como las demás.
- **Puente del sistema anterior (`importar.php`).** «Atencion» pasa a en-espera (antes en-ejecucion); «En Proceso» con fecha de inicio pasa a en-ejecucion; «Revision» con fecha de fin pasa a finalizada; se reconoce el predictivo; los estados que exigen motivo lo llevan.
- **Lo migrado no dispara avisos.** El vigía publica los cambios traídos de otro sistema marcados (`legado`), y el módulo de avisos los ignora. Antes, una carga masiva encolaba un aviso por cada orden vieja.
- Pruebas: `migracion.sh` 79, unitarias 59; `requerimientos.sh` resiste códigos de servicio con espacios.

### 21-11-2026 — Catálogo de widgets y creador de widgets

Primer paso para que el nodo se vea y se use como el sistema de origen: los mismos accesos, con el mismo nombre y en el mismo orden, sobre el modelo PIM.

- **widgets 1.0.0 · «Widgets y tablero»** (módulo nuevo, «en pruebas», AGPL-3.0-or-later): el administrador arma los **grupos** del menú (nombre, color, orden), decide de cada acceso el **nombre visible, grupo, ícono, orden y si se ve**, y define **perfiles** (qué widgets ve cada persona; el rol sigue diciendo qué puede hacer). Mientras no haya grupos propios, el menú queda como siempre.
- **Crear widgets desde la pantalla, sin escribir código**: *formulario* (carga con campos de texto, número, fecha, hora, lista y casilla, con número correlativo), *sábana* (lista con tarjetas por estado, filtros, búsqueda, período, edición en la fila, descarga CSV), *indicadores* (cuentas, sumas y promedios sobre un formulario o una sábana) y *página* (texto o dirección, embebida o no). La definición se valida entera en el servidor, descarta lo que no conoce y suma una versión con cada cambio. Los registros viven en una tabla propia del módulo.
- **Exportar e importar** definiciones (sin registros) para llevar un widget a otro nodo.
- **Seguridad**: lo que una persona no ve por su perfil no viaja al navegador y el servidor tampoco se lo deja usar; quien carga un formulario ve solo lo suyo; dos personas no se pisan una fila (número de revisión); una página solo admite `https://` o una ruta del propio nodo; todo cambio queda en el diario firmado.
- **Núcleo**: cinco puntos de extensión para módulos en la aplicación (`Nucleo.catalogo`, `alIniciar`, `configuracion`, `quitarPestana`, y las listas `iconos` y `accesos`). «Configuración» gana la solapa **Widgets**. Sin cambios en la base del núcleo.
- **Pruebas**: `pruebas/widgets.sh` (82 comprobaciones) y regresión completa de un nodo en verde.
- **requerimientos 1.0.0 · «Req. Mantenimiento», «Req. Ing. Clinica» y «Ver Sábanas»** (módulo nuevo, «en pruebas», AGPL-3.0-or-later): los pedidos como los hace un servicio, guardados por la API PIM. *Req. Mantenimiento*: servicio, categoría (la clase de sistema PIM del Anexo B, agrupada por familia) y detalle → `ServiceRequest` sobre el `Location`. *Req. Ing. Clinica*: equipo médico (buscador por código, marca, modelo y serie, con filtro por servicio) o instalación, uno u otro → `ServiceRequest` sobre el `Device` o el `Location`. *Ver Sábanas*: órdenes por empresa, tarjetas por estado con clic (los 11 estados PIM agrupados en Finalizado, En Proceso, Atención, Revisión, Pendiente y Denegado), mes, tipo de tarea, búsqueda, páginas de 50, descarga y cambios en la misma fila (estado, tipo de tarea y anotación, con `$transicion` e `If-Match`).
- **Código a la vista de la orden**: número – código del servicio – tercer tramo. En una instalación el tercer tramo es la clase de sistema PIM; en un equipo médico, el código del equipo. No cambia el número oficial ni el modelo PIM: se calcula al mostrar.
- **Servicio de cada usuario** y función **transversal** (elige el servicio en cada pedido; el sistema recuerda el último). Solapa «Requerimientos» en Configuración.
- **Pruebas**: `pruebas/requerimientos.sh` (32 comprobaciones).
- **migracion 1.0.0 · Configuración › «Migración»** (módulo nuevo, «en pruebas», AGPL-3.0-or-later): trae datos de otro sistema desde una **hoja de cálculo** (archivo CSV o texto pegado; detecta separador y codificación), una **hoja publicada en la web** (solo `https` y sitios de una lista) o una **base MySQL o MariaDB** (tabla o consulta `SELECT`, en transacción de solo lectura; la clave no se guarda). Destinos: servicios, empresas, equipos médicos, sistemas de instalación y órdenes. Pasos: leer el origen, unir columnas (propone el mapa), revisar las **equivalencias** con el conteo real de cada valor, **simular** y cargar. La carga se repite sin duplicar y cada registro queda marcado como traído de otro sistema.
- **Tablas de equivalencias** editables (estado → Tabla 9-2, tipo de mantenimiento → EN 13306, prioridad, tipo de tarea → clase de sistema del Anexo B), con reglas condicionales por fecha («en revisión» con fecha de fin pasa a finalizada). Lo que no tiene equivalente conserva el texto original.
- **Servidor PIM**: `pim/lib/migrar.php` y `pim/admin/migrar.php` (solo desde el propio nodo, con su token): carga por lotes con la misma función del puente de lectura (huella, versión y Provenance), simulación sin escritura y altas «solo si falta». Sin cambios en los archivos existentes del servidor.
- **Detalle escrito de los pedidos**: siempre se guarda; una casilla en Configuración › Migración › Ajustes decide si el Servidor PIM lo muestra (`exponer_detalle`).
- **Riesgo NFPA 99 a la vista**: la categoría del equipo aparece al elegirlo en «Req. Ing. Clinica» y en cada fila de «Ver Sábanas».
- **Pruebas**: `pruebas/migracion.sh` (70 comprobaciones, con una base de origen temporal).
- **Falta**: que un widget creado pueda leer y escribir recursos PIM (hoy usa su tabla propia), que las definiciones viajen solas por la colmena (hoy se exportan e importan a mano) y el formato de impresión.

### 20-11-2026 — Avisos, reportes, paneles de instalaciones, permisos por persona y aplicación instalable

Versión de trabajo. Se arma y se prueba aparte de la publicada (1.6.2) e incluye la 1.6.3 (cola de salida de la colmena, sin publicar todavía); pasa a ser la versión nueva de iProy cuando termine la verificación.

**Módulos nuevos** (los tres «en pruebas», AGPL-3.0-or-later, sin leer tablas de otros módulos: todo llega por el bus o por la API PIM)

- **notificaciones 1.0.0 · «Avisos»**: reglas (qué evento, con qué condición, por qué canal, a quién), cola con reintentos y silencio de repetidos. Canales: correo HTML por el SMTP del nodo, Telegram (bot propio) y webhook JSON firmado con HMAC-SHA256 (sirve para WhatsApp u otro sistema por un servicio intermedio). Avisa de alarmas, solicitudes nuevas, órdenes nuevas y cambios de estado. Los secretos van en un archivo aparte de `data/` y no salen por ninguna API.
- **reportes 1.0.0 · «Reportes»**: *Auditoría* (todas las órdenes, filtros, período, indicadores que llevan al detalle, calificación con estrellas, impresión de la orden y CSV) y *Reporte de la empresa* (recibidas, terminadas, pendientes, preventivo contra correctivo, movimientos día por día y CSV del período completo con el detalle del requerimiento). El Ejecutor solo ve su organización.
- **paneles 1.0.0 · «Paneles»**: tableros por área o instalación con tarjetas (valor, estado normal / fuera de rango / en alarma / sin datos, curva de 24 h), historial de 6 h a 1 año con lectura al pasar el cursor y CSV. Historial propio resumido (400 días por defecto). Cada panel se muestra por rol, por usuario o a todos.

**Núcleo y aplicación**

- **Permisos propios de un usuario** (migración 006): se le pueden sumar o quitar funciones respecto de su rol. El bloqueo le gana al permiso; no se conceden las reservadas al administrador ni las `pim.*` (las decide el rol en el Servidor PIM). Se editan en Usuarios.
- **Aplicación instalable**: manifiesto y service worker. Guarda solo la cáscara (página, `app.js`, íconos e interfaz de los módulos), nunca respuestas de la API ni del Servidor PIM. Aviso de «sin conexión». El navegador solo permite instalar y trabajar así en un contexto seguro (HTTPS o `localhost`).
- `enviar_correo()` acepta una versión HTML (`multipart/alternative`).
- Para los módulos: `Nucleo.kpis` con tarjetas que son botones (`k`) y `Nucleo.alClicKpi`, `Nucleo.csv` (UTF-8 con BOM, «;», protegido contra fórmulas), íconos nuevos.
- Solicitud: el equipo se busca también por marca, modelo y número de serie.
- **Vigía PIM (corrección):** las marcas de tiempo se comparan como instantes y no como texto. En un nodo nuevo la marca nacía en UTC y el servidor contesta en hora local: durante las primeras horas la marca no avanzaba y, pasadas 500 versiones de un recurso, los cambios nuevos no llegaban al bus y los viejos se volvían a publicar (órdenes sin reflejar en Reportes, avisos repetidos). Defecto presente en la 1.6.2.
- **Migraciones del núcleo:** la 005 es la cola de salida (1.6.3) y los permisos por usuario pasan a ser la 006. El núcleo registra las migraciones por número: dos archivos con el mismo número harían que una no se aplique nunca.

**Pruebas** (un nodo, FrankenPHP 1.13.3 y MariaDB 10.11): `notificaciones.sh` 58, `reportes.sh` 58, `paneles.sh` 38, `nucleo17.sh` 33, `colmena.sh` 20 y `cola.sh` 33 entre dos nodos con hub local, más las anteriores sin cambios de resultado. `pim.sh` arma la hora en -03:00 sea cual sea la zona del equipo que corre la prueba.

**Pendiente antes de publicar**: instalador (`instalador/` sigue apuntando a la 1.6.2, la última etiqueta publicada), probar Telegram y correo contra servicios reales, y los puntos de paridad que quedan abiertos (formato del código de orden, importador completo desde un sistema anterior).

## 1.6.3 · 17-11-2026 — Colmena: cola de salida

Resultado de la prueba de colmena con tres nodos del 17-11-2026. El plan de prueba decía que, con el hub caído, los pedidos «quedan en cola y se entregan al volver»; el software devolvía un error y no guardaba nada. Se corrige el software.

- **Cola de salida.** Cuando no hay camino hacia el otro nodo (está apagado, el hub no responde, este equipo quedó sin red), los pedidos de trabajo (`rpc`), los eventos y los avisos de aceptación y de revocación de un vínculo se guardan y el worker los reintenta solo. Salen en el orden en que se pidieron y llegan una sola vez: cada reintento usa el mismo identificador de sobre y el otro nodo descarta la repetición. Funciona igual por el hub y por la intranet sin hub. Detalle en `docs/COLMENA.md` §5.1.
- **Estados nuevos de un RPC:** `en-cola` (espera el camino) y `rechazado` (el vínculo se revocó, el otro nodo no lo aceptó o el administrador lo descartó). El plazo de 15 minutos para la respuesta se cuenta desde que el pedido salió.
- **Plazo y tope:** 72 horas en cola y hasta 200 envíos sin entregar por nodo destino. Lo que vence queda asentado en el diario.
- **Revocar sin conexión.** El aviso de revocación se encola: el otro nodo se entera cuando vuelve el camino. Lo que esperaba salir hacia un vínculo revocado se cierra sin entregarse.
- **Un nodo que revocó contesta que no.** Un RPC que llega por el hub a un nodo donde el vínculo ya no está activo recibe una respuesta 403 `vinculo-no-activo`. Antes no recibía nada y quedaba «enviado» hasta vencer.
- **Dónde se ve:** `colmena cola`, `colmena cola reintentar` y `colmena cola descartar <id>` en los dos lanzadores; tarjeta «Cola de salida» en la pestaña Colmena de la app; `GET /api/colmena/cola`.
- **Base de datos:** migración 005 del núcleo (tabla `nucleo_salida` y columna `nucleo_rpc.enviado`). Se aplica sola al iniciar el nodo.
- **Compatibilidad:** el protocolo `colmena/1` no cambia. Un nodo 1.6.3 se entiende con uno 1.6.2; el 1.6.2 simplemente no encola.
- **Tutorial nuevo:** `docs/TUTORIAL_COLMENA.md`, paso a paso para armar una colmena de hasta cuatro instalaciones en la misma red y por Internet.
- **Limitación conocida:** el rol `EJ` no se puede dar por un vínculo de colmena (el vínculo no lleva la organización del ejecutor; el servidor responde 403). Queda anotado para una versión próxima.
- **Prueba nueva:** `pruebas/cola.sh` (33 comprobaciones). El plan `docs/PRUEBA_COLMENA_3_NODOS.md` aclara el motivo obligatorio del paso 2.7 y describe el resultado esperado de las fallas provocadas.

## 1.6.2 · 09-11-2026 — Conformidad con el estándar: lo que dice la guía, lo que dice el contrato y lo que hace el servidor

Resultado de una auditoría de conformidad del Servidor PIM contra la guía 0.9.4 y el contrato OpenAPI. Se corrige el servidor donde no cumplía y se escribe en la guía y en el contrato lo que el servidor ya hacía sin estar documentado. Ningún cambio toca la base de datos.

- **El instalador gráfico de Windows instala siempre Git y PHP 8.3.** Paso nuevo «Instalar Git y PHP 8.3 (winget)»: ejecuta `winget install --id Git.Git -e --source winget` y `winget install --id PHP.PHP.8.3 -e --source winget --scope machine` en cada instalación, en vez de suponer que ya están. La instalación de iProy sigue sin depender de ellos: si winget no está o falla, el paso queda en aviso con el comando para hacerlo a mano y el resto continúa. El desinstalador no los quita (quedan anotados en «no se deshace»).
- **`$transicion` exige `If-Match`.** La guía ya decía «toda modificación exige If-Match», pero la operación lo aceptaba sin la cabecera: el Gestor y el Ejecutor podían pisarse la orden. Ahora responde **428** si falta y **412** si la versión no es la vigente, igual que `PUT`. Se adaptaron todos los que la llaman: el módulo de preventivos lee la versión de la orden que acaba de crear; los SDK de PHP y de JavaScript la leen solos si no se les pasa; la aplicación y la Consola ya la enviaban. **Quien integre por su cuenta tiene que enviar la cabecera.**
- **Un cliente sin rol ya no es Gestor.** Un registro de `data/pim/clientes/` sin `rol` (o con un rol que no existe) se trataba como GM, el rol con más permisos. Ahora opera como CI: consulta y nada más. `pim cliente alta` siempre exigió el rol, así que solo afecta a registros escritos a mano.
- **El Ejecutor ve solo lo suyo, también en las solicitudes.** Ya veía únicamente las órdenes de su organización; ahora tampoco lee, busca ni recorre el historial o la Provenance de las `ServiceRequest` de órdenes ajenas, ni lee versiones anteriores (`_history/{v}`) de órdenes que no son suyas.
- **El Gestor puede hacer las transiciones del Ejecutor: queda escrito.** El servidor siempre lo permitió (es lo que usa una institución donde una sola persona cumple los dos papeles); faltaba en la guía y en el contrato. No cambia el comportamiento.
- **El servidor acepta lo que el perfil acepta.** `ServiceRequest.note` es opcional, como en `pim-solicitud` (antes devolvía 422). Un `Device` se clasifica mirando todos sus `meta.profile`, no solo el primero.
- **FHIR al pie de la letra.** Un `Bundle` sin resultados ya no trae `"entry": []` (búsquedas, historial, artefactos de la guía y respuesta de lote). El CapabilityStatement deja de publicar la extensión `oauth-uris`, que estaba incompleta: el descubrimiento es `.well-known/smart-configuration`. `Location?type` respeta el `system` del token y admite `type:text=Local` (el texto sin modificador se sigue aceptando).
- **Historial con orden estable.** `_history` desempata por fecha, id y versión: dos cambios en el mismo milisegundo salen siempre en el mismo orden y no se repiten ni se pierden al paginar. Sigue ascendente por defecto, como necesita PIM-8, y queda declarado como desvío de FHIR R4; `_sort=-_lastUpdated` da el orden de R4 (lo más nuevo primero).
- **Una sola fuente para banda → prioridad.** El servidor ya no tiene una tabla propia: lee el ConceptMap `pim-banda-a-prioridad` de la guía, y una prueba unitaria lo compara.
- **Guía (PIM 0.9.4, sin cambio de número):** el marco técnico explica quién puede hacer cada transición, la concurrencia (428 y 412) y el cliente sin rol; dice que la orden tiene **11 estados**; suma un ejemplo de IRU con redundancia N+1 (8 + 5 + 4 + 1 = 18 → banda A → `stat`; con N+1 baja a la banda B → `asap`). Los ejemplos `ejemplo-riesgo` y `ejemplo-orden` contradecían esa regla y se corrigieron. El comentario de la columna `iru_f` decía «2–10»: es 1–10, como en la guía y en el código.
- **Contrato OpenAPI:** `If-Match` obligatorio en `$transicion`, con las respuestas 428 y 412; parámetros de `_history`. El archivo y su número siguen siendo 1.3.1: renumerarlo queda pendiente (lista en `x-pim-cambios.ajustes_sin_renumerar`).
- Pruebas: nueva `pruebas/negativas.sh` (36), que entra con tokens SMART de cada rol y comprueba lo que el servidor tiene que rechazar; contra la 1.6.1 fallan 23. `pim.sh` envía `If-Match` en sus transiciones y sigue en 70; unitarias 51 (tres nuevas: 11 estados, ConceptMap, IRU con N+1); `mem.sh` 28, `nucleo16.sh` 45, `colmena.sh` 20, `industrial.sh` 16 (sin los simuladores).

## 1.6.1 · 06-11-2026 — El instalador de Windows, probado de verdad (no simulado)

Primera instalación real de punta a punta del instalador gráfico de Windows: dentro de Wine 11 (con OviWine Soft 0.2.1 y PowerShell 7.4) y, en paralelo, el de Linux en EndeavourOS. Aparecieron cinco fallas que la prueba simulada de la 1.6.0 no podía ver; las cinco afectan también a un Windows real o a una PC con otra base de datos.

- **El instalador quedaba colgado para siempre en «Arrancar el sistema».** Leía la salida del lanzador por una tubería y esperaba a que se cerrara; MariaDB, FrankenPHP y los residentes la heredan y la mantienen abierta mientras el sistema funciona. Ahora la salida va a un archivo temporal, se muestra en vivo y se espera a que termine el proceso, no la tubería. El lanzador ya no puede quedarse esperando una respuesta por teclado (entrada vacía).
- **El nodo no podía crear su clave ES384** («No se pudo crear la clave ES384 (openssl)»: fallaban el PIM, los tokens y MLLP sobre TLS). PHP para Windows busca `openssl.cnf` en una carpeta que no existe; el lanzador ahora usa el que trae FrankenPHP (`extras\ssl\openssl.cnf`) si no hay `OPENSSL_CONF`.
- **La base de datos ya no exige el puerto 3407.** `init` toma el primero libre entre 3407 y 3499 (o el que se indique con `-PuertoDb`, como `--puerto-db` en Linux) y los dos instaladores lo informan: si hay otro MySQL o MariaDB abierto ya no hace falta cerrarlo. El informe muestra el puerto de la base.
- **MariaDB en Wine:** `mariadb-install-db` y `mariadbd` arrancan con `innodb_buffer_pool_size_max=1G` (plantilla `--config` y `my.ini`). Sin ese tope InnoDB reserva memoria virtual para 8 TB: en Windows es instantáneo, pero en Wine tardaba minutos y la creación de la base se cortaba («Can't write to mysqld's stdin»). Con el tope la base se crea en 11 s.
- **`start` en Wine:** las IP de la PC se leen sin `Get-NetIPAddress` cuando ese comando no existe (Wine, ediciones recortadas de Windows); el diagnóstico prueba los puertos abriéndolos, sin `Get-NetTCPConnection`.
- El estado final del instalador trae `resultado` (`ok` o `con_errores`) además de `fase`, para las pruebas automáticas; si no se pudo crear el icono del escritorio, el paso «Crear el nodo» queda «con aviso» y dice cómo crearlo después.
- Pruebas (nodo instalado con cada instalador, sin tocar nada a mano): **Windows en Wine** pim.sh 70/70 y mem.sh 28/28; **Linux** pim.sh 70/70 y mem.sh 28/28, con la base en el 3408 porque el 3407 estaba ocupado por el nodo de Windows.

## 1.6.0 · 06-11-2026 — Listas desplegables, stock del pañol y recuperación de la contraseña sin Internet

Objetivo: que el usuario escriba lo menos posible y que nadie quede afuera de un nodo que trabaja sin Internet.

- **Catálogos editables (listas desplegables)** en el núcleo (`nucleo_catalogo`, `nucleo_catalogo_valor`, migración 003): cada valor tiene un **código estable** y un texto que se puede corregir; nunca se borra, se desactiva. Vienen sembrados: unidades (SI y comerciales), tipos de mantenimiento (EN 13306), modos de falla (ISO 14224, 24 códigos), motivos de solicitud, rubros, fabricantes, frecuencias (el código son los días) y tipos de local. Los módulos suman los suyos en `modulo.json` (`"catalogos"`). El administrador los mantiene en **Configuración › Listas desplegables**; con permiso, el usuario agrega un valor desde el formulario con «＋ Otro…». Capacidades `catalogos.ver`, `catalogos.agregar` (GM, EJ) y `catalogos.admin`. Una semilla corregida por el administrador no se vuelve a pisar en el arranque.
- **Formularios:** tipos `lista` (con lista dependiente, sigla y buscador desde 20 opciones), `opciones`, `decimal` (coma decimal y unidad) y `check`; ayuda bajo el campo, ejemplo en el campo, etiquetas de un renglón y **grilla simétrica** de 1 a 4 columnas sin campos huérfanos. Los formularios largos quedan plegados en «＋ Nuevo …».
- **PIM y preventivos sin texto libre:** la solicitud elige el equipo de la lista y el motivo del catálogo; el cambio de estado de una orden elige responsable, tipo EN 13306 y modo de falla ISO 14224; el inventario sugiere el código del activo y de la ubicación y elige fabricante y tipo de local; el plan preventivo elige equipo, frecuencia, punto de uso y responsable. Estados en castellano.
- **Celular:** indicadores y accesos de a dos (el último impar ocupa el renglón), tablas como tarjetas (`table.resp`, «etiqueta: valor»), botones y filtros a todo el ancho, etiquetas largas que cortan renglón. Verificado en 375, 768 y 1366 px sin desbordes ni errores de consola.
- **Recuperación de la contraseña sin Internet** (migración 004, `nucleo_recuperacion`):
  - **códigos de recuperación** (8, de un solo uso, generados por el usuario en «Mi perfil»; se guardan como HMAC);
  - **clave temporal** del administrador (72 h; al ingresar se exige cambiarla y, mientras tanto, todo responde 428 `cambiar-clave`);
  - **enlace por correo** solo si el nodo tiene SMTP configurado (Configuración › Correo y recuperación; 30 min, un solo uso, respuesta genérica para no revelar usuarios, hasta 3 por hora);
  - y el lanzador (`<Producto>-Control usuario clave <u>`) para el administrador mismo.
  Cambiar la contraseña cierra las demás sesiones (la cookie lleva una huella de la clave). Cinco intentos fallidos bloquean la recuperación por códigos un rato.
- **Usuarios:** correo opcional, organización elegida de la lista PIM, «Generar clave temporal» y tarjeta de roles con su descripción.
- **Nuevo módulo `stock` 1.0.0** (pañol): insumos con código automático `{n}-{CAT}-{SUB}` (categoría y subcategoría de catálogo, con sigla), marca, unidad, mínimo, último precio y depósito; **ingresos y egresos de varios renglones** por comprobante (`ING-000001`, `EGR-000001`), todo o nada y con bloqueo de fila; no deja sacar más de lo que hay; egreso para una orden de trabajo PIM o un servicio de destino; proveedores con rubro; ficha (kardex) por insumo, resumen con bajo mínimo y widget del inicio. Capacidades `stock.ver` (GM, EJ, CI), `stock.mover` (GM, EJ), `stock.editar` (GM). Las plantillas `gestion-mantenimiento` y `mantenimiento-basico` de la incubadora lo incluyen.
- **Desinstalador generado por el instalador** (`uninstall-<Producto>.sh` en Linux; `uninstall-<Producto>.bat` + `.ps1` en Windows, con su manifiesto `uninstall-<Producto>.json`), en la misma carpeta del producto, armado con lo que el instalador fue registrando: carpeta, accesos directos, regla del firewall, caché y componentes. Es interactivo y simple (confirmar y elegir si se guarda una copia de los datos) y tiene opciones ocultas para automatizar (`--si`, `--sin-datos`, `--destino-respaldo`, `--cache`, `--conservar-firewall`). Detiene el nodo, guarda `data/` comprimida y verificada con SHA-256, quita los accesos, ofrece cerrar el puerto del firewall (pide permiso), borra la carpeta (y la de arriba solo si no quedó nada más tuyo) y deja un registro. No deshace librerías del sistema ni la sincronización de hora. Paso nuevo «Dejar el desinstalador» en el informe.
- **Un Ejecutor siempre es asignable como responsable:** al crear o editar un usuario con rol EJ, su empresa o área se crea en el directorio PIM si no existe (pim-empresa); si no se elige ninguna, se crea `EJ-<usuario>` con su nombre visible. Así aparece en «Responsable» al asignar la orden (asiento `usuario.organizacion`).
- Instalador para Windows: corregidos dos choques de nombres de variables (`$TOTAL`/`$total`, `$CACHE`/`$cache`) que alteraban la barra de avance durante las descargas (PowerShell no distingue mayúsculas, L-401).
- N.º de producto ANMAT: formato **confirmado** con la Disp. ANMAT 4831/05 y disposiciones de registro vigentes: `PM-<legajo de la empresa>-<número de producto>` (p. ej. `PM-2430-65`). El nodo acepta las variantes con espacio o dos puntos que usan las disposiciones («PM 2430-65», «PM: 2430-65») y las guarda normalizadas con guiones; la columna pasa a `VARCHAR(16)`.
- Pruebas: nueva `pruebas/nucleo16.sh` (45: catálogos, recuperación, Ejecutor → responsable y stock); `pim.sh` 70, `mem.sh` 28, `industrial.sh` 16 (sin los simuladores Modbus y MQTT), unitarias 48.

## 1.5.1 · 04-11-2026 — Auditoría del estándar antes de las pruebas de colmena (PIM 0.9.4, API 1.3.1)

Resultado de una revisión técnica independiente de la guía, la API y el código, previa a las pruebas de interoperabilidad entre tres nodos:

- **PIM-8 / ITI-91 corregido:** `_history` devolvía los cambios más nuevos primero y sin paginar, así que un nodo que sincronizaba con `_since` perdía los más viejos cuando había más de `_count`. Ahora va en orden cronológico ascendente, con `total` real y `link next` (`_offset`). La guía acota la promesa a los recursos versionados (Organization, Location, Device, ServiceRequest y Task).
- **IRU normativo (PIM 0.9.4):** la fórmula, los rangos (F 1–10, R 1–5, M 1–5, A −2..+2), la derivación de R desde NFPA 99, las bandas y los ajustes quedaron escritos en la guía y en la extensión `pim-iru` (`minValue`/`maxValue`). `pim-iru` distingue **banda base** de **banda ajustada** (con su motivo) y publica la **prioridad sugerida**; el mapa banda → `request-priority` es el ConceptMap `pim-banda-a-prioridad`. Así dos implementaciones sugieren lo mismo y la decisión humana se puede auditar.
- **Lo que un cliente descubre ahora coincide con lo que el servidor hace:** `SearchParameter` publicados para `decision-prioridad`, `banda` y `categoria-alcance`; la OperationDefinition `$transicion` incluye `prioridad`, `nota`, `decisionPrioridad` y `motivoPrioridad` (y `estado` pasa a opcional); el CapabilityStatement del Gestor suma Group y DiagnosticReport; el nodo sirve ConceptMap y SearchParameter en `/pim/r4/`.
- **IHE, declarado = implementado:** BALP y el puente ACM figuran como **planificados**; MEM-DMC toma el EUI-64 también de `67972 MDC_ATTR_SYS_ID`, registra si falta `IHE_PCD_015` en MSH-21, responde `ERR 100` al rechazar PID/PV1 y la guía documenta la ruta real `POST /api/m/mem/pcd15` y la opción `aceptar_r01`. La vuelta `finalizada → en-ejecucion` queda explicada como extensión del PIM respecto de XDW.
- **API 1.3.1:** DeviceMetric deja de figurar como modificable por PUT (el servidor nunca lo permitió).
- N.º de producto ANMAT: se acepta `PM-N-N` con 1 a 5 dígitos por grupo [supuesto, a confirmar con el padrón].
- `herramientas/contrato.py` ya no exceptúa recursos: mide de verdad que todo lo que sirve el servidor esté en `pim-gm`.
- Pruebas: `pim.sh` 69, `mem.sh` 28, `colmena.sh` 20 (intranet + hub de relevo local y el hub real en iproy.com.ar), unitarias 47.
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
