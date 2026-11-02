<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Cómo escribir un módulo

Hay dos caminos:

- **Rápido.** `php incubadora/incubar.php modulo <id> --nombre "…" --campos "campo:tipo[!],…"` genera un módulo completo que ya funciona: tabla, validación, API CRUD con baja lógica, interfaz, diario y bus. Después se edita como cualquier código propio.
- **A mano.** Seguí esta guía.

## 1. Estructura

```
modulos/<id>/
├── modulo.json        manifiesto (obligatorio)
├── lib.php            funciones del módulo, en el espacio de nombres Modulos\<Id> (opcional)
├── rutas.php          API: /api/m/<id>/… (opcional)
├── tareas/*.php       tareas del planificador (opcional)
├── eventos*.php       manejadores del bus (opcional)
├── sql/NNN_*.sql      migraciones: se aplican una sola vez y en orden (opcional)
└── ui.js              pestaña de la aplicación web (opcional)
```

## 2. Manifiesto

```json
{
  "id": "calibraciones",                 // = nombre de la carpeta; minúsculas, números y _
  "nombre": "Calibraciones de instrumentos",
  "version": "1.0.0",                    // semver
  "abi": 1,                              // ABI del núcleo; si no coincide, no se carga
  "estabilidad": "estable",              // estable | pruebas | obsoleto | retirado
  "licencia": "AGPL-3.0-or-later",       // SPDX; si no es libre, el nodo queda «contaminado»
  "descripcion": "…",
  "requiere": ["pim", "telemetria>=1.0"],
  "lib": "lib.php",
  "rutas": "rutas.php",
  "capacidades": { "calibraciones.ver": ["GM","EJ","CI"], "calibraciones.editar": ["GM"] },
  "tareas": [ { "id": "vencimientos", "cada": 3600, "archivo": "tareas/vencimientos.php" } ],
  "suscribe": { "pim/Device/update": "eventos.php", "telemetria/valor": "eventos_valor.php" },
  "ui": { "script": "ui.js", "orden": 60 },
  "config_defecto": { "aviso_dias": 15 },
  "activo_por_defecto": true
}
```

El núcleo valida el manifiesto **sin ejecutar código**: id, versión, ABI, estabilidad, licencia y que existan los archivos declarados. Si algo falla, el módulo queda en `error` con el motivo, visible en Sistema y en `/api/proc/modulos`.

## 3. Puntos de enganche

**Rutas** (`rutas.php`) devuelve una lista de `[MÉTODO, patrón, capacidad|null, función]`:

```php
return [
    ['GET',  '/registros',      'calibraciones.ver',    fn(array $c) => ['registros' => filas('SELECT …')]],
    ['POST', '/registros/{id}', 'calibraciones.editar', function (array $c) { …; return ['_http' => 201, 'registro' => $r]; }],
];
```

La función recibe `$c = {modulo, cfg, nodo, usuario, params, cuerpo, query}` y devuelve un arreglo, que sale como JSON con `ok: true`. Para un error se lanza `Nucleo\Fallo(http, mensaje)`.

**Tareas** (`tareas/x.php`) devuelven `fn(array $ctx): string`. El texto queda como resultado en `/api/proc/tareas`. La tarea corre con un bloqueo propio: nunca dos a la vez.

**Eventos** (`eventos.php`) devuelven `fn(array $evento, array $ctx): void`. `$evento` trae `{id, ts, tema, origen, datos}`. Si el manejador lanza una excepción, el evento se reintenta en el próximo tick. Después de 5 fallas seguidas se descarta y queda asentado en el diario.

> Los archivos de rutas, tareas y eventos devuelven **closures**. Las funciones con nombre van en `lib.php`, que se carga una sola vez; si no, un segundo `require` las redeclara.

**Interfaz** (`ui.js`) registra pestañas y tarjetas del Tablero con la API `window.Nucleo`:

```js
(() => {
  const { h, api, pim, tabla, form, panel, aviso, puede, fecha } = Nucleo;
  Nucleo.tablero(async (el) => { el.innerHTML = '<div class="tarjeta">…</div>'; });
  Nucleo.pestana({ id: 'calibraciones', titulo: 'Calibraciones', orden: 60, cap: 'calibraciones.ver', render: async (el) => { … } });
})();
```

`api(metodo, ruta, cuerpo)` llama a `/api/…` con la sesión y el encabezado anti-CSRF. `pim(metodo, ruta, cuerpo, cab)` llama a la API PIM con el rol del usuario.

## 4. Funciones del núcleo disponibles

Todas están en el espacio `Nucleo\`:

| Función | Uso |
|---|---|
| `q()`, `fila()`, `filas()`, `valor()`, `pdo()` | Base del nodo (tablas propias del módulo) |
| `publicar(tema, datos)`, `eventos(desde, patrón)` | Bus |
| `asentar(tipo, detalle, objeto)` | Diario firmado |
| `pim(metodo, ruta, cuerpo, ['rol' => 'GM'\|'FT'\|…])`, `pim_lista()` | Servidor PIM por la API |
| `cfg_modulo(id)`, `estado(clave, ?nuevo)` | Configuración (de `data/`) y estado chico persistente |
| `exigir(capacidad)`, `puede(capacidad)` | Permisos |
| `evento_remoto(par, tema, datos)`, `rpc(par, …)` | Colmena |

## 5. Reglas

1. No leas ni escribas tablas de otro módulo ni de PIM: usá su API o el bus.
2. Las tablas llevan el prefijo del módulo y se crean **solo** en migraciones. El DDL dentro de una transacción la confirma sola (lección L-319).
3. Nada institucional en el módulo. Lo que es de cada nodo va en `config_defecto` y se sobrescribe en `data/nucleo/modulos/<id>.json`.
4. Toda acción que importe para una auditoría se asienta en el diario. Todo cambio que otros puedan necesitar se publica en el bus.
5. Para subir de `pruebas` a `estable` hacen falta pruebas automáticas y un año sin cambios incompatibles.
