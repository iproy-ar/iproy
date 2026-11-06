<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Pruebas

> 🎓 **Capacitación PIM:** este documento tiene su versión guiada, con diagramas, ejemplos y foro, en [iproy.com.ar/pim](https://iproy.com.ar/pim/) (en particular [esta página](https://iproy.com.ar/pim/instalar#comprobar)). El código fuente vive en [github.com/iproy-ar/iproy](https://github.com/iproy-ar/iproy).


| Prueba | Qué necesita | Cómo |
|---|---|---|
| `unitarias.php` | Solo PHP con sodium (y `nodo/conf/pim-guia`) | `php pruebas/unitarias.php` |
| `pim.sh` | Un nodo iniciado | `bash pruebas/pim.sh <nodo>` |
| `nucleo16.sh` | Un nodo 1.6.0 iniciado con el módulo `stock` | `bash pruebas/nucleo16.sh <nodo>` |
| `mem.sh` | Un nodo iniciado con el módulo `mem` (la prueba lo habilita en 127.0.0.1) | `bash pruebas/mem.sh <nodo> [puerto-mllp]` |
| `colmena.sh` | Dos nodos iniciados en el mismo equipo (pueden ser de productos distintos) y, opcionalmente, un hub | `bash pruebas/colmena.sh <nodo-A> <nodo-B> [http://127.0.0.1:8070]` |
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

Resultados del 06-11-2026 (iProy 1.6.0, nodo de desarrollo): unitarias 48/48, pim 70/70, mem 28/28, nucleo16 43/43, industrial 16/16 (sin simuladores).

Resultados del 02-11-2026 (iProy 1.1.0 y Colmen 0.2.0):

- unitarias: 47/47;
- pim: 51/51 en los dos productos;
- mem: 19/19 en los dos productos (MLLP en 2575 y 2576);
- colmena: 20/20 (iProy ↔ Colmen, intranet y hub);
- industrial: 19/19 en los dos productos (con simulador Modbus y broker amqtt).

Las pruebas crean sus propios datos ficticios y no usan datos reales.


---

<sub>🎓 ¿Algo no quedó claro? [Preguntá en el foro de la capacitación PIM](https://iproy.com.ar/pim/foro) · [Dejá una propuesta de mejora](https://iproy.com.ar/pim/resenas) · Repositorio: https://github.com/iproy-ar/iproy</sub>
