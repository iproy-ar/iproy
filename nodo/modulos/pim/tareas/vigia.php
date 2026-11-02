<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Vigía PIM: lee el historial (_history?_since) de los recursos configurados y publica cada versión nueva en el bus:
 *   pim/<Tipo>/<create|update>  {id, version, numero, estado, prioridad, activo, tipo_mant}
 * Así los módulos reaccionan a órdenes y solicitudes sin tocar la base PIM (p. ej. preventivos reprograma al finalizar).
 */
declare(strict_types=1);

use function Nucleo\{pim, publicar, estado};

return function (array $ctx): string {
    $e = estado('pim_vigia');
    $n = 0;
    foreach ((array) $ctx['cfg']['vigiar'] as $tipo) {
        $st = $e[$tipo] ?? ['desde' => gmdate('Y-m-d\TH:i:s\Z'), 'vistos' => []];
        $r = pim('GET', "$tipo/_history?_since=" . rawurlencode($st['desde']) . '&_count=500', null, ['exigir' => true]);
        $ent = array_reverse((array) ($r['cuerpo']['entry'] ?? []));            // el servidor las da de la más nueva a la más vieja
        $vistos = [];
        $max = $st['desde'];
        foreach ($ent as $x) {
            $res = $x['resource'] ?? []; $ver = (string) ($res['meta']['versionId'] ?? '');
            $clave = ($res['id'] ?? '') . '/' . $ver;
            $lm = (string) ($x['response']['lastModified'] ?? $res['meta']['lastUpdated'] ?? $max);
            if ($lm >= $max) { $max = $lm; }
            $vistos[] = $clave;
            if (in_array($clave, (array) $st['vistos'], true)) continue;
            $accion = ($x['request']['method'] ?? 'PUT') === 'POST' ? 'create' : 'update';
            $ext = [];
            foreach ((array) ($res['extension'] ?? []) as $x2) $ext[basename((string) ($x2['url'] ?? ''))] = $x2['valueCode'] ?? ($x2['valueCoding']['code'] ?? null);
            publicar("pim/$tipo/$accion", array_filter([
                'id' => $res['id'] ?? null, 'version' => $ver, 'numero' => $res['identifier'][0]['value'] ?? null,
                'estado' => $res['businessStatus']['coding'][0]['code'] ?? ($res['status'] ?? null), 'prioridad' => $res['priority'] ?? null,
                'activo' => isset($res['focus']['reference']) ? substr((string) $res['focus']['reference'], 7) : (isset($res['subject']['reference']) && str_starts_with((string) $res['subject']['reference'], 'Device/') ? substr((string) $res['subject']['reference'], 7) : null),
                'tipo_mant' => $ext['pim-tipo-mantenimiento'] ?? null, 'descripcion' => isset($res['description']) ? mb_substr((string) $res['description'], 0, 200) : null,
                'nombre' => $res['deviceName'][0]['name'] ?? null,
            ], fn($v) => $v !== null), 'pim');
            $n++;
        }
        // se guardan las versiones vistas en el último segundo para no repetirlas en la próxima lectura (_since es «mayor o igual»)
        $e[$tipo] = ['desde' => $max, 'vistos' => array_slice(array_values(array_unique(array_merge(
            array_filter((array) $st['vistos'], fn() => true), $vistos))), -300)];
    }
    estado('pim_vigia', $e);
    return "$n cambios publicados";
};
