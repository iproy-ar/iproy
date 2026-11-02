-- iProy Portable · API de interoperabilidad (FHIR R4) · vistas de solo lectura · v0.3.0 · 29-10-2026
-- Mínimo privilegio (Ley 25.326, arts. 4 y 9): el usuario iproy_fhir SOLO ve estas vistas.
-- Nunca se exponen: nombre ni correo del solicitante, creado_por/modificado_por, auditor,
-- operarios, usuarios, contraseñas ni proveedores (tienen datos de contacto personales).
-- Las vistas leen las bases históricas legado_* (Fase 2). En la Fase 3 apuntarán a la base única «iproy».
-- Lo aplica iProy-Control en cada «start» (idempotente). Si falta una base legado_*, esa vista se omite.
-- DROP + CREATE y no CREATE OR REPLACE: en carpetas compartidas y pendrives (FAT/exFAT) el REPLACE falla al
-- copiar el dueño del respaldo .frm- (ERROR 35, L-308).

CREATE DATABASE IF NOT EXISTS iproy_fhir CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Órdenes de trabajo → FHIR Task (sin datos personales)
DROP VIEW IF EXISTS iproy_fhir.v_ordenes;
CREATE ALGORITHM=MERGE SQL SECURITY DEFINER VIEW iproy_fhir.v_ordenes AS
SELECT id, codigo, marca_temporal, servicio_solicitante, detalle, tipo_tarea, empresa, estado,
       fecha_atencion, fecha_proc, tipo_man, fecha_fin, prioridad, version, created_at, updated_at
FROM legado_requerimientos.req_iproy;

-- Catálogo de servicios de mantenimiento → FHIR Location
DROP VIEW IF EXISTS iproy_fhir.v_servicios;
CREATE ALGORITHM=MERGE SQL SECURITY DEFINER VIEW iproy_fhir.v_servicios AS
SELECT clave, nombre, codigo, activo, updated_at FROM legado_requerimientos.cat_servicio;

-- Catálogo de tipos de tarea → CodeSystem «tarea»
DROP VIEW IF EXISTS iproy_fhir.v_tareas;
CREATE ALGORITHM=MERGE SQL SECURITY DEFINER VIEW iproy_fhir.v_tareas AS
SELECT clave, nombre, codigo, prioridad, activo, updated_at FROM legado_requerimientos.cat_tarea;

-- Inventario de equipamiento médico (SIRE, hoja PMP_Equipos) → FHIR Device
-- tz = huso horario configurado en la planilla de origen (las fechas «solo día» se interpretan en ese huso)
DROP VIEW IF EXISTS iproy_fhir.v_equipos;
CREATE SQL SECURITY DEFINER VIEW iproy_fhir.v_equipos AS
SELECT e.fila, e.valores, e.sync_at, m.tz FROM legado_iproy.hoja_espejo e
LEFT JOIN legado_iproy.hoja_meta m ON m.libro = e.libro AND m.hoja = e.hoja
WHERE e.libro = 'sire' AND e.hoja = 'PMP_Equipos' AND e.fila > 1;
