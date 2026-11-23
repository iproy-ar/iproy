-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Copia de consulta de las órdenes (se llena con los eventos pim/Task/* y la API PIM; se puede reconstruir entera).
CREATE TABLE IF NOT EXISTS rep_orden (
  id CHAR(26) PRIMARY KEY,
  numero VARCHAR(40) NULL,
  version INT NOT NULL DEFAULT 0,
  estado VARCHAR(20) NOT NULL,
  prioridad VARCHAR(10) NULL,
  tipo_mant VARCHAR(40) NULL,
  titulo VARCHAR(200) NULL,
  detalle TEXT NULL,
  motivo VARCHAR(300) NULL,
  activo_id CHAR(26) NULL, activo VARCHAR(160) NULL,
  local_id CHAR(26) NULL, lugar VARCHAR(160) NULL,
  org_id CHAR(26) NULL,
  creada DATETIME NOT NULL,
  inicio DATETIME NULL,
  fin DATETIME NULL,
  actualizada DATETIME NOT NULL,
  KEY k_creada (creada), KEY k_fin (fin), KEY k_org (org_id, creada), KEY k_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Cada cambio de estado, con su fecha: de acá salen los movimientos diarios y los tiempos.
CREATE TABLE IF NOT EXISTS rep_movimiento (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  orden_id CHAR(26) NOT NULL,
  version INT NOT NULL,
  ts DATETIME NOT NULL,
  de VARCHAR(20) NULL,
  a VARCHAR(20) NOT NULL,
  UNIQUE KEY u_orden_version (orden_id, version), KEY k_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS rep_org (
  id CHAR(26) PRIMARY KEY,
  codigo VARCHAR(60) NULL,
  nombre VARCHAR(160) NOT NULL,
  tipo VARCHAR(20) NULL,
  KEY k_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- La calificación es dato propio del módulo: no se pierde al reconstruir.
CREATE TABLE IF NOT EXISTS rep_calificacion (
  orden_id CHAR(26) PRIMARY KEY,
  estrellas TINYINT NOT NULL,
  nota VARCHAR(300) NULL,
  usuario VARCHAR(60) NOT NULL,
  ts DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
