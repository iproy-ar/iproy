<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

namespace Modulos\Preventivos;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, pim, asentar, pdo, catalogo_valores, sin_tildes};

const TIPOS = ['preventivo-sistematico', 'preventivo-condicional', 'ensayo-reglamentario', 'mejora'];
const PRIORIDADES = ['routine', 'urgent', 'asap', 'stat'];

/** Frecuencias de meses (código de la lista «frecuencia» en días): avanzan por mes calendario, el mismo día del mes (o el último si no existe). */
const MESES = [30 => 1, 60 => 2, 90 => 3, 120 => 4, 180 => 6, 365 => 12, 730 => 24];
/** Próxima fecha a partir de $fecha: días exactos, o meses calendario para mensual, bimestral, …, anual, bienal. */
function avanzar(string $fecha, int $dias): string {
    $d = new \DateTimeImmutable($fecha);
    if (!isset(MESES[$dias])) return $d->modify('+' . max(1, $dias) . ' days')->format('Y-m-d');
    $dia = (int) $d->format('j'); $p = $d->modify('first day of this month')->modify('+' . MESES[$dias] . ' months');
    return $p->setDate((int) $p->format('Y'), (int) $p->format('n'), min($dia, (int) $p->format('t')))->format('Y-m-d');
}
/** Primera fecha del ciclo que no está vencida (para reprogramar un plan atrasado sin perder su día). */
function siguiente_no_vencida(string $fecha, int $dias): string {
    $hoy = date('Y-m-d'); $f = $fecha; $i = 0;
    while ($f < $hoy && $i++ < 2000) $f = avanzar($f, $dias);
    return $f;
}
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
    crear_tareas((int) pdo()->lastInsertId(), $p);
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
        if (!empty($f['cada_dias']) && empty($f['proxima'])) $f['proxima'] = avanzar(date('Y-m-d'), (int) $f['cada_dias']);
    }
    return $f;
}
function proyectar(int $dias): array {
    $l = [];
    foreach (filas('SELECT * FROM prev_plan WHERE habilitado = 1 AND cada_dias > 0 AND proxima IS NOT NULL') as $p) {
        $f = strtotime($p['proxima']); $fin = strtotime("+$dias days");
        $n = 0; while ($f <= $fin && $n++ < 400) { $l[] = ['fecha' => date('Y-m-d', $f), 'plan' => (int) $p['id'], 'titulo' => $p['titulo'], 'activo' => $p['activo_nombre']]; $f = strtotime(avanzar(date('Y-m-d', $f), (int) $p['cada_dias'])); }
    }
    usort($l, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));
    return $l;
}

// ------------------------------------------------------------------ importación de planes (1.2.0)

/** Días de una frecuencia escrita («Quincenal», «mensual», «30», «cada 15 días»), con la lista «frecuencia» del nodo. */
function dias_frecuencia(string $t): ?int {
    $t = trim($t); if ($t === '') return null;
    if (preg_match('/^(?:cada\s+)?(\d{1,4})(?:\s*d[ií]as?)?$/iu', $t, $m)) return (int) $m[1] ?: null;
    $raiz = fn(string $x) => preg_replace('/[ao]s?$/', '', strtolower(sin_tildes(trim($x))));   // «Diario» = «Diaria», «Mensuales» = «Mensual»
    foreach (catalogo_valores('frecuencia') as $v) if ($raiz((string) $v['texto']) === $raiz($t) && is_numeric($v['codigo'])) return (int) $v['codigo'];
    return null;
}
function fecha_iso(string $t): ?string {
    $t = trim($t); if ($t === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $t, $m)) return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $t, $m)) return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
    return null;
}
/**
 * Importa planes preventivos de otro sistema. Cada fila: activo (código del recurso físico), preventivo (la tarea), componente,
 * subcomponente, frecuencia, proxima (DD/MM/AAAA o AAAA-MM-DD), responsable (código de la empresa).
 * agrupar = un plan por activo y frecuencia (las tareas van como lista de verificación); si no, un plan por tarea.
 * Los planes entran en pausa salvo habilitar = true; sin frecuencia quedan siempre en pausa. Repetible: la clave de origen evita duplicar.
 */
