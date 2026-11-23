-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
CREATE TABLE IF NOT EXISTS ntf_regla (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  tema VARCHAR(80) NOT NULL,
  condicion TEXT NOT NULL,
  canal VARCHAR(12) NOT NULL,
  destino VARCHAR(600) NOT NULL,
  silencio_min INT NOT NULL DEFAULT 0,
  activa TINYINT NOT NULL DEFAULT 1,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modificado DATETIME NULL,
  modificado_por VARCHAR(60) NULL,
  KEY k_tema (tema, activa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ntf_envio (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  regla_id INT NOT NULL,
  evento_id BIGINT NOT NULL,
  clave VARCHAR(140) NOT NULL,
  tema VARCHAR(80) NOT NULL,
  asunto VARCHAR(200) NOT NULL,
  datos TEXT NOT NULL,
  canal VARCHAR(12) NOT NULL,
  destino VARCHAR(400) NOT NULL,
  estado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
  intentos INT NOT NULL DEFAULT 0,
  proximo DATETIME NOT NULL,
  error VARCHAR(300) NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviado DATETIME NULL,
  UNIQUE KEY u_regla_evento (regla_id, evento_id, destino),
  KEY k_estado (estado, proximo), KEY k_clave (regla_id, clave, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
