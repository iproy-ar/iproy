<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Un activo cambió de nombre en el Servidor PIM: la tabla de correspondencias lo muestra con el nombre vigente. */
declare(strict_types=1);

use function Nucleo\q;

return function (array $ev, array $ctx): void {
    $d = $ev['datos']; if (empty($d['id']) || !isset($d['nombre'])) return;
    q('UPDATE tel_mapa SET nombre = ? WHERE activo_id = ?', [mb_substr((string) $d['nombre'], 0, 200), (string) $d['id']]);
};
