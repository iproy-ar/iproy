<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Pruebas

> 🎓 **Capacitación PIM:** este documento tiene su versión guiada, con diagramas, ejemplos y foro, en [iproy.com.ar/pim](https://iproy.com.ar/pim/) (en particular [esta página](https://iproy.com.ar/pim/instalar#comprobar)). El código fuente vive en [github.com/iproy-ar/iproy](https://github.com/iproy-ar/iproy).


| Prueba | Qué necesita | Cómo |
|---|---|---|
| `unitarias.php` | Solo PHP con sodium (y `nodo/conf/pim-guia`) | `php pruebas/unitarias.php` |
| `pim.sh` | Un nodo iniciado | `bash pruebas/pim.sh <nodo>` |
| `negativas.sh` | Un nodo iniciado, `curl` y `openssl` (crea sus clientes SMART de prueba y los borra al terminar) | `bash pruebas/negativas.sh <nodo>` |
| `nucleo16.sh` | Un nodo 1.6.0 iniciado con el módulo `stock` | `bash pruebas/nucleo16.sh <nodo>` |
| `mem.sh` | Un nodo iniciado con el módulo `mem` (la prueba lo habilita en 127.0.0.1) | `bash pruebas/mem.sh <nodo> [puerto-mllp]` |
| `colmena.sh` | Dos nodos iniciados en el mismo equipo (pueden ser de productos distintos) y, opcionalmente, un hub | `bash pruebas/colmena.sh <nodo-A> <nodo-B> [http://127.0.0.1:8070]` |
| `tunel.sh` | Dos nodos iniciados con vínculo activo y un hub central de uso exclusivo (la prueba cambia su `config.php` y lo restaura). Pone los nodos en modo `central` mientras dura | `HUB_DIR=<hub> [OTROS="<nodo-C>"] bash pruebas/tunel.sh <nodo-A> <nodo-B> http://127.0.0.1:8070` |
| `malla.sh` | **Tres** nodos iniciados y un hub central de uso exclusivo. Arma A—B y B—C directos y A—C «solo por terceros» | `HUB_DIR=<hub> bash pruebas/malla.sh <nodo-A> <nodo-B> <nodo-C> http://127.0.0.1:8070` |
| `inventario.sh` | Un nodo iniciado con `pim` y `preventivos` (y `reportes`, si está). Crea dos usuarios y activos con un sufijo al azar; al terminar quedan «cargados por error» | `bash pruebas/inventario.sh <nodo>` |
| `industrial.sh` | Un nodo iniciado; opcionales: `herramientas/simuladores/modbus_sim.py` (pymodbus) y un broker MQTT con `amqtt_pub` | `bash pruebas/industrial.sh <nodo>` |

## Entorno de dos nodos para probar (resumen)

<sub>🎓 [Rutas de aprendizaje por rol →](https://iproy.com.ar/pim/aprende)</sub>

```bash
php incubadora/incubar.php nuevo --nombre Colmen --version 0.2.0 --destino /tmp/colmen
# copiá runtime/ a los dos nodos (o un enlace simbólico) y luego:
(cd nodo && bash iProy-Control.sh init --nombre "A" && bash iProy-Control.sh start --no-abrir)
(cd /tmp/colmen/Colmen-Portable && bash Colmen-Control.sh init --nombre "B" --puerto 8090 --puerto-db 3408 && bash Colmen-Control.sh start --no-abrir)
php -S 127.0.0.1:8070 -t hub &        # hub de relevo local
bash pruebas/colmena.sh nodo /tmp/colmen/Colmen-Portable http://127.0.0.1:8070
```

Cola de salida (1.6.3), con dos nodos iniciados y registrados en el hub (tarda unos 3 minutos: espera los reintentos del worker):

```bash
bash pruebas/cola.sh nodo /tmp/colmen/Colmen-Portable http://127.0.0.1:8070
```

Malla (cada nodo es hub, puente, emisor y receptor), con tres nodos y el hub central local (tarda unos 7 minutos):

```bash
PHP_CLI_SERVER_WORKERS=10 php -S 127.0.0.1:8070 -t hub &   # varios procesos: cada nodo deja un pedido abierto (espera larga)
HUB_DIR=hub bash pruebas/malla.sh nodo /tmp/nodoB /tmp/nodoC http://127.0.0.1:8070
# en modo LAN, para vincular por la dirección de la red: URL_B=https://<IP>:<puerto TLS de B> URL_C=https://<IP>:<puerto TLS de C>
```

`cola.sh` y `tunel.sh` prueban el camino por el hub central entre **dos** nodos: los ponen en modo `central` mientras duran. Si hay otros nodos vinculados con los dos, pasalos en `OTROS="<carpeta> <carpeta>"` para aislarlos durante la prueba; si no, la malla encuentra otro camino por ellos y la prueba no mide lo suyo.

Resultados del 22-11-2026 (malla, tres nodos recién instalados desde el código, dos en modo LAN con HTTPS propio y uno local, hub central 1.2.0 local): malla 52/52, colmena 20/20, tunel 20/20, cola 33/33, unitarias 96/96, pim 71/71, negativas 36/36, nucleo16 45/45, nucleo17 33/33. Contra un hub central 1.0.0 (como el publicado): colmena 20/20 en tres pares.

Resultados del 17-11-2026 (iProy 1.6.3, tres nodos de prueba de tres productos distintos con hub local): unitarias 51/51, pim 70/70, negativas 36/36, mem 28/28, nucleo16 45/45, industrial 16/16, colmena 20/20 en los tres pares, cola 33/33 en dos pares.

Resultados del 09-11-2026 (iProy 1.6.2, dos nodos de prueba con FrankenPHP 1.13.3 y la MariaDB 10.11 del sistema): unitarias 51/51, pim 70/70, negativas 36/36, mem 28/28, nucleo16 45/45, colmena 20/20 (intranet y hub local), industrial 16/16 (sin simuladores).

Resultados del 06-11-2026 (iProy 1.6.0, nodo de desarrollo): unitarias 48/48, pim 70/70, mem 28/28, nucleo16 45/45, industrial 16/16 (sin simuladores).

Resultados del 06-11-2026 (iProy 1.6.1, nodos recién instalados con los instaladores gráficos): Windows dentro de Wine (OviWine Soft) pim 70/70 y mem 28/28; Linux (EndeavourOS, base en el 3408) pim 70/70 y mem 28/28.

Resultados del 02-11-2026 (iProy 1.1.0 y Colmen 0.2.0):

- unitarias: 47/47;
- pim: 51/51 en los dos productos;
- mem: 19/19 en los dos productos (MLLP en 2575 y 2576);
- colmena: 20/20 (iProy ↔ Colmen, intranet y hub);
- industrial: 19/19 en los dos productos (con simulador Modbus y broker amqtt).

Las pruebas crean sus propios datos ficticios y no usan datos reales.


---

<sub>🎓 ¿Algo no quedó claro? [Preguntá en el foro de la capacitación PIM](https://iproy.com.ar/pim/foro) · [Dejá una propuesta de mejora](https://iproy.com.ar/pim/resenas) · Repositorio: https://github.com/iproy-ar/iproy</sub>

Resultados del 23-11-2026 (Inventario: planillas, estados y códigos que siguen a las órdenes): inventario 65/65 en el nodo de desarrollo, 66/66 en un nodo copiado y 65/65 en un producto incubado («Nodo1», plantilla gestion-mantenimiento) con el código anterior y llevado al nuevo con `incubar.php actualizar`, sin perder lo cargado. Regresión: unitarias 96, pim 71, negativas 36, nucleo16 45, nucleo17 33, preventivos 14, plan_semanal 57, requerimientos 56, auditoria 37, panol 91, paneles 38, widgets 85, migracion 115, iot 56, notificaciones 58, pedidos_red 20, reportes 114 de 115.
- [verificado] La que falta en reportes («series para TimesFM») depende de los datos: el nodo de prueba no tiene órdenes de meses completos anteriores.
- `plan_semanal.sh` y `migracion.sh` dependen del entorno: corren con la zona horaria del nodo (`TZ=America/Argentina/Buenos_Aires`) y con el cliente `mariadb` en el PATH (`nodo/runtime/linux-x64/mariadb/bin`). Sin eso dieron 49/57 (pasada la medianoche UTC) y 108/115.
- `requerimientos.sh` elige ahora un activo **en servicio** (un activo fuera de circulación no admite pedidos).

## 1.7.0 (20-11-2026)

| Prueba | Qué cubre | Resultado |
|---|---|---|
| `notificaciones.sh` | reglas, condiciones, cola, reintentos, silencio; correo HTML, Telegram y webhook firmado contra `sumidero.py` (nada sale a Internet) | 58/58 |
| `reportes.sh` | copia de consulta por el bus, Auditoría, calificación, Reporte de la empresa, alcance del Ejecutor | 58/58 |
| `paneles.sh` | señales por el bus, estados de las tarjetas, historial, armado de paneles, quién ve cada uno | 38/38 |
| `iot.sh` | Ola 4: fuentes de datos externas (servidor JSON simulado), lecturas y eventos sin repetir, historial, áreas con reglas por perfil y organización, paneles de energía, equipos, sinóptico y eventos, curvas, informe y paquetes | 56/56 |
| `nucleo17.sh` | permisos propios por usuario, archivos de la aplicación instalable y vigía PIM (cada cambio una sola vez) | 33/33 |

Las anteriores, sobre el mismo nodo: unitarias 54/54, pim 70/70, negativas 36/36, nucleo16 45/45, mem 28/28, industrial 16/16. `colmena.sh` 20/20 y `cola.sh` 33/33 entre dos nodos 1.7.0 en un mismo equipo, con hub local.

