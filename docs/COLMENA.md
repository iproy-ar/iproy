<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Protocolo colmena/1

Especificación del protocolo con el que se comunican los nodos de cualquier producto derivado de iProy (iProy, Colmen u otros). El nombre del producto es informativo: el protocolo es neutral y tiene versión.

## 1. Identidad del nodo

Cada nodo genera en su primer arranque estas claves, que guarda en `data/nucleo/identidad.json` y `data/nucleo/claves/` con permisos 600:

| Clave | Algoritmo | Uso |
|---|---|---|
| `firma` | ed25519 (libsodium) | Firma de sobres, del diario y de los pedidos al hub |
| `caja` | X25519 (`crypto_box_seal`) | Recibir sobres sellados |
| `colmena` | ECDSA P-384 (ES384) | Aserciones SMART para usar la API PIM de un nodo vinculado |

`GET /api/colmena/identidad` es público y devuelve: `protocolo` (`"colmena/1"`), `node_id`, `nombre`, `producto`, `version`, `nucleo`, `firma_pub`, `caja_pub`, `pim_jwk` (público) y `url`.

`huella` son los primeros 80 bits del SHA-256 de `firma_pub`, en 5 grupos de 4 caracteres hexadecimales. Los administradores la comparan por teléfono o en persona antes de aceptar un vínculo, igual que los «números de seguridad» de las mensajerías cifradas.

## 2. Sobre

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
  - `pong`
- **Validación al recibir:**
  1. el destinatario es este nodo;
  2. `ts` no es más viejo que 8 días ni más de 10 minutos en el futuro;
  3. la firma corresponde a la clave del vínculo (en una solicitud, a la clave que viene en ella, con TOFU);
  4. el `id` no se procesó antes (`nucleo_sobre_visto`, 9 días);
  5. si trae `caja`, se abre con la clave X25519 propia.

## 3. Vínculo: «el vínculo es la autorización»

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

El nodo A arma `{rpc, metodo, ruta, cuerpo, if_match, asercion}`, lo sella para B y lo envía.

- `asercion` es un JWT ES384 con `iss = sub = colmena-<a>` y `aud = http://nodo-<b>.colmena/pim/auth/token`.
- B controla que `aud` lo nombre a él y canjea la aserción en **su** Servidor PIM. Lo hace por 127.0.0.1, con `Host` igual al de `aud`.
- La operación se ejecuta con el rol del vínculo. La respuesta (`rpc-resp`, sellada para A) trae `http`, `cuerpo` y las cabeceras `etag`, `location`, `x-pim-task` y `last-modified`.
- **Por la intranet** la respuesta llega en el mismo pedido HTTP. **Por el hub** es asíncrona: `nucleo_rpc` queda `enviado` hasta que llega la respuesta. Si pasan 15 minutos, queda `vencido`.
- Todo lo que el nodo A hace en el nodo B queda en el Provenance de B con `colmena-<a>` como autor.

## 5. Transporte

| Vía | Cómo | Cuándo |
|---|---|---|
| Intranet | `POST <url>/api/colmena/sobre` con `{sobre}`. La respuesta trae `{ok, sobre}` | El vínculo tiene `url` y `via = auto` |
| Hub | `enviar` al hub y el destinatario lo retira con `buzon` en su tick (cada 15 s) | Si la intranet falla, o si `via = hub` |

**Proxy de salida.** Las redes con proxy se configuran en el núcleo (`proxy` en `data/nucleo/config.json`), en el panel de Redes o con `HTTPS_PROXY`. El tráfico hacia la LAN, hacia el propio equipo y hacia lo que figure en `NO_PROXY` va siempre directo.

La seguridad no depende de TLS: los sobres están firmados y sellados. TLS (CA propia del nodo) es una defensa adicional.

## 6. Hub de relevo (`hub/index.php`)

Es un buzón de guardar y reenviar para nodos detrás de NAT, sin IP fija ni puertos abiertos. Se instala en cualquier hosting con PHP 8.1 o superior, PDO SQLite y sodium.

| Pedido | Descripción |
|---|---|
| `GET ?r=estado` | Público: `{servicio: "colmena-hub", protocolo: "colmena/1", version}` |
| `POST ?r=registrar` | `{node_id, firma_pub, caja_pub, pim_jwk, nombre, producto, codigo}`. Prueba de posesión: el pedido va firmado con la clave que se registra. TOFU: la clave de un nodo no se puede reemplazar |
| `GET ?r=directorio&id=XYZ` | Claves públicas de un nodo, para vincularse por Internet. Hay que comparar la huella igual |
| `POST ?r=enviar` | `{sobre}`. El hub verifica que `de` sea quien pide y que la firma del sobre sea válida. Límites de tamaño y de pendientes |
| `GET ?r=buzon` | Hasta 100 sobres propios |
| `POST ?r=ack` | `{nros}`: borra los sobres ya retirados |

- **Autenticación de cada pedido:** `X-Colmena-Nodo`, `X-Colmena-Ts` (±5 min), `X-Colmena-Nonce` (hexadecimal aleatorio) y `X-Colmena-Firma`, que es la firma ed25519 de `"MÉTODO\nr\nts\nnonce\nsha256(cuerpo)"`.
- Cada firma se acepta una sola vez y hay un límite de pedidos por minuto.
- **Lo que el hub ve:** quién le escribe a quién, cuándo y el tamaño. **Lo que no ve:** el contenido.
- Si el hub cae o miente, solo puede demorar o descartar sobres. El destinatario detecta cualquier alteración.

## 7. Compatibilidad

`colmena/1` solo se amplía de forma compatible: tipos nuevos que un nodo viejo ignora, o campos opcionales nuevos. Un cambio incompatible se publica como `colmena/2` y, durante la transición, los nodos aceptan las dos versiones. La ruta neutral `/colmena/ping?id=1` sigue respondiendo para el descubrimiento en la LAN que usaban las versiones anteriores del portable.
