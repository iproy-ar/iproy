-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- 1.2.0: planes traídos de otro sistema (hoja PMP, CSV, MySQL). La clave de origen hace la carga repetible sin duplicar.
ALTER TABLE prev_plan
  ADD COLUMN IF NOT EXISTS origen VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS origen_clave CHAR(40) NULL,
  ADD UNIQUE KEY IF NOT EXISTS u_origen (origen_clave);
