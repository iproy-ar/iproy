-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Núcleo iProy 1.6.0 · catálogos editables (listas desplegables). Migración 003.
-- Cada lista tiene valores con código estable; el texto se puede corregir sin romper lo ya cargado.
-- Los valores nunca se borran: se desactivan (activo = 0), así los registros viejos siguen mostrando su texto.
CREATE TABLE IF NOT EXISTS nucleo_catalogo (
  clave VARCHAR(60) PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  descripcion VARCHAR(400) NULL,
  origen VARCHAR(40) NOT NULL DEFAULT 'nucleo',
  norma VARCHAR(160) NULL,
  padre VARCHAR(60) NULL,
  permite_otro TINYINT NOT NULL DEFAULT 0,
  con_sigla TINYINT NOT NULL DEFAULT 0,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nucleo_catalogo_valor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  catalogo VARCHAR(60) NOT NULL,
  codigo VARCHAR(60) NOT NULL,
  texto VARCHAR(160) NOT NULL,
  padre_codigo VARCHAR(60) NULL,
  sigla VARCHAR(12) NULL,
  orden INT NOT NULL DEFAULT 100,
  activo TINYINT NOT NULL DEFAULT 1,
  semilla TINYINT NOT NULL DEFAULT 0,
  editado TINYINT NOT NULL DEFAULT 0,
  creado_por VARCHAR(60) NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_cat_cod (catalogo, codigo),
  KEY k_padre (catalogo, padre_codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
