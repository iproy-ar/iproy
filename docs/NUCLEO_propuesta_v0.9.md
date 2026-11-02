# Núcleo iProy: el «kernel» para crear software de mantenimiento en salud

**Objetivo:** que cualquier institución o ciudad pueda crear su propia aplicación en minutos, sin reprogramar lo que todas necesitan: inventario, órdenes, riesgo, seguridad, sincronización y vínculos. Todo eso lo resuelve el núcleo, y cada aplicación es un módulo que se apoya en él. *[propuesta — Fase 1]*

## Capas

| Capa | Qué resuelve | Base existente |
|---|---|---|
| 1. Almacenamiento local | Base de datos propia de cada nodo con el esquema PIM; cada nodo nuevo empieza vacío | Implementación portable de referencia: MariaDB 11.4 (y SQLite para nodos mínimos [propuesta]) |
| 2. Motor PIM | Altas, búsquedas y validación de los perfiles PIM; máquina de estados de la orden; trazabilidad con huellas encadenadas | Servidor PIM v1.0 |
| 3. Seguridad | Roles, OAuth 2.0 SMART Backend Services, claves de cada nodo | Servidor PIM v1.0 |
| 4. Vínculos | Solicitud, aceptación, firma y registro de vínculos entre nodos (`vinculos.md`) | Nuevo |
| 5. Sincronización colmena | ULID, reloj lógico híbrido, el último cambio gana por campo, sin borrado físico; canales local, institucional y central | iProy Portable (arnés de sincronización) |
| 6. Bus de eventos | Avisos a los módulos: `orden.creada`, `orden.transicion`, `medicion.fuera_de_rango`, `vinculo.aceptado`… | Nuevo |
| 7. API pública | REST HL7 FHIR R4 (`api/`) y MQTT: son las «llamadas al sistema» del núcleo | Servidor PIM v1.0 |

Tecnología del nodo portable: FrankenPHP (PHP 8.5) y MariaDB 11.4, en Windows o Linux, sin dependencia de Google Sheets ni de servicios externos.

## Módulos

Un módulo es una carpeta con un manifiesto `modulo.json` (esquema en `nucleo/modulo.schema.json`) que declara:

- identificador, versión, licencia y autor;
- perfiles PIM que usa y permisos que pide (alcances SMART);
- eventos a los que se suscribe;
- pantallas que agrega al panel del nodo;
- tablas propias, siempre con el prefijo del módulo y sin modificar las tablas del núcleo.

El núcleo instala, activa y desactiva módulos sin tocar su propio código.

## Crear un módulo en minutos

```
pim nuevo-modulo control-panol --plantilla tablero
pim activar control-panol
```

Plantillas previstas: `tablero` (indicadores), `formulario` (alta de solicitudes o protocolos), `integracion-iot` (lectura MQTT de un sensor), `informe` (PDF firmado) y `registro-estatal` (exportación al REFES). La plantilla ya trae la autenticación, las llamadas a la API y la pantalla base, y el módulo funciona apenas se crea.

## Límite de licencia

- Módulo dentro del núcleo (comparte su proceso): AGPL-3.0, igual que el núcleo.
- Aplicación externa que solo usa la API: licencia libre de su autor (ver `LICENCIAS.md`).
