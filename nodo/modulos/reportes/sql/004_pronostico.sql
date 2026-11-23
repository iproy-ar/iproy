-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Reportes 1.3.0 · corridas de Google TimesFM (u otro modelo externo) que se calculan fuera del nodo y se guardan acá.
-- Serie: «total» · «srv:<servicio>» · «org:<id de empresa>»; frecuencia M (mensual). Cada corrida reemplaza a la anterior de la misma serie al mostrarla.
CREATE TABLE IF NOT EXISTS rep_corrida (
  id INT AUTO_INCREMENT PRIMARY KEY,
  modelo VARCHAR(80) NOT NULL, creada DATETIME NOT NULL, usuario VARCHAR(60) NULL,
  series INT NOT NULL DEFAULT 0, filas INT NOT NULL DEFAULT 0, nota VARCHAR(200) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS rep_pronostico (
  corrida_id INT NOT NULL, serie VARCHAR(190) NOT NULL, freq CHAR(1) NOT NULL DEFAULT 'M', periodo DATE NOT NULL,
  p10 DECIMAL(14,2) NULL, p50 DECIMAL(14,2) NOT NULL, p90 DECIMAL(14,2) NULL, ultimo_dato DATE NULL,
  PRIMARY KEY (corrida_id, serie, freq, periodo), KEY k_serie (serie, freq, corrida_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
