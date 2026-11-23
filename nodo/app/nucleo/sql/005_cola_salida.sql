-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Núcleo iProy 1.6.3 · cola de salida de la colmena. Migración 005.
--  · nucleo_salida: lo que no se pudo entregar porque no había camino (el otro nodo apagado, el hub caído,
--    este equipo sin red). Se guarda el CONTENIDO, no el sobre: el sobre se arma y se firma de nuevo en cada
--    intento (la aserción de un RPC dura 4 minutos), siempre con el mismo id, para que el otro nodo descarte
--    una entrega repetida.
--  · nucleo_rpc.enviado: momento en que el pedido salió de verdad; el plazo de respuesta se cuenta desde ahí.
CREATE TABLE IF NOT EXISTS nucleo_salida (
  id CHAR(26) PRIMARY KEY,
  par VARCHAR(12) NOT NULL,
  tipo VARCHAR(20) NOT NULL,
  sellado TINYINT NOT NULL DEFAULT 1,
  datos MEDIUMTEXT NOT NULL,
  rpc CHAR(26) NULL,
  intentos INT NOT NULL DEFAULT 0,
  ultimo_error VARCHAR(300) NULL,
  creado DATETIME(3) NOT NULL,
  proximo DATETIME NOT NULL,
  vence DATETIME NOT NULL,
  KEY k_par (par, creado),
  KEY k_proximo (proximo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE nucleo_rpc
  ADD COLUMN IF NOT EXISTS enviado DATETIME(3) NULL;
