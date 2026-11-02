-- =====================================================================
-- iProy Portable «Colmena» · Modo autónomo (offline-first) · v0.4.0 · 29-10-2026
-- Base `iproy` del nodo. Idempotente: iProy-Control lo aplica en cada start.
--   · iproy_auth_local : verificador local del login (nunca la contraseña ni el hash de Google)
--   · iproy_gas_cache  : última respuesta de cada lectura a los Apps Script (para trabajar sin red)
--   · iproy_gas_cola   : escrituras hechas sin red, en orden, para reenviarlas a Google
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS iproy_auth_local (
  email            VARCHAR(120) NOT NULL,
  verificador      VARCHAR(255) NOT NULL,            -- password_hash() local (argon2id o bcrypt)
  perfil_json      MEDIUMTEXT   NOT NULL CHECK (JSON_VALID(perfil_json)),
  origen           ENUM('google','pepper') NOT NULL DEFAULT 'google',
  ultimo_online    DATETIME         NULL,            -- último login confirmado por Google
  ultimo_offline   DATETIME         NULL,            -- último login resuelto en el nodo
  fallidos         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta  DATETIME         NULL,
  PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS iproy_gas_cache (
  clave            CHAR(40)     NOT NULL,            -- sha1(implementación + parámetros normalizados)
  dep              VARCHAR(90)  NOT NULL,
  accion           VARCHAR(60)  NOT NULL DEFAULT '',
  tipo             VARCHAR(100) NOT NULL DEFAULT 'application/json',
  cuerpo           LONGTEXT     NOT NULL,
  bytes            INT UNSIGNED NOT NULL DEFAULT 0,
  actualizado      DATETIME     NOT NULL,
  PRIMARY KEY (clave),
  KEY ix_act (actualizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS iproy_gas_cola (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,  -- el orden de llegada es el orden de envío
  clave_idem       CHAR(32)     NOT NULL,
  dep              VARCHAR(90)  NOT NULL,
  accion           VARCHAR(60)  NOT NULL DEFAULT '',
  metodo           VARCHAR(8)   NOT NULL DEFAULT 'POST',
  query            MEDIUMTEXT       NULL,
  cuerpo           LONGTEXT         NULL,
  tipo             VARCHAR(150)     NULL,
  usuario          VARCHAR(120)     NULL,
  estado           ENUM('pendiente','enviado','error','dudoso','descartado') NOT NULL DEFAULT 'pendiente',
  intentos         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  proximo          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_error     VARCHAR(255)     NULL,
  respuesta        TEXT             NULL,
  creado           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviado          DATETIME         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_idem (clave_idem),
  KEY ix_cola (estado, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sync_schema (version, descripcion) VALUES (3, 'Modo autónomo: login local, caché de lecturas y cola de escrituras (v0.4.0)');
