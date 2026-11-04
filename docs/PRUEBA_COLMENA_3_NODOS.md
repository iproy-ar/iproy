<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Plan de prueba: colmena de tres nodos (dos en la misma red y uno en otra institución)

<sub>🎓 [Lección: conectá tu institución con otras (la colmena) →](https://iproy.com.ar/pim/aprende/federar-hospitales) · [Protocolo colmena/1](COLMENA.md)</sub>

Objetivo: comprobar la interoperabilidad PIM entre nodos reales, en tres situaciones a la vez: **intranet** (A ↔ B), **Internet por hub de relevo** (A ↔ C y B ↔ C) y **productos distintos** (al menos uno incubado). Es la primera prueba fuera del laboratorio: lo que falle acá se registra y se corrige antes de la 1.6.

## Qué se necesita

| Nodo | Dónde | Producto sugerido | Vista | Alcance |
|---|---|---|---|---|
| **A** | PC 1 de tu institución (Linux) | iProy | menú | `lan` |
| **B** | PC 2 de tu institución, misma red (Windows) | producto incubado (p. ej. NodOvi) | widgets | `lan` |
| **C** | PC de la otra institución | producto incubado con su nombre | a elección | `local` alcanza (sale por el hub) |
| **Hub** | Hosting PHP del proyecto: `https://iproy.com.ar/colmena-hub/` (instalado y verificado el 04-11-2026 con dos nodos reales a través de Internet) | `hub/index.php` | — | HTTPS |

- Los tres nodos con **iProy 1.5.1 o posterior** (instalador gráfico o actualización). Para actualizar un nodo existente: `stop`, reemplazar `app/`, `modulos/`, `tools/` y los lanzadores, `start`.
- **Hora correcta** en las tres PC (`status` lo muestra). El hub rechaza pedidos con más de 5 minutos de desfase.
- Un canal fuera de banda entre los responsables (teléfono o videollamada) para **comparar las huellas** de las claves al vincular.

## 0. El hub

Ya está instalado en `https://iproy.com.ar/colmena-hub/` con **código de registro**: solo se registran los nodos que lo presenten. El código no está en este repositorio ni en el sitio: lo entrega el administrador del proyecto a cada institución por un canal privado. Comprobación pública: `curl https://iproy.com.ar/colmena-hub/?r=estado` → `{"servicio":"colmena-hub","protocolo":"colmena/1",…}`.

Para instalar otro hub propio: subir la carpeta `hub/`, copiar `config.ejemplo.php` como `config.php` con un `codigo_registro`, y si se puede poner `dir_datos` fuera de la carpeta pública (ver `hub/LEEME.md`).

> El hub no puede leer ni modificar los sobres: solo los guarda hasta que el destinatario los retira. Si cae, los nodos de la misma red siguen hablándose en forma directa.

## 1. Preparación en cada nodo (A, B y C)

```bash
bash <Prod>-Control.sh status          # servicios activos, hora OK
bash <Prod>-Control.sh colmena identidad   # anotar node_id y huella
```

Cargar en cada nodo al menos un local y dos activos reales (Inventario → Ubicaciones, Inventario → Nuevo activo), con IRU cargado en uno de ellos (F, R o categoría NFPA 99, M).

## 2. Intranet: A ↔ B

| Paso | En | Comando o acción | Resultado esperado |
|---|---|---|---|
| 2.1 | A | `colmena vincular https://<IP-de-B>:<puerto+363>` | «solicitud enviada» |
| 2.2 | B | `colmena lista` → ver la solicitud de A; **comparar la huella** por teléfono | huellas iguales |
| 2.3 | B | `colmena aceptar <id-de-A> SO` | vínculo activo, rol SO |
| 2.4 | A y B | `colmena ping <par>` | respuesta firmada en ambos sentidos |
| 2.5 | A | `pim remoto https://<IP-de-B>:<puerto+363>/pim/r4 …` o desde la app: Colmena → B → Inventario | A lee el inventario de B (PIM-1) |
| 2.6 | A | Desde la app, pedir un trabajo sobre un activo de B (rol SO) | En B aparece la solicitud y su orden; la Provenance dice que la originó A (PIM-3) |
| 2.7 | B | Asignar y avanzar esa orden | A la ve cambiar de estado (PIM-4) |

## 3. Internet: C por el hub

| Paso | En | Comando o acción | Resultado esperado |
|---|---|---|---|
| 3.1 | A, B, C | `colmena hub https://iproy.com.ar/colmena-hub <código>` (o Configuración → Colmena → hub) | «registrado» |
| 3.2 | C | `colmena vincular <node_id-de-A>` (por id: lo resuelve el hub) | solicitud en el buzón de A |
| 3.3 | A | `colmena lista` → comparar huella con C por teléfono → `colmena aceptar <id-de-C> CI` | activo, rol CI (solo consulta) |
| 3.4 | C | Colmena → A → Inventario | C lee a A por el hub (RPC asíncrona: hasta 15 s) |
| 3.5 | C | Intentar pedir un trabajo en A | **403**: el rol CI no puede (PIM-3 exige SO) |
| 3.6 | A | `colmena rol <id-de-C> SO` | C ya puede pedir |
| 3.7 | C | Pedir un trabajo en A | Orden en A con Provenance de C |
| 3.8 | B ↔ C | Repetir 3.2 a 3.4 entre B y C | funciona igual con dos productos distintos |

## 4. Fallas provocadas (lo que más enseña)

| Prueba | Cómo | Resultado esperado |
|---|---|---|
| Hub caído | Apagar el hub 10 minutos | A ↔ B siguen; los pedidos a C quedan en cola y se entregan al volver |
| Reloj desfasado | Adelantar 10 minutos la hora de C | El hub rechaza sus pedidos; `status` avisa el desfase; al corregir la hora, todo sigue |
| Vínculo revocado | En A: `colmena revocar <id-de-C>` | C recibe 403 desde el siguiente pedido |
| Huella distinta | Aceptar sin comparar | No hacerlo: anotar como riesgo humano del procedimiento |
| Edición simultánea | A y B editan el mismo activo de B casi al mismo tiempo | El segundo recibe **412** (If-Match) y debe releer |
| Sincronización ITI-91 | `GET Device/_history?_since=<ayer>&_count=5` desde A hacia B | páginas en orden cronológico con `link next` |

## 5. Qué registrar

Por cada paso: fecha y hora, nodo, comando, resultado, y si no coincidió con lo esperado, la salida literal (sin claves ni tokens). Los diarios de los tres nodos (`<Prod>-Control.sh verificar`) tienen que quedar íntegros al final. Todo va a la memoria de aprendizaje del proyecto como «Paso de prueba de colmena», con una lección por cada falla.

## 6. Criterio de éxito

- Las tres tablas (2, 3 y 4) completas, con al menos 2.6, 3.7 y la prueba de hub caído aprobadas.
- Ninguna orden duplicada ni perdida.
- Las Provenance muestran siempre el nodo de origen correcto.
- Lo que falló quedó con su lección y, si es del software, con su corrección en la 1.6.
