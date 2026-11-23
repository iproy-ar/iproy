-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Áreas de auditoría: qué empresas o áreas ofrece cada panel. Una misma empresa puede estar en los dos.
-- Un panel sin filas ofrece todas (así un nodo nuevo funciona sin configurar nada).
CREATE TABLE IF NOT EXISTS aud_area (
  panel VARCHAR(12) NOT NULL, org_id CHAR(26) NOT NULL,
  modificado DATETIME NOT NULL, modificado_por VARCHAR(60) NULL,
  PRIMARY KEY (panel, org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