function importar_planes(array $filas, bool $simular, bool $agrupar = true, bool $habilitar = false): array {
    $cuenta = ['nuevo' => 0, 'cambio' => 0, 'igual' => 0, 'error' => 0, 'sin_referencia' => 0]; $err = []; $sinFrec = []; $act = []; $grupos = [];
    $activo = function (string $cod) use (&$act): ?array {
        if (array_key_exists($cod, $act)) return $act[$cod];
        $d = pim('GET', 'Device?identifier=' . rawurlencode($cod) . '&_count=1', null, ['rol' => 'GM'])['cuerpo']['entry'][0]['resource'] ?? null;
        return $act[$cod] = $d ? ['id' => $d['id'], 'nombre' => $d['deviceName'][0]['name'] ?? $cod] : null;
    };
    foreach ($filas as $x) {
        $fila = $x['_fila'] ?? null; $cod = trim((string) ($x['activo'] ?? '')); $tarea = trim((string) ($x['preventivo'] ?? ''));
        if ($cod === '' || $tarea === '' || preg_match('/^[-=>< ]+$|^>>|<<$/', $tarea) || preg_match('/^>>|<<$/', trim((string) ($x['componente'] ?? '')))) continue;   // separadores de la hoja
        $a = $activo($cod);
        if (!$a) { $cuenta['sin_referencia']++; if (count($err) < 100) $err[] = ['fila' => $fila, 'motivo' => "No existe el activo «{$cod}» en PIM"]; continue; }
        $fr = trim((string) ($x['frecuencia'] ?? '')); $dias = dias_frecuencia($fr);
        if ($fr !== '' && $dias === null) { $sinFrec[$fr] = ($sinFrec[$fr] ?? 0) + 1; }
        $px = fecha_iso((string) ($x['proxima'] ?? ''));
        if (trim((string) ($x['proxima'] ?? '')) !== '' && !$px && count($err) < 100) $err[] = ['fila' => $fila, 'motivo' => 'Próxima ejecución ilegible: ' . mb_substr((string) $x['proxima'], 0, 30)];
        $comp = implode(' / ', array_filter([trim((string) ($x['componente'] ?? '')), trim((string) ($x['subcomponente'] ?? ''))], fn($v) => $v !== '' && $v !== '-'));
        $linea = ($comp !== '' ? "$comp: " : '') . $tarea;
        $k = $agrupar ? 'g|' . $a['id'] . '|' . ($dias ?? 'sin') : 't|' . $a['id'] . '|' . mb_strtolower($linea);
        $g = &$grupos[$k];
        $g ??= ['activo' => $a, 'dias' => $dias, 'frec' => $fr, 'tareas' => [], 'proxima' => null, 'responsable' => trim((string) ($x['responsable'] ?? '')) ?: null, 'cod' => $cod];
        if (!in_array($linea, $g['tareas'], true)) $g['tareas'][] = $linea;
        if ($px && (!$g['proxima'] || $px < $g['proxima'])) $g['proxima'] = $px;   // la más cercana del grupo
        unset($g);
    }
    $pl = [];
    foreach ($grupos as $k => $g) {
        $frecTxt = $g['dias'] ? (catalogo_texto_frec($g['dias']) ?: "cada {$g['dias']} días") : 'sin frecuencia';
        $tit = $agrupar ? mb_substr("Preventivo " . mb_strtolower($frecTxt) . " · {$g['activo']['nombre']}", 0, 160) : mb_substr($g['tareas'][0] . " · {$g['activo']['nombre']}", 0, 160);
        $f = ['titulo' => $tit, 'activo_id' => $g['activo']['id'], 'activo_nombre' => mb_substr($g['activo']['nombre'], 0, 200), 'tipo_mant' => 'preventivo-sistematico',
              'cada_dias' => $g['dias'], 'proxima' => $g['proxima'] ?? ($g['dias'] ? avanzar(date('Y-m-d'), (int) $g['dias']) : null),
              'responsable' => $g['responsable'], 'tareas' => implode("\n", $g['tareas']), 'origen' => 'importado', 'origen_clave' => sha1($k)];
        $ya = fila('SELECT * FROM prev_plan WHERE origen_clave = ?', [$f['origen_clave']]);
        if (!$ya) {
            $cuenta['nuevo']++; $f['habilitado'] = $habilitar && $g['dias'] ? 1 : 0;
            if (!$simular) { q('INSERT INTO prev_plan (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')', array_values($f)); $f['id'] = (int) pdo()->lastInsertId(); }
        } else {
            // ya traído: se actualiza lo que viene del origen; la próxima fecha solo si el plan todavía no generó órdenes (después la manda la ejecución real)
            $cmb = array_diff_key($f, ['origen' => 1, 'origen_clave' => 1]);
            if (fila('SELECT 1 FROM prev_generada WHERE plan_id = ? LIMIT 1', [$ya['id']])) unset($cmb['proxima']);
            $cmb = array_filter($cmb, fn($v, $c) => (string) ($ya[$c] ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH);
            if (!$cmb) { $cuenta['igual']++; continue; }
            $cuenta['cambio']++;
            if (!$simular) q('UPDATE prev_plan SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($cmb))) . ' WHERE id = ?', [...array_values($cmb), $ya['id']]);
        }
        if (count($pl) < 20) $pl[] = ['titulo' => $tit, 'tareas' => count($g['tareas']), 'cada_dias' => $g['dias'], 'proxima' => $f['proxima']];
    }
    // planes importados antes, de los mismos activos, que ya no vienen en el origen (cambió la frecuencia o se quitó la tarea): quedan en pausa, no se borran
    $claves = array_map('sha1', array_keys($grupos)); $acts = array_values(array_unique(array_map(fn($g) => $g['activo']['id'], $grupos))); $retirados = 0;
    if ($acts) {
        $viejos = filas("SELECT id, origen_clave, habilitado FROM prev_plan WHERE origen = 'importado' AND activo_id IN (" . implode(',', array_fill(0, count($acts), '?')) . ')', $acts);
        foreach ($viejos as $v) if (!in_array($v['origen_clave'], $claves, true)) { $retirados++; if (!$simular && (int) $v['habilitado']) q('UPDATE prev_plan SET habilitado = 0 WHERE id = ?', [$v['id']]); }
    }
    $cuenta['retirado'] = $retirados;
    $r = ['planes' => count($grupos), 'cuenta' => $cuenta, 'errores' => $err, 'sin_equivalencia' => $sinFrec ? ['frecuencia' => $sinFrec] : [], 'muestra' => $pl,
          'sin_frecuencia' => count(array_filter($grupos, fn($g) => !$g['dias'])), 'agrupar' => $agrupar, 'habilitar' => $habilitar];
    if (!$simular) asentar('preventivo.importacion', array_diff_key($r, ['muestra' => 1, 'errores' => 1]));
    return $r;
}
function catalogo_texto_frec(int $dias): string {
    foreach (catalogo_valores('frecuencia') as $v) if ((string) $v['codigo'] === (string) $dias) return (string) $v['texto'];
    return '';
}

// ------------------------------------------------------------------ registro por tarea (1.3.0)
const RESULTADOS = ['pendiente', 'hecha', 'no_hecha', 'no_aplica'];
/** Renglones de la lista de verificación del plan (una tarea por renglón). */
function lineas_tareas(?string $t): array { return array_values(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 300), preg_split('/\R/u', (string) $t)), fn($x) => $x !== '')); }
function crear_tareas(int $gen, array $p): void {
    foreach (lineas_tareas($p['tareas'] ?? '') as $i => $t) q('INSERT IGNORE INTO prev_tarea (generada_id, plan_id, n, tarea) VALUES (?,?,?,?)', [$gen, $p['id'], $i + 1, $t]);
}
/** Orden generada con sus tareas; las anteriores a 1.3.0 se completan con las tareas actuales del plan. Un Ejecutor solo ve las de su empresa. */
function orden_tareas(int $gen, array $u): array {
    $g = fila('SELECT g.*, p.titulo, p.tareas, p.activo_nombre FROM prev_generada g JOIN prev_plan p ON p.id = g.plan_id WHERE g.id = ?', [$gen]) ?? throw new Fallo(404, 'No existe esa orden preventiva');
    if (($u['rol'] ?? '') === 'EJ') exigir_empresa($g, $u);
    if (!fila('SELECT 1 FROM prev_tarea WHERE generada_id = ? LIMIT 1', [$gen])) crear_tareas($gen, ['id' => $g['plan_id'], 'tareas' => $g['tareas']]);
    $t = filas('SELECT n, tarea, resultado, nota, usuario, actualizado FROM prev_tarea WHERE generada_id = ? ORDER BY n', [$gen]);
    return ['orden' => ['id' => (int) $g['id'], 'plan' => (int) $g['plan_id'], 'titulo' => $g['titulo'], 'activo' => $g['activo_nombre'], 'task_id' => $g['task_id'], 'programada' => $g['programada'], 'estado' => $g['estado']],
            'tareas' => $t, 'resumen' => resumen_tareas($t)];
}
function resumen_tareas(array $t): array {
    $c = array_count_values(array_column($t, 'resultado')); return ['total' => count($t)] + array_map(fn($k) => (int) ($c[$k] ?? 0), array_combine(RESULTADOS, RESULTADOS));
}
/** La orden tiene que ser de la empresa del Ejecutor (se consulta al Servidor PIM). */
function exigir_empresa(array $g, array $u): void {
    $t = pim('GET', 'Task/' . $g['task_id'], null, ['rol' => 'GM'])['cuerpo'] ?? [];
    $own = (string) ($t['owner']['reference'] ?? '');
    $cods = str_starts_with($own, 'Organization/') ? array_map(fn($i) => (string) ($i['value'] ?? ''), (array) (pim('GET', $own, null, ['rol' => 'GM'])['cuerpo']['identifier'] ?? [])) : [];
    if (!in_array(trim((string) ($u['organizacion'] ?? '')), $cods, true)) throw new Fallo(403, 'Esa orden es de otra empresa');
}
function registrar_tareas(int $gen, array $l, array $u): array {
    orden_tareas($gen, $u);   // existe, permisos y renglones creados
    $n = 0;
    foreach ($l as $x) {
        $r = (string) ($x['resultado'] ?? ''); if (!in_array($r, RESULTADOS, true)) throw new Fallo(422, 'resultado: ' . implode(' | ', RESULTADOS));
        $nota = mb_substr(trim((string) ($x['nota'] ?? '')), 0, 300) ?: null;
        if ($r === 'no_hecha' && !$nota) throw new Fallo(422, 'Una tarea que no se hizo necesita el motivo (nota)');
        $n += q('UPDATE prev_tarea SET resultado = ?, nota = ?, usuario = ?, actualizado = NOW() WHERE generada_id = ? AND n = ? AND (resultado <> ? OR COALESCE(nota, \'\') <> COALESCE(?, \'\'))',
                [$r, $nota, (string) ($u['usuario'] ?? ''), $gen, (int) ($x['n'] ?? 0), $r, $nota])->rowCount();
    }
    if ($n) asentar('preventivo.tareas', ['cambios' => $n], (string) $gen);
    return orden_tareas($gen, $u) + ['cambios' => $n];
}
require_once __DIR__ . '/lib_pmp.php';   // 2.0.0: plan preventivo por semanas
