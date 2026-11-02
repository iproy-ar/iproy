-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
CREATE TABLE IF NOT EXISTS alm_definicion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  etiqueta VARCHAR(60) NOT NULL,
  activo_id VARCHAR(64) NOT NULL,
  punto VARCHAR(40) NOT NULL,
  tipo VARCHAR(12) NOT NULL,
  limite DOUBLE NOT NULL,
  banda DOUBLE NOT NULL DEFAULT 0,
  retardo_s INT NOT NULL DEFAULT 0,
  prioridad TINYINT NOT NULL DEFAULT 3,
  mensaje VARCHAR(255) NOT NULL,
  causa VARCHAR(500) NULL,
  accion VARCHAR(500) NULL,
  enclavar TINYINT NOT NULL DEFAULT 0,
  genera_orden TINYINT NOT NULL DEFAULT 0,
  habilitada TINYINT NOT NULL DEFAULT 1,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_etiqueta (etiqueta), KEY k_ap (activo_id, punto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS alm_estado (
  def_id INT PRIMARY KEY,
  estado VARCHAR(20) NOT NULL DEFAULT 'normal',
  condicion TINYINT NOT NULL DEFAULT 0,
  pendiente_desde DATETIME(3) NULL,
  valor DOUBLE NULL,
  desde DATETIME(3) NULL,
  activada DATETIME(3) NULL,
  estado_previo VARCHAR(20) NULL,
  archivada_hasta DATETIME NULL,
  motivo VARCHAR(255) NULL,
  orden VARCHAR(80) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS alm_historial (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  def_id INT NOT NULL,
  ts DATETIME(3) NOT NULL,
  evento VARCHAR(20) NOT NULL,
  de VARCHAR(20) NULL,
  a VARCHAR(20) NOT NULL,
  valor DOUBLE NULL,
  usuario VARCHAR(60) NULL,
  nota VARCHAR(255) NULL,
  KEY k_def (def_id, ts), KEY k_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
