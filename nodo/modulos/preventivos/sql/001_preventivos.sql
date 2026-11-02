-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
CREATE TABLE IF NOT EXISTS prev_plan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(160) NOT NULL,
  activo_id VARCHAR(64) NOT NULL,
  activo_nombre VARCHAR(200) NULL,
  tipo_mant VARCHAR(30) NOT NULL DEFAULT 'preventivo-sistematico',
  cada_dias INT NULL,
  anticipacion_dias INT NOT NULL DEFAULT 0,
  punto VARCHAR(40) NULL,
  cada_valor DOUBLE NULL,
  ultimo_valor DOUBLE NULL,
  proxima DATE NULL,
  ultima DATE NULL,
  prioridad VARCHAR(10) NOT NULL DEFAULT 'routine',
  responsable VARCHAR(80) NULL,
  tareas TEXT NULL,
  habilitado TINYINT NOT NULL DEFAULT 1,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS prev_generada (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  programada DATE NULL,
  disparo VARCHAR(20) NOT NULL,
  sr_id VARCHAR(64) NULL,
  task_id VARCHAR(64) NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'abierta',
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cerrada DATETIME NULL,
  KEY k_plan (plan_id), KEY k_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
