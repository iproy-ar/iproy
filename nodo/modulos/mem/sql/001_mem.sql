-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Registro de mensajes IHE PCD-15 recibidos (sin datos de pacientes: PCD-15 no lleva PID ni PV1)
CREATE TABLE IF NOT EXISTS mem_mensaje (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ts DATETIME NOT NULL,
  control VARCHAR(40) NULL,
  tipo VARCHAR(30) NULL,
  eui64 VARCHAR(16) NULL,
  device_id VARCHAR(26) NULL,
  observaciones INT NOT NULL DEFAULT 0,
  ack CHAR(2) NOT NULL,
  detalle VARCHAR(255) NULL,
  origen VARCHAR(60) NULL,
  KEY ix_mem_ts (ts), KEY ix_mem_eui (eui64)
);
