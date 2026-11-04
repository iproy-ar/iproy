### Declaración de Integración PIM

<sub>🎓 [Rutas de aprendizaje por rol →](https://iproy.com.ar/pim/aprende)</sub>

Basada en el **IHE Integration Statement**. Un proveedor la publica para declarar qué parte del PIM implementa su producto. Un pliego puede exigirla: «el oferente presentará la Declaración de Integración PIM de su producto, con nivel Intermedio como mínimo».

La versión computable es la extensión `pim-conformidad` del CapabilityStatement del servidor (`GET [base]/metadata`). La implementación de referencia la publica además en `GET /api/pim/declaracion` (JSON) y en la consola.

#### Plantilla

**1. Producto y proveedor**

| Campo | Valor |
|---|---|
| Producto | |
| Versión exacta | |
| Proveedor | |
| Contacto | |
| Fecha de emisión | |
| Versión del PIM | 0.9.4 |

**2. Nivel de conformidad declarado:** Básico / Intermedio / Avanzado.

**3. Perfiles de integración, actores y opciones**

| Perfil PIM | Actor | Opciones |
|---|---|---|
| PIM-INV | GM | mcsd-update |
| … | … | … |

**4. Perfiles IHE agrupados**

| Perfil IHE | Actor IHE | Agrupado con el actor PIM |
|---|---|---|
| ITI mCSD | Directory | GM |
| DEV MEM-DMC | DMIC | GM |
| … | … | … |

**5. Vínculo a las declaraciones de conformidad subyacentes**

- CapabilityStatement FHIR: URL de `/metadata`.
- Perfil de conformidad HL7 v2 (si declara PIM-EQM).
- Resultado del kit de pruebas PIM: fecha, versión del kit y resultado.

**6. Firma**

Responsable técnico del proveedor. Firma digital (Ley 25.506) opcional.


---

<sub>🎓 ¿Algo no quedó claro? [Preguntá en el foro de la capacitación PIM](https://iproy.com.ar/pim/foro) · [Dejá una propuesta de mejora](https://iproy.com.ar/pim/resenas) · Repositorio: https://github.com/iproy-ar/iproy</sub>
