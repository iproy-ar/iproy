-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Stock 1.2.0 · herramientas del pañol: datos administrativos del ingreso, pedidos de insumos y conteo cíclico.
ALTER TABLE stock_movimiento ADD COLUMN IF NOT EXISTS orden_compra VARCHAR(80) NULL AFTER comprobante;
-- Pedido de insumos (FHIR SupplyRequest, uno por renglón al exponerlo). Número PED-AAAAMMDD-NNN (el sistema anterior agrega una letra al final [pendiente: confirmar qué indica]).
CREATE TABLE IF NOT EXISTS stock_pedido (
  id INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(24) NOT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'Solicitado',
  prioridad VARCHAR(20) NOT NULL DEFAULT 'Normal',
  destino VARCHAR(160) NULL, proveedor_id INT NULL, necesario DATE NULL,
  motivo VARCHAR(200) NULL, observaciones VARCHAR(600) NULL,
  creado DATETIME NOT NULL, creado_por VARCHAR(60) NULL, actualizado DATETIME NULL, actualizado_por VARCHAR(60) NULL,
  UNIQUE KEY u_numero (numero), KEY k_estado (estado, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS stock_pedido_linea (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pedido_id INT NOT NULL, renglon INT NOT NULL, insumo_id INT NOT NULL,
  cantidad DECIMAL(14,3) NOT NULL, recibido DECIMAL(14,3) NOT NULL DEFAULT 0, nota VARCHAR(200) NULL,
  UNIQUE KEY u_ped_renglon (pedido_id, renglon), KEY k_insumo (insumo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Conteo cíclico: lo contado contra lo que decía el sistema; el ajuste (si se pide) es un movimiento AJUSTE aparte.
CREATE TABLE IF NOT EXISTS stock_conteo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  insumo_id INT NOT NULL, fecha DATETIME NOT NULL, sistema DECIMAL(14,3) NOT NULL, contado DECIMAL(14,3) NOT NULL,
  diferencia DECIMAL(14,3) NOT NULL, nota VARCHAR(200) NULL, ajuste VARCHAR(20) NULL, usuario VARCHAR(60) NULL,
  KEY k_insumo (insumo_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
