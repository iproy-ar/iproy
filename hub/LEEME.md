<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Hub de relevo de la colmena

Conecta por Internet nodos que no tienen IP fija ni puertos abiertos: un hospital, un pañol, la notebook de un contratista. Es **un solo archivo PHP**, que corre en cualquier hosting común con PHP 8.1 o posterior, las extensiones `pdo_sqlite` y `sodium`, y Apache o Nginx.

## Instalación

1. Subí la carpeta `hub/` al hosting (por ejemplo, a `public_html/colmena-hub/`).
2. Copiá `config.ejemplo.php` como `config.php`. Si se puede, poné `dir_datos` **fuera** de `public_html`; si queda adentro, `datos/.htaccess` lo protege en Apache. En Nginx, negá `/datos/`.
3. Si querés que solo se registren tus nodos, definí `codigo_registro` y, opcionalmente, `nodos_permitidos`.
4. Probalo: `https://tu-dominio/colmena-hub/index.php?r=estado` → `{"servicio":"colmena-hub","protocolo":"colmena/1",…}`.
5. En cada nodo: `bash <Prod>-Control.sh colmena hub https://tu-dominio/colmena-hub [código]`.

## Qué puede y qué no puede hacer el hub

- **Puede ver** quién le escribe a quién, cuándo y cuánto.
- **No puede**:
  - leer el contenido, porque va sellado con X25519 para el destinatario;
  - falsificarlo, porque va firmado con ed25519 y el destinatario lo verifica;
  - repetir pedidos, porque cada firma lleva un nonce de un solo uso;
  - reemplazar la clave de un nodo, porque se fija en el primer registro (TOFU).
- **Si se cae:** los nodos siguen trabajando solos. Los sobres esperan (no hay pérdida si el nodo reintenta) y, por la intranet, la colmena funciona directo.

Mantenimiento: los sobres que nadie retira se descartan a los `ttl_dias`. La base es un único archivo SQLite que se puede respaldar copiándolo.
