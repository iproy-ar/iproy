-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- 1.3.0: registro por tarea de cada orden preventiva (una orden por visita, pero cada tarea queda con su resultado, como el Historial del sistema anterior).
CREATE TABLE IF NOT EXISTS prev_tarea (
  id INT AUTO_INCREMENT PRIMARY KEY,
  generada_id INT NOT NULL,
  plan_id INT NOT NULL,
  n SMALLINT NOT NULL,
  tarea VARCHAR(300) NOT NULL,
  resultado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
  nota VARCHAR(300) NULL,
  usuario VARCHAR(60) NULL,
  actualizado DATETIME NULL,
  UNIQUE KEY u_gen_n (generada_id, n), KEY k_plan (plan_id, resultado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
