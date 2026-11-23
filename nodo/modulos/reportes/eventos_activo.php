<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Un activo cambió en el Servidor PIM (nombre o código corregido): sus órdenes en la copia de consulta pasan a mostrar lo vigente. */
declare(strict_types=1);

use function Nucleo\q;
use function Modulos\Reportes\completar_codigos;

return function (array $ev, array $ctx): void {
    completar_codigos();
    $d = $ev['datos']; if (empty($d['id'])) return;
    q('UPDATE rep_orden SET activo = COALESCE(?, activo), activo_codigo = COALESCE(?, activo_codigo) WHERE activo_id = ?',
      [isset($d['nombre']) ? mb_substr((string) $d['nombre'], 0, 160) : null, isset($d['codigo']) ? mb_substr((string) $d['codigo'], 0, 80) : null, (string) $d['id']]);
};
