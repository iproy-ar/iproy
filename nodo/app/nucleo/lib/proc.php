<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Introspección del nodo, al estilo /proc de Linux: todo lo que el núcleo sabe de sí mismo, de solo lectura. */
declare(strict_types=1);

namespace Nucleo;

function proc(string $que): array {
    return match ($que) {
        'sistema' => proc_sistema(),
        'modulos' => array_values(array_map(fn($m) => array_diff_key($m, ['dir' => 1, 'config_defecto' => 1]) + [
            'estado' => resolver()['estado'][$m['id']] ?? '?',
            'error' => valor('SELECT error FROM nucleo_modulo WHERE id = ?', [$m['id']]),
            'migraciones' => filas('SELECT numero, archivo, aplicado FROM nucleo_migracion WHERE modulo = ? ORDER BY numero', [$m['id']])], manifiestos())),
        'tareas' => filas('SELECT * FROM nucleo_tarea ORDER BY id'),
        'bus' => ['suscripciones' => filas('SELECT * FROM nucleo_suscripcion ORDER BY suscriptor'),
                  'ultimo_evento' => (int) (valor('SELECT MAX(id) FROM nucleo_evento') ?? 0),
                  'temas' => filas('SELECT tema, COUNT(*) AS n, MAX(ts) AS ultimo FROM nucleo_evento GROUP BY tema ORDER BY ultimo DESC LIMIT 50')],
        'diario' => array_map(fn($r) => ['id' => (int) $r['id'], 'detalle' => json_decode($r['detalle'], true)] + $r,
                              filas('SELECT id, ts, tipo, actor, objeto, detalle, hash FROM nucleo_diario ORDER BY id DESC LIMIT ' . max(1, min(500, (int) ($_GET['n'] ?? 100))))),
        'vinculos' => array_map(fn($v) => array_diff_key($v, ['pim_jwk' => 1, 'caja_pub' => 1]), filas('SELECT * FROM nucleo_vinculo ORDER BY alta')),
        'capacidades' => ['roles' => ROLES, 'capacidades' => capacidades()],
        'rpc' => filas('SELECT id, par, metodo, ruta, via, estado, http, creado, respondido FROM nucleo_rpc ORDER BY creado DESC LIMIT 50'),
        default => throw new Fallo(404, "No existe /api/proc/$que (sistema, modulos, tareas, bus, diario, vinculos, capacidades, rpc)"),
    };
}
function proc_sistema(): array {
    $p = producto(); $n = nodo(); $r = resolver();
    $carga = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
    return ['producto' => $p['nombre'], 'version' => $p['version'], 'base' => $p['base'], 'licencia' => $p['licencia'], 'fuente' => $p['fuente'],
            'nucleo' => VERSION, 'abi' => ABI, 'protocolo' => PROTOCOLO, 'nodo' => $n, 'huella' => huella(identidad()['firma_pub']),
            'php' => PHP_VERSION, 'servidor' => $_SERVER['SERVER_SOFTWARE'] ?? null, 'db' => valor('SELECT VERSION()'),
            'modulos' => ['cargados' => $r['orden'], 'estado' => $r['estado']], 'contaminado' => contaminado(),
            'eventos' => (int) (valor('SELECT COUNT(*) FROM nucleo_evento') ?? 0), 'diario' => (int) (valor('SELECT COUNT(*) FROM nucleo_diario') ?? 0),
            'vinculos_activos' => (int) (valor("SELECT COUNT(*) FROM nucleo_vinculo WHERE estado = 'activo'") ?? 0),
            'hub' => ['url' => cfg()['hub']['url'] ?? '', 'activo' => !empty(cfg()['hub']['activo'])],
            'worker_ultimo' => \nodo_kv(\nodo_pdo(), 'worker_ultimo'), 'carga' => $carga, 'memoria_mb' => round(memory_get_peak_usage() / 1048576, 1),
            'disco_libre_gb' => round((float) @disk_free_space(raiz()) / 1073741824, 1), 'hora' => date('c')];
}
