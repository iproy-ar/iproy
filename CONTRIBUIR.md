<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Cómo contribuir

¡Gracias! Para que el proyecto pueda seguir siendo libre y mantenible:

## 1. Firma de origen (DCO), sin CLA

Cada commit lleva al final la línea `Signed-off-by: Nombre Apellido <correo>` (`git commit -s`). Con esa línea declarás lo que dice el [Developer Certificate of Origin 1.1](https://developercertificate.org/): que el aporte es tuyo, o que tenés derecho a aportarlo, bajo la licencia del proyecto. No se pide ceder derechos a nadie.

## 2. Licencia y cabeceras

- Todo archivo nuevo empieza con `SPDX-License-Identifier: AGPL-3.0-or-later` (o `CC-BY-SA-4.0` en la documentación) y `SPDX-FileCopyrightText: <año> <autor>`.
- No se aceptan dependencias con licencias incompatibles con la AGPL. Un módulo con licencia no libre se puede cargar, pero el nodo queda marcado como «contaminado» (*tainted*), igual que en Linux.
- `php incubadora/incubar.php verificar nodo` tiene que terminar sin observaciones.

## 3. Pruebas

- `php pruebas/unitarias.php` tiene que pasar entero.
- Si tocás el núcleo, la colmena o un módulo industrial, corré también `pruebas/colmena.sh` o `pruebas/industrial.sh` contra nodos de prueba (ver `pruebas/LEEME.md`).
- Cada arreglo de un error trae la prueba que lo habría detectado. Cada prueba nueva se valida con una mutación que la hace fallar: si la prueba no falla al romper el código a propósito, no está probando nada.

## 4. Reglas de diseño

- **El núcleo es chico.** Lo que es del dominio (órdenes, alarmas, protocolos) va en un módulo con su `modulo.json`.
- **Los módulos no leen la base de otro módulo ni la del Servidor PIM.** Usan la API (`Nucleo\pim()`), el bus (`publicar()` y `suscribe`) o la API del otro módulo.
- **No se rompe el ABI estable.** Los cambios incompatibles van a una versión nueva del ABI, con período de obsolescencia (ver GOBERNANZA.md).
- **Nada de secretos ni datos reales en el repositorio**, tampoco en las pruebas: se usan datos ficticios.
- La interfaz usa la estética del proyecto: degradés azules y fondos claros, sin fondos oscuros. Los formularios son limpios.
- Los textos de la interfaz y la documentación se escriben en castellano rioplatense claro.

## 5. Proceso

1. Abrí un *issue* que describa el problema o la propuesta. Si el cambio es grande, primero hace falta un acuerdo.
2. Cada *pull request* trata un solo tema. Explicá qué se probó y cómo.
3. Revisa el mantenedor del área (ver `MAINTAINERS`). Los cambios al núcleo y al protocolo necesitan dos revisiones.
