# Licencias del PIM: qué se aplica a qué

| Componente | Licencia | Por qué |
|---|---|---|
| Núcleo iProy, módulos oficiales, kit de pruebas, servidor de referencia | **AGPL-3.0-or-later** | Es la licencia GNU pensada para software que se usa por red: si alguien modifica el núcleo y lo ofrece como servicio, debe publicar su código. La GPL común no cubre ese caso |
| Especificación, perfiles, sistemas de códigos, contrato OpenAPI, documentación | **CC BY-SA 4.0** | Copyleft para textos: toda versión modificada del estándar debe seguir siendo libre. Es compatible en un sentido con la GPLv3, de modo que los ejemplos pueden incluirse en el código |

## Qué permite y qué no

- **Permite:** usar, estudiar, modificar y redistribuir; **cobrar** por instalación, soporte, capacitación o desarrollo a medida.
- **Exige:** publicar el código fuente de toda versión modificada que se distribuya o se ofrezca por red, con la misma licencia; mantener los avisos de autoría.
- **No permite:** cerrar el código ni la especificación, ni redistribuirlos bajo licencias privativas.

> **Aclaración importante:** ninguna licencia libre prohíbe el uso comercial. Una licencia que lo prohibiera dejaría de ser libre y frenaría la adopción (por ejemplo, impediría que una empresa contratista cobre por implementar el PIM en un hospital). La AGPL garantiza lo que se busca: que nadie pueda apropiarse del sistema ni bloquearlo.

## El límite del núcleo (modelo Linux)

Como en Linux, donde los programas usan el kernel mediante llamadas al sistema sin quedar obligados por su licencia:

- Una **aplicación externa** que solo usa la API REST o MQTT del PIM es un programa independiente y puede tener la licencia que su autor elija.
- Un **módulo que se carga dentro del núcleo** (comparte su proceso y sus funciones internas) es, según la interpretación de la Free Software Foundation, una obra derivada: debe ser AGPL-3.0.

Así, el núcleo es siempre libre y, a la vez, cualquiera puede construir sobre él.
