-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Módulo stock 1.0.0 · insumos del pañol, movimientos (ingresos y egresos) y proveedores.
-- Categoría, subcategoría, marca, unidad, depósito, origen y motivo son CÓDIGOS de catálogos editables (nucleo_catalogo_valor):
-- el texto se corrige en Configuración › Listas sin tocar lo ya cargado.
CREATE TABLE IF NOT EXISTS stock_insumo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL,
  categoria VARCHAR(60) NOT NULL,
  subcategoria VARCHAR(60) NOT NULL,
  descripcion VARCHAR(200) NOT NULL,
  marca VARCHAR(60) NULL,
  modelo VARCHAR(80) NULL,
  unidad VARCHAR(20) NOT NULL DEFAULT 'u',
  minimo DECIMAL(14,3) NOT NULL DEFAULT 0,
  precio DECIMAL(14,2) NULL,
  deposito VARCHAR(60) NULL,
  existencia DECIMAL(14,3) NOT NULL DEFAULT 0,
  activo TINYINT NOT NULL DEFAULT 1,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_codigo (codigo),
  KEY k_cat (categoria, subcategoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_proveedor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(160) NOT NULL,
  especialidad VARCHAR(60) NULL,
  contacto VARCHAR(120) NULL,
  email VARCHAR(160) NULL,
  telefono VARCHAR(60) NULL,
  direccion VARCHAR(200) NULL,
  activo TINYINT NOT NULL DEFAULT 1,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un comprobante (clave ING-000001 / EGR-000001) tiene uno o más renglones; «saldo» es la existencia que quedó.
CREATE TABLE IF NOT EXISTS stock_movimiento (
  id INT AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(20) NOT NULL,
  renglon INT NOT NULL DEFAULT 1,
  tipo VARCHAR(10) NOT NULL,
  insumo_id INT NOT NULL,
  cantidad DECIMAL(14,3) NOT NULL,
  precio DECIMAL(14,2) NULL,
  saldo DECIMAL(14,3) NOT NULL,
  fecha DATE NOT NULL,
  origen VARCHAR(60) NULL,
  motivo VARCHAR(60) NULL,
  comprobante VARCHAR(80) NULL,
  expediente VARCHAR(80) NULL,
  resolucion VARCHAR(80) NULL,
  proveedor_id INT NULL,
  orden VARCHAR(64) NULL,
  orden_numero VARCHAR(40) NULL,
  destino VARCHAR(64) NULL,
  destino_nombre VARCHAR(200) NULL,
  solicitante VARCHAR(120) NULL,
  observacion VARCHAR(400) NULL,
  usuario VARCHAR(60) NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_clave_renglon (clave, renglon),
  KEY k_insumo (insumo_id, fecha),
  KEY k_tipo_fecha (tipo, fecha),
  KEY k_orden (orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
