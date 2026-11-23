-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- 1.5.0: código vigente del activo en la copia de las órdenes (búsquedas y estadísticas por código). Se completa con los eventos
-- pim/Device/* (si se corrige el código de un activo, todas sus órdenes pasan a mostrar el nuevo).
ALTER TABLE rep_orden ADD COLUMN IF NOT EXISTS activo_codigo VARCHAR(80) NULL AFTER activo_id;
ALTER TABLE rep_orden ADD KEY IF NOT EXISTS k_activo (activo_id);
ALTER TABLE rep_orden ADD KEY IF NOT EXISTS k_activo_codigo (activo_codigo)
