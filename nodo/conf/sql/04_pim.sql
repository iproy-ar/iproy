-- =====================================================================
-- iProy · Servidor PIM (Protocolo de Interoperabilidad de Mantenimiento) · modelo nativo · v1.0.0 · 30-10-2026
-- Base PROPIA «iproy_pim»: un sistema paralelo. No modifica ninguna tabla de iProy ni de las bases legado_*.
-- La importación inicial LEE las vistas iproy_fhir.* (solo lectura) y copia a estas tablas.
-- Reglas PIM v0.9: IDs ULID (26), versión por registro (If-Match), nunca borrado físico, sin datos de pacientes.
-- Idempotente: iProy-Control lo aplica en cada start.
-- =====================================================================
SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS iproy_pim CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE iproy_pim;

-- Organization: organismo (pim-organismo), empresas contratistas y áreas internas (pim-empresa)
CREATE TABLE IF NOT EXISTS pim_organizacion (
  id            CHAR(26)     NOT NULL,
  tipo          ENUM('organismo','empresa','area') NOT NULL,
  codigo        VARCHAR(60)  NOT NULL,
  nombre        VARCHAR(160) NOT NULL,
  refes         VARCHAR(30)      NULL,
  parte_de      CHAR(26)         NULL,
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  origen        VARCHAR(20)  NOT NULL DEFAULT 'pim' COMMENT 'pim | legado (importado de iProy)',
  origen_huella     CHAR(40)         NULL COMMENT 'importación: huella de los datos de iProy (detecta cambios)',
  version       INT UNSIGNED NOT NULL DEFAULT 1,
  creado        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  actualizado   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), UNIQUE KEY uk_org_codigo (tipo, codigo), KEY ix_org_act (actualizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Location: edificio, nivel, local, sala, cama o área de servicio (pim-local)
CREATE TABLE IF NOT EXISTS pim_local (
  id               CHAR(26)     NOT NULL,
  codigo           VARCHAR(60)  NOT NULL,
  nombre           VARCHAR(160) NOT NULL,
  tipo_fisico      VARCHAR(4)   NOT NULL DEFAULT 'area' COMMENT 'bu | wi | lvl | ro | bd | area (location-physical-type)',
  parte_de         CHAR(26)         NULL,
  grupo_uso_medico VARCHAR(2)       NULL COMMENT 'AEA 90364-7-710: 0 | 1 | 2a | 2b',
  clase_iso14644   VARCHAR(10)      NULL,
  presion_relativa CHAR(1)          NULL COMMENT '+ | - | 0',
  renovaciones_h   DECIMAL(6,1)     NULL,
  estado           VARCHAR(10)  NOT NULL DEFAULT 'active',
  origen           VARCHAR(20)  NOT NULL DEFAULT 'pim',
  origen_huella     CHAR(40)         NULL COMMENT 'importación: huella de los datos de iProy (detecta cambios)',
  version          INT UNSIGNED NOT NULL DEFAULT 1,
  creado           DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  actualizado      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), UNIQUE KEY uk_loc_codigo (codigo), KEY ix_loc_nombre (nombre), KEY ix_loc_act (actualizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Device: sistema de instalación (pim-sistema), equipo o componente (pim-activo) y equipo médico (puente)
CREATE TABLE IF NOT EXISTS pim_activo (
  id                CHAR(26)     NOT NULL,
  clase             ENUM('sistema','activo','equipo-medico') NOT NULL,
  codigo            VARCHAR(60)  NOT NULL,
  nombre            VARCHAR(200) NOT NULL,
  tipo_codigo       VARCHAR(40)      NULL COMMENT 'pim-sistema (instalaciones) o clase-equipo (equipos médicos)',
  tipo_texto        VARCHAR(160)     NULL,
  categoria_riesgo  TINYINT          NULL COMMENT 'NFPA 99: 1 (lesión grave o muerte) … 4 (sin impacto)',
  redundancia       VARCHAR(4)       NULL COMMENT 'N | N+1 | 2N',
  local_id          CHAR(26)         NULL,
  padre_id          CHAR(26)         NULL COMMENT 'jerarquía ISO 14224',
  propietario_id    CHAR(26)         NULL,
  fabricante        VARCHAR(120)     NULL,
  modelo            VARCHAR(120)     NULL,
  serie             VARCHAR(120)     NULL,
  estado            VARCHAR(20)  NOT NULL DEFAULT 'active' COMMENT 'active | inactive | entered-in-error',
  iru_f             TINYINT          NULL COMMENT 'Índice de Riesgo Unificado: función 2–10',
  iru_r             TINYINT          NULL COMMENT 'riesgo 1–5',
  iru_m             TINYINT          NULL COMMENT 'mantenimiento 1–5',
  iru_a             TINYINT          NULL COMMENT 'historial −2…+2',
  origen            VARCHAR(20)  NOT NULL DEFAULT 'pim',
  origen_huella     CHAR(40)         NULL COMMENT 'importación: huella de los datos de iProy (detecta cambios)',
  version           INT UNSIGNED NOT NULL DEFAULT 1,
  creado            DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  actualizado       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), UNIQUE KEY uk_act_codigo (codigo), KEY ix_act_local (local_id), KEY ix_act_clase (clase, tipo_codigo), KEY ix_act_act (actualizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rango aceptable de cada punto de medición de un activo (PIM-TEL)
CREATE TABLE IF NOT EXISTS pim_punto (
  activo_id   CHAR(26)     NOT NULL,
  punto       VARCHAR(40)  NOT NULL COMMENT 'pim-punto: PRES-LINEA, TEMP-AMB, HUM-REL…',
  unidad      VARCHAR(20)  NOT NULL COMMENT 'UCUM',
  minimo      DECIMAL(14,4)    NULL,
  maximo      DECIMAL(14,4)    NULL,
  genera_orden TINYINT(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (activo_id, punto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ServiceRequest: solicitud de servicio (pim-solicitud, PIM-SOL)
CREATE TABLE IF NOT EXISTS pim_solicitud (
  id               CHAR(26)     NOT NULL,
  numero           VARCHAR(30)  NOT NULL,
  estado           VARCHAR(20)  NOT NULL DEFAULT 'active' COMMENT 'active | completed | revoked | entered-in-error',
  prioridad        VARCHAR(10)  NOT NULL DEFAULT 'routine' COMMENT 'routine | urgent | asap | stat',
  categoria_codigo VARCHAR(40)      NULL,
  categoria_texto  VARCHAR(160)     NULL,
  descripcion      TEXT             NULL,
  local_id         CHAR(26)         NULL,
  activo_id        CHAR(26)         NULL,
  estado_declarado VARCHAR(25)      NULL COMMENT 'operativo-con-falla | degradado | fuera-de-servicio',
  solicitante      VARCHAR(160)     NULL COMMENT 'rol o servicio que pide (nunca un paciente)',
  cliente          VARCHAR(64)  NOT NULL,
  autoria          DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  version          INT UNSIGNED NOT NULL DEFAULT 1,
  actualizado      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), UNIQUE KEY uk_sol_numero (numero), KEY ix_sol_estado (estado), KEY ix_sol_act (actualizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Task: orden de trabajo (pim-orden, PIM-OT)
CREATE TABLE IF NOT EXISTS pim_orden (
  id                 CHAR(26)     NOT NULL,
  numero             VARCHAR(30)  NOT NULL COMMENT 'nativas: <NODO>-000123 · importadas: número de iProy',
  codigo_legado      VARCHAR(30)      NULL COMMENT 'código orden-SERV-TAREA de iProy',
  legado_id          INT UNSIGNED     NULL COMMENT 'Req_IPROY.id (solo importadas)',
  solicitud_id       CHAR(26)         NULL,
  activo_id          CHAR(26)         NULL,
  local_id           CHAR(26)         NULL,
  responsable_id     CHAR(26)         NULL COMMENT 'Organization (empresa o área) dueña de la orden',
  estado             VARCHAR(20)  NOT NULL DEFAULT 'requested' COMMENT 'Task.status',
  estado_negocio     VARCHAR(20)  NOT NULL DEFAULT 'solicitada' COMMENT 'pim-estado-orden (Tabla 9-2)',
  motivo_estado      VARCHAR(255)     NULL,
  prioridad          VARCHAR(10)  NOT NULL DEFAULT 'routine',
  tipo_mantenimiento VARCHAR(30)      NULL COMMENT 'pim-tipo-mantenimiento (EN 13306)',
  modo_falla         VARCHAR(10)      NULL COMMENT 'pim-modo-falla (ISO 14224)',
  categoria_codigo   VARCHAR(40)      NULL,
  categoria_texto    VARCHAR(160)     NULL,
  descripcion        TEXT             NULL COMMENT 'se publica solo si exponer_detalle = true',
  inicio             DATETIME(3)      NULL,
  fin                DATETIME(3)      NULL,
  autoria            DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  origen             VARCHAR(20)  NOT NULL DEFAULT 'pim',
  origen_huella     CHAR(40)         NULL COMMENT 'importación: huella de los datos de iProy (detecta cambios)',
  version            INT UNSIGNED NOT NULL DEFAULT 1,
  actualizado        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), UNIQUE KEY uk_ord_numero (numero), UNIQUE KEY uk_ord_legado (legado_id),
  KEY ix_ord_estado (estado_negocio, autoria), KEY ix_ord_resp (responsable_id, estado), KEY ix_ord_sol (solicitud_id),
  KEY ix_ord_activo (activo_id), KEY ix_ord_act (actualizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nota agregada a una orden (Task.note): texto de trabajo, sin datos de pacientes
CREATE TABLE IF NOT EXISTS pim_nota (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  orden_id  CHAR(26)     NOT NULL,
  texto     VARCHAR(1000) NOT NULL,
  autor     VARCHAR(64)  NOT NULL COMMENT 'client_id que la escribió',
  ts        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), KEY ix_nota_orden (orden_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Observation: medición de una instalación (pim-medicion, PIM-TEL)
CREATE TABLE IF NOT EXISTS pim_observacion (
  id              CHAR(26)      NOT NULL,
  activo_id       CHAR(26)      NOT NULL,
  punto           VARCHAR(40)   NOT NULL,
  valor           DECIMAL(14,4) NOT NULL,
  unidad          VARCHAR(20)   NOT NULL,
  instante        DATETIME(3)   NOT NULL,
  interpretacion  VARCHAR(2)        NULL COMMENT 'N | H | L',
  minimo          DECIMAL(14,4)     NULL,
  maximo          DECIMAL(14,4)     NULL,
  orden_id        CHAR(26)          NULL COMMENT 'orden generada por la regla de fuera de rango',
  cliente         VARCHAR(64)   NOT NULL,
  creado          DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), KEY ix_obs_activo (activo_id, punto, instante), KEY ix_obs_instante (instante)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versiones (Provenance y _history, PIM-FED): una fila por cada cambio de un recurso
CREATE TABLE IF NOT EXISTS pim_version (
  recurso   VARCHAR(30)  NOT NULL,
  id        CHAR(26)     NOT NULL,
  version   INT UNSIGNED NOT NULL,
  accion    VARCHAR(10)  NOT NULL COMMENT 'create | update',
  cliente   VARCHAR(64)  NOT NULL,
  ts        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  motivo    VARCHAR(255)     NULL,
  contenido MEDIUMTEXT   NOT NULL,
  huella    CHAR(64)     NOT NULL COMMENT 'SHA-256 encadenado con la versión anterior',
  PRIMARY KEY (recurso, id, version), KEY ix_ver_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AuditEvent: cada acceso (quién, qué, resultado). Sin parámetros sensibles ni tokens.
CREATE TABLE IF NOT EXISTS pim_auditoria (
  id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  cliente  VARCHAR(64)      NULL,
  metodo   VARCHAR(8)   NOT NULL,
  ruta     VARCHAR(200) NOT NULL,
  http     SMALLINT     NOT NULL,
  n        INT              NULL,
  ip       VARCHAR(45)      NULL,
  PRIMARY KEY (id), KEY ix_aud_ts (ts), KEY ix_aud_cli (cliente, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Numeración de solicitudes y órdenes nativas (<NODO>-SOL-000001 / <NODO>-000001)
CREATE TABLE IF NOT EXISTS pim_numerador (
  serie   VARCHAR(20)  NOT NULL,
  ultimo  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (serie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estado de la importación desde iProy
CREATE TABLE IF NOT EXISTS pim_importacion (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  resumen   TEXT         NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Columna agregada en 1.0.0 a tablas creadas por una versión previa del script (idempotente, MariaDB)
ALTER TABLE pim_organizacion ADD COLUMN IF NOT EXISTS origen_huella CHAR(40) NULL;
ALTER TABLE pim_local        ADD COLUMN IF NOT EXISTS origen_huella CHAR(40) NULL;
ALTER TABLE pim_activo       ADD COLUMN IF NOT EXISTS origen_huella CHAR(40) NULL;
ALTER TABLE pim_orden        ADD COLUMN IF NOT EXISTS origen_huella CHAR(40) NULL;

-- 1.1.0 (PIM 0.9.2): datos del REFES y EUI-64 del PMA (IHE MEM-DMC), categoría de alcance, valores no numéricos y origen de los puntos
ALTER TABLE pim_activo       ADD COLUMN IF NOT EXISTS categoria_alcance CHAR(1)     NULL COMMENT 'A | B | C | D (sección 3.3); NULL = se deduce del tipo';
ALTER TABLE pim_activo       ADD COLUMN IF NOT EXISTS anmat_pm          VARCHAR(16) NULL COMMENT 'PM-<legajo>-<producto>, Disp. ANMAT 4831/05';
ALTER TABLE pim_activo       MODIFY COLUMN anmat_pm VARCHAR(16) NULL COMMENT 'PM-<legajo>-<producto>, Disp. ANMAT 4831/05';
ALTER TABLE pim_activo       ADD COLUMN IF NOT EXISTS eui64             VARCHAR(16) NULL COMMENT 'EUI-64 del equipo (IHE PCD-15)';
ALTER TABLE pim_activo       ADD COLUMN IF NOT EXISTS fecha_fabricacion DATE        NULL;
ALTER TABLE pim_activo       ADD COLUMN IF NOT EXISTS puesta_en_marcha  DATE        NULL;
ALTER TABLE pim_activo       ADD COLUMN IF NOT EXISTS pertenencia       VARCHAR(40) NULL COMMENT 'pim-valores (REFES)';
ALTER TABLE pim_activo       ADD COLUMN IF NOT EXISTS clase_riesgo_pm   VARCHAR(3)  NULL COMMENT 'I | II | III | IV';
ALTER TABLE pim_activo       ADD UNIQUE INDEX IF NOT EXISTS uk_act_eui64 (eui64);
ALTER TABLE pim_punto        ADD COLUMN IF NOT EXISTS origen VARCHAR(20) NOT NULL DEFAULT 'pim' COMMENT 'pim | auto (creado al recibir una medición) | mem-dmc';
ALTER TABLE pim_observacion  MODIFY COLUMN valor DECIMAL(14,4) NULL;
ALTER TABLE pim_observacion  MODIFY COLUMN unidad VARCHAR(20) NULL;
ALTER TABLE pim_observacion  ADD COLUMN IF NOT EXISTS valor_tipo  CHAR(1)      NOT NULL DEFAULT 'q' COMMENT 'q Quantity · c CodeableConcept · s string · b boolean';
ALTER TABLE pim_observacion  ADD COLUMN IF NOT EXISTS valor_texto VARCHAR(200)     NULL;
ALTER TABLE pim_observacion  ADD COLUMN IF NOT EXISTS origen      VARCHAR(20)  NOT NULL DEFAULT 'pim';
ALTER TABLE pim_solicitud    ADD COLUMN IF NOT EXISTS observacion_id CHAR(26) NULL COMMENT 'reasonReference → Observation (orden por telemetría o MEM-DMC)';
ALTER TABLE pim_punto        ADD COLUMN IF NOT EXISTS id CHAR(26) NULL COMMENT 'DeviceMetric.id (determinístico: activo + punto)';
ALTER TABLE pim_punto        ADD UNIQUE INDEX IF NOT EXISTS uk_punto_id (id);

-- 1.4.0: registro de la decisión del auditor sobre la prioridad sugerida por el IRU (sesgo de automatización, human-in-the-loop)
ALTER TABLE pim_orden ADD COLUMN IF NOT EXISTS prioridad_sugerida    VARCHAR(10)  NULL COMMENT 'routine | urgent | asap | stat, de la banda IRU del activo';
ALTER TABLE pim_orden ADD COLUMN IF NOT EXISTS sugerencia_fundamento VARCHAR(160) NULL COMMENT 'IRU = F + R + M + A = n · banda';
ALTER TABLE pim_orden ADD COLUMN IF NOT EXISTS decision_prioridad    VARCHAR(10)  NULL COMMENT 'aceptada | cambiada | rechazada; NULL = pendiente';
ALTER TABLE pim_orden ADD COLUMN IF NOT EXISTS decision_motivo       VARCHAR(255) NULL;
ALTER TABLE pim_orden ADD COLUMN IF NOT EXISTS decision_autor        VARCHAR(80)  NULL;
ALTER TABLE pim_orden ADD COLUMN IF NOT EXISTS decision_ts           DATETIME(3)  NULL;
