-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Paneles 2.0.0 (Ola 4): áreas con permisos, tipos de panel, historial en dos niveles y eventos de equipos.
-- Áreas: la portada de Paneles IoT. Quién ve un área lo dicen sus reglas (rol, perfil, organización, usuario).
CREATE TABLE IF NOT EXISTS pan_area (
  id INT AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(40) NOT NULL, nombre VARCHAR(80) NOT NULL, descripcion VARCHAR(200) NULL,
  icono VARCHAR(30) NOT NULL DEFAULT 'paneles', color VARCHAR(9) NOT NULL DEFAULT '#1e88e5', orden INT NOT NULL DEFAULT 50,
  reglas TEXT NULL, activo TINYINT NOT NULL DEFAULT 1, modificado DATETIME NULL, modificado_por VARCHAR(60) NULL,
  UNIQUE KEY u_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Cada panel pertenece (o no) a un área y tiene un tipo: tarjetas, energia, equipos, sinoptico o eventos. «vista» guarda lo propio de cada tipo.
ALTER TABLE pan_panel ADD COLUMN IF NOT EXISTS area_id INT NULL, ADD COLUMN IF NOT EXISTS tipo VARCHAR(12) NOT NULL DEFAULT 'tarjetas',
  ADD COLUMN IF NOT EXISTS vista MEDIUMTEXT NULL, ADD COLUMN IF NOT EXISTS clave VARCHAR(40) NULL, ADD UNIQUE KEY IF NOT EXISTS u_clave (clave), ADD KEY IF NOT EXISTS k_area (area_id, orden);
-- Nombre legible de la señal (por ejemplo «Tensión L1-N»), si la fuente lo informa.
ALTER TABLE pan_punto ADD COLUMN IF NOT EXISTS etiqueta VARCHAR(80) NULL, ADD KEY IF NOT EXISTS k_activo (activo);
-- Historial largo, por hora. pan_serie queda como detalle fino de las últimas semanas (detalle_dias).
CREATE TABLE IF NOT EXISTS pan_hora (
  activo_id VARCHAR(64) NOT NULL, punto VARCHAR(40) NOT NULL, ts DATETIME NOT NULL,
  n INT NOT NULL, suma DOUBLE NOT NULL, minimo DOUBLE NOT NULL, maximo DOUBLE NOT NULL,
  PRIMARY KEY (activo_id, punto, ts), KEY k_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO pan_hora (activo_id, punto, ts, n, suma, minimo, maximo)
  SELECT activo_id, punto, FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts) / 3600) * 3600), SUM(n), SUM(suma), MIN(minimo), MAX(maximo) FROM pan_serie GROUP BY 1, 2, 3;
-- Eventos y alarmas que informan los equipos (bus equipos/evento): una fila por evento; «fin» vacío = sigue activo.
CREATE TABLE IF NOT EXISTS pan_evento (
  clave VARCHAR(120) NOT NULL PRIMARY KEY, activo VARCHAR(64) NULL, ts DATETIME NOT NULL, fin DATETIME NULL,
  mensaje VARCHAR(300) NOT NULL, categoria VARCHAR(40) NULL, nivel VARCHAR(10) NOT NULL DEFAULT 'evento', fuente VARCHAR(40) NULL, recibido DATETIME NOT NULL,
  KEY k_activo (activo, ts), KEY k_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
