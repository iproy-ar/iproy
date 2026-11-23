-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- 2.1.0: tareas diarias con 7 casillas dentro de la orden semanal (lunes a domingo).
-- Un carácter por día: '1' hecho, '0' sin hacer, '-' fuera del año (primera y última semana parciales).
ALTER TABLE pmp_ejecucion ADD COLUMN IF NOT EXISTS dias CHAR(7) NULL AFTER fecha_real;
