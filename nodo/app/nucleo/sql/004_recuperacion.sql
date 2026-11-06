-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Núcleo iProy 1.6.0 · recuperación de contraseña sin Internet. Migración 004.
--  · email del usuario (opcional: el enlace por correo solo funciona si el nodo tiene salida a Internet y SMTP)
--  · cambiar_clave + clave_vence: clave temporal que da el administrador; al ingresar se exige cambiarla
--  · nucleo_recuperacion: códigos de un solo uso (se guardan como HMAC, nunca en claro) y enlaces por correo
ALTER TABLE nucleo_usuario
  ADD COLUMN IF NOT EXISTS email VARCHAR(160) NULL,
  ADD COLUMN IF NOT EXISTS cambiar_clave TINYINT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS clave_vence DATETIME NULL;

CREATE TABLE IF NOT EXISTS nucleo_recuperacion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(60) NOT NULL,
  tipo VARCHAR(10) NOT NULL,
  hash CHAR(64) NOT NULL,
  vence DATETIME NULL,
  usado DATETIME NULL,
  ip VARCHAR(45) NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_usuario (usuario, tipo),
  UNIQUE KEY u_hash (hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
