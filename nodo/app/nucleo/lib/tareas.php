<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Planificador: el worker del lanzador llama al núcleo cada 15 s; acá se ejecutan las tareas vencidas de los módulos
 * ("tareas": [{"id": "generar", "cada": 300, "archivo": "tareas/generar.php"}]) con un presupuesto de tiempo,
 * más las tareas propias del núcleo (bus, colmena/hub, limpieza). Cada ejecución deja duración y resultado (/api/proc/tareas).
 */
declare(strict_types=1);

namespace Nucleo;

function registrar_tareas(): void {
    foreach (activos() as $id => $m) foreach ((array) $m['tareas'] as $t) {
        $cada = max(5, (int) ($t['cada'] ?? 60));
        q('INSERT INTO nucleo_tarea (id, cada_s, proxima) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE cada_s = VALUES(cada_s)', ["$id:{$t['id']}", $cada]);
    }
}
function tick(float $presupuesto = 40.0): array {
    $lk = fopen(dir_datos('run') . '/tick.lock', 'c');        // un solo tick a la vez (worker y CLI pueden coincidir)
    if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return ['saltado' => 'otro tick en curso'];
    try { return tick_cuerpo($presupuesto); } finally { flock($lk, LOCK_UN); fclose($lk); }
}
function tick_cuerpo(float $presupuesto): array {
    $t0 = microtime(true); $hecho = [];
    $hecho['bus'] = despachar(min(8.0, $presupuesto / 4));
    try { $hecho['colmena'] = colmena_tick(); } catch (\Throwable $e) { $hecho['colmena'] = 'error: ' . $e->getMessage(); }
    $tareas = [];
    foreach (activos() as $id => $m) foreach ((array) $m['tareas'] as $t) $tareas["$id:{$t['id']}"] = [$id, $m, $t];
    $vencidas = filas('SELECT id FROM nucleo_tarea WHERE proxima <= NOW() ORDER BY proxima');
    foreach ($vencidas as $v) {
        if (!isset($tareas[$v['id']])) continue;
        if (microtime(true) - $t0 > $presupuesto) break;
        [$id, $m, $t] = $tareas[$v['id']];
        $hecho[$v['id']] = ejecutar_tarea($v['id'], $id, $m, $t);
    }
    // eventos producidos por las tareas (p. ej. mediciones MQTT) se entregan en el mismo tick
    if (microtime(true) - $t0 < $presupuesto) $hecho['bus2'] = despachar(min(5.0, $presupuesto - (microtime(true) - $t0)));
    $ult = estado('nucleo_limpieza');
    if (($ult['ts'] ?? 0) < time() - 3600) { $hecho['limpieza'] = limpiar_eventos(); estado('nucleo_limpieza', ['ts' => time()]); }
    return $hecho;
}
function ejecutar_tarea(string $clave, string $id, array $m, array $t): string {
    // una misma tarea nunca corre dos veces a la vez (worker + «ejecutar ahora»): p. ej. dos clientes MQTT con el mismo id se expulsan
    $lk = fopen(dir_datos('run') . '/tarea-' . preg_replace('/[^a-z0-9_.-]/i', '_', $clave) . '.lock', 'c');
    if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return 'en curso (otra ejecución)';
    try { return ejecutar_tarea_cuerpo($clave, $id, $m, $t); } finally { flock($lk, LOCK_UN); fclose($lk); }
}
function ejecutar_tarea_cuerpo(string $clave, string $id, array $m, array $t): string {
    $i = microtime(true); $ok = 1;
    $GLOBALS['nucleo_modulo_actual'] = $id;
    try {
        incluir($id);
        $fn = require $m['dir'] . '/' . $t['archivo'];
        $r = is_callable($fn) ? $fn(ctx($id)) : $fn;
        $res = texto_corto($r ?? 'ok', 250);
    } catch (\Throwable $e) { $ok = 0; $res = texto_corto('error: ' . $e->getMessage(), 250); }
    finally { unset($GLOBALS['nucleo_modulo_actual']); }
    $ms = (int) ((microtime(true) - $i) * 1000);
    q('UPDATE nucleo_tarea SET ultima = NOW(), proxima = DATE_ADD(NOW(), INTERVAL cada_s SECOND), duracion_ms = ?, resultado = ?, ok = ?, fallos = IF(?, 0, fallos + 1) WHERE id = ?',
      [$ms, $res, $ok, $ok, $clave]);
    return $res;
}
/** Ejecuta una tarea ya (botón «Ejecutar ahora» o CLI). */
function ejecutar_ahora(string $clave): string {
    [$id, $tid] = array_pad(explode(':', $clave, 2), 2, '');
    $m = modulo($id) ?? throw new Fallo(404, "Módulo $id no cargado");
    foreach ((array) $m['tareas'] as $t) if ($t['id'] === $tid) return ejecutar_tarea($clave, $id, $m, $t);
    throw new Fallo(404, "No existe la tarea $clave");
}
