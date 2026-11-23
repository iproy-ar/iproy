-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Copia propia de los egresos de pañol ligados a una orden (evento «stock/movimiento» del módulo stock).
-- Sirve para la nota de pañol en la sábana y para el ranking de insumos por empresa. El stock sigue siendo del módulo stock.
CREATE TABLE IF NOT EXISTS rep_insumo (
  clave VARCHAR(20) NOT NULL, renglon INT NOT NULL,
  orden_id VARCHAR(64) NOT NULL, fecha DATE NOT NULL,
  codigo VARCHAR(40) NOT NULL, descripcion VARCHAR(200) NULL, unidad VARCHAR(20) NULL,
  cantidad DECIMAL(14,3) NOT NULL, precio DECIMAL(14,2) NULL, observacion VARCHAR(400) NULL,
  PRIMARY KEY (clave, renglon), KEY k_orden (orden_id), KEY k_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
