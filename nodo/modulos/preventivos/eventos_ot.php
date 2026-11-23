<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Cuando una orden preventiva se finaliza (o se anula), se cierra y el plan se reprograma desde la fecha real. */
declare(strict_types=1);

use function Nucleo\{fila, q, asentar};

return function (array $ev, array $ctx): void {
    $d = $ev['datos']; $est = (string) ($d['estado'] ?? '');
    if (!in_array($est, ['finalizada', 'conformada', 'anulada', 'rechazada'], true)) return;
    // 2.0.0: orden semanal del plan preventivo
    if ($o = fila("SELECT id FROM pmp_orden WHERE task_id = ? AND estado = 'abierta'", [(string) ($d['id'] ?? '')])) {
        q('UPDATE pmp_orden SET estado = ?, cerrada = NOW() WHERE id = ?', [in_array($est, ['finalizada', 'conformada'], true) ? 'hecha' : 'anulada', $o['id']]);
        asentar('pmp.orden_cerrada', ['task' => $d['id'], 'estado' => $est], (string) $o['id']);
        return;
    }
    $g = fila("SELECT g.*, p.cada_dias FROM prev_generada g JOIN prev_plan p ON p.id = g.plan_id WHERE g.task_id = ? AND g.estado = 'abierta'", [(string) ($d['id'] ?? '')]);
    if (!$g) return;
    q("UPDATE prev_generada SET estado = ?, cerrada = NOW() WHERE id = ?", [in_array($est, ['finalizada', 'conformada'], true) ? 'hecha' : 'anulada', $g['id']]);
    if (in_array($est, ['finalizada', 'conformada'], true) && $g['cada_dias']) {
        $base = $ctx['cfg']['reprogramar_desde_ejecucion'] ? date('Y-m-d') : ($g['programada'] ?: date('Y-m-d'));
        $prox = \Modulos\Preventivos\avanzar($base, (int) $g['cada_dias']);   // mensual, bimestral…: mismo día del mes (1.3.0)
        q('UPDATE prev_plan SET ultima = CURDATE(), proxima = ? WHERE id = ?', [$prox, $g['plan_id']]);
        asentar('preventivo.reprogramado', ['task' => $d['id'], 'proxima' => $prox], (string) $g['plan_id']);
    } elseif ($g['cada_dias']) {
        $px = (string) (fila('SELECT proxima FROM prev_plan WHERE id = ?', [$g['plan_id']])['proxima'] ?? '');
        q('UPDATE prev_plan SET proxima = ? WHERE id = ?', [\Modulos\Preventivos\avanzar(max($px ?: date('Y-m-d'), date('Y-m-d')), (int) $g['cada_dias']), $g['plan_id']]);
    }
};
