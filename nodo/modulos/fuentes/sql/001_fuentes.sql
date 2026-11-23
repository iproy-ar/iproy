-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Fuentes externas (HTTP, JSON, solo lectura) y marcas para no repetir lecturas ni eventos.
CREATE TABLE IF NOT EXISTS fue_fuente (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  descripcion VARCHAR(300) NULL,
  url VARCHAR(1000) NOT NULL,
  cada_s INT NOT NULL DEFAULT 60,
  activa TINYINT NOT NULL DEFAULT 0,
  reglas MEDIUMTEXT NOT NULL,
  historial MEDIUMTEXT NULL,
  ultimo_intento DATETIME NULL,
  ultimo_ok DATETIME NULL,
  estado VARCHAR(12) NOT NULL DEFAULT 'nueva',
  error VARCHAR(500) NULL,
  lecturas BIGINT NOT NULL DEFAULT 0,
  eventos BIGINT NOT NULL DEFAULT 0,
  modificado DATETIME NULL, modificado_por VARCHAR(60) NULL,
  UNIQUE KEY u_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- última lectura publicada de cada activo y punto (lo que no es más nuevo no se vuelve a publicar)
CREATE TABLE IF NOT EXISTS fue_marca (
  fuente_id INT NOT NULL, clave VARCHAR(120) NOT NULL, ts INT NOT NULL,
  PRIMARY KEY (fuente_id, clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- eventos ya publicados (con la huella de su estado: si cambia, por ejemplo al reconocerse, se publica de nuevo)
CREATE TABLE IF NOT EXISTS fue_visto (
  fuente_id INT NOT NULL, clave VARCHAR(120) NOT NULL, huella CHAR(8) NOT NULL,
  PRIMARY KEY (fuente_id, clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
