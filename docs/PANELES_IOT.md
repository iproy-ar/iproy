<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Paneles IoT y fuentes de datos (Ola 4)

Esta guía explica cómo llegan al nodo los datos que ya juntan otros sistemas y cómo se muestran en **Paneles IoT**.
Los datos de cada institución (direcciones, códigos de activo, perfiles y organizaciones) **no van en el repositorio**:
se cargan como paquetes JSON desde Configuración.

## 1. Cómo viajan los datos

```
sistema externo (HTTP/JSON, solo lectura)
   └─ fuentes ──▶ telemetria/medicion ──▶ telemetria (último valor, serie, PIM por excepción) ──▶ telemetria/valor ──▶ paneles, alarmas
   └─ fuentes ──▶ telemetria/historial (a pedido) ──▶ telemetria/serie ──▶ paneles (sin alarmas ni PIM)
   └─ fuentes ──▶ equipos/evento ──▶ paneles (eventos y alarmas del equipo, con inicio y fin)
```

- **Las fuentes nunca escriben en el origen.** Leen una dirección cada tanto (`cada_s`, desde 15 s).
- **Una lectura repetida no se publica dos veces:** se recuerda la última marca de tiempo de cada señal. Un evento se vuelve a avisar solo si cambia (por ejemplo, cuando termina).
- **El historial se trae a pedido** («Traer historial», hasta `max_dias`). Va en lote y no dispara alarmas ni notificaciones. Repetirlo no duplica: se descartan los momentos que ya están.

## 2. Fuentes de datos (Configuración › Fuentes de datos)

Cada fuente tiene:

- **una dirección**: las claves en la dirección (`token`, `clave`, `key`…) se muestran ocultas;
- **reglas** que dicen qué es cada dato;
- opcionalmente, **un historial**.

Se pueden «Probar» sin publicar nada, «Leer ahora», pausar y exportar o importar como paquete (`iproy-fuentes/1`).

| Regla | Para respuestas como | Campos principales |
|---|---|---|
| `objeto` | `{"ultimo": {"ts": …, "v_l1n": 230}}` | `ruta`, `activo`, `ts` o `ts_raiz`, `campos` (`"*"` o mapa), `excluir`, `unidades`, `etiquetas`, `escalas` |
| `lista` | equipos con sus puntos: `[{"c": "UTA-01", "p": [[id, nombre, valor, unidad]]}]` | `ruta`, `activo_de`, `activo_mapa`, `sin_mapa`, `puntos_ruta`, índices `punto`/`etiqueta`/`valor`/`unidad`, `ts_raiz`, `punto_mapa`, `solo_mapa` |
| `filas` | una fila por momento | `ruta`, `ts`, `activo`, `campos` |
| `series` | `{"clave": [[ts, valor], …]}` | `ruta`, `activo`, `campos` |
| `columnas` | `{"t": [ts…], "k": {"clave": [valor…]}}` | `ruta_ts`, `ruta`, `activo`, `campos` |
| `eventos` | eventos y alarmas con inicio y fin | `ruta`, `id`, `ts`, `fin`, `mensaje`, `categoria`, `alarma_si`, `activo` |

- **Activo:** el código de inventario PIM, con o sin la sigla del establecimiento.
- **Punto:** se normaliza a mayúsculas con guiones (`v_l1n` → `V-L1N`, `ai/1` → `AI-1`).
- **Momentos:** epoch en milisegundos o segundos, o texto de fecha.

**Historial:**

```json
{"url": "…&desde={desde_ms}&hasta={hasta_ms}", "tramo_horas": 24, "max_dias": 32,
 "variables": {"equipo": [{"valor": "UTA-01", "codigo": "CLIMA-UTA-01"}]}, "reglas": [ … ]}
```

- **Marcadores de la dirección:** `{desde_ms}`, `{hasta_ms}`, `{desde_s}`, `{hasta_s}`, `{desde_fecha}`, `{hasta_fecha}`, `{horas}`, `{dias}` y cada variable (`{equipo}`).
- **Variables con más datos:** si la variable trae otros campos, como `codigo`, se usan como `{equipo_codigo}`.
- **Valores con espacios o símbolos:** se codifican solos en la dirección.

**Permisos:**

- `fuentes.ver` y `fuentes.configurar` (GM).
- En la interfaz, la solapa está en Configuración (administrador).

## 3. Paneles IoT

### Portada y áreas

La portada muestra una tarjeta por **área** (Quirófanos, Electricidad, Ósmosis…). Cada tarjeta indica:

- cuántas señales hay en alarma, fuera de rango, sin datos o normales;
- las alarmas activas que informan los equipos.

Los indicadores de arriba filtran las áreas.

**Quién ve un área.** En Configuración › Paneles IoT, cada área tiene **reglas**:

