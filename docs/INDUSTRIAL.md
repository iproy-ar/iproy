<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Integración industrial: SCADA liviano, IoT y mantenimiento

iProy no reemplaza un SCADA de control. Se ubica en el nivel 3 de ISA-95: gestión de mantenimiento y de activos. Desde ahí:

- toma datos de campo (MQTT, Modbus, HTTP);
- gestiona alarmas con criterio ISA-18.2;
- dispara órdenes de trabajo y preventivos por condición.

## 1. Telemetría (módulo `telemetria`)

- **Entrada única:** el evento `telemetria/medicion` `{activo, punto, valor, unidad, ts, origen}`. Puede venir de:
  - MQTT;
  - Modbus;
  - `POST /api/m/telemetria/medicion`, con una medición o `{"mediciones": [...]}` (hasta 1000; usuario de rol FT, con su token);
  - el bus de otro nodo.
- El `activo` puede ser el id PIM o el **código de inventario** (identifier). La resolución se guarda en caché una hora.
- **Envío a PIM por excepción:** solo si cambió más que la banda muerta (`banda_muerta_pct`, 0,5 % por defecto) o pasó `pim_cada_s` (300 s). Se manda en lotes (Bundle batch, rol FT). Así un sensor que reporta cada segundo no satura el estándar.
- Guarda el último valor y una serie de 7 días para los gráficos.
- Publica `telemetria/valor` con el id PIM resuelto, que es lo que consumen alarmas y preventivos.

## 2. MQTT 3.1.1 (módulo `mqtt` + `tools/residente.php`)

- **Temas** (configurables por plantilla): `pim/v1/{refes}/{activo}/{punto}`. Por ejemplo, `pim/v1/HOSP1/CMP-01/PRES-LINEA`.
- **Carga:** un número (`412.5`) o JSON corto `{"v": 412.5, "u": "kPa", "t": "2026-11-02T10:00:00-03:00"}`.
- **Proceso residente:** mantiene la conexión permanente (QoS 1, PUBACK) y publica cada mensaje en el bus en el acto. Lo inician y detienen `start` y `stop`. Mientras su latido esté fresco, la tarea `mqtt:escuchar` no hace nada; si el residente cae, la tarea escucha unos segundos por ciclo como respaldo.
- **Salida:** cada cambio de alarma se publica (retenido) en `pim/v1/{refes}/{activo}/{punto}/alarma`.
- **Broker recomendado:** Mosquitto o EMQX, con sesiones persistentes (`clean session = 0`) y TLS si sale de la planta. `amqtt` sirve solo para pruebas: no guarda los mensajes de un cliente desconectado \[verificado en las pruebas\].
- **Sparkplug B (ISO/IEC 20237):** previsto en la hoja de ruta. El entorno ya tiene la extensión `protobuf`. Se agregará como plantilla `spBv1.0/{grupo}/DDATA/{nodo}/{dispositivo}`, con decodificación de métricas y estado (NBIRTH/NDEATH).

## 3. Modbus TCP (módulo `modbus`)

- Funciones 3 (registros de retención) y 4 (registros de entrada).
- Agrupa direcciones contiguas en lecturas de hasta 120 registros.
- **Tipos:** `int16`, `uint16`, `int32`, `uint32` y `float32`.
- **Orden de palabras:** `ABCD` (big-endian) o `CDAB`.
- Cada registro admite `escala` y `desplazamiento`.
- Reconoce las excepciones Modbus (dirección inválida, gateway sin ruta, etc.). Un equipo que no responde se asienta en el diario solo cuando cambia de estado, no en cada intento.
- **Para Modbus RTU (RS-485):** usar un gateway RTU→TCP. La unidad (`unidad`) se pasa tal cual.

```json
{ "habilitado": true, "equipos": [ { "nombre": "tablero-quirofano", "host": "192.168.10.50", "puerto": 502, "unidad": 1, "cada_s": 30,
  "activo": "TGBT-QX", "habilitado": true, "registros": [
    { "punto": "TENSION-L1", "funcion": 4, "direccion": 0, "tipo": "float32", "orden": "ABCD", "unidad": "V" },
    { "punto": "CORRIENTE-L1", "funcion": 4, "direccion": 6, "tipo": "uint16", "escala": 0.01, "unidad": "A" } ] } ] }
```

## 4. Alarmas ANSI/ISA-18.2 · IEC 62682 (módulo `alarmas`)

- **Estados:**
  - `normal`
  - `no-reconocida`
  - `reconocida`
  - `rtn-no-reconocida` (volvió a normal sin reconocer)
  - `enclavada` (*latched*, requiere `restablecer`)
  - `archivada` (*shelved*, siempre con vencimiento: como máximo `archivar_max_min`)
  - `suprimida` (por diseño)
  - `fuera-servicio`
- **Definición:** tipo (alta, alta-alta, baja, baja-baja), límite, **banda muerta** (histéresis), **retardo de activación**, prioridad (1 a 4), mensaje, causa y **acción esperada del operador**.
- **Racionalización.** Reconocer y archivar son acciones del turno (`alarmas.operar`). Suprimir, sacar de servicio y restaurar esos estados son decisiones de ingeniería (`alarmas.configurar`). Todo exige un motivo y queda en el historial y en el diario firmado.
- **Orden automática.** Si la definición lo pide, al activarse se crea una ServiceRequest en PIM con prioridad 1→stat, 2→asap, 3→urgent o 4→routine, y la acción esperada en la nota. La ServiceRequest genera su Task.
- **Indicadores** (ISA-18.2 §16, EEMUA 191):
  - activaciones en 24 h y promedio cada 10 min;
  - pico en 10 min (más de 10 es avalancha);
  - alarmas estancadas (más de 24 h);
  - intermitentes (3 o más en poco tiempo);
  - las 10 que más se activan.

## 5. Mantenimiento preventivo (módulo `preventivos`)

- **Por calendario:** cada N días, con anticipación configurable. Genera la orden en PIM con el tipo EN 13306 `preventivo-sistematico` y la asigna al responsable del plan (organización PIM). Si no hay responsable, la deja en revisión.
- **Por uso o condición:** contador de un punto de telemetría (por ejemplo, `HORAS-MARCHA` cada 500 h). Tipo `preventivo-condicional`.
- **Reprogramación** desde la fecha real de finalización (configurable), escuchando `pim/Task/update`. No genera duplicados mientras haya una orden abierta.

## 6. OPC UA, BACnet y otros protocolos

Para mantener el núcleo chico y sin dependencias nativas, la vía recomendada es un **gateway** que publique en MQTT con la convención `pim/v1/…`. Opciones:

- Node-RED;
- un servicio de dispositivo de EdgeX Foundry, que desde la 4.0 usa MQTT como bus de mensajes por defecto;
- el gateway del fabricante.

Si la necesidad lo justifica, un módulo `opcua` podría usar un cliente externo. Queda en la hoja de ruta.

## 7. Seguridad OT

- El nodo **lee** de los equipos (FC3 y FC4) y **no escribe** en PLC: no hay funciones 5, 6, 15 ni 16 de Modbus. Esto es deliberado.
- Recomendaciones para la red:
  - separar la red de planta (VLAN);
  - usar TLS y credenciales en el broker;
  - que el nodo sea el único que inicia conexiones hacia los equipos.
