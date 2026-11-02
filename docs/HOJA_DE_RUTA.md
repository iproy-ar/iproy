<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Hoja de ruta, pendientes y límites conocidos

## Límites conocidos de la versión 1.1.0 (honestos)

- **Windows.** El lanzador `.ps1` tiene los mismos comandos, pasa el análisis sintáctico y se probó con pwsh en Linux (`colmena lista`). La primera corrida real en Windows queda pendiente \[pendiente\].
- **MQTT sin residente.** Si el proceso residente no está corriendo, la tarea de respaldo escucha unos segundos por ciclo. Con brokers sin sesiones persistentes se pueden perder mensajes. Con el residente (que `start` inicia solo) no se perdió ninguno en las pruebas.
- **Hub.** La RPC por el hub es asíncrona: la latencia es la del tick del otro nodo (hasta 15 s; en las pruebas, entre 8 y 12 s). Para tiempo real, conviene la intranet o una VPN.
- **Servidor PIM 1.1 frente a la especificación 0.9.2.** Sin diferencias no declaradas (`especificacion/DIFERENCIAS_IMPLEMENTACION.md`). El nivel declarado es **Básico** más REG, RSG, TEL, FED, ALR y EQM: faltan PRV, ENS y KPI para el Intermedio.
- **Validación oficial.** Las instancias no se validaron todavía con el validador de HL7 (lo corre el flujo de publicación en GitHub) ni los mensajes PCD-15 con las herramientas de IHE/NIST \[pendiente\].
- **MEM-DMC.** Los atributos de batería y autoprueba no tienen código MDC asignado en el suplemento Rev. 1.5: se reconocen por el nombre (`MDCX_…`). El receptor PCD-16 (MEM-LS) propio queda para la 1.2; la ubicación se toma del PL que llega en PCD-15.
- **Bases heredadas.** En la distribución pública, el puente con un sistema anterior (vistas `iproy_fhir` sobre bases `legado_*`) queda inactivo si esas bases no existen. Se registra un aviso en `data/logs/fhir_vistas.log`. No impide nada.
- **Cifrado de la base en reposo.** Depende del sistema operativo (por ejemplo, BitLocker o LUKS).

## Próximas versiones

### 1.1 (hecho el 02-11-2026)

- ~~Especificación PIM en `especificacion/`~~ (0.9.2, alineada con IHE; SUSHI 0/0).
- ~~Contrato OpenAPI~~ (1.2.0): DeviceMetric + Observation (L-331), artefactos de la guía, `Device/$refes` y rol RE.
- ~~Directorio IHE mCSD (ITI-90/91)~~ y ~~receptor IHE MEM-DMC (PCD-15)~~ (módulo `mem`).
- ~~Declaración de Integración PIM~~ (IHE Integration Statement).

### 1.2

- Nivel **Intermedio**: PIM-PRV (PlanDefinition, Questionnaire), PIM-ENS (DiagnosticReport con acta firmada) y PIM-KPI (Measure, MeasureReport).
- Receptor PCD-16 (MEM-LS), opción Feed de mCSD (ITI-130) y auditoría con IHE BALP.
- Kit de pruebas Inferno y validación con el validador oficial de HL7 en el CI.
- Sparkplug B en el módulo mqtt; notificaciones (correo, Telegram) como módulo; respaldos firmados y `restaurar` en Windows.

- Sincronización de datos entre nodos vinculados (la Fase 3 del portable): réplica selectiva por recurso PIM sobre `_history`, con resolución LWW por campo (lecciones L-286 a L-288). Se probará con el arnés de convergencia y una mutación.
- Pronóstico de series de tiempo (TimesFM) como módulo opcional: tendencias de consumo y de fallas a partir de `tel_serie` y del historial de órdenes.
- Cliente PWA sin conexión para los técnicos en planta.

### 2.0

- Módulo OPC UA (cliente externo) y BACnet/IP por gateway.
- Nodo en Android (Termux).
- Fundación o *steward* neutral (ver GOBERNANZA.md y SEGURIDAD.md).

## Pendientes de decisión del autor

- Cuenta de GitHub y correo de seguridad: marcadores `CUENTA-GITHUB` y `CORREO-SEGURIDAD`, se completan con `herramientas/fijar-github.sh` (ver `docs/publicar-en-github.md`).
- Dónde resuelve el canónico `https://iproy.com.ar/fhir` (redirección a la guía publicada).
- Llevar PIM-EQM y PIM-OT al dominio IHE Devices (doble vía de estandarización, PIM 0.9.2 §11.3).
- Nombre y espacio de nombres canónico del estándar al publicar (iProy o la versión final).
- Pasos legales previos a la publicación, ya documentados en la memoria del proyecto (L-344, L-345): pruebas de autoría, depósito en la DNDA, nota a la institución, publicación AGPL y archivo en Software Heritage.
