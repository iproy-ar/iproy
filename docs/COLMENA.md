<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Protocolo colmena/1

> 🎓 **Capacitación PIM:** este documento tiene su versión guiada, con diagramas, ejemplos y foro, en [iproy.com.ar/pim](https://iproy.com.ar/pim/) (en particular [esta página](https://iproy.com.ar/pim/aprende/federar-hospitales)). El código fuente vive en [github.com/iproy-ar/iproy](https://github.com/iproy-ar/iproy).


Especificación del protocolo con el que se comunican los nodos de cualquier producto derivado de iProy (iProy, Colmen u otros). El nombre del producto es informativo: el protocolo es neutral y tiene versión.

## 1. Identidad del nodo

<sub>🎓 [Lección: conectá tu hospital con otros (la colmena) →](https://iproy.com.ar/pim/aprende/federar-hospitales)</sub>

Cada nodo genera en su primer arranque estas claves, que guarda en `data/nucleo/identidad.json` y `data/nucleo/claves/` con permisos 600:

| Clave | Algoritmo | Uso |
|---|---|---|
| `firma` | ed25519 (libsodium) | Firma de sobres, del diario y de los pedidos al hub |
| `caja` | X25519 (`crypto_box_seal`) | Recibir sobres sellados |
| `colmena` | ECDSA P-384 (ES384) | Aserciones SMART para usar la API PIM de un nodo vinculado |

`GET /api/colmena/identidad` es público y devuelve: `protocolo` (`"colmena/1"`), `node_id`, `nombre`, `producto`, `version`, `nucleo`, `firma_pub`, `caja_pub`, `pim_jwk` (público) y `url`.

`huella` son los primeros 80 bits del SHA-256 de `firma_pub`, en 5 grupos de 4 caracteres hexadecimales. Los administradores la comparan por teléfono o en persona antes de aceptar un vínculo, igual que los «números de seguridad» de las mensajerías cifradas.

## 2. Sobre

<sub>🎓 [Lección: conectá tu hospital con otros (la colmena) →](https://iproy.com.ar/pim/aprende/federar-hospitales)</sub>

```json
{ "v": 1, "id": "<ULID>", "de": "VN2", "para": "4YX", "ts": 1793642648, "tipo": "rpc",
  "caja": "<base64 crypto_box_seal(JSON, caja_pub del destinatario)>",      // o "datos": {...} sin sellar
  "firma": "<base64 ed25519(JSON canónico del sobre sin «firma», clave de «de»)>" }
```

- **JSON canónico:** las claves de los objetos van ordenadas, las listas mantienen su orden, sin escapar `/` ni Unicode, y conservando `1.0`. Es el mismo algoritmo en el nodo y en el hub.
- **Tipos:**
  - `vinculo-solicitud` (datos sin sellar: la identidad de quien pide)
  - `vinculo-recibido`
  - `vinculo-aceptado`
  - `vinculo-revocado`
  - `rpc`
  - `rpc-resp`
  - `evento`
  - `ping`
  - `pong` (devuelve en `ping` el `id` del ping: así se mide la demora del camino por el que salió)
  - `relevo` (malla): lleva adentro, sin sellar, un sobre ajeno ya firmado y sellado por su autor. Lo usa un nodo que hace de puente
  - `anuncio` (malla): direcciones del nodo, si su hub propio está encendido, su modo de red y a qué nodos llega
- **Validación al recibir:**
  1. el destinatario es este nodo;
  2. `ts` no es más viejo que 8 días ni más de 10 minutos en el futuro;
  3. la firma corresponde a la clave del vínculo (en una solicitud, a la clave que viene en ella, con TOFU);
  4. el `id` no se procesó antes (`nucleo_sobre_visto`, 9 días);
  5. si trae `caja`, se abre con la clave X25519 propia.

## 3. Vínculo: «el vínculo es la autorización»

<sub>🎓 [Lección: conectá tu hospital con otros (la colmena) →](https://iproy.com.ar/pim/aprende/federar-hospitales)</sub>

```
A: colmena vincular <url | ID>  ──vinculo-solicitud (identidad A)──▶  B: estado «entrante» (no autoriza nada todavía)
                                ◀──vinculo-recibido (identidad B)──
B: colmena aceptar A <rol>      ──vinculo-aceptado (identidad B)──▶  A: estado «activo»
   (B registra a A como cliente PIM «colmena-a» con su clave ES384 y el rol elegido; A hace lo mismo con B, rol CI)
```

- **Roles posibles:**
  - `CI`: consulta;
  - `SO`: solicitante (puede pedir trabajos);
  - `EJ`: ejecutor, por organización;
  - `FT`: telemetría;
  - `GM`: gestor;
  - `RE`: registro estatal (solo lee PMA y usa `Device/$refes`).

  `colmena rol <par> <rol>` cambia el rol en cualquier momento.
- Si las dos partes se piden el vínculo a la vez, queda activo automáticamente: hay consentimiento mutuo.
- **Revocar.** El nodo mueve el cliente PIM del otro a `_bajas/` y avisa con un sobre firmado. A partir de ahí no hay RPC ni eventos.
- **Cambio de clave.** Si un nodo aparece con otra clave para un vínculo vigente, se rechaza (409). Para restablecerlo hay que revocar y volver a vincular verificando la huella.
- **Tipo y vigencia** (según `docs/vinculos.md`):
  - el tipo es `local`, `institucional` o `central`, y se elige al pedir: `colmena vincular <url> institucional`;
  - la vigencia y el acta se fijan al aceptar: `colmena aceptar <par> <rol> <AAAA-MM-DD> <acta.pdf>`. Del acta se guarda solo su sha256, y las dos partes registran la misma huella;
  - al vencer, el vínculo pasa a `vencido` y el cliente PIM del otro nodo se da de baja.
- **Respaldo legal (opcional).** El acuerdo entre instituciones puede firmarse en papel o con firma digital (Ley 25.506) y citar las dos huellas y la fecha. El diario de cada nodo asienta la solicitud, la aceptación y la revocación con firma.

## 4. RPC sobre la API PIM

<sub>🎓 [Lección: conectá tu hospital con otros (la colmena) →](https://iproy.com.ar/pim/aprende/federar-hospitales)</sub>

El nodo A arma `{rpc, metodo, ruta, cuerpo, if_match, asercion}`, lo sella para B y lo envía.

- `asercion` es un JWT ES384 con `iss = sub = colmena-<a>` y `aud = http://nodo-<b>.colmena/pim/auth/token`.
- B controla que `aud` lo nombre a él y canjea la aserción en **su** Servidor PIM. Lo hace por 127.0.0.1, con `Host` igual al de `aud`.
- La operación se ejecuta con el rol del vínculo. La respuesta (`rpc-resp`, sellada para A) trae `http`, `cuerpo` y las cabeceras `etag`, `location`, `x-pim-task` y `last-modified`.
- **Por la intranet** la respuesta llega en el mismo pedido HTTP. **Por el hub** es asíncrona: `nucleo_rpc` queda `enviado` hasta que llega la respuesta. Si pasan 15 minutos, queda `vencido`.
- **Sin camino** (el otro nodo apagado, el hub caído, este equipo sin red) el pedido no da error: queda `en-cola` y sale solo cuando el camino vuelve (§5.1). El plazo de 15 minutos se cuenta desde que salió, no desde que se pidió.
- Si el vínculo ya no está activo en B, B contesta por el hub un `rpc-resp` con `http = 403` y `error = vinculo-no-activo`. Por la intranet contesta 403 en el mismo pedido HTTP.
- Estados de `nucleo_rpc`: `en-cola` → `enviado` → `respondido`; o `vencido` (no hubo respuesta, o pasó el plazo de la cola) y `rechazado` (el vínculo se revocó, el otro nodo no aceptó el sobre, o el administrador lo descartó).
- Todo lo que el nodo A hace en el nodo B queda en el Provenance de B con `colmena-<a>` como autor.

## 5. Transporte

<sub>🎓 [Lección: conectá tu hospital con otros (la colmena) →](https://iproy.com.ar/pim/aprende/federar-hospitales)</sub>

| Camino | Cómo | Cuándo se puede usar |
|---|---|---|
| Directo | `POST <url>/api/colmena/sobre` con `{sobre}`. La respuesta trae `{ok, sobre}` | Este nodo llega a una dirección del otro (la del vínculo o las que anunció) y `via = auto` |
| Hub propio | El sobre queda en el buzón que este nodo ofrece (§5.2) y el otro lo retira | El otro nodo está escuchando en el hub de este |
| Hub de otro nodo | `enviar` al hub de un tercer nodo vinculado con los dos | Los dos llegan a ese nodo, o ese nodo llega al destino y hace de puente |
| Hub central | `enviar` al hub (§6) y el destinatario lo retira con `buzon` | Los dos lo tienen configurado y están registrados |
| Puente | Un sobre `relevo` hacia un tercer nodo, que lo reenvía | Ese nodo anunció que llega al destino |

Se usa **el camino más rápido que ande** (§5.2). Con un hub con espera larga el destinatario recibe en 1-2 s; con un hub 1.0.0, en su tick (cada 15 s).

**Túnel por el hub (1.7.0).** Funciona como AnyDesk en modo relevo: ningún nodo abre puertos ni necesita IP fija; cada uno solo hace salidas HTTPS.

- **Escucha.** Entre un tick y el siguiente, el worker llama a `/_iproy/escuchar.php`, que deja abierto un `buzon&espera=N` con el hub. El hub responde apenas llega un sobre, y el nodo lo procesa y contesta en el acto. Un pedido entre nodos de redes distintas tarda 1-2 s en lugar de 15-45 s.
- **Hub viejo.** Si el hub no anuncia `espera_max` (hub 1.0.0), el worker vuelve solo a mirar cada 15 s.
- **Hora corregida.** El hub devuelve su hora en cada respuesta (`X-Colmena-Hora` y `hora`). El nodo guarda su diferencia y firma pedidos y sobres con la hora corregida (tope ±15 min). Un rechazo por hora se reintenta una vez después de medirla. Así, una PC con el reloj corrido unos minutos no queda afuera.
- **Probar camino** (`GET /api/colmena/diagnostico[?vinculos=1]` y el botón en Colmena). Revisa:
  - el hub, el proxy, el nombre y el certificado (avisa si parece un proxy que inspecciona HTTPS);
  - la versión del hub y la espera larga;
  - la hora, el registro y la escucha;
  - con `vinculos=1`, un pedido de prueba a cada vínculo, con la vía y el tiempo.

  Cada falla viene con el remedio en palabras.
- **Lo que garantiza.** Si la PC puede navegar por HTTPS (aunque sea por un proxy configurado en el nodo), el nodo se conecta sin tocar la red. Ningún túnel, tampoco AnyDesk, conecta si el edificio bloquea toda salida salvo una lista de sitios permitidos: en ese caso hay que pedir que se permita el hub.

**Proxy de salida.** Las redes con proxy se configuran en el núcleo (`proxy` en `data/nucleo/config.json`), en el panel de Redes o con `HTTPS_PROXY`. El tráfico hacia la LAN, hacia el propio equipo y hacia lo que figure en `NO_PROXY` va siempre directo.

La seguridad no depende de TLS: los sobres están firmados y sellados. TLS (CA propia del nodo) es una defensa adicional.

### 5.1 Cola de salida (desde iProy 1.6.3)

Cuando no hay camino hacia el otro nodo, el envío se guarda en `nucleo_salida` y el worker lo reintenta en cada tick.

| Regla | Detalle |
|---|---|
| Qué se encola | `rpc`, `evento`, `vinculo-aceptado` y `vinculo-revocado`. El `ping` y el pedido de vínculo no: son diagnósticos y contestan en el acto que no hay camino |
| Cuándo | Solo si **no hubo camino** (sin conexión, tiempo agotado, error 5xx del hub). Si el otro lado contestó que no (4xx), el envío falla en el acto: reintentar no lo arregla |
| Orden | Por nodo destino, en el orden en que se pidieron. Mientras un nodo tenga envíos en cola, los nuevos van detrás |
| Una sola vez | El sobre se arma y se firma de nuevo en cada intento (la aserción de un RPC dura 4 minutos), siempre con el **mismo `id`**: si un intento llegó y se perdió la confirmación, el otro nodo descarta la repetición (`nucleo_sobre_visto`) |
| Reintentos | A los 30 s, 1, 2, 4 y después cada 5 minutos. Un nodo que no responde no frena los envíos a los demás |
| Plazo | 72 horas (`COLA_HORAS`). Pasado ese tiempo el envío se cierra y el RPC queda `vencido` |
| Tope | 200 envíos sin entregar por nodo destino (`COLA_MAX`); el siguiente responde 503 `cola-llena` |
| Revocación | Al revocar un vínculo, lo que esperaba salir hacia ese nodo se cierra como `rechazado`. El aviso de revocación sí se encola: el otro nodo se entera cuando vuelve el camino |
| Diario | `colmena.cola_alta`, `colmena.cola_entregado` (con vía, intentos y segundos de espera), `colmena.cola_vencido`, `colmena.cola_rechazado`, `colmena.cola_anulado`, `colmena.cola_descartado` |

API: `GET /api/colmena/cola` (ver), `POST /api/colmena/cola/reintentar` (reintenta ya, sin esperar el turno) y `DELETE /api/colmena/cola/{id}` (descarta; solo el administrador). En el lanzador: `colmena cola`, `colmena cola reintentar`, `colmena cola descartar <id>`. En la app: pestaña Colmena → «Cola de salida» (aparece cuando hay algo pendiente).

Lo que la cola **no** hace: no guarda lo que el otro nodo ya recibió y está procesando (eso es el buzón del hub), y no reintenta un pedido que el otro nodo rechazó.
La cola guarda el contenido del pedido sin sellar en la base local del nodo, igual que el resto de los datos de la institución.

### 5.2 Malla: cada nodo es hub, puente, emisor y receptor

La colmena no depende de un punto único. Los mismos nodos la sostienen.

**Hub propio.** Cada nodo ofrece en `<su dirección>/colmena-hub/index.php` el mismo servicio que el hub central (§6: `estado`, `directorio`, `enviar`, `buzon`, `ack`), con tres diferencias:

- **Autorización:** solo atiende a los nodos con **vínculo activo y vigente**. No hay registro ni código: el vínculo es la autorización.
- **Firma del pedido:** agrega la cabecera `X-Colmena-Hub: <id del hub>` y firma `"MÉTODO\nr\nts\nnonce\nsha256(cuerpo)\n<id del hub>"`. Un pedido firmado para un hub no sirve en otro.
- **Respuestas:** `enviar` devuelve `entrega`: `buzon` (el destino escucha en ese hub), `puente` (se reenvió), `espera` (quedó guardado hasta que el destino aparezca) o `directo` (era para el dueño del hub). `buzon` devuelve además `malla: {oyentes, alcanza}`: quiénes escuchan ahí y a quiénes llega ese nodo de primera mano.

El buzón guarda hasta 500 sobres por destinatario durante 72 horas. Las esperas largas tienen cupo (`cupo_espera`, 4 por defecto: cada una ocupa un hilo de PHP); al resto se le responde en el acto con `reintentar_en: 3`.

**Varios hubs a la vez.** La escucha del worker mantiene un pedido abierto con el hub central y con el hub de cada nodo vinculado que alcanza, todos al mismo tiempo. Lo que llega por cualquiera se procesa en el acto, y la respuesta vuelve por donde llegó el pedido.

**Cómo se conocen los caminos.**

- **Sondeo** (cada 5 minutos, y enseguida cuando cambia algo): busca a cada nodo vinculado en sus direcciones conocidas (`GET /api/colmena/identidad`, que tiene que devolver el mismo identificador y la misma clave del vínculo) y mira si su hub propio está encendido. Una dirección que no responde se prueba cada vez menos (30 minutos, después 6 horas).
- **Anuncio:** cada nodo le cuenta a sus vinculados sus direcciones, si su hub está encendido, su modo de red y a qué nodos llega. Lo manda cuando algo cambia y cada 30 minutos.
- **Medición:** cada 5 minutos sale un `ping` por cada camino. El `pong` vuelve con el `id` del ping y deja la demora de ida y vuelta (promedio móvil). Hasta que hay mediciones se usa un orden supuesto: directo, hub propio, hub de otro nodo, hub central, puente.

**Cómo se elige.** El directo, si anda, va siempre primero: no tiene intermediarios y responde en el acto. Los demás se ordenan por demora medida y se usa el primero. Si falla, se pasa al siguiente y ese camino queda en pausa (10 s, 20 s, … hasta 5 minutos). Si un hub avisa que el destino no está escuchando ahí, el sobre queda en ese buzón pero se sigue probando con los demás: una entrega repetida se descarta sola (`nucleo_sobre_visto`). Si no queda ningún camino, el envío va a la cola de salida (§5.1).

**Puente.** Un nodo reenvía un sobre ajeno con estas reglas:

1. tiene vínculo activo y vigente **con el origen y con el destino**;
2. la firma del origen es válida (el puente la verifica, pero no puede abrir la caja);
3. cada sobre se encamina una sola vez (`nucleo_puente_visto`);
4. usa solo caminos que conoce de primera mano: el buzón de su hub, la dirección directa del destino o el hub central (en ese caso, adentro de un `relevo`).

Hay, entonces, **un solo puente** entre origen y destino. Las cadenas más largas no están en esta versión. La respuesta vuelve por el mismo puente.

**Interruptores** (Colmena › Red, o `PUT /api/colmena/config {malla: {hub_propio, modo}}`):

| Interruptor | Qué hace |
|---|---|
| Hub propio encendido / apagado | Apagado, `/colmena-hub/` responde 503 y el nodo no guarda ni reenvía nada para otros. Sigue en la colmena usando los caminos de los demás |
| Modo `malla` | Todos los caminos |
| Modo `central` | Solo el hub central: `POST /api/colmena/sobre` responde 503, no usa ni ofrece hubs de nodos. Puede usar puentes, porque el `relevo` viaja por el hub central |
| Modo `aislado` | Sin tráfico de colmena: no escucha, no recibe (503) y no muestra su identidad. Lo que sale espera en la cola, sin contar intentos, hasta que el administrador lo reconecte. Antes de cortar, avisa a sus vinculados |

`via = hub` en un vínculo significa «a ese nodo, solo por terceros»: ni directo ni por su hub propio.

**Estado y diagnóstico:** `GET /api/colmena/malla` (hub propio, hubs alcanzados, caminos por vínculo con su demora) y `POST /api/colmena/malla/sondear` (vuelve a buscar y a medir ya). «Probar camino» incluye el modo de red, el hub propio, el hub de cada nodo y el camino preferido de cada vínculo.

**Límite físico.** Un nodo detrás de un router solo puede ser hub de quienes llegan a él: los de su misma red, o cualquiera si tiene dirección pública (`url_publica`). Dos edificios sin dirección pública se encuentran en el hub central. Alcanza con que **uno** de los dos nodos llegue al otro: el que llega escucha en el hub del otro y por ahí pasa el tráfico en los dos sentidos.

**Lo que ve cada uno.** Un hub o un puente ve quién le escribe a quién, cuándo y el tamaño; no ve el contenido. El anuncio le muestra a cada vinculado con qué otros nodos está vinculado este.

## 6. Hub central de relevo (`hub/index.php`)

<sub>🎓 [Lección: conectá tu hospital con otros (la colmena) →](https://iproy.com.ar/pim/aprende/federar-hospitales)</sub>

Es un buzón de guardar y reenviar para nodos detrás de NAT, sin IP fija ni puertos abiertos. Se instala en cualquier hosting con PHP 8.1 o superior, PDO SQLite y sodium.

| Pedido | Descripción |
|---|---|
| `GET ?r=estado` | Público: `{servicio: "colmena-hub", protocolo: "colmena/1", version, hora, espera_max}` |
| `POST ?r=registrar` | `{node_id, firma_pub, caja_pub, pim_jwk, nombre, producto, codigo}`. Prueba de posesión: el pedido va firmado con la clave que se registra. TOFU: la clave de un nodo no se puede reemplazar |
| `GET ?r=directorio&id=XYZ` | Claves públicas de un nodo, para vincularse por Internet. Hay que comparar la huella igual |
| `POST ?r=enviar` | `{sobre}`. El hub verifica que `de` sea quien pide y que la firma del sobre sea válida. Límites de tamaño y de pendientes. Desde la 1.2.0 responde `escucha`: segundos desde el último pedido del destinatario (menos de 45 = está escuchando) |
| `GET ?r=buzon[&espera=N]` | Hasta 100 sobres propios. Con `espera` (1.1.0) el pedido queda abierto hasta N s (tope `espera_max`, 25 por defecto) y responde apenas hay sobres |
| `POST ?r=ack` | `{nros}`: borra los sobres ya retirados |

- **Autenticación de cada pedido:** `X-Colmena-Nodo`, `X-Colmena-Ts` (±5 min), `X-Colmena-Nonce` (hexadecimal aleatorio) y `X-Colmena-Firma`, que es la firma ed25519 de `"MÉTODO\nr\nts\nnonce\nsha256(cuerpo)"`.
- Cada firma se acepta una sola vez y hay un límite de pedidos por minuto.
- **Lo que el hub ve:** quién le escribe a quién, cuándo y el tamaño. **Lo que no ve:** el contenido.
- Si el hub cae o miente, solo puede demorar o descartar sobres. El destinatario detecta cualquier alteración.
- **Mantenimiento (1.2.0):** con `'mantenimiento' => true` en `config.php` el hub responde 503 a todo. Los nodos siguen por sus otros caminos (§5.2) y lo que no tenga camino espera en la cola de cada nodo.
- El hub central es un camino más de la malla: el que usan los nodos que no se alcanzan entre sí.

## 7. Compatibilidad

<sub>🎓 [Lección: conectá tu hospital con otros (la colmena) →](https://iproy.com.ar/pim/aprende/federar-hospitales)</sub>

`colmena/1` solo se amplía de forma compatible: tipos nuevos que un nodo viejo ignora, o campos opcionales nuevos. Los tipos `relevo` y `anuncio` de la malla son de esa clase: un nodo anterior los rechaza por tipo desconocido y sigue funcionando por los caminos de siempre (directo y hub central). Un cambio incompatible se publica como `colmena/2` y, durante la transición, los nodos aceptan las dos versiones. La ruta neutral `/colmena/ping?id=1` sigue respondiendo para el descubrimiento en la LAN que usaban las versiones anteriores del portable.


---

<sub>🎓 ¿Algo no quedó claro? [Preguntá en el foro de la capacitación PIM](https://iproy.com.ar/pim/foro) · [Dejá una propuesta de mejora](https://iproy.com.ar/pim/resenas) · Repositorio: https://github.com/iproy-ar/iproy</sub>
