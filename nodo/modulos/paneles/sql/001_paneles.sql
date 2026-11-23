-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Señales conocidas, con su último valor (llegan por el tema telemetria/valor) y el estado de su alarma (alarmas/cambio).
CREATE TABLE IF NOT EXISTS pan_punto (
  activo_id VARCHAR(64) NOT NULL, punto VARCHAR(40) NOT NULL,
  activo VARCHAR(64) NULL, nombre VARCHAR(200) NULL,
  valor DOUBLE NOT NULL, unidad VARCHAR(20) NOT NULL DEFAULT '', ts DATETIME NOT NULL,
  alarma VARCHAR(20) NULL, alarma_etiqueta VARCHAR(80) NULL, alarma_prioridad TINYINT NULL,
  PRIMARY KEY (activo_id, punto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Historial resumido en intervalos fijos: cantidad, suma, mínimo y máximo de cada intervalo.
CREATE TABLE IF NOT EXISTS pan_serie (
  activo_id VARCHAR(64) NOT NULL, punto VARCHAR(40) NOT NULL, ts DATETIME NOT NULL,
  n INT NOT NULL, suma DOUBLE NOT NULL, minimo DOUBLE NOT NULL, maximo DOUBLE NOT NULL,
  PRIMARY KEY (activo_id, punto, ts), KEY k_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS pan_panel (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL, descripcion VARCHAR(200) NULL, color VARCHAR(9) NOT NULL DEFAULT '#1e88e5',
  orden INT NOT NULL DEFAULT 50, roles VARCHAR(60) NOT NULL DEFAULT '', usuarios TEXT NULL, activo TINYINT NOT NULL DEFAULT 1,
  modificado DATETIME NULL, modificado_por VARCHAR(60) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS pan_item (
  id INT AUTO_INCREMENT PRIMARY KEY, panel_id INT NOT NULL,
  activo_id VARCHAR(64) NOT NULL, punto VARCHAR(40) NOT NULL,
  titulo VARCHAR(80) NOT NULL, minimo DOUBLE NULL, maximo DOUBLE NULL, decimales TINYINT NOT NULL DEFAULT 1, orden INT NOT NULL DEFAULT 0,
  KEY k_panel (panel_id, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
