<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Tutorial: armar una colmena paso a paso (misma red e Internet, hasta 4 instalaciones)

<sub>🎓 [Lección: conectá tu institución con otras (la colmena) →](https://iproy.com.ar/pim/aprende/federar-hospitales) · [Protocolo colmena/1](COLMENA.md) · [Plan de prueba de 3 nodos](PRUEBA_COLMENA_3_NODOS.md)</sub>

Este tutorial te lleva desde cero hasta tener **cuatro instalaciones** intercambiando inventario y pedidos de trabajo: dos en la misma red, y dos más en otros lugares, conectadas por Internet. Cada paso dice **en qué equipo** se hace, **qué escribir** y **qué tenés que ver** antes de seguir.

Requiere **iProy 1.6.3 o posterior** en todos los nodos (por la cola de salida). Un nodo 1.6.2 se vincula igual, pero no guarda los pedidos cuando se corta el camino.

## 1. Qué vas a armar

| Nodo | Ejemplo | Dónde está | Cómo habla con los demás |
|---|---|---|---|
| **A** | Mantenimiento del hospital (Linux) | Red del hospital | Directo con B; por el hub con C y D |
| **B** | Pañol o Electromedicina (Windows) | **La misma red** que A | Directo con A; por el hub con C y D |
| **C** | Otro hospital o centro de salud | Otra ciudad, otra red | Solo por el hub |
| **D** | Empresa contratista o nivel central | Otra red | Solo por el hub |

Tres ideas que conviene tener claras antes de empezar:

1. **Cada nodo es dueño de sus datos.** Nadie copia la base de nadie. Un nodo le pregunta a otro, y el otro contesta según el permiso que le dio.
2. **El vínculo es la autorización.** Dos nodos solo se hablan si uno pidió el vínculo y el otro lo aceptó. Al aceptar se elige **qué puede hacer** el otro (su rol). Se puede revocar en cualquier momento.
3. **Los vínculos son de a pares.** Con 4 nodos hay hasta 6 vínculos posibles (A–B, A–C, A–D, B–C, B–D, C–D). **No hace falta crearlos todos**: solo los que tengan sentido. Que A esté vinculado con B y con C no le da a B ningún acceso a C.

Hay dos caminos, y el nodo elige solo:

| Camino | Cuándo se usa | Qué necesita |
|---|---|---|
| **Directo** (misma red) | Los dos nodos se ven por la red local | Los dos en alcance `lan` y el puerto abierto en el cortafuegos |
| **Hub de relevo** (Internet) | No se ven directo | Que cada nodo pueda **salir** a Internet por HTTPS. No hay que abrir puertos, ni tener IP fija, ni tocar el router |

El hub es un buzón: guarda los sobres hasta que el destinatario los retira. **No puede leerlos ni modificarlos**: van firmados y sellados para el destinatario.

Funciona como AnyDesk en modo relevo: cada nodo solo **sale** por HTTPS y deja un pedido abierto con el hub. Con un hub 1.1.0, un sobre llega en 1 o 2 segundos; con un hub 1.0.0, cada 15 segundos. Si la PC puede navegar por Internet (aunque sea por un proxy), el nodo se conecta sin tocar la red del edificio.

## 2. Qué necesitás antes de empezar

| Qué | Para qué | Cómo comprobarlo |
|---|---|---|
| Una PC por nodo, Linux x64 o Windows 10/11 | Cada instalación | — |
| iProy 1.6.3 o un producto incubado sobre 1.6.3 | Todos | `status` muestra la versión |
| **Hora correcta** en todas las PC (sincronización automática activada) | El hub rechaza pedidos con más de 5 minutos de diferencia | `status` avisa el desfase |
| Un teléfono o videollamada entre los responsables | **Comparar las huellas** al vincular | — |
| A y B: conectados a la misma red (mismo router o misma VLAN) | Camino directo | Desde A: `ping <IP de B>` |
| C y D: salida a Internet por HTTPS (puerto 443) | Camino por el hub | `curl -s https://iproy.com.ar/colmena-hub/?r=estado` devuelve `{"ok":true,…}` |
| El **código de registro** del hub | Registrar cada nodo en el hub | Lo entrega el administrador del proyecto por un canal privado |

> **El código de registro es un secreto.** No lo pegues en un chat, un correo grupal, una captura ni un repositorio. Pasalo por teléfono o en mano, y tipealo en cada PC.

En todo el tutorial, `<Prod>` es el nombre de tu producto: `iProy-Control.sh` en Linux, `iProy-Control.bat` en Windows; si incubaste un producto propio será `MiProducto-Control.sh` / `.bat`. Los comandos son los mismos en los dos sistemas:

```bash
bash iProy-Control.sh colmena lista        # Linux, dentro de la carpeta del producto
```
```bat
iProy-Control.bat colmena lista            :: Windows, dentro de la carpeta del producto
```

Casi todo se puede hacer también desde la app: **pestaña Colmena**. El tutorial usa comandos porque se pueden copiar y dejan registro; al final de cada parte se indica el equivalente en la app.

## 3. Parte 1 — Instalar cada nodo

Hacé esto **en cada una de las PC** (A, B, C y D). El tutorial completo de instalación está en <https://iproy.com.ar/pim/instalar>; acá va lo mínimo.

**Paso 1.1 — Instalar.** Usá el instalador gráfico (Linux: `instalador.py`; Windows: `instalador.ps1`) o descomprimí el paquete portable en una carpeta sin espacios raros (por ejemplo `C:\iProy` o `~/iProy`).

**Paso 1.2 — Inicializar con nombre y alcance.** El nombre es el que van a ver los otros nodos: que diga **quién sos**.

| Nodo | Comando |
|---|---|
| A (misma red) | `bash iProy-Control.sh init --nombre "Mantenimiento Central" --modo lan` |
| B (misma red) | `iProy-Control.bat init --nombre "Pañol Central" --modo lan` |
| C (remoto) | `init --nombre "Hospital del Sur" --modo local` |
| D (remoto) | `init --nombre "Contratista Clima SA" --modo local` |

- `--modo lan`: el nodo se ve en la red local por `https://<IP>:8443/`. **Lo necesitan A y B.**
- `--modo local`: el nodo solo se ve desde su propia PC. **Le alcanza a C y D**, porque el hub no necesita que nadie entre: el nodo sale.
- Si ya lo habías inicializado en `local`, se cambia sin reinstalar: `red modo lan` y después `stop` y `start`.
- Si en una misma PC hay dos nodos (solo para ensayar), al segundo dale otros puertos: `--puerto 8090 --puerto-db 3408`. El puerto seguro es siempre el puerto + 363 (8080 → 8443, 8090 → 8453).

**Paso 1.3 — Crear el administrador e iniciar.**

```bash
bash iProy-Control.sh usuario alta admin AD    # pide la contraseña dos veces, sin mostrarla: anotala antes
bash iProy-Control.sh start
```

**Paso 1.4 — Comprobar.**

```bash
bash iProy-Control.sh status
```

Tenés que ver los servicios activos, la versión 1.6.3 y la hora sin aviso de desfase.

**Paso 1.5 — Anotar la identidad del nodo.**

```bash
bash iProy-Control.sh colmena identidad
```

Anotá dos datos en una planilla como esta (la vas a usar en todos los vínculos):

| Nodo | Nombre | `node_id` (3 caracteres) | Huella | IP en la red (solo A y B) |
|---|---|---|---|---|
| A | Mantenimiento Central | | | |
| B | Pañol Central | | | |
| C | Hospital del Sur | | | — |
| D | Contratista Clima SA | | | — |

La **huella** es un resumen corto de la clave pública del nodo. Sirve para comprobar por teléfono que el nodo que pide el vínculo es el que dice ser. No es un secreto.

**Paso 1.6 — Cargar algo para probar.** En cada nodo, al menos una ubicación y dos activos (Inventario → Ubicaciones, Inventario → Nuevo activo). Sin datos no hay nada que consultar.

✅ **Al terminar la Parte 1:** cuatro nodos andando, cada uno con su `node_id` y su huella anotados.

## 4. Parte 2 — Colmena local: A y B en la misma red

**Paso 2.1 — Averiguar la IP de B.** En B:

- Windows: `ipconfig` → «Dirección IPv4» (por ejemplo `192.168.1.42`).
- Linux: `hostname -I`.

Conviene que A y B tengan **IP fija o reservada en el router** (reserva DHCP). Si la IP de B cambia, el camino directo deja de andar y los envíos pasan al hub (si está configurado) o quedan en la cola de salida hasta que se corrija.

**Paso 2.2 — Abrir el puerto en el cortafuegos de B (y de A).**

- **Windows:** la primera vez que inicia, Windows pregunta. Permití **solo redes privadas**. Si no preguntó: Panel de control → Firewall de Windows Defender → Configuración avanzada → Reglas de entrada → Nueva regla → Puerto → TCP **8443** → Permitir → solo perfil **Privado**.
- **Linux con ufw:** `sudo ufw allow from 192.168.1.0/24 to any port 8443 proto tcp` (ajustá la red a la tuya).
- **Linux con firewalld:** `sudo firewall-cmd --permanent --add-port=8443/tcp && sudo firewall-cmd --reload`.

**Paso 2.3 — Comprobar que A ve a B.** En A:

```bash
curl -sk https://192.168.1.42:8443/api/colmena/identidad
```

Tiene que devolver un texto que empieza con `{"protocolo":"colmena/1","node_id":"…`. Si no responde: revisá la IP, que B esté iniciado en modo `lan` (`status`) y el cortafuegos. **No sigas hasta que esto ande.**

**Paso 2.4 — A pide el vínculo.** En A:

```bash
bash iProy-Control.sh colmena vincular https://192.168.1.42:8443
```

Respuesta esperada: `estado: saliente`, con el nombre y la **huella de B**. Comparala con la que anotaste de B en la planilla.

**Paso 2.5 — B compara la huella y acepta.** En B:

```bat
iProy-Control.bat colmena lista
```

Aparece una solicitud `entrante` de A con su huella. **Llamá por teléfono al responsable de A y comparen la huella carácter por carácter.** Si coincide, aceptá eligiendo el rol:

```bat
iProy-Control.bat colmena aceptar <node_id de A> SO
```

El rol es **lo que B le deja hacer a A dentro de B**:

| Rol | Qué puede hacer el otro nodo en el tuyo | Cuándo darlo |
|---|---|---|
| `CI` | Solo consultar (inventario, órdenes, estado) | Por defecto. Auditoría, nivel central, otra institución que solo mira |
| `SO` | Consultar y **pedir trabajos** | Un servicio que te solicita mantenimiento |
| `GM` | Gestionar el mantenimiento completo | Solo otro nodo de **tu misma** área de mantenimiento |

Empezá siempre con `CI` y subí el rol cuando haga falta: en la app, Colmena → clic en el vínculo → «Rol que este nodo le da» → «Guardar el rol»; o por comando, `colmena rol <node_id> SO`.

> **Contratistas (rol `EJ`).** El rol Ejecutor existe en el PIM, pero **todavía no se puede dar por un vínculo de colmena**: el vínculo no lleva la organización del ejecutor y el servidor responde 403 («El cliente Ejecutor no tiene una organización válida asignada»). Comprobado en iProy 1.6.3. Por ahora, a una empresa contratista dale `CI` para que consulte; para que avance sus órdenes, creale un cliente PIM con su organización (`pim cliente alta <id> <jwks.json> EJ <organización>`).

Opcional, para dejar constancia formal: `colmena aceptar <node_id> SO 2027-12-31 acta.pdf` agrega una **fecha de vencimiento** y el **sha256 del acta o convenio** firmado. Al vencer, el vínculo se corta solo.

**Paso 2.6 — El vínculo es de ida y vuelta, pero los roles no.** Lo anterior definió qué puede hacer A en B. Para definir qué puede hacer **B en A**, en A:

```bash
bash iProy-Control.sh colmena rol <node_id de B> CI
```

**Paso 2.7 — Probar.** En A:

```bash
bash iProy-Control.sh colmena ping <node_id de B>          # respuesta: pong, via lan, en milisegundos
bash iProy-Control.sh colmena rpc <node_id de B> GET "Device?_summary=count"
```

El segundo comando le pregunta a B cuántos activos tiene. Tiene que devolver `estado: respondido`, `http: 200` y `via: lan`.

**Paso 2.8 — Pedir un trabajo de un nodo a otro.** Primero, en B: Colmena → clic en el vínculo con A → «Rol que este nodo le da a A»: **SO** → «Guardar el rol» (o `colmena rol <node_id de A> SO`). Después, en A: Colmena → clic en B → «Pedirle un trabajo» (o el acceso **Pedidos a otros nodos**, en Red de instituciones): buscá el equipo o la instalación de B por nombre o código, escribí el trabajo, elegí la prioridad y enviá. A muestra el **número de orden de B** y sigue su estado (solicitada, asignada, en ejecución, finalizada…) solo cada 10 minutos o al tocar el pedido. En B aparece la solicitud con su orden, y en la procedencia figura que **la originó A**. Si B está apagado, el pedido espera en la cola de salida (o en el hub) y sale solo cuando vuelve.

En la app: Colmena → «Pedir un vínculo» (pegar `https://IP:8443`) en A; Colmena → clic en la solicitud → «Aceptar» en B.

✅ **Al terminar la Parte 2:** A y B con vínculo `activo`, `ping` con `via: lan`. Esta colmena local **funciona sin Internet**.

## 5. Parte 3 — Colmena remota: sumar C y D por Internet

**Paso 3.1 — Elegir el hub.**

- **El del proyecto:** `https://iproy.com.ar/colmena-hub`. Ya está instalado; necesitás el código de registro.
- **Uno propio** (recomendado si la colmena es de tu provincia o de tu red de hospitales): en cualquier hosting con PHP 8 y HTTPS, subí la carpeta `hub/` del repositorio, copiá `config.ejemplo.php` como `config.php`, poné tu propio `codigo_registro` y, si el hosting lo permite, mové `dir_datos` fuera de la carpeta pública (ver `hub/LEEME.md`). Comprobá con `curl -s https://tu-dominio/colmena-hub/?r=estado`.

**Paso 3.2 — Registrar cada nodo en el hub.** En **los cuatro** (A, B, C y D):

```bash
bash iProy-Control.sh colmena hub https://iproy.com.ar/colmena-hub <código de registro>
```

Respuesta esperada: `hub: activo` y, en la segunda línea, el registro aceptado. Si dice que el código no es válido, revisá que lo hayas tipeado bien. Si dice algo de la hora, corregí el reloj de esa PC.

En la app (1.7.0): **Colmena → «Configurar el hub central»**. Se escribe la dirección y el código de registro, y «Guardar y registrar» hace los dos pasos. El código se tipea en cada PC; no se manda por chat ni por correo.

**Paso 3.2b — Probar el camino.** En cada nodo: Colmena → **«Probar camino»**. Revisa el hub, el proxy, el certificado, la hora, el registro y la escucha, y manda un pedido de prueba a cada vínculo. Lo que falla aparece en rojo, con lo que hay que hacer.

**Paso 3.3 — C pide el vínculo a A, por su identificador.** Por Internet no se usa la IP: se usa el `node_id`, y el hub dice dónde dejar el sobre. En C:

```bash
bash iProy-Control.sh colmena vincular <node_id de A>
```

Respuesta: `estado: saliente`, `via: hub` y la **huella de A**. Comparala con la planilla.

**Paso 3.4 — A compara la huella y acepta.** En A, esperá hasta 15 segundos y:

```bash
bash iProy-Control.sh colmena lista                       # aparece C como «entrante»
```

**Teléfono: comparar la huella de C.** Por Internet este paso importa todavía más: es lo único que impide que un tercero se haga pasar por C.

```bash
bash iProy-Control.sh colmena aceptar <node_id de C> CI
```

**Paso 3.5 — Probar.** En C:

```bash
bash iProy-Control.sh colmena ping <node_id de A>
bash iProy-Control.sh colmena rpc <node_id de A> GET "Device?_summary=count"
```

Por el hub las respuestas tardan más (hasta unos 30 segundos: el otro nodo retira su buzón cada 15). El `rpc` espera la respuesta hasta 60 segundos y devuelve `estado: respondido`, `via: hub`.

**Paso 3.6 — Dejar que C pida trabajos (si corresponde).** En A: `colmena rol <node_id de C> SO`. A partir de ahí C puede pedir trabajos sobre activos de A, y cada pedido queda en A firmado por C.

**Paso 3.7 — Repetir para los otros pares que necesites.**

| Vínculo | Quién pide | Quién acepta | Rol sugerido |
|---|---|---|---|
| A – C | C: `colmena vincular <id de A>` | A: `colmena aceptar <id de C> CI` | CI, o SO si C solicita trabajos a A |
| A – D | A: `colmena vincular <id de D>` | D: `colmena aceptar <id de A> SO` · y en A: `colmena rol <id de D> CI` | A le pide trabajos a D (SO); D consulta en A el estado de lo suyo (CI) |
| B – C | C: `colmena vincular <id de B>` | B: `colmena aceptar <id de C> CI` | CI |
| B – D, C – D | Solo si hace falta | | |

En cada uno: **pedir → comparar huella por teléfono → aceptar con rol → `ping`**.

**Paso 3.8 — (Opcional) Forzar el hub entre A y B.** A y B se hablan directo. Si querés que usen siempre el hub (por ejemplo, porque la red interna bloquea el puerto): en los dos, `colmena via <node_id> hub`. Para volver: `colmena via <node_id> auto`. Con `auto`, si el camino directo falla, se usa el hub solo.

✅ **Al terminar la Parte 3:** `colmena lista` en A muestra a B, C y D `activo`, cada uno con su rol.

## 6. Parte 4 — Comprobar toda la colmena

En cada nodo:

```bash
bash iProy-Control.sh colmena lista
```

| Columna | Qué tenés que ver |
|---|---|
| Estado | `activo` en todos los vínculos que creaste |
| Rol PIM | El rol que **vos le diste** al otro |
| Huella | La misma que figura en tu planilla |
| Último contacto | Hace pocos minutos |

Prueba cruzada (marcá cada casilla):

| Desde ↓ / Hacia → | A | B | C | D |
|---|---|---|---|---|
| **A** | — | ping ☐ consulta ☐ | ping ☐ consulta ☐ | ping ☐ pedido ☐ |
| **B** | ping ☐ consulta ☐ | — | ping ☐ | ☐ |
| **C** | ping ☐ consulta ☐ pedido ☐ | ping ☐ | — | ☐ |
| **D** | ping ☐ consulta ☐ | ☐ | ☐ | — |

Y una comprobación que vale la pena hacer una vez: con rol `CI`, intentá pedir un trabajo. Tiene que responder **403**. Eso prueba que el rol se respeta.

## 7. Parte 5 — Qué pasa cuando se corta el camino (cola de salida)

Desde la 1.6.3, si un nodo no puede llegar al otro, **el pedido no se pierde ni da error**: queda en una cola y sale solo cuando el camino vuelve.

**Ensayo recomendado (5 minutos):**

1. En C, desconectá el cable de red o el wifi.
2. En C, pedí un trabajo sobre un activo de A (o `colmena rpc <id de A> GET metadata`). Respuesta: `estado: en-cola`.
3. Mirá la cola:
   ```bash
   bash iProy-Control.sh colmena cola
   ```
   Muestra el envío, cuántos intentos lleva y el motivo del último.
4. Volvé a conectar la red. **No hagas nada más.** En menos de 5 minutos el pedido sale; `colmena cola` queda vacía y la orden aparece en A, **una sola vez**.

| Situación | Qué hace el nodo |
|---|---|
| El otro nodo está apagado (misma red, sin hub) | Encola y entrega cuando se enciende |
| El otro nodo está apagado (con hub) | Lo deja en el buzón del hub; el otro lo retira al encender |
| El hub no responde | Encola; A y B siguen hablándose directo |
| Este equipo se quedó sin red | Encola todo; sale al volver, en el mismo orden |
| Pasan 72 horas sin poder entregar | Cierra el envío como `vencido` y lo asienta en el diario |
| Se revoca el vínculo con envíos pendientes | Los pendientes se cierran sin entregarse; el aviso de revocación sí llega |

Comandos: `colmena cola` (ver), `colmena cola reintentar` (no esperar el turno), `colmena cola descartar <id>` (sacar uno; solo el administrador). En la app: Colmena → tarjeta «Cola de salida», que aparece cuando hay algo pendiente.

Lo que **no** se encola: el `ping` y el pedido de vínculo. Son para diagnosticar, así que contestan en el acto que no hay camino.

## 8. Parte 6 — Administrar los vínculos

| Quiero… | Comando (en el nodo que da el permiso) |
|---|---|
| Ver mis vínculos | `colmena lista` |
| Cambiar lo que puede hacer el otro | `colmena rol <node_id> CI` (o `SO`, `GM`) |
| Cortar un vínculo ya | `colmena revocar <node_id>` |
| Volver a vincular después de revocar | Repetir pedir → comparar huella → aceptar |
| Cambiar de hub | `colmena hub <url nueva> <código>` en cada nodo |
| Dejar de usar el hub | `colmena hub apagar` |
| Ver qué hizo el otro nodo en el mío | App → Sistema → Diario (acciones `colmena.*`); en cada orden, la procedencia dice qué nodo la originó |

Revocar es inmediato en tu nodo. El otro se entera cuando recibe el aviso; si en ese momento no hay camino, el aviso espera en la cola y llega después.

## 9. Parte 7 — La malla: cada nodo es hub de los otros

Desde la 1.7.0 la colmena no depende de un punto único. Cada nodo es **hub, puente, emisor y receptor**, y no hay nada que instalar aparte: viene encendido.

**Qué hace cada nodo, sin que nadie se lo pida:**

- Ofrece su **hub propio** (`<su dirección>/colmena-hub/`) a los nodos con los que tiene vínculo activo. A nadie más.
- **Escucha a la vez** en el hub central y en el hub de cada nodo vinculado al que llega.
- Para cada envío usa **el camino más rápido que ande**: directo, por su hub, por el hub de otro nodo, por el hub central o con otro nodo de puente. Los mide cada 5 minutos.
- Si un camino cae, pasa al siguiente. Si no queda ninguno, el envío espera en la cola de salida (Parte 5).

**Qué gana la colmena del tutorial.**

| Situación | Qué pasa |
|---|---|
| Se cae el hub central | A y B siguen entre ellos (directo o por el hub de cualquiera de los dos). Lo que iba a C y D espera en la cola y sale solo cuando el hub vuelve |
| B tiene el cortafuegos cerrado y A no llega a su dirección | Alcanza con que B llegue a A: B escucha en el hub de A y por ahí pasa todo, en los dos sentidos |
| A y B se ven, B y C se ven, pero A y C no | A le deja el sobre a B y B se lo pasa a C: B hace de puente. Para eso B tiene que tener vínculo activo con los dos |
| Un nodo tiene dirección pública | Es hub de todos sus vinculados, también de los que están detrás de un router |

**Ver los caminos.** Colmena → **«Red de la colmena»**: los hubs que usa el nodo y, por cada vínculo, el camino elegido con su demora y las alternativas. «Volver a medir los caminos» busca y mide en el momento. Por comando: `colmena malla` y `colmena medir`.

**Los dos interruptores** (Colmena → «Red de la colmena»; solo el administrador):

| Interruptor | Para qué | Comando |
|---|---|---|
| **Hub propio** encendido / apagado | Apagarlo si sospechás un ataque por ese lado. El nodo deja de guardar y reenviar para otros, pero sigue en la colmena usando los caminos de los demás | `colmena hub-propio apagar` · `encender` |
| Modo **Malla** | Lo normal: todos los caminos | `colmena modo malla` |
| Modo **Solo hub central** | Nada entra directo a este equipo y no usa ni ofrece hubs de nodos. Solo saca y trae sobres del hub central | `colmena modo central` |
| Modo **Aislado** | Corta toda la colmena: no manda ni recibe. Lo que sale espera en la cola (72 horas) hasta que lo reconectes. Antes de cortar, avisa a sus vinculados | `colmena modo aislado` |

Aislar o reconectar un nodo queda asentado en el diario (`colmena.malla`).

**Lo que la malla no puede hacer.** Un nodo detrás de un router solo es hub de quienes llegan a él. Dos edificios sin dirección pública se encuentran en el hub central: por eso conviene tenerlo configurado aunque la colmena local ande sola. Y hay un solo puente entre origen y destino: no se arman cadenas de varios.

**`via hub` cambió de sentido.** Ahora significa «a ese nodo, solo por terceros» (ni directo ni por su hub propio). Los vínculos que lo tenían puesto vuelven a `auto` al actualizar.

✅ **Para comprobarlo:** con A, B y C vinculados, poné el hub central en mantenimiento (o `colmena hub apagar` en A y en B) y pedí algo de A a B: responde igual. La prueba automática es `pruebas/malla.sh`.

## 10. Si algo no anda

| Síntoma | Causa más probable | Qué hacer |
|---|---|---|
| `colmena vincular https://IP:8443` → «no responde un nodo colmena/1» | IP equivocada, B en modo `local`, cortafuegos, o redes distintas (wifi de invitados) | Repetir el paso 2.3; en B: `status` y, si hace falta, `red modo lan` |
| `colmena hub …` → código inválido | Error de tipeo o código de otro hub | Volver a pedirlo y tipearlo |
| `colmena hub …` → rechazo por la hora | Reloj con más de 15 minutos de diferencia (hasta 15 se corrige solo contra la hora del hub) | Activar la hora automática del sistema |
| No sé qué falla | — | Colmena → «Probar camino»: dice qué anda, qué no y cómo arreglarlo |
| `colmena vincular <id>` → «no está registrado en el hub» | El otro nodo no hizo el paso 3.2, o usa otro hub | Registrarlo en el mismo hub |
| La solicitud no aparece del otro lado | Por un hub 1.0.0 tarda hasta 15 s (1 o 2 s con un hub 1.1.0); o el otro nodo está apagado | Esperar; `colmena lista` otra vez |
| «El nodo … cambió de clave» | El otro nodo se reinstaló, **o alguien se hace pasar por él** | No aceptar sin llamar por teléfono. Si es legítimo: revocar el vínculo viejo y vincular de nuevo comparando la huella |
| El `rpc` responde `http: 403` | El rol que te dieron no alcanza | Pedir al otro administrador que cambie tu rol |
| El `rpc` responde 403 con `vinculo-no-activo` | El otro nodo revocó el vínculo o venció | Hablar con el otro administrador y vincular de nuevo |
| El `rpc` queda `en-cola` | No hay camino en este momento | `colmena cola` muestra el motivo; sale solo al volver |
| El `rpc` queda `enviado` y después `vencido` | El otro nodo está apagado hace más de 15 minutos (el sobre lo espera en el hub) | Avisar al otro administrador; al encender, lo procesa |
| El `ping` va por `hub` entre A y B | El camino directo no anda (IP cambiada o puerto cerrado) y la malla usó otro | No es una falla: sigue funcionando. Para recuperar el directo: paso 2.3; reservar la IP en el router |
| «Este nodo está aislado de la colmena» | El administrador puso el modo Aislado | Colmena → Red de la colmena → Malla (o `colmena modo malla`) |
| Colmena → Red muestra un hub «no responde» | Ese nodo está apagado o en otra red | Nada: se usa otro camino. Vuelve solo cuando el nodo aparece |
| Hay proxy en la red | El nodo no sale directo a Internet | Panel de Redes del nodo, o variable `HTTPS_PROXY` |

## 11. Seguridad: lo mínimo que no se negocia

1. **Comparar la huella por teléfono en cada vínculo.** Es el único paso que no puede hacer la máquina.
2. **El código del hub no se escribe en ningún lado público.**
3. **Rol mínimo.** Empezar con `CI`. `GM` solo dentro de tu propia área.
4. **Vencimiento y acta** para vínculos con otras instituciones o empresas.
5. **Revocar** cuando termina un contrato o cambia el responsable del otro nodo.
6. **Copia de seguridad** de la carpeta `data/` de cada nodo: ahí están las claves. Si se pierde, el nodo nace con otra identidad y hay que rehacer todos sus vínculos.
7. Para ensayar, **datos de prueba**. Con datos reales, cada institución responde por los suyos.

## 12. Resumen en una página

```text
EN CADA NODO
  init --nombre "…" --modo lan|local     usuario alta admin AD     start     status
  colmena identidad                      → anotar node_id y huella

MISMA RED (A ↔ B)
  A:  colmena vincular https://<IP de B>:8443
  B:  colmena lista   → comparar huella por teléfono
  B:  colmena aceptar <id de A> <rol>
  A:  colmena rol <id de B> <rol>
  A:  colmena ping <id de B>             → pong, via lan

INTERNET (C, D)
  todos:  colmena hub https://…/colmena-hub <código>
  C:  colmena vincular <id de A>
  A:  colmena lista   → comparar huella por teléfono
  A:  colmena aceptar <id de C> <rol>
  C:  colmena ping <id de A>             → pong, via hub

DÍA A DÍA
  colmena lista · colmena rol <id> <rol> · colmena revocar <id>
  colmena cola · colmena cola reintentar
```
