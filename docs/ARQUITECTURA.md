<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Arquitectura de iProy 1.0

> 🎓 **Capacitación PIM:** este documento tiene su versión guiada, con diagramas, ejemplos y foro, en [iproy.com.ar/pim](https://iproy.com.ar/pim/) (en particular [esta página](https://iproy.com.ar/pim/planos)). El código fuente vive en [github.com/iproy-ar/iproy](https://github.com/iproy-ar/iproy).


iProy se organiza como un sistema operativo: un **núcleo** pequeño y estable, **módulos** cargables que aportan el dominio, **procesos** de usuario (la aplicación web, los clientes PIM, los gateways) y una **red** de nodos (la colmena). Cada pieza tiene un análogo en Linux, y de ahí sale la mayoría de las reglas.

```
                 ┌──────────────────────────────── nodo (un equipo: PC, servidor, mini-PC de planta) ───────────────────────────────┐
 navegador ──▶   │  /app/  aplicación web (una sola página; pestañas = ui.js de cada módulo)                                          │
 sistemas  ──▶   │  /pim/r4  Servidor PIM · HL7 FHIR R4 · SMART Backend Services · Provenance encadenado                              │
 gateways  ──▶   │  /api/   NÚCLEO ─ módulos · bus · planificador · diario firmado · usuarios/capacidades · /api/proc · colmena       │
 MQTT/Modbus ─▶  │     módulos: pim · telemetria · alarmas (ISA-18.2) · preventivos · mqtt (+ residente) · modbus · <los tuyos>      │
                 │  FrankenPHP (Caddy + PHP 8.5) · MariaDB 11.4 · worker 15 s · residente MQTT       data/ = todo lo del nodo     │
                 └─────────────────────────────────────────────────────┬────────────────────────────────────────────────────────────┘
                                         colmena/1 (sobres firmados + sellados)
                          intranet: directo  ◀──────────────┴──────────────▶  Internet: hub de relevo (PHP en un hosting)
```

## 1. Analogías con Linux y reglas que se derivan

<sub>🎓 [Planos y diagramas de la arquitectura →](https://iproy.com.ar/pim/planos)</sub>

| Linux | iProy | Regla |
|---|---|---|
| Kernel monolítico con módulos cargables (`modprobe`) | `app/nucleo` + `modulos/<id>/modulo.json` | Lo del dominio va en un módulo. El núcleo solo ofrece mecanismos |
| `Documentation/ABI` (estable / pruebas / obsoleto / retirado) | `"abi": 1` y `"estabilidad"` en cada manifiesto | Lo estable no se rompe en 2 años. Un ABI distinto no se carga |
| Kernel *tainted* por módulos propietarios | `contaminado` en `/api/proc/sistema` y en `/api` | Un módulo no libre funciona, pero el nodo lo declara |
| `depmod` (dependencias) | `"requiere": ["pim", "telemetria>=1.0"]` | Orden topológico. Si falta una dependencia, el módulo queda en `dependencia` y no carga |
| `/proc` y `/sys` | `GET /api/proc/{sistema,modulos,tareas,bus,diario,vinculos,capacidades,rpc}` | Todo el estado interno se puede inspeccionar, solo en lectura |
| `journald` / auditd | `nucleo_diario`: hash SHA-256 encadenado + firma ed25519 | Lo que importa auditar se asienta, y `verificar` detecta cualquier alteración |
| Capacidades (`CAP_*`) | `capacidades` por rol en cada manifiesto | Se exige una capacidad, nunca un rol fijo en el código |
| cron / timers de systemd | `"tareas": [{id, cada, archivo}]` + planificador | Presupuesto de tiempo por tick. Una tarea no corre dos veces a la vez |
| Netlink / D-Bus | bus de eventos con temas MQTT (`a/b/c`, `+`, `#`) | Los módulos se comunican por eventos, no leyendo tablas ajenas |
| Demonios residentes | `tools/residente.php` (MQTT) | Lo que necesita una conexión permanente vive fuera del ciclo de 15 s |
| Ramas estables y LTS · kernel como CNA | `SEGURIDAD.md`, `GOBERNANZA.md` | Divulgación coordinada; un CVE se cierra cuando la corrección está publicada en una versión con soporte |

## 2. Núcleo (`nodo/app/nucleo`)

<sub>🎓 [Planos y diagramas de la arquitectura →](https://iproy.com.ar/pim/planos)</sub>

| Archivo | Responsabilidad |
|---|---|
| `index.php` | Router único de `/api/*` (Caddy reescribe `/api` a este archivo; `lib/` y `sql/` nunca se sirven) |
| `lib/nucleo.php` | Configuración, PDO, JSON canónico, identificadores ordenables, HTTP saliente, carga de bibliotecas |
| `lib/modulos.php` | Lectura y validación de manifiestos, resolución de dependencias, migraciones, arranque, activar y desactivar |
| `lib/bus.php` | `publicar()`, `tema_coincide()`, `despachar()` con un cursor por suscriptor, retención |
| `lib/tareas.php` | Planificador: tick con bloqueo, tareas vencidas, bloqueo por tarea, «ejecutar ahora» |
| `lib/diario.php` | `asentar()` y `verificar_diario()` |
| `lib/identidad.php` | Claves del nodo (ed25519, X25519, ES384), huella, firmar y verificar, sellar y abrir, aserciones SMART |
| `lib/usuarios.php` | Roles, capacidades, sesión firmada, tokens de integración, Argon2id, bloqueo por intentos |
| `lib/pimcli.php` | Cliente del Servidor PIM: clientes internos `nucleo-<rol>`, caché de tokens, proxy en el mismo proceso, ejecución con la aserción de otro nodo |
| `lib/colmena.php` | Sobres, vínculos, transporte por intranet y hub, RPC, eventos remotos |
| `lib/proc.php` | Introspección |
| `sql/001_nucleo.sql` | Tablas `nucleo_*` en la base del nodo |

**Arranque.** `start` del lanzador llama a `POST /api/nucleo/arranque` (solo desde 127.0.0.1 y con el token del worker). El arranque hace cinco cosas:

1. aplica las migraciones del núcleo;
2. crea la identidad si no existe;
3. resuelve los módulos y aplica sus migraciones;
4. registra sus tareas;
5. asienta `nucleo.arranque` en el diario.

**Tick.** El worker llama a `/_iproy/tick.php` cada 15 s, y este ejecuta `_iproy/tareas/nucleo.php`. Cada tick hace lo siguiente:

1. despacha el bus;
2. atiende la colmena (buzón del hub, RPC vencidas);
3. corre las tareas vencidas, con un presupuesto de 40 s;
4. despacha de nuevo lo que esas tareas publicaron;
5. una vez por hora, aplica la retención.

**Identidad del producto.** El nombre, la versión, la licencia, la URL de la fuente y los colores salen de `producto.json`. La incubadora reescribe ese archivo al crear un derivado, sin tocar el núcleo.

## 3. Datos y separación de responsabilidades

<sub>🎓 [Planos y diagramas de la arquitectura →](https://iproy.com.ar/pim/planos)</sub>

- `data/` es todo lo propio del nodo: base de datos, claves, configuración de módulos, estado y respaldos. Nunca entra al repositorio ni a un derivado.
- **Los módulos no leen la base PIM.** Usan la API (`Nucleo\pim()`) con clientes internos y roles mínimos (FT para telemetría, GM para órdenes). Por eso cada acción queda en el Provenance y el historial, igual que si la hiciera un sistema externo.
- Cada módulo tiene sus tablas, con su prefijo (`tel_`, `alm_`, `prev_`, `mqtt_`) y migraciones propias.
- **La aplicación web** usa la API del núcleo y `/api/pim/*`. Este último ejecuta la API PIM en el mismo proceso, con un cliente según el rol del usuario (AD→GM, EJ→cliente de su organización). Así no ocupa dos hilos por pedido (lección L-321).

## 4. Seguridad

<sub>🎓 [Lección: copias de seguridad, seguridad y red →](https://iproy.com.ar/pim/aprende/respaldo-seguridad-red)</sub>

Ver `SEGURIDAD.md`. En resumen:

- no hay secretos en el código;
- sesión firmada, con encabezado anti-CSRF para escribir;
- Argon2id para las contraseñas;
- diario firmado;
- sobres de la colmena con firma y sello de extremo a extremo;
- en el Servidor PIM: roles y alcances SMART, If-Match obligatorio y Provenance encadenado.

## 5. Flujo de punta a punta (probado en `pruebas/industrial.sh`)

<sub>🎓 [Lección: sensores con MQTT, alarmas y órdenes automáticas →](https://iproy.com.ar/pim/aprende/sensores-mqtt-esp32)</sub>

1. Un sensor publica `pim/v1/HOSP/CMP-01/PRES-LINEA` en el broker MQTT.
2. El residente lo convierte en el evento `telemetria/medicion` del bus.
3. `telemetria` resuelve el activo en PIM y guarda el último valor y la serie. Encola la Observation (por excepción) y publica `telemetria/valor`.
4. `alarmas` evalúa la definición (banda muerta, retardo). Si la condición aparece pasa a `no-reconocida`, publica `alarmas/cambio` y, si la prioridad lo pide, crea una ServiceRequest en PIM. La ServiceRequest genera su Task.
5. `mqtt` publica el cambio de alarma en el broker, y el SCADA o el tablero lo ve.
6. El técnico reconoce la alarma en `/app/` y la orden sigue su ciclo (Tabla 9-2 del PIM).
7. El vigía `pim` publica `pim/Task/update`. Si la orden era preventiva, `preventivos` reprograma el plan desde la fecha real.
8. Si el activo es de otro hospital vinculado, la orden se pudo pedir por la colmena (rol SO). En ese caso el Provenance del otro nodo registra `colmena-<este nodo>` como autor.


---

<sub>🎓 ¿Algo no quedó claro? [Preguntá en el foro de la capacitación PIM](https://iproy.com.ar/pim/foro) · [Dejá una propuesta de mejora](https://iproy.com.ar/pim/resenas) · Repositorio: https://github.com/iproy-ar/iproy</sub>
