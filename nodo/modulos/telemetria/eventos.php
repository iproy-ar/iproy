<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** telemetria/medicion → último valor, serie corta, cola hacia PIM (por excepción) y evento telemetria/valor (con el id PIM). */
declare(strict_types=1);

use function Modulos\Telemetria\{normalizar, resolver_activo};
use function Nucleo\{q, fila, publicar, asentar, estado};

return function (array $ev, array $ctx): void {
    $m = normalizar((array) $ev['datos']);
    $a = resolver_activo($m['activo']);
    if (!$a) {                                    // activo desconocido: se avisa una vez por hora, sin trabar el bus
        $e = estado('tel_desconocidos');
        if (($e[$m['activo']] ?? 0) < time() - 3600) { asentar('telemetria.activo_desconocido', ['activo' => $m['activo'], 'punto' => $m['punto'], 'origen' => $m['origen']]); $e[$m['activo']] = time(); estado('tel_desconocidos', $e); }
        return;
    }
    $u = fila('SELECT * FROM tel_ultimo WHERE activo_id = ? AND punto = ?', [$a['id'], $m['punto']]);
    $cfg = $ctx['cfg'];
    $banda = abs((float) ($u['env_valor'] ?? 0)) * (float) $cfg['banda_muerta_pct'] / 100;
    $enviar = !$u || $u['env_ts'] === null || abs($m['valor'] - (float) $u['env_valor']) > $banda
              || strtotime($m['ts']) - strtotime((string) $u['env_ts']) >= (int) $cfg['pim_cada_s'];
    q('INSERT INTO tel_ultimo (activo_id, punto, valor, unidad, ts, origen, env_valor, env_ts, n) VALUES (?,?,?,?,?,?,?,?,1)
       ON DUPLICATE KEY UPDATE valor = VALUES(valor), unidad = VALUES(unidad), ts = VALUES(ts), origen = VALUES(origen),
       env_valor = IF(?, VALUES(valor), env_valor), env_ts = IF(?, VALUES(ts), env_ts), n = n + 1',
      [$a['id'], $m['punto'], $m['valor'], $m['unidad'], $m['ts'], $m['origen'], $enviar ? $m['valor'] : null, $enviar ? $m['ts'] : null, $enviar ? 1 : 0, $enviar ? 1 : 0]);
    q('INSERT INTO tel_serie (activo_id, punto, ts, valor) VALUES (?,?,?,?)', [$a['id'], $m['punto'], $m['ts'], $m['valor']]);
    if ($enviar) q('INSERT INTO tel_cola (activo_id, punto, valor, unidad, ts) VALUES (?,?,?,?,?)', [$a['id'], $m['punto'], $m['valor'], $m['unidad'], $m['ts']]);
    publicar('telemetria/valor', ['activo_id' => $a['id'], 'activo' => $m['activo'], 'nombre' => $a['nombre'], 'punto' => $m['punto'],
                                  'valor' => $m['valor'], 'unidad' => $m['unidad'], 'ts' => $m['ts'], 'origen' => $m['origen']], 'telemetria');
};