- Una regla nombra uno o más de: `rol`, `perfil` (módulo widgets), `organizacion`, `usuario`.
- La regla se cumple si se cumple **todo** lo que nombra.
- Alcanza con que se cumpla **una** de las reglas del área.
- Un área sin reglas la ve todo el que tiene `paneles.ver`.
- Quien tiene `paneles.configurar` ve todas.
- Un área oculta no la ve nadie más que quien configura.

Ejemplo: `[{"perfil": "empresas", "organizacion": "Empresa A"}, {"usuario": "jefe.mant"}]`.

Dentro del área, cada panel puede además limitarse por roles o usuarios (como antes).

### Tipos de panel y su configuración («vista», JSON)

| Tipo | Qué muestra | `vista` |
|---|---|---|
| `tarjetas` | señales sueltas con estado, curva de 24 h e historial | (no lleva) |
| `energia` | estado general con semáforo, tarjetas con mínimo y máximo del período (al tocarlos se marca el momento en la curva), curvas elegibles, períodos de 1 h a 30 días o entre fechas, informe imprimible o Word | `activo`, `curvas` [{id, titulo, unidad, puntos}], `referencias` {PUNTO: {titulo, verde: [a, b], amarillo: [a, b], unidad, decimales}}, `energia` {potencia, unidad, factor_potencia, contador} |
| `equipos` | grilla por sector; el detalle se abre debajo, sin agrandar las otras tarjetas: tendencias, variables y análisis de fallas | `grupos` [{titulo, activos, nota}] o `activos`, `nombres`, `tarjeta` [puntos], `etiquetas`, `curvas`, `rangos` {PUNTO: [a, b]}, `analisis` |
| `sinoptico` | esquema con valores en vivo, variables, tendencias, eventos y alarmas | `activo`, `plantilla` (`osmosis` o `lista`), `slots` {casillero: PUNTO}, `estados` {valor: texto}, `curvas`, `rangos` |
| `eventos` | indicadores que filtran (alarmas activas, alarmas, eventos), período, búsqueda, categoría, por día, ranking y lista con CSV | `activos` (vacío = todos) |

**Análisis de fallas** (`analisis`, tipo equipos):

- `{"tipo": "desvio", "real": "AI-1", "consigna": "AV-2", "tolerancia": 1.5}`: tiempo fuera de la consigna, peor desvío y promedio.
- `{"tipo": "saturacion", "punto": "AO-1", "mayor_que": 95}`: tiempo saturado y racha más larga.
- `{"tipo": "rango", "punto": "…", "minimo": …, "maximo": …}`.

Tocar un indicador marca en la curva el momento del peor desvío o de la racha más larga.

**Casilleros de la plantilla `osmosis`:**

- `estado`;
- `cloro`;
- `c_entrada`, `c_salida`;
- `q_entrada`, `q_permeado`, `q_concentrado`;
- `alimentacion`, `membrana`, `concentrado`;
- `rechazo`, `recuperacion`.

**Informe de energía:**

- Cada referencia se evalúa por intervalos del período: «Normal» si el 95 % del tiempo estuvo en verde; «Atención» si el 95 % estuvo en verde o amarillo; si no, «Fuera de referencia».
- Energía consumida: si hay `contador` (kWh acumulados) que cubre el período, sale de su diferencia; si no, de integrar la potencia. Se informa la cobertura de datos.

### Historial

- **Dos niveles:**
  - `pan_serie`: detalle fino cada `paso_s` (300 s) durante `detalle_dias` (35);
  - `pan_hora`: por hora durante `retener_dias` (400).
- **Elección automática:** según el período pedido se usa uno u otro.
- **Depuración:** la tarea `depurar` borra lo vencido, también los eventos.

### Paquete (Configuración › Paneles IoT)

`iproy-paneles/1` lleva las áreas con sus reglas y los paneles con su vista y sus señales. Las señales van **por código de activo**, para llevarlo a otro nodo.

- **Al importar:** lo que tiene la misma clave se actualiza.
- **Señales que todavía no informaron:** se saltean con aviso. Conviene importar las fuentes y leerlas antes.

## 4. Rutas

**Fuentes** (`m/fuentes/`):

- `GET fuentes`, `POST fuentes`, `PUT fuentes/{id}`;
- `POST fuentes/{id}/activa|leer|historial|borrar`;
- `POST probar`;
- `GET exportar`, `POST importar`.

**Paneles** (`m/paneles/`):

- `GET portada`, `GET paneles?area=`, `GET paneles/{id}`;
- `GET curvas/{id}?p=CÓDIGO|PUNTO,…&horas=|desde=&hasta=`;
- `GET eventos/{id}`, `GET informe/{id}`;
- `GET|POST areas`, `PUT areas/{id}`, `POST areas/{id}/borrar`;
- `GET|POST paquete`.

**Núcleo:** `POST docx` ({modelo, archivo}) arma un Word para cualquier módulo.
