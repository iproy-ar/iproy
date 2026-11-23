-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Grupos del menú y del inicio.
CREATE TABLE IF NOT EXISTS wid_grupo (
  id VARCHAR(40) NOT NULL PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL, color VARCHAR(9) NOT NULL DEFAULT '#546e7a', orden INT NOT NULL DEFAULT 50, activo TINYINT NOT NULL DEFAULT 1,
  modificado DATETIME NULL, modificado_por VARCHAR(60) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Catálogo. origen = 'modulo': el acceso lo aporta un módulo o el núcleo y acá solo se cambia cómo se ve.
--           origen = 'definido': widget creado desde la pantalla; «definicion» (JSON) dice qué hace.
CREATE TABLE IF NOT EXISTS wid_widget (
  id VARCHAR(60) NOT NULL PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL, grupo VARCHAR(40) NOT NULL DEFAULT '', icono VARCHAR(30) NOT NULL DEFAULT '', orden INT NOT NULL DEFAULT 50,
  activo TINYINT NOT NULL DEFAULT 1, descripcion VARCHAR(200) NOT NULL DEFAULT '',
  origen VARCHAR(10) NOT NULL DEFAULT 'modulo', tipo VARCHAR(20) NULL, definicion MEDIUMTEXT NULL, version INT NOT NULL DEFAULT 1,
  modificado DATETIME NULL, modificado_por VARCHAR(60) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Perfil = qué widgets ve una persona (el rol sigue diciendo qué puede hacer). «widgets» es una lista JSON de ids.
CREATE TABLE IF NOT EXISTS wid_perfil (
  id VARCHAR(40) NOT NULL PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL, color VARCHAR(9) NOT NULL DEFAULT '#1e88e5', descripcion VARCHAR(200) NOT NULL DEFAULT '', activo TINYINT NOT NULL DEFAULT 1,
  widgets MEDIUMTEXT NOT NULL, modificado DATETIME NULL, modificado_por VARCHAR(60) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS wid_usuario (
  usuario VARCHAR(60) NOT NULL PRIMARY KEY, perfil VARCHAR(40) NOT NULL, modificado DATETIME NULL, modificado_por VARCHAR(60) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Registros de los widgets creados. Cada fila pertenece a un solo widget.
CREATE TABLE IF NOT EXISTS wid_dato (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  widget VARCHAR(60) NOT NULL, numero INT NOT NULL, estado VARCHAR(40) NOT NULL DEFAULT '', datos MEDIUMTEXT NOT NULL,
  creado DATETIME NOT NULL, creado_por VARCHAR(60) NOT NULL, modificado DATETIME NULL, modificado_por VARCHAR(60) NULL, revision INT NOT NULL DEFAULT 1,
  UNIQUE KEY u_num (widget, numero), KEY k_w (widget, creado), KEY k_e (widget, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
