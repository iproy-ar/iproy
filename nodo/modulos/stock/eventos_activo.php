<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Un activo cambió en el Servidor PIM: los pedidos del pañol que lo nombran muestran el código y el nombre vigentes. */
declare(strict_types=1);

use function Nucleo\q;

return function (array $ev, array $ctx): void {
    $d = $ev['datos']; if (empty($d['id']) || (!isset($d['codigo']) && !isset($d['nombre']))) return;
    q("UPDATE stock_pedido SET activo_nombre = ? WHERE activo = ?", [mb_substr(trim(($d['codigo'] ?? '') . ' · ' . ($d['nombre'] ?? ''), ' ·'), 0, 200), (string) $d['id']]);
};
