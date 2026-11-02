<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Hoja de ruta, pendientes y límites conocidos

## Límites conocidos de la versión 1.0.0 (honestos)

- **Windows.** El lanzador `.ps1` tiene los mismos comandos, pasa el análisis sintáctico y se probó con pwsh en Linux (`colmena lista`). La primera corrida real en Windows queda pendiente \[pendiente\].
- **MQTT sin residente.** Si el proceso residente no está corriendo, la tarea de respaldo escucha unos segundos por ciclo. Con brokers sin sesiones persistentes se pueden perder mensajes. Con el residente (que `start` inicia solo) no se perdió ninguno en las pruebas.
- **Hub.** La RPC por el hub es asíncrona: la latencia es la del tick del otro nodo (hasta 15 s; en las pruebas, entre 8 y 12 s). Para tiempo real, conviene la intranet o una VPN.
- **PIM 1.0.** La implementación de referencia cumple el contrato 1.0.0. El contrato 1.1.0 (DeviceMetric, 11 CodeSystems, `$refes`, rol RE) y el espacio canónico definitivo (`PIM_CANONICAL`) están pendientes. La incubadora protege `https://iproy.com.ar/fhir` y `https://iproy.com.ar/fhir`, pero el canónico se define al integrar la especificación.
- **Bases heredadas.** En la distribución pública, el puente con un sistema anterior (vistas `iproy_fhir` sobre bases `legado_*`) queda inactivo si esas bases no existen. Se registra un aviso en `data/logs/fhir_vistas.log`. No impide nada.
- **Cifrado de la base en reposo.** Depende del sistema operativo (por ejemplo, BitLocker o LUKS).

## Próximas versiones

### 1.1

- Incorporar la especificación PIM (`pim-repositorio_v0.9.1c`) en `especificacion/`.
- Implementar el contrato OpenAPI 1.1.0: DeviceMetric + Observation (L-331), CodeSystems completos, operación `Device/$refes` y rol RE.
- Kit de pruebas Inferno.
- Sparkplug B en el módulo mqtt: birth/death, métricas y estado del nodo de borde.
- Notificaciones (correo, Telegram) como módulo, a partir de `alarmas/cambio` y `pim/Task/*`.
- Respaldos firmados (sha256 asentado en el diario) y `restaurar` en Windows.

### 1.2

- Sincronización de datos entre nodos vinculados (la Fase 3 del portable): réplica selectiva por recurso PIM sobre `_history`, con resolución LWW por campo (lecciones L-286 a L-288). Se probará con el arnés de convergencia y una mutación.
- Pronóstico de series de tiempo (TimesFM) como módulo opcional: tendencias de consumo y de fallas a partir de `tel_serie` y del historial de órdenes.
- Cliente PWA sin conexión para los técnicos en planta.

### 2.0

- Módulo OPC UA (cliente externo) y BACnet/IP por gateway.
- Nodo en Android (Termux).
- Fundación o *steward* neutral (ver GOBERNANZA.md y SEGURIDAD.md).

## Pendientes de decisión del autor

- URL pública definitiva del repositorio (`producto.json` → `fuente`) y correo de seguridad.
- Nombre y espacio de nombres canónico del estándar al publicar (iProy o la versión final).
- Pasos legales previos a la publicación, ya documentados en la memoria del proyecto (L-344, L-345): pruebas de autoría, depósito en la DNDA, nota a la institución, publicación AGPL y archivo en Software Heritage.
