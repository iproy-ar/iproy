-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- 2.2.0: un activo fuera de circulación no genera órdenes. Sus tareas y planes se pausan solos y se marcan «baja»;
-- si el activo vuelve a servicio, se reanudan solos los que se pausaron por eso (no los que pausó una persona).
ALTER TABLE pmp_tarea ADD COLUMN IF NOT EXISTS pausa VARCHAR(20) NULL COMMENT 'baja = pausada porque el activo salió de servicio';
ALTER TABLE prev_plan ADD COLUMN IF NOT EXISTS pausa VARCHAR(20) NULL COMMENT 'baja = pausado porque el activo salió de servicio'
