-- =====================================================================
-- iProy Portable «Colmena» · DDL de sincronización (propuesta Fase 1)
-- Documento: IPROY-POR-001 · anexo A · v0.1 · 28-10-2026
-- Base única por nodo (decisión 28-10-2026): `iproy`, con prefijos por dominio.
-- Probado en MariaDB 10.11.14 (contenedor). Objetivo: MariaDB 11.4 LTS.
-- Reglas: utf8mb4_unicode_ci · fechas en UTC · HLC como texto ordenable.
-- HLC = 13 dígitos de ms UTC + '-' + 4 hex de contador + '-' + node_id (3)
--       ej. 1761674400000-0000-K7P  (22 caracteres, orden lexicográfico = causal)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------- 1. Identidad y versión del nodo --------------------------
CREATE TABLE IF NOT EXISTS sync_nodo (
  node_id        CHAR(3)      NOT NULL,           -- alfabeto A31 (sin 0 O 1 I L)
  nombre         VARCHAR(80)  NOT NULL,
  rol            ENUM('nodo','hub') NOT NULL DEFAULT 'nodo',
  clave_pub      VARBINARY(32)    NULL,           -- Ed25519 (sodium)
  confirmado     TINYINT(1)   NOT NULL DEFAULT 0, -- 1 = el hub confirmó que es único
  local_actual   TINYINT(1)   NOT NULL DEFAULT 0, -- 1 = este nodo
  alta_utc       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_schema (
  version        INT UNSIGNED NOT NULL,
  aplicado_utc   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  descripcion    VARCHAR(200) NOT NULL,
  sha256         CHAR(64)         NULL,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_kv (          -- último HLC emitido, secretos NO
  clave          VARCHAR(64)  NOT NULL,
  valor          VARCHAR(255) NOT NULL,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 2. Registro de cambios (append-only) ---------------------
CREATE TABLE IF NOT EXISTS sync_changelog (
  seq            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, -- orden LOCAL de llegada (cursor de envío)
  change_id      CHAR(26)     NOT NULL,           -- ULID único global (idempotencia)
  hlc            CHAR(22)     NOT NULL,
  base_hlc       CHAR(22)         NULL,           -- HLC del campo que vio el editor (detecta concurrencia)
  node_id        CHAR(3)      NOT NULL,           -- nodo que ORIGINÓ el cambio
  tabla          VARCHAR(64)  NOT NULL,
  uid            CHAR(26)     NOT NULL,
  campo          VARCHAR(64)  NOT NULL,           -- '*' para alta (I) y baja (D)
  op             CHAR(1)      NOT NULL,           -- I alta · U cambio · D baja (lápida)
  valor          LONGTEXT         NULL CHECK (valor IS NULL OR JSON_VALID(valor)),
  usuario        VARCHAR(120)     NULL,
  schema_version INT UNSIGNED NOT NULL,
  via            ENUM('local','hub','lan','pendrive','importacion') NOT NULL DEFAULT 'local',
  recibido_utc   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (seq),
  UNIQUE KEY uk_change (change_id),
  KEY ix_hlc (hlc),
  KEY ix_reg (tabla, uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 3. Estado LWW por campo ----------------------------------
CREATE TABLE IF NOT EXISTS sync_campo (
  tabla          VARCHAR(64)  NOT NULL,
  uid            CHAR(26)     NOT NULL,
  campo          VARCHAR(64)  NOT NULL,
  hlc            CHAR(22)     NOT NULL,           -- HLC del valor vigente
  change_id      CHAR(26)     NOT NULL,
  PRIMARY KEY (tabla, uid, campo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 4. Conflictos (nada se pierde) ---------------------------
CREATE TABLE IF NOT EXISTS sync_conflictos (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tabla          VARCHAR(64)  NOT NULL,
  uid            CHAR(26)     NOT NULL,
  campo          VARCHAR(64)  NOT NULL,
  valor_ganador  LONGTEXT         NULL,
  hlc_ganador    CHAR(22)     NOT NULL,
  valor_perdedor LONGTEXT         NULL,
  hlc_perdedor   CHAR(22)     NOT NULL,
  change_perdedor CHAR(26)    NOT NULL,
  detectado_utc  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  revisado_por   VARCHAR(120)     NULL,
  revisado_utc   DATETIME(3)      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_conf (change_perdedor, hlc_ganador),
  KEY ix_pend (revisado_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 5. Vecinos y cursores por vía ----------------------------
CREATE TABLE IF NOT EXISTS sync_peers (
  peer_id        CHAR(3)      NOT NULL,
  via            ENUM('hub','lan','pendrive') NOT NULL,
  direccion      VARCHAR(255)     NULL,           -- https://IP:puerto o dominio; NULL en pendrive
  descubierto    ENUM('manual','mdns','hub') NOT NULL DEFAULT 'manual',
  cursor_enviado BIGINT UNSIGNED NOT NULL DEFAULT 0, -- último seq local que el peer confirmó
  cursor_recibido BIGINT UNSIGNED NOT NULL DEFAULT 0, -- último seq DEL PEER ya aplicado aquí
  ultima_sync_utc DATETIME(3)     NULL,
  ultimo_error   VARCHAR(255)     NULL,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (peer_id, via)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 6. Numeración por nodo (NO se sincroniza) ----------------
CREATE TABLE IF NOT EXISTS sync_secuencia (
  serie          VARCHAR(32)  NOT NULL,           -- 'req', 'bio', 'ped', 'sgr' ...
  ultimo         INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (serie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 7. Cola de salida a servicios con Internet ---------------
CREATE TABLE IF NOT EXISTS sync_outbox (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  canal          ENUM('telegram','whatsapp','email','hub_mysql','timesfm','portal_exp') NOT NULL,
  clave_idem     CHAR(26)     NOT NULL,           -- evita doble envío al reintentar
  payload        LONGTEXT     NOT NULL CHECK (JSON_VALID(payload)),
  estado         ENUM('pendiente','enviando','enviado','error','descartado') NOT NULL DEFAULT 'pendiente',
  intentos       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  proximo_utc    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  ultimo_error   VARCHAR(255)     NULL,
  creado_utc     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_idem (canal, clave_idem),
  KEY ix_cola (estado, proximo_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 8. Columnas comunes de toda tabla replicada --------------
-- Ejemplo de dominio: requerimientos (resumido; los campos reales salen de Req_IPROY)
CREATE TABLE IF NOT EXISTS req_ordenes (
  uid            CHAR(26)     NOT NULL,           -- clave interna (ULID); NUNCA el correlativo
  nodo_origen    CHAR(3)      NOT NULL,
  nro_local      INT UNSIGNED NOT NULL,           -- correlativo del nodo que la creó
  codigo         VARCHAR(16)      NULL,           -- 'RTE-PTA' (tarea-área), lo calcula lib_codigos
  nro_visible    VARCHAR(40)      NULL,           -- 12-RTE-PTA·K7P; lo calcula lib_sync (ver anexo: MariaDB rechaza la columna generada)
  servicio       VARCHAR(120)     NULL,
  descripcion    TEXT             NULL,
  estado         VARCHAR(30)      NULL,
  prioridad      CHAR(1)          NULL,           -- A B C D
  empresa        VARCHAR(60)      NULL,
  creado_por     VARCHAR(120)     NULL,
  creado_hlc     CHAR(22)     NOT NULL,
  modificado_hlc CHAR(22)     NOT NULL,
  deleted_hlc    CHAR(22)         NULL,           -- lápida: nunca borrado físico
  version        INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (uid),
  UNIQUE KEY uk_nro (nodo_origen, nro_local),
  KEY ix_visible (nro_visible),
  KEY ix_estado (estado, deleted_hlc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock: EVENTOS, no saldos (RP-02). El saldo es una vista.
CREATE TABLE IF NOT EXISTS stock_movimientos (
  uid            CHAR(26)     NOT NULL,
  clave_movim    VARCHAR(80)  NOT NULL,           -- idempotencia funcional (OT+item+tipo)
  codigo_item    VARCHAR(30)  NOT NULL,
  tipo           ENUM('ingreso','egreso','ajuste','anulacion') NOT NULL,
  cantidad       DECIMAL(12,3) NOT NULL,          -- con signo: egreso < 0
  ref_orden_uid  CHAR(26)         NULL,
  nodo_origen    CHAR(3)      NOT NULL,
  creado_por     VARCHAR(120)     NULL,
  creado_hlc     CHAR(22)     NOT NULL,
  modificado_hlc CHAR(22)     NOT NULL,
  deleted_hlc    CHAR(22)         NULL,
  version        INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (uid),
  UNIQUE KEY uk_clave (clave_movim),
  KEY ix_item (codigo_item)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW stock_saldo AS
  SELECT codigo_item, SUM(cantidad) AS saldo, COUNT(*) AS movimientos
  FROM stock_movimientos WHERE deleted_hlc IS NULL GROUP BY codigo_item;

INSERT IGNORE INTO sync_schema (version, descripcion) VALUES (1, 'Base de sync colmena v0.1 (Fase 1)');
