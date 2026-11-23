-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Pedidos de trabajo hechos a otros nodos de la colmena y su seguimiento.
CREATE TABLE IF NOT EXISTS red_pedido (
  id INT AUTO_INCREMENT PRIMARY KEY,
  par VARCHAR(16) NOT NULL,
  par_nombre VARCHAR(160) NULL,
  activo_ref VARCHAR(100) NOT NULL,
  activo_nombre VARCHAR(200) NULL,
  activo_codigo VARCHAR(80) NULL,
  detalle VARCHAR(2000) NOT NULL,
  prioridad VARCHAR(10) NOT NULL DEFAULT 'routine',
  rpc_alta CHAR(26) NULL,
  rpc_consulta CHAR(26) NULL,
  sr_id VARCHAR(64) NULL,
  task_id VARCHAR(64) NULL,
  numero VARCHAR(40) NULL,
  estado VARCHAR(30) NOT NULL DEFAULT 'enviando',
  motivo VARCHAR(300) NULL,
  responsable VARCHAR(200) NULL,
  error VARCHAR(300) NULL,
  creado_por VARCHAR(60) NOT NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  consultado DATETIME NULL,
  KEY k_par (par, creado), KEY k_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
