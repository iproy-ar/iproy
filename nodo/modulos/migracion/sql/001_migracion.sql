-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Equivalencias: valor del sistema de origen → valor PIM. «origen» se guarda normalizado (minúsculas, sin acentos);
-- una condición va después de «|» (p. ej. «revision|con_fin»). destino vacío = sin equivalente (se conserva el texto original).
CREATE TABLE IF NOT EXISTS mig_equivalencia (
  lista VARCHAR(30) NOT NULL, origen VARCHAR(160) NOT NULL, etiqueta VARCHAR(160) NOT NULL DEFAULT '', destino VARCHAR(80) NOT NULL DEFAULT '', nota VARCHAR(240) NOT NULL DEFAULT '',
  semilla TINYINT NOT NULL DEFAULT 0, modificado DATETIME NULL, modificado_por VARCHAR(60) NULL,
  PRIMARY KEY (lista, origen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mig_corrida (
  id INT AUTO_INCREMENT PRIMARY KEY, creado DATETIME NOT NULL, creado_por VARCHAR(60) NOT NULL,
  origen VARCHAR(20) NOT NULL, origen_detalle VARCHAR(200) NOT NULL DEFAULT '', destino VARCHAR(20) NOT NULL, simulada TINYINT NOT NULL DEFAULT 0,
  filas INT NOT NULL DEFAULT 0, resumen MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
