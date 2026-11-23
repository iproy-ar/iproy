-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Clase de la orden: «equipo» (equipamiento médico) o «instalacion». Permite paneles separados (Auditoría Mant / Auditor Bio).
-- Lo ya proyectado se completa por aproximación (con activo = equipo); «reconstruir» lo deja exacto.
ALTER TABLE rep_orden ADD COLUMN IF NOT EXISTS clase VARCHAR(12) NULL, ADD KEY IF NOT EXISTS k_clase (clase, creada);
UPDATE rep_orden SET clase = IF(activo_id IS NULL, 'instalacion', 'equipo') WHERE clase IS NULL;
