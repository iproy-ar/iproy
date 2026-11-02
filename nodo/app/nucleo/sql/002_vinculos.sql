-- SPDX-License-Identifier: AGPL-3.0-or-later
-- SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
-- Vínculos según docs/vinculos.md (especificación 0.9.1): tipo, vigencia y huella del acta firmada.
ALTER TABLE nucleo_vinculo ADD COLUMN IF NOT EXISTS tipo VARCHAR(14) NOT NULL DEFAULT 'institucional';
ALTER TABLE nucleo_vinculo ADD COLUMN IF NOT EXISTS vence DATE NULL;
ALTER TABLE nucleo_vinculo ADD COLUMN IF NOT EXISTS acta_sha CHAR(64) NULL
