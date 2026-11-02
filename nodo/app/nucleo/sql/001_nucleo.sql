-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Núcleo iProy · tablas propias (base «iproy» del nodo). Migración 001.
CREATE TABLE IF NOT EXISTS nucleo_modulo (
  id VARCHAR(40) PRIMARY KEY,
  version VARCHAR(20) NOT NULL,
  activo TINYINT NOT NULL DEFAULT 1,
  estado VARCHAR(20) NOT NULL DEFAULT 'cargado',
  error TEXT NULL,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nucleo_evento (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ts DATETIME(3) NOT NULL,
  tema VARCHAR(160) NOT NULL,
  origen VARCHAR(60) NOT NULL,
  datos MEDIUMTEXT NOT NULL,
  KEY k_tema (tema), KEY k_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nucleo_suscripcion (
  suscriptor VARCHAR(160) PRIMARY KEY,
  cursor_id BIGINT NOT NULL DEFAULT 0,
  fallos INT NOT NULL DEFAULT 0,
  ultimo_error TEXT NULL,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nucleo_tarea (
  id VARCHAR(120) PRIMARY KEY,
  cada_s INT NOT NULL,
  proxima DATETIME NOT NULL,
  ultima DATETIME NULL,
  duracion_ms INT NULL,
  resultado VARCHAR(255) NULL,
  ok TINYINT NOT NULL DEFAULT 1,
  fallos INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Diario append-only: cada asiento encadena el hash del anterior y va firmado (ed25519) con la clave del nodo.
CREATE TABLE IF NOT EXISTS nucleo_diario (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ts DATETIME(3) NOT NULL,
  tipo VARCHAR(60) NOT NULL,
  actor VARCHAR(80) NOT NULL,
  objeto VARCHAR(160) NULL,
  detalle MEDIUMTEXT NOT NULL,
  hash_prev CHAR(64) NOT NULL,
  hash CHAR(64) NOT NULL,
  firma VARCHAR(100) NOT NULL,
  KEY k_tipo (tipo), KEY k_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nucleo_usuario (
  usuario VARCHAR(60) PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  rol CHAR(2) NOT NULL,
  organizacion VARCHAR(80) NULL,
  clave_hash VARCHAR(255) NOT NULL,
  activo TINYINT NOT NULL DEFAULT 1,
  intentos INT NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME NULL,
  ultimo_ingreso DATETIME NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colmena: vínculos firmados entre nodos («el vínculo es la autorización»)
CREATE TABLE IF NOT EXISTS nucleo_vinculo (
  par VARCHAR(12) PRIMARY KEY,
  nombre VARCHAR(120) NULL,
  producto VARCHAR(60) NULL,
  url VARCHAR(255) NULL,
  firma_pub VARCHAR(64) NOT NULL,
  caja_pub VARCHAR(64) NOT NULL,
  pim_jwk TEXT NOT NULL,
  huella VARCHAR(40) NOT NULL,
  estado VARCHAR(12) NOT NULL,
  rol_pim CHAR(2) NOT NULL DEFAULT 'CI',
  via VARCHAR(10) NOT NULL DEFAULT 'auto',
  ultimo_contacto DATETIME NULL,
  alta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nucleo_rpc (
  id CHAR(26) PRIMARY KEY,
  par VARCHAR(12) NOT NULL,
  metodo VARCHAR(8) NOT NULL,
  ruta VARCHAR(500) NOT NULL,
  via VARCHAR(10) NOT NULL,
  estado VARCHAR(12) NOT NULL,
  http INT NULL,
  respuesta MEDIUMTEXT NULL,
  creado DATETIME(3) NOT NULL,
  respondido DATETIME(3) NULL,
  KEY k_par (par)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sobres ya procesados (anti-repetición; se guardan 9 días, más que la vida de un sobre en el hub)
CREATE TABLE IF NOT EXISTS nucleo_sobre_visto (
  id CHAR(26) PRIMARY KEY,
  de VARCHAR(12) NOT NULL,
  ts DATETIME NOT NULL,
  KEY k_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
