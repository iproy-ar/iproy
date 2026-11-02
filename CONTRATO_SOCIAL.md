<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Contrato social de iProy

Estos son los compromisos del proyecto con quienes lo usan, lo adoptan o lo mejoran. Se inspiran en el Contrato Social de Debian. Ninguna versión futura puede debilitarlos: solo se pueden ampliar.

1. **iProy será siempre software libre.** El código se publica bajo GNU AGPL-3.0-or-later y la especificación bajo CC BY-SA 4.0. No habrá ediciones «comunitarias» recortadas ni núcleos cerrados. Las contribuciones se aceptan con DCO y sin CLA, de modo que nadie, tampoco el autor, pueda volver a licenciar lo que aportaron otros.
2. **Toda versión se publica.** Cada versión que se entregue o se ofrezca por red (también los derivados creados con la incubadora) viene con su código fuente completo, accesible desde la propia aplicación (`/api/fuente` y el pie de página). Las correcciones de seguridad se publican junto con su aviso.
3. **Los datos son de quien los genera.** iProy no envía datos a terceros ni tiene telemetría oculta. Cada nodo nace vacío, sus claves se generan en el propio equipo y nunca salen de él. La colmena funciona solo por **vínculo aceptado por ambas partes**, y cualquiera de las dos lo puede revocar.
4. **Primero los estándares.** Si existe un estándar, se aplica; si hay uno cercano, se adapta; si no hay ninguno, se crea y se publica abierto (PIM). Los protocolos entre nodos son neutrales y tienen versión, para que productos distintos se entiendan.
5. **Nada institucional en el repositorio.** No se suben datos, documentos, logos ni configuraciones de ningún organismo. Lo que es de una institución vive en su nodo (`data/`), nunca en el código.
6. **Compatibilidad hacia adelante.** Lo que el núcleo declara «estable» (ABI de módulos, protocolo `colmena/1`, API PIM) no se rompe sin un período de obsolescencia anunciado, de al menos dos años para lo estable, como en Linux.
7. **Transparencia.** Los problemas, las decisiones y la hoja de ruta son públicos. Cuando algo no está verificado se lo marca así: [verificado], [supuesto], [pendiente] o [contradicción].
