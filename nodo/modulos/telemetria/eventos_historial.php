<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** 1.1.0 · telemetria/historial (lecturas viejas en lote: una serie por evento) → serie propia y telemetria/serie para quien guarde historial (paneles).
 *  No pasa por el último valor, la cola hacia PIM ni las alarmas: es pasado. */
declare(strict_types=1);

use function Modulos\Telemetria\{resolver_activo, guardar_historial};
use function Nucleo\{publicar, asentar};

return function (array $ev, array $ctx): void {
    $d = (array) $ev['datos'];
    $act = trim((string) ($d['activo'] ?? '')); $pto = strtoupper(trim((string) ($d['punto'] ?? '')));
    if ($act === '' || !preg_match('/^[A-Z0-9\-]{2,40}$/', $pto)) return;
    $a = resolver_activo($act); if (!$a) { asentar('telemetria.historial_sin_activo', ['activo' => $act, 'punto' => $pto]); return; }
    $nuevos = guardar_historial($a, $pto, array_slice((array) ($d['pares'] ?? []), 0, 20000));
    if (!$nuevos) return;
    $u = trim((string) ($d['unidad'] ?? '')); $u = $u === '' || mb_strlen($u) > 20 ? '1' : $u;
    foreach (array_chunk($nuevos, 5000) as $b)
        publicar('telemetria/serie', ['activo_id' => $a['id'], 'activo' => $act, 'nombre' => $a['nombre'], 'punto' => $pto, 'unidad' => $u,
            'etiqueta' => mb_substr(trim((string) ($d['etiqueta'] ?? '')), 0, 80) ?: null, 'origen' => mb_substr((string) ($d['origen'] ?? 'historial'), 0, 40),
            'pares' => array_map(fn($p) => [date('Y-m-d H:i:s', $p[0]), $p[1]], $b)], 'telemetria');
};
