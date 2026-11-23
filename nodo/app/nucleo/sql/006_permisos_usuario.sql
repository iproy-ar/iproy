-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Núcleo iProy 1.7.0 · permisos propios de un usuario, sobre los de su rol. Migración 006 (la 005 es la cola de salida de la 1.6.3).
--  · cap_extra: capacidades que se le suman aunque su rol no las tenga (nunca las reservadas al administrador ni las del Servidor PIM)
--  · cap_bloqueadas: capacidades que se le quitan aunque su rol las tenga
ALTER TABLE nucleo_usuario
  ADD COLUMN IF NOT EXISTS cap_extra TEXT NULL,
  ADD COLUMN IF NOT EXISTS cap_bloqueadas TEXT NULL;
