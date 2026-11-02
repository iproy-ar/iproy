<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Gobernanza (propuesta inicial)

Hay dos gobernanzas, una para cada cosa que se gobierna:

- **El estándar PIM:** es una propuesta (PIM-PROP-001, sección 12) que rige solo si un organismo lo adopta.
  1. **Etapa provincial:** Comisión Provincial PIM del Ministerio de Salud Pública de San Juan. Aplica el estándar, fija la gradualidad y lleva el registro de sistemas conformes.
  2. **Etapa nacional:** Consejo Directivo con la Dirección Nacional de Sistemas de Información en Salud, provincias, IRAM, hospitales y academia.
- **El software iProy:** sigue las etapas de abajo.

Hasta que alguna de esas instancias se forme, el proyecto lo mantiene su autor a título personal.

## Etapas

1. **Fundación (hoy).** El autor actúa como mantenedor principal, con un rol parecido al de Linus Torvalds en Linux: integra, publica versiones y delega áreas.
2. **Comunidad.** Cuando haya al menos tres organizaciones usando iProy, se forma un **Consejo técnico** de entre 3 y 7 personas, elegidas por quienes contribuyeron en los últimos 12 meses. El Consejo decide por consenso y, si no lo hay, por mayoría.
3. **Fundación neutral.** Si el proyecto crece, se evaluará alojarlo en una fundación sin fines de lucro, que podría actuar como *steward* de software libre en los términos del Reglamento europeo de ciberresiliencia (ver SEGURIDAD.md).

## Mantenedores por área

El archivo `MAINTAINERS` sigue el formato del de Linux: cada área tiene responsables (`M:`), un estado (`S:`) y las rutas que cubre (`F:`). Una persona que aporta de forma sostenida en un área puede proponerse como co-mantenedora.

## Versiones y estabilidad

- Las versiones siguen el esquema **semver**: `MAYOR.MENOR.PARCHE`.
- Habrá una rama estable de **largo plazo (LTS)** por año, con correcciones de seguridad durante 2 años.
- **ABI de módulos:**
  - lo marcado «estable» no se rompe durante al menos 2 años;
  - lo «obsoleto» se anuncia con una versión de anticipación como mínimo;
  - lo «retirado» deja de cargarse.

  Son los mismos niveles que usa Documentation/ABI de Linux.
- **Protocolos:**
  - `colmena/1` y la API PIM solo se amplían de forma compatible;
  - un cambio incompatible se publica como `colmena/2`, y durante la transición se aceptan las dos versiones.

## Decisiones

Las decisiones de diseño se registran como ADR (*Architecture Decision Record*) en `docs/decisiones/`. Cada ADR indica el contexto, la decisión, las alternativas que se descartaron y sus consecuencias. Una decisión registrada solo se revierte con otro ADR.
