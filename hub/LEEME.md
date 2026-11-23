<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Hub de relevo de la colmena

> 🎓 **Capacitación PIM:** este documento tiene su versión guiada, con diagramas, ejemplos y foro, en [iproy.com.ar/pim](https://iproy.com.ar/pim/) (en particular [esta página](https://iproy.com.ar/pim/aprende/federar-hospitales)). El código fuente vive en [github.com/iproy-ar/iproy](https://github.com/iproy-ar/iproy).


Conecta por Internet nodos que no tienen IP fija ni puertos abiertos: un hospital, un pañol, la notebook de un contratista. Es **un solo archivo PHP**, que corre en cualquier hosting común con PHP 8.1 o posterior, las extensiones `pdo_sqlite` y `sodium`, y Apache o Nginx.

## Instalación

<sub>🎓 [Tutorial: instalar un sistema PIM en Linux y Windows →](https://iproy.com.ar/pim/instalar)</sub>

1. Subí la carpeta `hub/` al hosting (por ejemplo, a `public_html/colmena-hub/`).
2. Copiá `config.ejemplo.php` como `config.php`. Si se puede, poné `dir_datos` **fuera** de `public_html`; si queda adentro, `datos/.htaccess` lo protege en Apache. En Nginx, negá `/datos/`.
3. Si querés que solo se registren tus nodos, definí `codigo_registro` y, opcionalmente, `nodos_permitidos`.
4. Probalo: `https://tu-dominio/colmena-hub/index.php?r=estado` → `{"servicio":"colmena-hub","protocolo":"colmena/1",…}`.
5. En cada nodo: `bash <Prod>-Control.sh colmena hub https://tu-dominio/colmena-hub [código]`.

### Actualizar un hub 1.0.0 o 1.1.0 a 1.2.0 (espera larga, hora y malla)

- Se reemplaza **solo** `index.php` (y `config.ejemplo.php`). `config.php` y la carpeta `datos/` no se tocan: los nodos registrados y los sobres pendientes quedan como están.
- Los nodos viejos siguen funcionando igual (miran cada 15 s). Los nodos 1.7.0 con la escucha reciben los sobres en 1-2 s.
- `espera_max` (25 s por defecto) es cuánto puede quedar abierto un pedido de buzón. Cada nodo que escucha ocupa un proceso PHP mientras espera: si el hosting limita los procesos simultáneos, bajalo (por ejemplo a 10) o ponelo en 0 para apagar la espera.
- **1.2.0:** `enviar` le dice al remitente hace cuánto pidió algo el destinatario (`escucha`). Con eso un nodo sabe si lo están esperando ahí o si le conviene otro camino de la malla.
- **1.2.0:** `'mantenimiento' => true` hace que el hub responda 503 a todo mientras trabajás en el hosting. Los nodos siguen por sus otros caminos y lo que no tenga camino espera en la cola de cada nodo.
- `desfase_prueba_s` y `demora_prueba_ms` son solo para las pruebas automáticas: dejalos en 0.

### El hub central y la malla

Desde iProy 1.7.0 cada nodo es además hub de sus vinculados (`<nodo>/colmena-hub/`) y elige el camino más rápido. Este hub pasa a ser el **hub central**: el punto de encuentro de los nodos que no se alcanzan entre sí (otro edificio, otra ciudad, detrás de un router). Los nodos de una misma red siguen trabajando entre ellos aunque este hub no esté.

## Qué puede y qué no puede hacer el hub

<sub>🎓 [Lección: conectá tu hospital con otros (la colmena) →](https://iproy.com.ar/pim/aprende/federar-hospitales)</sub>

- **Puede ver** quién le escribe a quién, cuándo y cuánto.
- **No puede**:
  - leer el contenido, porque va sellado con X25519 para el destinatario;
  - falsificarlo, porque va firmado con ed25519 y el destinatario lo verifica;
  - repetir pedidos, porque cada firma lleva un nonce de un solo uso;
  - reemplazar la clave de un nodo, porque se fija en el primer registro (TOFU).
- **Si se cae:** los nodos siguen trabajando solos. Los que se alcanzan entre sí siguen por la malla (directo, por el hub de cualquiera de ellos o con uno de puente). Lo demás espera en la cola de salida de cada nodo y sale cuando el hub vuelve.

Mantenimiento: los sobres que nadie retira se descartan a los `ttl_dias`. La base es un único archivo SQLite que se puede respaldar copiándolo.


---

<sub>🎓 ¿Algo no quedó claro? [Preguntá en el foro de la capacitación PIM](https://iproy.com.ar/pim/foro) · [Dejá una propuesta de mejora](https://iproy.com.ar/pim/resenas) · Repositorio: https://github.com/iproy-ar/iproy</sub>
