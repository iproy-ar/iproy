<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

namespace Modulos\Preventivos;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, pim, asentar, pdo};

const TIPOS = ['preventivo-sistematico', 'preventivo-condicional', 'ensayo-reglamentario', 'mejora'];
const PRIORIDADES = ['routine', 'urgent', 'asap', 'stat'];

/** ¿Hay una orden de este plan todavía abierta? (no se generan órdenes duplicadas) */
function abierta(int $plan): ?array { return fila("SELECT * FROM prev_generada WHERE plan_id = ? AND estado = 'abierta' ORDER BY id DESC LIMIT 1", [$plan]); }

/** Genera la solicitud + orden en PIM para un plan. $disparo: calendario | uso | manual. */
function generar(array $p, string $disparo): array {
    if ($a = abierta((int) $p['id'])) throw new Fallo(409, "El plan ya tiene una orden abierta ({$a['task_id']})");
    $nota = "[Preventivo · plan {$p['id']}] {$p['titulo']}" . ($p['tareas'] ? "\nTareas:\n" . $p['tareas'] : '')
          . ($disparo === 'uso' ? "\nDisparo por uso: {$p['punto']} = {$p['ultimo_valor']}" : ($p['proxima'] ? "\nFecha programada: {$p['proxima']}" : ''));
    $r = pim('POST', 'ServiceRequest', ['resourceType' => 'ServiceRequest', 'status' => 'active', 'intent' => 'order', 'priority' => $p['prioridad'],
        'subject' => ['reference' => 'Device/' . $p['activo_id']], 'code' => ['coding' => [['code' => 'PREVENTIVO']], 'text' => 'Mantenimiento preventivo programado'],
        'requester' => ['display' => 'Plan preventivo ' . $p['id']], 'note' => [['text' => $nota]]], ['rol' => 'GM', 'exigir' => true]);
    $task = substr((string) ($r['cab']['x-pim-task'] ?? ''), 5);
    $sr = (string) ($r['cuerpo']['id'] ?? '');
    // tipo de mantenimiento EN 13306 y responsable: transición de la orden recién creada
    if ($task !== '') {
        $par = [['name' => 'tipoMantenimiento', 'valueCode' => $p['tipo_mant']]];
        if ($p['responsable']) {
            $org = pim('GET', 'Organization?identifier=' . rawurlencode($p['responsable']) . '&_count=1', null, ['rol' => 'GM'])['cuerpo']['entry'][0]['resource']['id'] ?? null;
            if ($org) $par = array_merge([['name' => 'estado', 'valueCode' => 'asignada'], ['name' => 'responsable', 'valueReference' => ['reference' => "Organization/$org"]]], $par);
        }
        if (count($par) === 1) $par = array_merge([['name' => 'estado', 'valueCode' => 'en-revision'], ['name' => 'motivo', 'valueString' => 'Preventivo programado: falta asignar responsable']], $par);
        // $transicion exige If-Match (1.6.2): se lee la versión vigente de la orden recién creada
        $ver = pim('GET', "Task/$task", null, ['rol' => 'GM'])['cuerpo']['meta']['versionId'] ?? '1';
        $t = pim('POST', "Task/$task/\$transicion", ['resourceType' => 'Parameters', 'parameter' => $par], ['rol' => 'GM', 'if_match' => 'W/"' . $ver . '"']);
        if ($t['http'] >= 400) asentar('preventivo.transicion_fallida', ['task' => $task, 'error' => $t['cuerpo']['issue'][0]['diagnostics'] ?? $t['http']], (string) $p['id']);
    }
    q('INSERT INTO prev_generada (plan_id, programada, disparo, sr_id, task_id) VALUES (?,?,?,?,?)', [$p['id'], $p['proxima'], $disparo, $sr, $task]);
    asentar('preventivo.orden', ['task' => $task, 'disparo' => $disparo, 'programada' => $p['proxima']], (string) $p['id']);
    return ['plan' => (int) $p['id'], 'solicitud' => $sr, 'orden' => $task, 'disparo' => $disparo];
}
function validar(array $c, bool $nuevo): array {
    $f = [];
    if ($nuevo || isset($c['titulo'])) { $f['titulo'] = trim((string) ($c['titulo'] ?? '')); if ($f['titulo'] === '') throw new Fallo(422, 'Falta el título'); }
    if ($nuevo || isset($c['activo_id'])) {
        $a = trim((string) ($c['activo_id'] ?? ''));
        $d = $a === '' ? null : (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $a) ? pim('GET', "Device/$a", null, ['rol' => 'GM'])['cuerpo']
             : (pim('GET', 'Device?identifier=' . rawurlencode($a) . '&_count=1', null, ['rol' => 'GM'])['cuerpo']['entry'][0]['resource'] ?? null));
        if (!$d || ($d['resourceType'] ?? '') !== 'Device') throw new Fallo(422, "No existe el activo «{$a}» en PIM (id o código de inventario)");
        $f['activo_id'] = $d['id']; $f['activo_nombre'] = $d['deviceName'][0]['name'] ?? null;
    }
    if (isset($c['tipo_mant'])) { if (!in_array($c['tipo_mant'], TIPOS, true)) throw new Fallo(422, 'tipo_mant: ' . implode(' | ', TIPOS)); $f['tipo_mant'] = $c['tipo_mant']; }
    if (isset($c['prioridad'])) { if (!in_array($c['prioridad'], PRIORIDADES, true)) throw new Fallo(422, 'prioridad: ' . implode(' | ', PRIORIDADES)); $f['prioridad'] = $c['prioridad']; }
    foreach (['cada_dias', 'anticipacion_dias'] as $k) if (array_key_exists($k, $c)) $f[$k] = $c[$k] === null || $c[$k] === '' ? null : max(0, (int) $c[$k]);
    if (array_key_exists('cada_valor', $c)) $f['cada_valor'] = is_numeric($c['cada_valor']) ? (float) $c['cada_valor'] : null;
    if (array_key_exists('punto', $c)) $f['punto'] = $c['punto'] ? strtoupper((string) $c['punto']) : null;
    foreach (['proxima', 'ultima'] as $k) if (array_key_exists($k, $c)) {
        if ($c[$k] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $c[$k])) throw new Fallo(422, "$k: AAAA-MM-DD"); $f[$k] = $c[$k] ?: null;
    }
    foreach (['responsable', 'tareas'] as $k) if (array_key_exists($k, $c)) $f[$k] = trim((string) $c[$k]) ?: null;
    if (isset($c['habilitado'])) $f['habilitado'] = $c['habilitado'] ? 1 : 0;
    if ($nuevo) {
        if (empty($f['cada_dias']) && empty($f['cada_valor'])) throw new Fallo(422, 'El plan necesita frecuencia: cada_dias (calendario) y/o punto + cada_valor (uso)');
        if (!empty($f['cada_valor']) && empty($f['punto'])) throw new Fallo(422, 'Un plan por uso necesita el punto de medición (p. ej. HORAS-MARCHA)');
        if (!empty($f['cada_valor']) && empty($c['tipo_mant'])) $f['tipo_mant'] = 'preventivo-condicional';
        if (!empty($f['cada_dias']) && empty($f['proxima'])) $f['proxima'] = date('Y-m-d', strtotime('+' . $f['cada_dias'] . ' days'));
    }
    return $f;
}
function proyectar(int $dias): array {
    $l = [];
    foreach (filas('SELECT * FROM prev_plan WHERE habilitado = 1 AND cada_dias > 0 AND proxima IS NOT NULL') as $p) {
        $f = strtotime($p['proxima']); $fin = strtotime("+$dias days");
        while ($f <= $fin) { $l[] = ['fecha' => date('Y-m-d', $f), 'plan' => (int) $p['id'], 'titulo' => $p['titulo'], 'activo' => $p['activo_nombre']]; $f = strtotime('+' . $p['cada_dias'] . ' days', $f); }
    }
    usort($l, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));
    return $l;
}
