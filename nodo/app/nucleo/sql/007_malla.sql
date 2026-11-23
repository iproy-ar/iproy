-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Núcleo iProy 1.7.0 · malla de la colmena (cada nodo es hub, puente, emisor y receptor). Migración 007.
--  · nucleo_hub_buzon: el buzón del HUB PROPIO de este nodo. Guarda sobres para los nodos vinculados que lo usan de relevo
--    (los sobres siguen firmados y sellados de extremo a extremo: este nodo no puede leerlos ni alterarlos).
--  · nucleo_hub_nonce: cada pedido firmado al hub propio se acepta una sola vez; sirve también para el tope por minuto.
--  · nucleo_hub_oyente: quién está escuchando en el hub propio (último pedido de buzón de cada nodo).
--  · nucleo_malla_hub: los hubs de OTROS nodos que este nodo alcanza (dirección, estado, espera, a quiénes llegan).
--  · nucleo_ruta: caminos conocidos hacia cada nodo vinculado, con su demora medida; se elige el más rápido que anda.
--  · nucleo_puente_visto: sobres ya reenviados como puente (no se reenvían dos veces ni dan vueltas).
--  · nucleo_vinculo.urls / url_ok / malla: direcciones que anuncia el otro nodo, la que respondió y lo que anunció de su hub.
--  · nucleo_vinculo.via: «hub» pasa a significar solo «nunca directo, lo pidió el administrador». Antes se ponía sola al vincularse
--    por el hub; ahora el camino se elige midiendo, así que los vínculos existentes vuelven a «auto».
CREATE TABLE IF NOT EXISTS nucleo_hub_buzon (
  nro BIGINT AUTO_INCREMENT PRIMARY KEY,
  id VARCHAR(40) NOT NULL,
  de VARCHAR(12) NOT NULL,
  para VARCHAR(12) NOT NULL,
  por VARCHAR(12) NOT NULL,
  saltos TINYINT NOT NULL DEFAULT 0,
  recibido DATETIME NOT NULL,
  intento DATETIME NULL,
  contenido MEDIUMTEXT NOT NULL,
  UNIQUE KEY u_sobre (id, para),
  KEY k_para (para, nro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nucleo_hub_nonce (
  h CHAR(64) PRIMARY KEY,
  nodo VARCHAR(12) NOT NULL,
  ts INT NOT NULL,
  KEY k_nodo (nodo, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nucleo_hub_oyente (
  nodo VARCHAR(12) PRIMARY KEY,
  visto INT NOT NULL,
  espera_hasta INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nucleo_malla_hub (
  clave VARCHAR(12) PRIMARY KEY,
  url VARCHAR(255) NOT NULL,
  estado VARCHAR(12) NOT NULL DEFAULT 'nuevo',
  version VARCHAR(24) NULL,
  espera_max INT NOT NULL DEFAULT 0,
  desfase INT NOT NULL DEFAULT 0,
  ms INT NULL,
  probado INT NOT NULL DEFAULT 0,
  ok_ts INT NOT NULL DEFAULT 0,
  falla_ts INT NOT NULL DEFAULT 0,
  fallos INT NOT NULL DEFAULT 0,
  error VARCHAR(200) NULL,
  oyentes TEXT NULL,
  alcanza TEXT NULL,
  malla_ts INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nucleo_ruta (
  par VARCHAR(12) NOT NULL,
  camino VARCHAR(24) NOT NULL,
  ms INT NULL,
  ok_ts INT NOT NULL DEFAULT 0,
  eco_ts INT NOT NULL DEFAULT 0,
  falla_ts INT NOT NULL DEFAULT 0,
  fallos INT NOT NULL DEFAULT 0,
  error VARCHAR(200) NULL,
  ping_id CHAR(26) NULL,
  ping_ts DOUBLE NULL,
  usos INT NOT NULL DEFAULT 0,
  PRIMARY KEY (par, camino),
  KEY k_ping (ping_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nucleo_puente_visto (
  id VARCHAR(60) PRIMARY KEY,
  ts INT NOT NULL,
  KEY k_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE nucleo_vinculo ADD COLUMN IF NOT EXISTS urls TEXT NULL;
ALTER TABLE nucleo_vinculo ADD COLUMN IF NOT EXISTS url_ok VARCHAR(255) NULL;
ALTER TABLE nucleo_vinculo ADD COLUMN IF NOT EXISTS malla TEXT NULL;
UPDATE nucleo_vinculo SET via = 'auto' WHERE via = 'hub'
