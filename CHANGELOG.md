<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Cambios

## 1.0.0 · 02-11-2026 — núcleo modular, colmena e incubadora

### Agregado

**Núcleo** (`nodo/app/nucleo`)
- Módulos cargables con manifiesto, ABI versionado y niveles de estabilidad.
- Dependencias con orden topológico y migraciones SQL con sha256.
- Marca de nodo «contaminado» cuando hay módulos con licencia no libre.
- Bus de eventos con comodines MQTT y un cursor por suscriptor.
- Planificador con presupuesto de tiempo y un bloqueo por tarea.
- Diario encadenado y firmado con ed25519.
- Usuarios con roles PIM más el administrador (AD), y capacidades por módulo.
- Introspección `/api/proc` y proxy PIM en el mismo proceso con el rol del usuario.

**Colmena** `colmena/1`
- Identidad propia de cada nodo (ed25519, X25519 y ES384) y huella para comparar fuera de banda.
- Vínculos con aceptación mutua, revocables, con el rol PIM que se le otorga al otro nodo.
- Sobres firmados y sellados.
- RPC sobre la API PIM del otro nodo, con aserción SMART dirigida a ese nodo.
- Eventos remotos y transporte por la intranet o por el hub.

**Hub de relevo** (`hub/`)
- Un único PHP para hosting común, con SQLite y libsodium.
- Funciona como buzón de guardar y reenviar, con autenticación firmada, nonce, límite de pedidos y TOFU.

**Módulos**
- `pim`: vigía de cambios que publica en el bus, y pestañas de órdenes, solicitudes e inventario.
- `telemetria`: resolución del activo, envío por excepción a PIM, serie corta y gráfico.
- `alarmas`: ciclo de vida ISA-18.2 completo, órdenes automáticas e indicadores EEMUA 191.
- `preventivos`: por calendario y por uso, con reprogramación desde la ejecución real.
- `mqtt`: cliente 3.1.1, con proceso residente y publicación de alarmas.
- `modbus`: TCP FC3/FC4, agrupación de registros y tipos de 16 y 32 bits.

**Aplicación web** (`/app/`)
- Una sola página que arma sus pestañas con lo que aporta cada módulo.

**Incubadora**
- `nuevo`: renombrado completo del producto, logo, plantillas y aviso de derivación.
- `modulo`: genera un CRUD completo con API, interfaz, diario y bus.
- `verificar`: controla licencias, cabeceras SPDX, secretos y datos institucionales.

**Lanzadores**
- Comandos `nucleo`, `modulo`, `usuario` y `colmena`.
- Residente MQTT.
- Windows (`.ps1`) con los mismos comandos.

**Pruebas**
- Unitarias (32), integración de la colmena (19) e integración industrial (19).

### Agregado el 02-11-2026, tarde

- **Especificación PIM 0.9.1** en `especificacion/`: FSH compilado con SUSHI 3.21.0, 0 errores y 0 advertencias.
- Contrato OpenAPI 1.1.0 en `api/`.
- Documentos de la especificación: dominio canónico, vínculos, pruebas y publicación.
- `CITATION.cff` y CI: guía, pruebas unitarias, `verificar` y contrato.
- `herramientas/contrato.py`: tabla de diferencias entre la especificación y la implementación (L-329).
- **Vínculos según `docs/vinculos.md`:**
  - tipo (`local`, `institucional` o `central`);
  - vigencia: al vencer, el vínculo deja de autorizar y el cliente PIM del otro nodo se da de baja;
  - sha256 del acta firmada.
- **Proxy de salida** para la colmena, para redes con proxy (L-291): el tráfico a la LAN y al propio equipo nunca pasa por el proxy.
- Rango `nucleo` en el manifiesto, que se verifica. Esquema unificado en `docs/modulo.schema.json`.

### Cambiado

- El Servidor PIM publica en el canónico de la especificación, `https://iproy.com.ar/fhir`, y acepta `https://iproy.com.ar/pim` como alias.
- El lanzador detecta la IP de la LAN también en Arch y EndeavourOS, donde `hostname` no acepta `-I`.

- La distribución pública no trae datos ni configuración institucional:
  - organismo genérico;
  - sin empresas precargadas;
  - prefijo `legado_` para las bases heredadas.
