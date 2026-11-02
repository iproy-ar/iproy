<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Envía la cola de mediciones al Servidor PIM como lote (Bundle batch de Observation, rol FT) y poda la serie local. */
declare(strict_types=1);

use function Nucleo\{pim, filas, q};

return function (array $ctx): string {
    $l = filas('SELECT * FROM tel_cola ORDER BY id LIMIT ' . max(1, min(500, (int) $ctx['cfg']['lote'])));
    $ok = 0; $mal = 0;
    if ($l) {
        $tz = (new DateTime('now'))->format('P');
        $b = ['resourceType' => 'Bundle', 'type' => 'batch', 'entry' => array_map(fn($m) => ['request' => ['method' => 'POST', 'url' => 'Observation'], 'resource' => [
            'resourceType' => 'Observation', 'status' => 'final', 'device' => ['reference' => 'Device/' . $m['activo_id']],
            'code' => ['coding' => [['code' => $m['punto']]]], 'effectiveDateTime' => str_replace(' ', 'T', substr($m['ts'], 0, 19)) . $tz,
            'valueQuantity' => ['value' => (float) $m['valor'], 'unit' => $m['unidad'], 'system' => 'http://unitsofmeasure.org', 'code' => $m['unidad']]]], $l)];
        $r = pim('POST', '', $b, ['rol' => 'FT']);
        if ($r['http'] !== 200) return "PIM respondió HTTP {$r['http']}: se reintenta";
        foreach ((array) ($r['cuerpo']['entry'] ?? []) as $e) str_starts_with((string) ($e['response']['status'] ?? ''), '201') ? $ok++ : $mal++;
        q('DELETE FROM tel_cola WHERE id <= ?', [(int) end($l)['id']]);
    }
    q('DELETE FROM tel_serie WHERE ts < DATE_SUB(NOW(), INTERVAL ? DAY)', [max(1, (int) $ctx['cfg']['serie_dias'])]);
    return "$ok enviadas a PIM" . ($mal ? ", $mal rechazadas" : '');
};
