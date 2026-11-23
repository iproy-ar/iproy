-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Stock 1.3.0 · el pedido de insumos sigue el estándar PIM para su prioridad:
--   prioridad = request-priority FHIR (routine, urgent, asap, stat), sugerida por la orden o el activo (IRU, NFPA 99);
--   letra final del número = banda PIM A–D (ConceptMap pim-banda-a-prioridad, en sentido inverso). Número: PED-AAAAMMDD-NNN-L.
ALTER TABLE stock_pedido
  ADD COLUMN IF NOT EXISTS letra CHAR(1) NULL AFTER prioridad,
  ADD COLUMN IF NOT EXISTS prioridad_sugerida VARCHAR(10) NULL AFTER letra,
  ADD COLUMN IF NOT EXISTS fundamento VARCHAR(300) NULL AFTER prioridad_sugerida,
  ADD COLUMN IF NOT EXISTS motivo_prioridad VARCHAR(300) NULL AFTER fundamento,
  ADD COLUMN IF NOT EXISTS orden VARCHAR(64) NULL AFTER motivo_prioridad,
  ADD COLUMN IF NOT EXISTS orden_numero VARCHAR(40) NULL AFTER orden,
  ADD COLUMN IF NOT EXISTS activo VARCHAR(64) NULL AFTER orden_numero,
  ADD COLUMN IF NOT EXISTS activo_nombre VARCHAR(200) NULL AFTER activo,
  ADD COLUMN IF NOT EXISTS enviado DATETIME NULL,
  ADD COLUMN IF NOT EXISTS enviado_a VARCHAR(300) NULL;
-- pedidos de la 1.2.0: la prioridad estaba en texto; pasa a código FHIR y el número suma su letra
UPDATE stock_pedido SET prioridad = CASE prioridad WHEN 'Inmediata' THEN 'stat' WHEN 'Lo antes posible' THEN 'asap' WHEN 'Urgente' THEN 'urgent' WHEN 'Normal' THEN 'routine' ELSE prioridad END;
UPDATE stock_pedido SET letra = CASE prioridad WHEN 'stat' THEN 'A' WHEN 'asap' THEN 'B' WHEN 'urgent' THEN 'C' ELSE 'D' END WHERE letra IS NULL;
UPDATE stock_pedido SET numero = CONCAT(numero, '-', letra) WHERE numero REGEXP '^PED-[0-9]{8}-[0-9]{3}$';
