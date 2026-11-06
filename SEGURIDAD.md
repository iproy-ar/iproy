<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Política de seguridad

> 🎓 **Capacitación PIM:** este documento tiene su versión guiada, con diagramas, ejemplos y foro, en [iproy.com.ar/pim](https://iproy.com.ar/pim/) (en particular [esta página](https://iproy.com.ar/pim/aprende/respaldo-seguridad-red)). El código fuente vive en [github.com/iproy-ar/iproy](https://github.com/iproy-ar/iproy).


## Cómo reportar una vulnerabilidad

<sub>🎓 [Lección: copias de seguridad, seguridad y red →](https://iproy.com.ar/pim/aprende/respaldo-seguridad-red)</sub>

No la publiques en un *issue*. Escribí a **admin@iproy.com.ar** o usá el aviso privado de vulnerabilidades del repositorio (GitHub → Security → Report a vulnerability). Contá qué componente afecta, la versión y cómo reproducirla.

| Plazo | Compromiso |
|---|---|
| 72 horas | Acuse de recibo |
| 7 días | Evaluación inicial y severidad (CVSS) |
| 90 días como máximo | Divulgación coordinada; antes si ya hay corrección publicada |

La corrección se publica con un aviso que indica las versiones afectadas, cómo mitigar y cómo actualizar, y se da crédito a quien lo reportó si lo desea.

## Versiones con soporte

<sub>🎓 [Lección: copias de seguridad, seguridad y red →](https://iproy.com.ar/pim/aprende/respaldo-seguridad-red)</sub>

| Versión | Soporte |
|---|---|
| 1.0.x (actual) | Correcciones de seguridad |
| LTS anual (a partir de la primera) | 2 años de correcciones de seguridad |

Como hace el kernel Linux desde que es CNA, una vulnerabilidad se da por cerrada recién cuando su corrección está publicada en una versión con soporte.

## Diseño de seguridad (resumen)

<sub>🎓 [Lección: copias de seguridad, seguridad y red →](https://iproy.com.ar/pim/aprende/respaldo-seguridad-red)</sub>

- **Sin secretos en el código.** Contraseñas, claves y tokens se generan en `data/` al instalar, con permisos 600. `incubar.php verificar` busca claves privadas, contraseñas escritas y datos institucionales.
- **Contraseñas** con Argon2id y bloqueo de 5 minutos tras 5 intentos fallidos.
- **Sesión** en una cookie firmada (HMAC-SHA256), con los atributos HttpOnly y SameSite=Strict. Para escribir hace falta además el encabezado `X-Nucleo`, como protección contra CSRF. Desde la 1.6.0 la cookie lleva una huella de la contraseña: al cambiarla se cierran las demás sesiones.
- **Recuperación de la contraseña sin Internet (1.6.0).** Códigos de un solo uso que genera el propio usuario (se guardan como HMAC, nunca en claro), clave temporal del administrador con vencimiento y cambio obligatorio, y enlace por correo solo si el nodo tiene SMTP. Las respuestas no revelan si un usuario existe y los intentos están limitados. La contraseña del servidor SMTP queda en `data/` y la API nunca la devuelve.
- **Diario** de solo agregado, encadenado con SHA-256 y firmado con ed25519. Se verifica con `nucleo verificar`.
- **Colmena.** Los sobres van firmados (ed25519) y sellados (X25519, `crypto_box_seal`). Cada sobre se procesa una sola vez y tiene una vida máxima de 8 días. La huella se compara fuera de banda antes de aceptar un vínculo.
- **Hub.** No puede leer ni falsificar los sobres. Además verifica la firma de cada pedido con un nonce de un solo uso y una ventana de ±5 minutos, limita la cantidad de pedidos y fija la clave de cada nodo en el primer uso (TOFU).
- **Servidor PIM.** Usa SMART Backend Services (aserciones RS384 o ES384), roles con alcances mínimos, If-Match obligatorio y Provenance encadenado.

## Reglamento europeo de ciberresiliencia (CRA)

<sub>🎓 [Lección: copias de seguridad, seguridad y red →](https://iproy.com.ar/pim/aprende/respaldo-seguridad-red)</sub>

El Reglamento (UE) 2024/2847 obliga a los fabricantes a informar vulnerabilidades explotadas desde el 11-09-2026 y se aplica completo desde el 11-12-2027.

- Para el software libre crea la figura del *steward*: una persona jurídica que sostiene software libre destinado a actividades comerciales. Sus obligaciones son livianas: política de ciberseguridad pública, cooperación y reportes.
- Según la información disponible, un desarrollador individual sin actividad comercial no sería *steward* \[a verificar con asesoría legal\].
- Quien **comercialice** un derivado de iProy en la UE será fabricante y tendrá las obligaciones completas.

Para facilitar el cumplimiento se ofrecen:

- esta política;
- el SBOM de cada versión, con `php herramientas/sbom.php`;
- la divulgación coordinada descrita arriba.


---

<sub>🎓 ¿Algo no quedó claro? [Preguntá en el foro de la capacitación PIM](https://iproy.com.ar/pim/foro) · [Dejá una propuesta de mejora](https://iproy.com.ar/pim/resenas) · Repositorio: https://github.com/iproy-ar/iproy</sub>
