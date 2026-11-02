<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Incubadora de software

Sirve para que una persona o una institución tenga **en minutos** un sistema de gestión propio que funcione de verdad, esté estandarizado y sea libre. No es una plantilla para copiar y pegar: es la misma distribución base, probada, con otro nombre, otra imagen y los módulos que elijas.

Necesita PHP 8.1 o posterior: `php` del sistema, o `runtime/linux-x64/frankenphp php-cli`.

```bash
php incubar.php plantillas
php incubar.php nuevo --nombre Colmen --sigla CLM --color "#0277bd" --plantilla gestion-mantenimiento --destino ../../Colmen \
                      --descripcion "Gestión de mantenimiento de la red de centros de salud" --fuente https://ejemplo.org/colmen.git
php incubar.php modulo calibraciones --nombre "Calibraciones" --campos "instrumento:texto!,fecha:fecha!,resultado:opcion(apto|no-apto)!" \
                       --destino ../../Colmen/Colmen-Portable/modulos --ver GM,EJ,CI --editar GM
php incubar.php verificar ../../Colmen/Colmen-Portable
```

## `nuevo`: producto derivado

- Copia `../nodo` y renombra el producto en contenido, archivos y carpetas, respetando mayúsculas: `iProy→Colmen`, `IPROY→COLMEN`, `iproy→colmen`. Eso alcanza al lanzador, las variables, las bases de datos, el espacio de nombres PHP y el encabezado del worker.
- **No renombra** (está protegido):
  - el espacio de nombres canónico del estándar PIM (`https://iproy.com.ar/pim` y `/fhir`);
  - los avisos de copyright («Proyecto iProy y colaboradores»);
  - el protocolo `colmena/1`.

  Gracias a eso, el derivado se vincula con cualquier otro nodo y cumple la AGPL.
- Nunca copia `data/`, `runtime/` ni `descargas/`, ni sigue enlaces simbólicos.
- **Plantillas:**
  - `gestion-mantenimiento`: todo;
  - `mantenimiento-basico`: pim y preventivos;
  - `monitoreo-industrial`: pim, telemetría, alarmas, mqtt y modbus;
  - `minima`: pim.

  Los módulos que no están en la plantilla quedan en `modulos.disponibles/`. Para activarlos, se mueven a `modulos/`.
- Genera:
  - `producto.json` (nombre, versión 0.1.0, base, colores y fuente);
  - el logo en un hexágono con la sigla (`logo.png` y `logo-512.png`);
  - `AVISO_DERIVADO.md` (AGPL §5);
  - `INCUBACION.json`, con los reemplazos contados;
  - `LEEME_<Nombre>.md`.
- Al final ejecuta `verificar`.

## `modulo`: módulo nuevo

- **Tipos de campo:** `texto`, `largo`, `numero`, `entero`, `fecha`, `fechahora`, `si-no`, `opcion(a|b|c)` y `activo`. Un `!` al final lo hace obligatorio.
- **Genera:**
  - `modulo.json`, con capacidades `<id>.ver` y `<id>.editar`;
  - la migración SQL;
  - la validación (`lib.php`);
  - la API REST (`/api/m/<id>/registros`), donde DELETE es una baja lógica;
  - los asientos en el diario (`<id>.alta|cambio|baja`);
  - los eventos en el bus (`<id>/creado|modificado|baja`);
  - la pestaña de la aplicación (`ui.js`);
  - el LEEME.
- Para activarlo: `…-Control.sh nucleo arranque` o reiniciar el nodo.

## `verificar`

Controla lo siguiente:

- licencias libres (SPDX) en cada manifiesto;
- cabeceras SPDX en el núcleo y los módulos;
- ausencia de claves privadas, contraseñas escritas en el código y nombres de instituciones;
- que no se haya copiado ninguna carpeta `data/`.

Si encuentra algo, termina con código 2: sirve como control antes de publicar o en integración continua.
