-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Operarios de cada empresa o área y su trabajo en cada orden (quién, desde cuándo, hasta cuándo).
-- La orden vive en el Servidor PIM; acá se guarda solo su id y su número para mostrar. «org» es el código de la organización.
CREATE TABLE IF NOT EXISTS req_operario (
  id INT AUTO_INCREMENT PRIMARY KEY,
  org VARCHAR(60) NOT NULL, nombre VARCHAR(80) NOT NULL, activo TINYINT NOT NULL DEFAULT 1,
  creado DATETIME NOT NULL, creado_por VARCHAR(60) NULL,
  UNIQUE KEY u_org_nombre (org, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS req_trabajo (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  orden_id CHAR(26) NOT NULL, orden_numero VARCHAR(40) NULL, org VARCHAR(60) NOT NULL, operario_id INT NOT NULL,
  inicio DATETIME NULL, fin DATETIME NULL, creado DATETIME NOT NULL, creado_por VARCHAR(60) NULL,
  UNIQUE KEY u_orden_operario (orden_id, operario_id), KEY k_operario (operario_id, inicio), KEY k_org (org, inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
