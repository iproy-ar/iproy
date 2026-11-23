-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Servicio (Location PIM) al que pertenece cada usuario: con él se arma su pedido. «ultimo» recuerda el último servicio elegido por quien puede elegir.
CREATE TABLE IF NOT EXISTS req_usuario (
  usuario VARCHAR(60) NOT NULL PRIMARY KEY,
  local_id VARCHAR(64) NULL, local_nombre VARCHAR(160) NULL, ultimo VARCHAR(64) NULL,
  modificado DATETIME NULL, modificado_por VARCHAR(60) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
