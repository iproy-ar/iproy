-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Reportes 1.4.0 · tipo de tarea de la orden = clase de sistema PIM (Task.code con el sistema pim-sistema), para filtrar y pronosticar por tarea.
-- Las órdenes ya copiadas se completan en la próxima reconstrucción (una sola pasada por la API PIM).
ALTER TABLE rep_orden ADD COLUMN IF NOT EXISTS tarea VARCHAR(20) NULL, ADD KEY IF NOT EXISTS k_tarea (tarea, creada);
