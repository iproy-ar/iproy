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
        // Una carga masiva deja cientos de versiones con la misma marca de tiempo. Si no entran en una página, «desde» no puede avanzar
        // y el vigía repetía la misma página para siempre: se leen todas las páginas de la tanda (hasta 20.000 versiones por pasada).
        $r = ['cuerpo' => ['entry' => []]]; $leidas = 0;
        for ($k = 0; $k < 200 && $leidas < 20000; $k++) {   // el servidor decide el tamaño real de la página: se avanza por lo que devolvió
            $p1 = pim('GET', "$tipo/_history?_since=" . rawurlencode($st['desde']) . "&_count=500&_offset=$leidas", null, ['exigir' => true]);
            $e1 = (array) ($p1['cuerpo']['entry'] ?? []); if (!$e1) break;
            array_push($r['cuerpo']['entry'], ...$e1); $leidas += count($e1);
            if (!array_filter((array) ($p1['cuerpo']['link'] ?? []), fn($l) => ($l['relation'] ?? '') === 'next')) break;
        }
        // 1.7.0: las fechas se comparan como instantes, no como texto. «desde» nace en UTC (…Z) y el servidor informa en la hora
        // local (…-03:00): comparadas como texto, «desde» no avanzaba durante las primeras horas de un nodo nuevo y, pasadas las
        // 500 versiones de la página, los cambios nuevos no se veían y los viejos se volvían a publicar.
        $inst = function (string $s): float { try { return (float) (new \DateTimeImmutable($s))->format('U.u'); } catch (\Throwable) { return 0.0; } };
        $ent = (array) ($r['cuerpo']['entry'] ?? []);
        $hora = fn(array $x) => $inst((string) ($x['response']['lastModified'] ?? $x['resource']['meta']['lastUpdated'] ?? ''));
        usort($ent, fn($a, $b) => $hora($a) <=> $hora($b));                    // de la más vieja a la más nueva, venga como venga
        $vistos = [];
        $max = $st['desde'];
        foreach ($ent as $x) {
            $res = $x['resource'] ?? []; $ver = (string) ($res['meta']['versionId'] ?? '');
            $clave = ($res['id'] ?? '') . '/' . $ver;
            $lm = (string) ($x['response']['lastModified'] ?? $res['meta']['lastUpdated'] ?? $max);
            if ($inst($lm) >= $inst($max)) { $max = $lm; }
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
                // 1.7.0: código vigente y ubicación del activo, para que cada módulo ponga al día sus copias (búsquedas y estadísticas)
                'codigo' => $tipo === 'Device' ? (string) (array_values(array_filter((array) ($res['identifier'] ?? []), fn($i) => str_ends_with((string) ($i['system'] ?? ''), '/sid/activo')))[0]['value'] ?? '') ?: null : null,
                'ubicacion' => $tipo === 'Device' ? ($res['location']['display'] ?? null) : null,
                // lo traído de otro sistema se publica (las copias de consulta lo necesitan) pero marcado: no es una novedad de hoy
                'legado' => in_array('legado', array_column((array) ($res['meta']['tag'] ?? []), 'code'), true) ? true : null,
            ], fn($v) => $v !== null), 'pim');
            $n++;
        }
        // se guardan las versiones vistas en el último segundo para no repetirlas en la próxima lectura (_since es «mayor o igual»)
        $e[$tipo] = ['desde' => $max, 'vistos' => array_slice(array_values(array_unique(array_merge(
            array_filter((array) $st['vistos'], fn() => true), $vistos))), -max(300, count($vistos)))];   // nunca menos que lo leído en esta pasada
    }
    estado('pim_vigia', $e);
    return "$n cambios publicados";
};
