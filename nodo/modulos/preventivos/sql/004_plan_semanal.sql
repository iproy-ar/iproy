-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- 2.0.0: plan preventivo por semanas (como la planilla de cronograma anual).
-- Cada tarea es una fila: sistema › ubicación › equipo (activo PIM) › instalación › sector › categoría › parte › preventivo, con su frecuencia en semanas.
-- El cronograma de cada año marca en qué semanas (1 = la del 1 de enero, lunes a domingo; 53 o 54 por año) se hace cada tarea.
-- Cada lunes se genera UNA orden por equipo con las tareas de esa semana; cada tarea se cierra por separado con su fecha real.
CREATE TABLE IF NOT EXISTS pmp_tarea (
  id INT AUTO_INCREMENT PRIMARY KEY,
  activo_id VARCHAR(64) NOT NULL,
  activo_codigo VARCHAR(80) NULL,
  activo_nombre VARCHAR(200) NULL,
  sistema VARCHAR(120) NULL,
  ubicacion VARCHAR(120) NULL,
  instalacion VARCHAR(120) NULL,
  sector VARCHAR(120) NULL,
  categoria VARCHAR(120) NULL,
  parte VARCHAR(160) NULL,
  preventivo VARCHAR(300) NOT NULL,
  predictivo VARCHAR(300) NULL,
  personal VARCHAR(60) NULL,
  hoja_vida VARCHAR(60) NULL,
  inspeccion VARCHAR(60) NULL,
  insumos VARCHAR(300) NULL,
  frecuencia VARCHAR(3) NULL,
  tipo_mant VARCHAR(30) NOT NULL DEFAULT 'preventivo-sistematico',
  responsable VARCHAR(80) NULL,
  orden_fila INT NOT NULL DEFAULT 0,
  activa TINYINT NOT NULL DEFAULT 1,
  origen VARCHAR(20) NULL,
  origen_clave CHAR(40) NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modificado DATETIME NULL,
  UNIQUE KEY u_origen (origen_clave), KEY k_activo (activo_id), KEY k_sistema (sistema, orden_fila)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pmp_semana (
  tarea_id INT NOT NULL,
  anio SMALLINT NOT NULL,
  semana TINYINT NOT NULL,
  manual TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY (tarea_id, anio, semana), KEY k_semana (anio, semana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pmp_orden (
  id INT AUTO_INCREMENT PRIMARY KEY,
  anio SMALLINT NOT NULL,
  semana TINYINT NOT NULL,
  activo_id VARCHAR(64) NOT NULL,
  activo_nombre VARCHAR(200) NULL,
  sr_id VARCHAR(64) NULL,
  task_id VARCHAR(64) NULL,
  numero VARCHAR(40) NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'abierta',
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cerrada DATETIME NULL,
  UNIQUE KEY u_semana_activo (anio, semana, activo_id), KEY k_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pmp_ejecucion (
  orden_id INT NOT NULL,
  tarea_id INT NOT NULL,
  resultado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
  fecha_real DATE NULL,
  nota VARCHAR(300) NULL,
  usuario VARCHAR(60) NULL,
  actualizado DATETIME NULL,
  PRIMARY KEY (orden_id, tarea_id), KEY k_tarea (tarea_id, resultado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- relevamiento en recorrida: lo que carga el operario queda como borrador hasta que un Gestor lo aprueba
CREATE TABLE IF NOT EXISTS pmp_relevamiento (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid CHAR(36) NOT NULL,
  usuario VARCHAR(60) NOT NULL,
  estado VARCHAR(12) NOT NULL DEFAULT 'enviado',
  titulo VARCHAR(200) NULL,
  datos MEDIUMTEXT NOT NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado DATETIME NULL,
  revisado_por VARCHAR(60) NULL,
  revisado DATETIME NULL,
  nota_revision VARCHAR(300) NULL,
  resultado TEXT NULL,
  UNIQUE KEY u_uid (uid), KEY k_estado (estado, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pmp_plantilla (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  partes MEDIUMTEXT NOT NULL,
  semilla TINYINT NOT NULL DEFAULT 0,
  modificado DATETIME NULL,
  UNIQUE KEY u_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
