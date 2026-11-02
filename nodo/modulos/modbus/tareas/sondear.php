<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use Modulos\Modbus\Cliente;
use function Modulos\Modbus\{bloques, decodificar};
use const Modulos\Modbus\PALABRAS;
use function Nucleo\{publicar, estado, asentar};

return function (array $ctx): string {
    $c = $ctx['cfg'];
    if (empty($c['habilitado'])) return 'deshabilitado (modulo config modbus)';
    $e = estado('modbus'); $n = 0; $err = [];
    foreach ((array) $c['equipos'] as $q) {
        if (empty($q['habilitado'])) continue;
        $k = $q['nombre'];
        if (($e[$k]['ts'] ?? 0) > time() - (int) ($q['cada_s'] ?? 30) + 2) continue;
        $cli = new Cliente((string) $q['host'], (int) ($q['puerto'] ?? 502), (int) ($q['unidad'] ?? 1));
        try {
            $cli->conectar();
            foreach (bloques((array) $q['registros']) as $b) {
                $w = $cli->leer($b['fc'], $b['desde'], $b['fin'] - $b['desde']);
                foreach ($b['regs'] as $r) {
                    $o = (int) $r['direccion'] - $b['desde'];
                    $v = decodificar(array_slice($w, $o, PALABRAS[$r['tipo'] ?? 'uint16']), $r['tipo'] ?? 'uint16', $r['orden'] ?? 'ABCD');
                    $v = $v * (float) ($r['escala'] ?? 1) + (float) ($r['desplazamiento'] ?? 0);
                    publicar('telemetria/medicion', ['activo' => $q['activo'], 'punto' => $r['punto'], 'valor' => round($v, 6), 'unidad' => $r['unidad'] ?? '1', 'origen' => "modbus:$k"], 'modbus');
                    $n++;
                }
            }
            $e[$k] = ['ts' => time(), 'ok' => true];
        } catch (\Throwable $x) {
            if (($e[$k]['ok'] ?? true)) asentar('modbus.falla', ['equipo' => $k, 'error' => $x->getMessage()]);    // se registra el cambio de estado, no cada intento
            $e[$k] = ['ts' => time(), 'ok' => false, 'error' => $x->getMessage()]; $err[] = $k;
        } finally { $cli->cerrar(); }
    }
    estado('modbus', $e);
    return "$n lecturas" . ($err ? ', sin respuesta: ' . implode(', ', $err) : '');
};
