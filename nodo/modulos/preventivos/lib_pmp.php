<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

/*
 * Plan preventivo por semanas (2.0.0), como la planilla de cronograma anual.
 *  - Semana 1 = la que contiene el 1 de enero; las semanas van de lunes a domingo; un año tiene 53 o 54 (las de los extremos son parciales).
 *  - Las frecuencias son semanas fijas: semanal 1, quincenal 2, mensual 4, bimestral 8, trimestral 13, semestral 26, anual 52, cada 2 años 104;
 *    «diaria» marca todas las semanas (la tarea se hace todos los días de esa semana).
 *  - Cada lunes (o el 1/1 si la semana empieza el año) se genera UNA orden PIM por equipo con sus tareas de la semana;
 *    cada tarea se cierra por separado: hecha (con la fecha real, dentro o fuera de la semana), no se hizo (con motivo) o no aplica.
 */
namespace Modulos\Preventivos;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, pim, asentar, pdo};

const NS_PIM = 'https://iproy.com.ar/fhir';   // espacio de nombres canónico de PIM (docs/dominio-canonico.md)
const FRECS = ['D' => ['Diaria', 1], 'SM' => ['Semanal', 1], 'Q' => ['Quincenal', 2], 'M' => ['Mensual', 4], 'B' => ['Bimestral', 8],
               'T' => ['Trimestral', 13], 'S' => ['Semestral', 26], 'A' => ['Anual', 52], '2A' => ['Cada 2 años', 104]];
const CAMPOS_TAREA = ['sistema' => 120, 'ubicacion' => 120, 'instalacion' => 120, 'sector' => 120, 'categoria' => 120, 'parte' => 160, 'preventivo' => 300,
                      'predictivo' => 300, 'personal' => 60, 'hoja_vida' => 60, 'inspeccion' => 60, 'insumos' => 300, 'responsable' => 80];

// ------------------------------------------------------------------ semanas del año
function dia_semana(\DateTimeImmutable $d): int { return (int) $d->format('N') - 1; }   // lunes = 0
function semanas_anio(int $y): int { return semana_de(sprintf('%04d-12-31', $y))[1]; }
/** [año, semana] de una fecha: semana 1 = la del 1 de enero (lunes a domingo). */
function semana_de(string $fecha): array {
    $d = new \DateTimeImmutable(substr($fecha, 0, 10)); $y = (int) $d->format('Y');
    $e = new \DateTimeImmutable("$y-01-01");
    return [$y, intdiv((int) $d->format('z') + dia_semana($e), 7) + 1];
}
/** Primer y último día de la semana DENTRO del año (las de los extremos son parciales). */
function rango_semana(int $y, int $w): array {
    $e = new \DateTimeImmutable("$y-01-01"); $lun = $e->modify('-' . dia_semana($e) . ' days')->modify('+' . (7 * ($w - 1)) . ' days');
    $ini = max($lun, $e); $fin = min($lun->modify('+6 days'), new \DateTimeImmutable("$y-12-31"));
    return [$ini->format('Y-m-d'), $fin->format('Y-m-d')];
}
function semana_valida(int $y, int $w): void {
    if ($y < 2000 || $y > 2100) throw new Fallo(422, 'Año fuera de rango');
    if ($w < 1 || $w > semanas_anio($y)) throw new Fallo(422, "El año $y tiene " . semanas_anio($y) . ' semanas');
}
/** Código de frecuencia a partir del texto de una planilla («Mensual», «mensual », «2 Años», «Quincenal»…); null si no se reconoce. */
function frec_de_texto(?string $t): ?string {
    $t = strtr(mb_strtolower(trim((string) $t)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']); if ($t === '') return null;
    $t = preg_replace('/\s+/', ' ', $t);
    if (isset(FRECS[strtoupper($t)])) return strtoupper($t);
    $m = ['diari' => 'D', 'semanal' => 'SM', 'quincenal' => 'Q', 'mensual' => 'M', 'bimestral' => 'B', 'trimestral' => 'T', 'semestral' => 'S', 'anual' => 'A',
          'bienal' => '2A', '2 anos' => '2A', 'cada 2 anos' => '2A', 'dos anos' => '2A'];
    foreach ($m as $k => $v) if (str_starts_with($t, $k)) return $v;
    return null;
}
function frec_valida(?string $f): ?string {
    if ($f === null || $f === '') return null;
    if (!isset(FRECS[$f])) throw new Fallo(422, 'frecuencia: ' . implode(' | ', array_keys(FRECS)));
    return $f;
}
/** Semanas del año en que cae una tarea que empieza en $inicio, con su frecuencia. */
function semanas_de_frecuencia(string $f, int $inicio, int $anio): array {
    $n = semanas_anio($anio); $paso = FRECS[$f][1]; $l = [];
    for ($w = $inicio; $w <= $n; $w += $paso) $l[] = $w;
    return $l;
}

// ------------------------------------------------------------------ tareas
/** Equipo o instalación en PIM por id o código (con o sin la sigla del establecimiento). */
function activo_pim(string $a): array {
    $a = trim($a); if ($a === '') throw new Fallo(422, 'Falta el equipo o la instalación');
    $d = preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $a) ? (pim('GET', "Device/$a", null, ['rol' => 'GM'])['cuerpo'] ?? null)
        : (pim('GET', 'Device?identifier=' . rawurlencode($a) . '&_count=1', null, ['rol' => 'GM'])['cuerpo']['entry'][0]['resource'] ?? null);
    if (!$d || ($d['resourceType'] ?? '') !== 'Device') throw new Fallo(422, "No existe el equipo o la instalación «{$a}» en PIM");
    $cod = ''; foreach ((array) ($d['identifier'] ?? []) as $i) if (str_ends_with((string) ($i['system'] ?? ''), '/sid/activo')) { $cod = (string) $i['value']; break; }
    return ['id' => $d['id'], 'codigo' => $cod ?: ($d['identifier'][0]['value'] ?? null), 'nombre' => $d['deviceName'][0]['name'] ?? $a];
}
function validar_tarea(array $c, bool $nueva): array {
    $f = [];
    foreach (CAMPOS_TAREA as $k => $max) if ($nueva || array_key_exists($k, $c)) { $v = trim((string) ($c[$k] ?? '')); $f[$k] = $v === '' ? null : mb_substr($v, 0, $max); }
    if (($nueva || array_key_exists('preventivo', $c)) && empty($f['preventivo'])) throw new Fallo(422, 'Falta la tarea preventiva');
    if ($nueva || array_key_exists('activo', $c)) { $a = activo_pim((string) ($c['activo'] ?? '')); $f += ['activo_id' => $a['id'], 'activo_codigo' => $a['codigo'], 'activo_nombre' => mb_substr((string) $a['nombre'], 0, 200)]; }
    if ($nueva || array_key_exists('frecuencia', $c)) $f['frecuencia'] = frec_valida(($x = (string) ($c['frecuencia'] ?? '')) === '' ? null : (isset(FRECS[$x]) ? $x : (frec_de_texto($x) ?? $x)));
    if (array_key_exists('tipo_mant', $c)) { if (!in_array($c['tipo_mant'], ['preventivo-sistematico', 'preventivo-condicional', 'predictivo', 'ensayo-reglamentario', 'mejora'], true)) throw new Fallo(422, 'tipo_mant inválido'); $f['tipo_mant'] = $c['tipo_mant']; }
    if (array_key_exists('activa', $c)) $f['activa'] = $c['activa'] ? 1 : 0;
    if (array_key_exists('orden_fila', $c)) $f['orden_fila'] = (int) $c['orden_fila'];
    return $f;
}
function crear_tarea(array $c, array $u): array {
    $f = validar_tarea($c, true);
    q('INSERT INTO pmp_tarea (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')', array_values($f));
    $id = (int) pdo()->lastInsertId(); asentar('pmp.tarea', $f, (string) $id);
    if (!empty($c['anio']) && !empty($c['semana_inicio']) && $f['frecuencia']) marcar_desde($id, (int) $c['anio'], (int) $c['semana_inicio']);
    return tarea($id);
}
function modificar_tarea(int $id, array $c, array $u): array {
    $ant = fila('SELECT * FROM pmp_tarea WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe la tarea');
    $f = validar_tarea($c, false); $f['modificado'] = date('Y-m-d H:i:s');
    q('UPDATE pmp_tarea SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?', [...array_values($f), $id]);
    asentar('pmp.tarea_modificada', array_diff_key($f, ['modificado' => 1]), (string) $id);
    return tarea($id);
}
function tarea(int $id): array { return fila('SELECT * FROM pmp_tarea WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe la tarea'); }

// ------------------------------------------------------------------ cronograma
/** Marca las semanas del año desde $inicio según la frecuencia (reemplaza las marcas automáticas de ese año; las puestas a mano se respetan). */
function marcar_desde(int $tareaId, int $anio, int $inicio): array {
    $t = tarea($tareaId); semana_valida($anio, $inicio);
    if (!$t['frecuencia']) throw new Fallo(422, 'La tarea no tiene frecuencia: marcá las semanas a mano o elegí una frecuencia');
    q('DELETE FROM pmp_semana WHERE tarea_id = ? AND anio = ? AND manual = 0', [$tareaId, $anio]);
    $w = semanas_de_frecuencia($t['frecuencia'], $inicio, $anio);
    foreach ($w as $s) q('INSERT IGNORE INTO pmp_semana (tarea_id, anio, semana, manual) VALUES (?,?,?,0)', [$tareaId, $anio, $s]);
    asentar('pmp.cronograma', ['tarea' => $tareaId, 'anio' => $anio, 'inicio' => $inicio, 'semanas' => count($w)], (string) $tareaId);
    return semanas_tarea($tareaId, $anio);
}
/** Pone o quita una semana a mano (como pintar o despintar la celda de la planilla). */
function alternar_semana(int $tareaId, int $anio, int $semana): array {
    tarea($tareaId); semana_valida($anio, $semana);
    if (fila('SELECT 1 FROM pmp_semana WHERE tarea_id = ? AND anio = ? AND semana = ?', [$tareaId, $anio, $semana])) q('DELETE FROM pmp_semana WHERE tarea_id = ? AND anio = ? AND semana = ?', [$tareaId, $anio, $semana]);
    else q('INSERT INTO pmp_semana (tarea_id, anio, semana, manual) VALUES (?,?,?,1)', [$tareaId, $anio, $semana]);
    return semanas_tarea($tareaId, $anio);
}
/** Marcado en bloque desde la grilla (2.1.0): a varias tareas les pone frecuencia y/o las marca desde una semana.
 *  Con frecuencia: el año se marca según ella. Sin frecuencia: solo esa semana, a mano (como pintar una celda). */
function marcar_lote(array $c, array $u): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($c['ids'] ?? []))))); if (!$ids) throw new Fallo(422, 'Elegí al menos una tarea');
    if (count($ids) > 1000) throw new Fallo(422, 'Hasta 1000 tareas por vez');
    $f = array_key_exists('frecuencia', $c) && $c['frecuencia'] !== '__igual' ? frec_valida($c['frecuencia'] === '' ? null : (string) $c['frecuencia']) : '__igual';
    $anio = (int) ($c['anio'] ?? 0); $w = (int) ($c['semana_inicio'] ?? 0); if ($w) semana_valida($anio, $w);
    if ($f === '__igual' && !$w) throw new Fallo(422, 'Indicá una frecuencia, una semana de inicio o las dos');
    $r = ['tareas' => 0, 'frecuencia' => 0, 'marcadas' => 0];
    foreach ($ids as $id) {
        $t = tarea($id); $r['tareas']++;
        if ($f !== '__igual' && $t['frecuencia'] !== $f) { q('UPDATE pmp_tarea SET frecuencia = ?, modificado = NOW() WHERE id = ?', [$f, $id]); $r['frecuencia']++; $t['frecuencia'] = $f; }
        if ($w) { if ($t['frecuencia']) marcar_desde($id, $anio, $w); else q('INSERT IGNORE INTO pmp_semana (tarea_id, anio, semana, manual) VALUES (?,?,?,1)', [$id, $anio, $w]); $r['marcadas']++; }
    }
    asentar('pmp.lote', $r + ['anio' => $anio, 'semana' => $w, 'frecuencia' => $f], (string) ($u['usuario'] ?? ''));
    return $r;
}
function semanas_tarea(int $tareaId, int $anio): array { return array_map('intval', array_column(filas('SELECT semana FROM pmp_semana WHERE tarea_id = ? AND anio = ? ORDER BY semana', [$tareaId, $anio]), 'semana')); }
/** Arma el año siguiente: cada tarea con frecuencia arranca en la misma semana que este año (las que ya tienen marcas allá no se tocan). */
function copiar_anio(int $anio): array {
    $dest = $anio + 1; $n = 0; $salt = 0;
    foreach (filas('SELECT s.tarea_id, MIN(s.semana) w0, t.frecuencia FROM pmp_semana s JOIN pmp_tarea t ON t.id = s.tarea_id WHERE s.anio = ? AND t.activa = 1 GROUP BY s.tarea_id, t.frecuencia', [$anio]) as $r) {
        if (fila('SELECT 1 FROM pmp_semana WHERE tarea_id = ? AND anio = ? LIMIT 1', [$r['tarea_id'], $dest])) { $salt++; continue; }
        if ($r['frecuencia']) { $w0 = min((int) $r['w0'], semanas_anio($dest)); foreach (semanas_de_frecuencia($r['frecuencia'], $w0, $dest) as $s) q('INSERT IGNORE INTO pmp_semana (tarea_id, anio, semana, manual) VALUES (?,?,?,0)', [$r['tarea_id'], $dest, $s]); }
        else foreach (filas('SELECT semana, manual FROM pmp_semana WHERE tarea_id = ? AND anio = ?', [$r['tarea_id'], $anio]) as $s) if ((int) $s['semana'] <= semanas_anio($dest)) q('INSERT IGNORE INTO pmp_semana (tarea_id, anio, semana, manual) VALUES (?,?,?,1)', [$r['tarea_id'], $dest, $s['semana']]);
        $n++;
    }
    asentar('pmp.copia_anio', ['de' => $anio, 'a' => $dest, 'tareas' => $n, 'ya_tenian' => $salt]);
    return ['anio' => $dest, 'tareas' => $n, 'ya_tenian' => $salt];
}
/** Tareas con sus semanas marcadas en el año, con filtros (sistema, ubicación, equipo, frecuencia, texto). */
function cronograma(int $anio, array $g): array {
    semana_valida($anio, 1);
    $w = ['t.activa = 1']; $a = [];
    foreach (['sistema', 'ubicacion', 'frecuencia'] as $k) if (($v = trim((string) ($g[$k] ?? ''))) !== '') { if ($v === '-') { $w[] = "t.$k IS NULL"; continue; } $w[] = "t.$k = ?"; $a[] = $v; }
    if (($v = (string) ($g['marcas'] ?? '')) !== '') $w[] = ($v === '0' ? 'NOT ' : '') . 'EXISTS (SELECT 1 FROM pmp_semana s WHERE s.tarea_id = t.id AND s.anio = ' . (int) $anio . ')';
    if (($v = trim((string) ($g['activo'] ?? ''))) !== '') { $w[] = 't.activo_id = ?'; $a[] = $v; }
    if (($v = trim((string) ($g['q'] ?? ''))) !== '') { $w[] = 'CONCAT_WS(" ", t.activo_nombre, t.activo_codigo, t.instalacion, t.sector, t.categoria, t.parte, t.preventivo, t.predictivo) LIKE ?'; $a[] = '%' . $v . '%'; }
    $ts = filas('SELECT t.* FROM pmp_tarea t WHERE ' . implode(' AND ', $w) . ' ORDER BY t.sistema, t.ubicacion, t.activo_nombre, t.orden_fila, t.id LIMIT 3000', $a);
    $sem = []; if ($ts) foreach (filas('SELECT tarea_id, semana, manual FROM pmp_semana WHERE anio = ? AND tarea_id IN (' . implode(',', array_map('intval', array_column($ts, 'id'))) . ')', [$anio]) as $s) $sem[$s['tarea_id']][] = (int) $s['semana'];
    [$ya, $wa] = semana_de(date('Y-m-d'));
    return ['anio' => $anio, 'semanas' => semanas_anio($anio), 'actual' => $ya === $anio ? $wa : null, 'meses' => meses_semanas($anio), 'frecuencias' => FRECS,
            'tareas' => array_map(fn($t) => $t + ['semanas_marcadas' => $sem[$t['id']] ?? []], $ts),
            'sistemas' => array_column(filas('SELECT DISTINCT sistema FROM pmp_tarea WHERE activa = 1 AND sistema IS NOT NULL ORDER BY sistema'), 'sistema'),
            'ubicaciones' => array_column(filas('SELECT DISTINCT ubicacion FROM pmp_tarea WHERE activa = 1 AND ubicacion IS NOT NULL ORDER BY ubicacion'), 'ubicacion')];
}
/** Mes de cada semana (el del lunes, o enero para la primera) para el encabezado de la grilla. */
function meses_semanas(int $anio): array { $l = []; for ($w = 1, $n = semanas_anio($anio); $w <= $n; $w++) $l[] = (int) substr(rango_semana($anio, $w)[0], 5, 2); return $l; }

// ------------------------------------------------------------------ órdenes de la semana
/** Genera, si faltan, las órdenes de la semana: una por equipo con sus tareas marcadas. Devuelve cuántas creó. */
function generar_semana(int $anio, int $semana): array {
    semana_valida($anio, $semana); [$d, $h] = rango_semana($anio, $semana);
    $por = []; foreach (filas('SELECT t.* FROM pmp_semana s JOIN pmp_tarea t ON t.id = s.tarea_id WHERE s.anio = ? AND s.semana = ? AND t.activa = 1 ORDER BY t.activo_nombre, t.orden_fila, t.id', [$anio, $semana]) as $t) $por[$t['activo_id']][] = $t;
    $n = 0; $err = [];
    foreach ($por as $act => $ts) {
        if ($o = fila('SELECT id FROM pmp_orden WHERE anio = ? AND semana = ? AND activo_id = ?', [$anio, $semana, $act])) { foreach ($ts as $t) q('INSERT IGNORE INTO pmp_ejecucion (orden_id, tarea_id) VALUES (?,?)', [$o['id'], $t['id']]); continue; }
        try { crear_orden_semana($anio, $semana, $d, $h, $ts); $n++; }
        catch (\Throwable $e) { $err[] = ['activo' => $ts[0]['activo_nombre'], 'error' => $e->getMessage()]; asentar('pmp.error', ['activo' => $act, 'error' => $e->getMessage()], "$anio-$semana"); }
    }
    return ['anio' => $anio, 'semana' => $semana, 'equipos' => count($por), 'creadas' => $n, 'errores' => $err];
}
function crear_orden_semana(int $anio, int $semana, string $d, string $h, array $ts): void {
    $a = $ts[0]; $dmy = fn($x) => implode('/', array_reverse(explode('-', $x)));
    $lineas = array_map(fn($t) => '- ' . implode(' / ', array_filter([$t['parte'], $t['preventivo']])) . ($t['predictivo'] ? " · predictivo: {$t['predictivo']}" : '') . ($t['frecuencia'] ? ' (' . mb_strtolower(FRECS[$t['frecuencia']][0]) . ')' : ''), $ts);
    $nota = "[Preventivo semana $semana/$anio] {$a['activo_nombre']}\nRealizar entre el {$dmy($d)} y el {$dmy($h)}; informar la fecha real de cada tarea" . (in_array('D', array_column($ts, 'frecuencia'), true) ? ' y marcar cada día las diarias' : '') . ".\nTareas:\n" . implode("\n", $lineas);
    $prio = 'routine';
    $r = pim('POST', 'ServiceRequest', ['resourceType' => 'ServiceRequest', 'status' => 'active', 'intent' => 'order', 'priority' => $prio, 'subject' => ['reference' => 'Device/' . $a['activo_id']],
        'code' => ['coding' => [['code' => 'PREVENTIVO']], 'text' => "Preventivo semana $semana · " . mb_substr((string) $a['activo_nombre'], 0, 120)],
        'requester' => ['display' => 'Plan preventivo semanal'], 'note' => [['text' => mb_substr($nota, 0, 3800)]]], ['rol' => 'GM', 'exigir' => true]);
    $task = substr((string) ($r['cab']['x-pim-task'] ?? ''), 5); $sr = (string) ($r['cuerpo']['id'] ?? ''); $num = null;
    if ($task !== '') {
        $tipos = array_unique(array_column($ts, 'tipo_mant')); $tipo = count($tipos) === 1 ? $tipos[0] : 'preventivo-sistematico';
        $par = [['name' => 'tipoMantenimiento', 'valueCode' => $tipo]];
        $resp = current(array_filter(array_column($ts, 'responsable'))) ?: null;
        if ($resp && ($org = pim('GET', 'Organization?identifier=' . rawurlencode($resp) . '&_count=1', null, ['rol' => 'GM'])['cuerpo']['entry'][0]['resource']['id'] ?? null))
            $par = array_merge([['name' => 'estado', 'valueCode' => 'asignada'], ['name' => 'responsable', 'valueReference' => ['reference' => "Organization/$org"]]], $par);
        else $par = array_merge([['name' => 'estado', 'valueCode' => 'en-revision'], ['name' => 'motivo', 'valueString' => 'Preventivo programado: falta asignar responsable']], $par);
        $tk = pim('GET', "Task/$task", null, ['rol' => 'GM'])['cuerpo']; $num = $tk['identifier'][0]['value'] ?? null;
        $t = pim('POST', "Task/$task/\$transicion", ['resourceType' => 'Parameters', 'parameter' => $par], ['rol' => 'GM', 'if_match' => 'W/"' . ($tk['meta']['versionId'] ?? '1') . '"']);
        if ($t['http'] >= 400) asentar('pmp.transicion_fallida', ['task' => $task, 'error' => $t['cuerpo']['issue'][0]['diagnostics'] ?? $t['http']]);
    }
    q('INSERT INTO pmp_orden (anio, semana, activo_id, activo_nombre, sr_id, task_id, numero) VALUES (?,?,?,?,?,?,?)', [$anio, $semana, $a['activo_id'], $a['activo_nombre'], $sr, $task, $num]);
    $oid = (int) pdo()->lastInsertId();
    foreach ($ts as $t) q('INSERT IGNORE INTO pmp_ejecucion (orden_id, tarea_id) VALUES (?,?)', [$oid, $t['id']]);
    asentar('pmp.orden', ['anio' => $anio, 'semana' => $semana, 'task' => $task, 'tareas' => count($ts)], (string) $oid);
}
/** La semana: equipos con sus tareas marcadas, la orden generada y lo registrado de cada tarea. */
function vista_semana(int $anio, int $semana, array $u): array {
    semana_valida($anio, $semana); [$d, $h] = rango_semana($anio, $semana);
    $ts = filas('SELECT t.*, o.id orden_id, o.numero, o.task_id, o.estado orden_estado, e.resultado, e.fecha_real, e.dias, e.nota, e.usuario reg_usuario, e.actualizado reg_fecha
        FROM pmp_semana s JOIN pmp_tarea t ON t.id = s.tarea_id LEFT JOIN pmp_orden o ON o.anio = s.anio AND o.semana = s.semana AND o.activo_id = t.activo_id
        LEFT JOIN pmp_ejecucion e ON e.orden_id = o.id AND e.tarea_id = t.id WHERE s.anio = ? AND s.semana = ? AND t.activa = 1 ORDER BY t.sistema, t.activo_nombre, t.orden_fila, t.id', [$anio, $semana]);
    $eq = [];
    foreach ($ts as $t) {
        $k = $t['activo_id'];
        $eq[$k] ??= ['activo_id' => $k, 'activo_nombre' => $t['activo_nombre'], 'activo_codigo' => $t['activo_codigo'], 'sistema' => $t['sistema'], 'ubicacion' => $t['ubicacion'],
                     'orden_id' => $t['orden_id'] ? (int) $t['orden_id'] : null, 'numero' => $t['numero'], 'orden_estado' => $t['orden_estado'], 'tareas' => []];
        $eq[$k]['tareas'][] = ['id' => (int) $t['id'], 'parte' => $t['parte'], 'preventivo' => $t['preventivo'], 'predictivo' => $t['predictivo'], 'frecuencia' => $t['frecuencia'], 'personal' => $t['personal'],
                               'resultado' => $t['resultado'] ?? ($t['orden_id'] ? 'pendiente' : null), 'fecha_real' => $t['fecha_real'],
                               'dias' => $t['frecuencia'] === 'D' ? dias_semana($anio, $semana, $t['dias'] ?? null) : null, 'nota' => $t['nota'], 'usuario' => $t['reg_usuario'], 'actualizado' => $t['reg_fecha']];
    }
    $eq = array_values($eq);
    $tot = ['tareas' => count($ts)] + array_fill_keys(['hecha', 'no_hecha', 'no_aplica', 'pendiente', 'sin_orden'], 0);
    $tot['a_tiempo'] = 0; $tot['dias_programados'] = 0; $tot['dias_hechos'] = 0;   // hechas dentro de su semana (solo las tareas que se muestran) y días de las diarias
    foreach ($eq as $e) foreach ($e['tareas'] as $t) {
        $tot[$t['resultado'] ?? 'sin_orden']++; if ($t['resultado'] === 'hecha' && $t['fecha_real'] >= $d && $t['fecha_real'] <= $h) $tot['a_tiempo']++;
        foreach ((array) $t['dias'] as $x) if ($x['en_rango']) { $tot['dias_programados']++; $tot['dias_hechos'] += (int) $x['hecho']; }
    }
    return ['anio' => $anio, 'semana' => $semana, 'semanas' => semanas_anio($anio), 'desde' => $d, 'hasta' => $h, 'equipos' => $eq, 'totales' => $tot];
}
/** Los 7 días (lunes a domingo) de una semana para una tarea diaria: fecha, si cae dentro del año y si está hecho. */
function dias_semana(int $anio, int $semana, ?string $dias): array {
    [$d, $h] = rango_semana($anio, $semana); $lun = (new \DateTimeImmutable($d))->modify('-' . dia_semana(new \DateTimeImmutable($d)) . ' days'); $l = [];
    for ($i = 0; $i < 7; $i++) { $f = $lun->modify("+$i days")->format('Y-m-d'); $en = $f >= $d && $f <= $h; $l[] = ['fecha' => $f, 'en_rango' => $en, 'hecho' => $en && ($dias[$i] ?? '0') === '1']; }
    return $l;
}
/** Registra el resultado de las tareas de una orden semanal. Hecha exige la fecha real; no se hizo exige el motivo.
 *  Diarias (2.1.0): llegan con «dias» (7 valores, lunes a domingo). Con todos los días del año marcados pasa sola a «hecha» (fecha real = el último día);
 *  si falta alguno y la semana ya se cerró, va «no se hizo» con el motivo. No se marca un día futuro. */
function registrar_semana(int $ordenId, array $l, array $u): array {
    $o = fila('SELECT * FROM pmp_orden WHERE id = ?', [$ordenId]) ?? throw new Fallo(404, 'No existe esa orden semanal');
    if (($u['rol'] ?? '') === 'EJ') exigir_empresa(['task_id' => $o['task_id']], $u);
    [$d] = rango_semana((int) $o['anio'], (int) $o['semana']); $hoy = date('Y-m-d'); $n = 0;
    foreach ($l as $x) {
        $tid = (int) ($x['tarea_id'] ?? 0); if (!fila('SELECT 1 FROM pmp_ejecucion WHERE orden_id = ? AND tarea_id = ?', [$ordenId, $tid])) throw new Fallo(422, "La tarea $tid no es de esta orden");
        $r = (string) ($x['resultado'] ?? ''); if (!in_array($r, RESULTADOS, true)) throw new Fallo(422, 'resultado: ' . implode(' | ', RESULTADOS));
        $nota = mb_substr(trim((string) ($x['nota'] ?? '')), 0, 300) ?: null; $fr = trim((string) ($x['fecha_real'] ?? '')) ?: null; $ds = null;
        if ((tarea($tid)['frecuencia'] ?? '') === 'D' && is_array($x['dias'] ?? null)) {
            $cal = dias_semana((int) $o['anio'], (int) $o['semana'], null); $ds = ''; $ult = null; $falta = 0;
            foreach ($cal as $i => $c) {
                $on = !empty($x['dias'][$i]) && $c['en_rango'];
                if ($on && $c['fecha'] > $hoy) throw new Fallo(422, 'No se puede marcar un día que todavía no llegó (' . implode('/', array_reverse(explode('-', $c['fecha']))) . ')');
                $ds .= !$c['en_rango'] ? '-' : ($on ? '1' : '0'); if ($on) $ult = $c['fecha']; elseif ($c['en_rango']) $falta++;
            }
            if (!$falta && $r !== 'no_aplica') { $r = 'hecha'; $fr = $ult; }
            elseif ($r === 'hecha') throw new Fallo(422, "Faltan $falta día(s) de esta tarea diaria: marcalos o, si no se hicieron, elegí «No se hizo» con el motivo");
        }
        if ($r === 'hecha') {
            if (!$fr || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fr) || !checkdate((int) substr($fr, 5, 2), (int) substr($fr, 8, 2), (int) substr($fr, 0, 4))) throw new Fallo(422, 'Una tarea hecha necesita la fecha real (AAAA-MM-DD)');
            if ($fr > $hoy) throw new Fallo(422, 'La fecha real no puede ser futura');
            if ($fr < date('Y-m-d', strtotime("$d -31 days"))) throw new Fallo(422, 'La fecha real es muy anterior a la semana programada');
        } else $fr = null;
        if ($r === 'no_hecha' && !$nota) throw new Fallo(422, 'Una tarea que no se hizo necesita el motivo (nota)');
        $n += q('UPDATE pmp_ejecucion SET resultado = ?, fecha_real = ?, dias = COALESCE(?, dias), nota = ?, usuario = ?, actualizado = NOW() WHERE orden_id = ? AND tarea_id = ?
                 AND (resultado <> ? OR COALESCE(fecha_real, \'\') <> COALESCE(?, \'\') OR COALESCE(nota, \'\') <> COALESCE(?, \'\') OR COALESCE(dias, \'\') <> COALESCE(?, dias, \'\'))',
                [$r, $fr, $ds, $nota, (string) ($u['usuario'] ?? ''), $ordenId, $tid, $r, $fr, $nota, $ds])->rowCount();
    }
    if ($n) asentar('pmp.registro', ['orden' => $ordenId, 'cambios' => $n], (string) $ordenId);
    $p = (int) (fila("SELECT COUNT(*) n FROM pmp_ejecucion WHERE orden_id = ? AND resultado = 'pendiente'", [$ordenId])['n'] ?? 0);
    return ['orden' => $ordenId, 'cambios' => $n, 'pendientes' => $p];
}
/** Cumplimiento del año por semana (para el tablero): programadas, hechas, hechas dentro de su semana. */
function cumplimiento(int $anio): array {
    $l = filas("SELECT o.semana, COUNT(*) programadas, SUM(e.resultado = 'hecha') hechas, SUM(e.resultado = 'no_hecha') no_hechas, SUM(e.resultado = 'pendiente') pendientes
                FROM pmp_ejecucion e JOIN pmp_orden o ON o.id = e.orden_id WHERE o.anio = ? GROUP BY o.semana ORDER BY o.semana", [$anio]);
    return array_map(fn($x) => array_map('intval', $x), $l);
}

// ------------------------------------------------------------------ relevamiento en recorrida (borradores del operario → revisión del Gestor)
const RELEV_ESTADOS = ['enviado', 'aprobado', 'rechazado'];
function guardar_relevamiento(array $c, array $u): array {
    $uid = (string) ($c['uid'] ?? ''); if (!preg_match('/^[0-9a-f-]{36}$/', $uid)) throw new Fallo(422, 'uid: identificador del borrador (UUID)');
    $d = $c['datos'] ?? null; if (!is_array($d)) throw new Fallo(422, 'Faltan los datos del relevamiento');
    $un = (array) ($d['unidad'] ?? []);
    if (trim((string) ($un['nombre'] ?? '')) === '' && trim((string) ($un['codigo'] ?? '')) === '') throw new Fallo(422, 'La unidad necesita nombre o código');
    $tareas = array_values(array_filter((array) ($d['tareas'] ?? []), fn($t) => trim((string) ($t['preventivo'] ?? '')) !== ''));
    if (!$tareas) throw new Fallo(422, 'El relevamiento no tiene tareas');
    if (count($tareas) > 400) throw new Fallo(422, 'Hasta 400 tareas por unidad');
    $d['tareas'] = $tareas; $json = json_encode($d, JSON_UNESCAPED_UNICODE); if (strlen($json) > 2_000_000) throw new Fallo(422, 'El relevamiento es demasiado grande');
    $tit = mb_substr(trim(($un['codigo'] ?? '') . ' · ' . ($un['nombre'] ?? ''), ' ·'), 0, 200);
    $ya = fila('SELECT * FROM pmp_relevamiento WHERE uid = ?', [$uid]);
    if ($ya) {
        if ($ya['usuario'] !== ($u['usuario'] ?? '') && ($u['rol'] ?? '') !== 'GM' && ($u['rol'] ?? '') !== 'AD') throw new Fallo(403, 'Ese relevamiento es de otra persona');
        if ($ya['estado'] === 'aprobado') throw new Fallo(409, 'Ya fue aprobado: no se modifica');
        q("UPDATE pmp_relevamiento SET datos = ?, titulo = ?, estado = 'enviado', actualizado = NOW() WHERE id = ?", [$json, $tit, $ya['id']]);
        $id = (int) $ya['id'];
    } else { q('INSERT INTO pmp_relevamiento (uid, usuario, titulo, datos) VALUES (?,?,?,?)', [$uid, (string) ($u['usuario'] ?? ''), $tit, $json]); $id = (int) pdo()->lastInsertId(); }
    asentar('pmp.relevamiento', ['id' => $id, 'tareas' => count($tareas)], (string) $id);
    return relevamiento($id, $u);
}
function relevamiento(int $id, array $u): array {
    $r = fila('SELECT * FROM pmp_relevamiento WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe ese relevamiento');
    if (!in_array($u['rol'] ?? '', ['GM', 'AD'], true) && $r['usuario'] !== ($u['usuario'] ?? '')) throw new Fallo(403, 'Ese relevamiento es de otra persona');
    $r['datos'] = json_decode((string) $r['datos'], true); $r['resultado'] = $r['resultado'] ? json_decode((string) $r['resultado'], true) : null;
    return $r;
}
function relevamientos(array $u, string $estado = ''): array {
    $w = []; $a = []; if (!in_array($u['rol'] ?? '', ['GM', 'AD'], true)) { $w[] = 'usuario = ?'; $a[] = (string) ($u['usuario'] ?? ''); }
    if (in_array($estado, RELEV_ESTADOS, true)) { $w[] = 'estado = ?'; $a[] = $estado; }
    return array_map(function ($r) { $d = json_decode((string) $r['datos'], true); unset($r['datos'], $r['resultado']); return $r + ['tareas' => count((array) ($d['tareas'] ?? []))]; },
        filas('SELECT * FROM pmp_relevamiento' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY FIELD(estado, \'enviado\', \'rechazado\', \'aprobado\'), creado DESC LIMIT 300', $a));
}
/** El Gestor aprueba: crea el equipo en PIM si no existe (código con la sigla del establecimiento la pone el Servidor PIM) y pasa las tareas al plan. */
function aprobar_relevamiento(int $id, array $c, array $u): array {
    $r = relevamiento($id, $u); if ($r['estado'] === 'aprobado') throw new Fallo(409, 'Ya estaba aprobado');
    $d = $r['datos']; if (is_array($c['datos'] ?? null)) $d = $c['datos'];   // el Gestor puede corregir antes de aprobar
    $un = (array) ($d['unidad'] ?? []); $cod = trim((string) ($un['codigo'] ?? ''));
    $act = null; try { if ($cod !== '') $act = activo_pim($cod); } catch (Fallo $e) { $act = null; }
    $creado = false;
    if (!$act) {
        $clase = trim((string) ($un['clase'] ?? ''));
        $dev = ['resourceType' => 'Device', 'status' => 'active', 'deviceName' => [['name' => mb_substr(trim((string) ($un['nombre'] ?? $cod)), 0, 200), 'type' => 'user-friendly-name']]];
        if ($cod !== '') $dev['identifier'] = [['system' => NS_PIM . '/sid/activo', 'value' => $cod]];
        if ($clase !== '') $dev['type'] = ['coding' => [['system' => NS_PIM . '/CodeSystem/pim-sistema', 'code' => $clase]]];
        $rr = pim('POST', 'Device', $dev, ['rol' => 'GM']);
        if (($rr['http'] ?? 0) >= 300 || empty($rr['cuerpo']['id'])) throw new Fallo(422, 'El Servidor PIM no aceptó el equipo: ' . ($rr['cuerpo']['issue'][0]['diagnostics'] ?? ('HTTP ' . ($rr['http'] ?? '?'))));
        $act = activo_pim($rr['cuerpo']['id']); $creado = true;
    }
    $anio = (int) ($c['anio'] ?? date('Y')); $ids = [];
    foreach ((array) ($d['tareas'] ?? []) as $i => $t) {
        $f = ['activo' => $act['id'], 'sistema' => $un['sistema'] ?? null, 'ubicacion' => $un['ubicacion'] ?? null, 'instalacion' => $un['instalacion'] ?? null, 'sector' => $un['sector'] ?? null,
              'categoria' => $un['categoria'] ?? null, 'parte' => $t['parte'] ?? null, 'preventivo' => $t['preventivo'] ?? '', 'predictivo' => $t['predictivo'] ?? null, 'personal' => $t['personal'] ?? null,
              'hoja_vida' => $t['hoja_vida'] ?? null, 'inspeccion' => $t['inspeccion'] ?? null, 'insumos' => $t['insumos'] ?? null, 'frecuencia' => $t['frecuencia'] ?? '', 'responsable' => $un['responsable'] ?? null, 'orden_fila' => $i];
        $nt = crear_tarea($f + (!empty($t['semana_inicio']) ? ['anio' => $anio, 'semana_inicio' => (int) $t['semana_inicio']] : []), $u);
        q("UPDATE pmp_tarea SET origen = 'relevamiento', origen_clave = ? WHERE id = ?", [sha1("relev|$id|$i"), $nt['id']]); $ids[] = $nt['id'];
    }
    $res = ['activo' => $act, 'activo_creado' => $creado, 'tareas' => $ids, 'anio' => $anio];
    q("UPDATE pmp_relevamiento SET estado = 'aprobado', revisado_por = ?, revisado = NOW(), nota_revision = ?, resultado = ?, datos = ? WHERE id = ?",
      [(string) ($u['usuario'] ?? ''), mb_substr(trim((string) ($c['nota'] ?? '')), 0, 300) ?: null, json_encode($res, JSON_UNESCAPED_UNICODE), json_encode($d, JSON_UNESCAPED_UNICODE), $id]);
    asentar('pmp.relevamiento_aprobado', ['id' => $id, 'tareas' => count($ids), 'activo_creado' => $creado], (string) $id);
    return relevamiento($id, $u);
}
function rechazar_relevamiento(int $id, array $c, array $u): array {
    $r = relevamiento($id, $u); if ($r['estado'] === 'aprobado') throw new Fallo(409, 'Ya estaba aprobado');
    $n = mb_substr(trim((string) ($c['nota'] ?? '')), 0, 300); if ($n === '') throw new Fallo(422, 'Escribí qué hay que corregir');
    q("UPDATE pmp_relevamiento SET estado = 'rechazado', revisado_por = ?, revisado = NOW(), nota_revision = ? WHERE id = ?", [(string) ($u['usuario'] ?? ''), $n, $id]);
    asentar('pmp.relevamiento_rechazado', ['id' => $id], (string) $id);
    return relevamiento($id, $u);
}

// ------------------------------------------------------------------ plantillas por tipo de equipo (proponen partes y tareas; todo editable)
const PLANTILLAS_SEMILLA = [
    'Motor eléctrico' => [['Motor', 'Ventilador / Aspas', 'Saneamiento mecánico de rejillas', 'M'], ['Motor', 'Rodamientos', 'Verificación de temperatura y ruidos', 'B'],
        ['Motor', 'Rotor y Eje', 'Inspección de alineación y juego axial', 'B'], ['Motor', 'Caja de Bornes', 'Ajuste de terminales con torquímetro', 'T'], ['Motor', 'Bobinado', 'Prueba de aislamiento (megado)', 'T']],
    'Bomba centrífuga' => [['Sistema hidráulico', 'Sello mecánico', 'Inspección de goteo admisible', 'Q'], ['Sistema hidráulico', 'Impulsor', 'Inspección de cavitación', 'SM'],
        ['Sistema hidráulico', 'Filtro de succión', 'Limpieza mecánica', 'B'], ['Transmisión mecánica', 'Acople flexible', 'Verificación de alineación', 'B'],
        ['Motor', 'Rodamientos', 'Verificación de temperatura y ruidos', 'T'], ['Motor', 'Caja de Bornes', 'Ajuste de terminales con torquímetro', 'T'], ['Motor', 'Bobinado', 'Prueba de aislamiento (megado)', 'T']],
    'Unidad de tratamiento de aire (UTA)' => [['Sistema de filtrado', 'Filtros G4', 'Lavado o reemplazo', 'T'], ['Sistema de filtrado', 'Filtros absolutos', 'Verificación de saturación', 'T'],
        ['Intercambio térmico', 'Bandeja de condensado', 'Limpieza y destape de drenaje', 'T'], ['Intercambio térmico', 'Serpentinas', 'Bactericida y peinado de aletas', 'S'],
        ['Motor', 'Ventilador / Aspas', 'Saneamiento mecánico de rejillas', 'M'], ['Motor', 'Rodamientos', 'Verificación de temperatura y ruidos', 'T'], ['Motor', 'Caja de Bornes', 'Ajuste de terminales con torquímetro', 'Q']],
    'Equipo de frío (chiller / condensadora)' => [['Circuito refrigerante', 'Compresor', 'Presiones y nivel de aceite', 'T'], ['Circuito refrigerante', 'Válvula de expansión', 'Control de sobrecalentamiento', 'M'],
        ['Intercambio térmico', 'Evaporador', 'Inspección de incrustaciones', 'SM'], ['Intercambio térmico', 'Condensador', 'Limpieza mecánica de aletas', 'B'], ['Sensores', 'Presostatos', 'Validación operativa de seguridad', 'M']],
    'Tablero eléctrico' => [['Tablero', 'Gabinete / Puertas', 'Saneamiento, aspirado fino y cierre estanco', 'S'], ['Tablero', 'Contactores / Relés', 'Control de vibración magnética y reemplazo preventivo', 'S'],
        ['Tablero', 'Borneras de potencia', 'Reajuste de torque con herramienta calibrada', 'A'], ['Tablero', 'Cableado interno', 'Inspección visual de aislación e indicios de recalentamiento', 'S'], ['Tablero', 'Disyuntores / Térmicas', 'Revisión general', 'A']],
    'Ascensor hidráulico' => [['Tracción', 'Central', 'Nivel de aceite', 'M'], ['Tracción', 'Pistón', 'Limpieza de vástago', 'M'], ['Habitáculo', 'Cabina', 'Nivelación de parada', 'M'], ['Estructura', 'Guías', 'Lubricación de carriles', 'M']],
    'Plataforma elevadora' => [['Mecanismo de elevación', 'Plataforma', 'Integridad de cadenas o cables', 'M'], ['Mecanismo de elevación', 'Pistón hidráulico', 'Fugas en retenes y vástago', 'M'],
        ['Seguridad', 'Finales de carrera', 'Prueba de parada en límites', 'M'], ['Seguridad', 'Trabas de puertas', 'Verificación de enclavamientos', 'M']],
    'Tanque a presión' => [['Instrumentación', 'Manómetro', 'Validación de aguja', 'Q'], ['Control de presión', 'Válvula de seguridad', 'Test de disparo', 'Q'], ['Cuerpo', 'Válvula de purga', 'Limpieza de fondo', 'M'], ['Estructura', '', 'Revisión de anclajes y corrosión', 'B']],
    'Caldera' => [['Combustión', 'Quemador', 'Calidad de llama', 'M'], ['Intercambio térmico', 'Cámara', 'Deshollinado profundo', 'S'], ['Seguridad', 'Válvulas de alivio', 'Test de disparo manual', 'T'], ['Evacuación', 'Chimenea', 'Inspección de conductos', 'S']],
    'Grupo electrógeno' => [['Alimentación', 'Tanque de combustible', 'Control de autonomía y purga', 'M'], ['Sistema eléctrico', 'Baterías', 'Mantenimiento de bornes y voltaje', 'M'], ['Generación', 'Alternador', 'Limpieza de bobinados y bornes', 'S'],
        ['Motor', 'Rodamientos', 'Verificación de temperatura y ruidos', 'T'], ['Motor', 'Caja de Bornes', 'Ajuste de terminales con torquímetro', 'T']],
];
function sembrar_plantillas(): void {
    static $hecho = false; if ($hecho) return; $hecho = true;
    if (fila('SELECT 1 FROM pmp_plantilla LIMIT 1')) return;
    foreach (PLANTILLAS_SEMILLA as $n => $p) q('INSERT IGNORE INTO pmp_plantilla (nombre, partes, semilla) VALUES (?,?,1)',
        [$n, json_encode(array_map(fn($x) => ['parte' => trim($x[0] . ($x[1] !== '' ? ' / ' . $x[1] : '')), 'preventivo' => $x[2], 'frecuencia' => $x[3]], $p), JSON_UNESCAPED_UNICODE)]);
}
function plantillas(): array { sembrar_plantillas(); return array_map(fn($r) => ['id' => (int) $r['id'], 'nombre' => $r['nombre'], 'semilla' => (int) $r['semilla'], 'partes' => json_decode((string) $r['partes'], true)], filas('SELECT * FROM pmp_plantilla ORDER BY nombre')); }
function guardar_plantilla(array $c): array {
    $n = mb_substr(trim((string) ($c['nombre'] ?? '')), 0, 80); if ($n === '') throw new Fallo(422, 'Falta el nombre de la plantilla');
    $p = array_values(array_filter(array_map(fn($x) => ['parte' => mb_substr(trim((string) ($x['parte'] ?? '')), 0, 160), 'preventivo' => mb_substr(trim((string) ($x['preventivo'] ?? '')), 0, 300),
        'frecuencia' => frec_valida(($f = (string) ($x['frecuencia'] ?? '')) === '' ? null : $f)], (array) ($c['partes'] ?? [])), fn($x) => $x['preventivo'] !== ''));
    if (!$p) throw new Fallo(422, 'La plantilla no tiene tareas');
    q('INSERT INTO pmp_plantilla (nombre, partes, semilla, modificado) VALUES (?,?,0,NOW()) ON DUPLICATE KEY UPDATE partes = VALUES(partes), modificado = NOW()', [$n, json_encode($p, JSON_UNESCAPED_UNICODE)]);
    return ['plantillas' => plantillas()];
}

// ------------------------------------------------------------------ importación de tareas (hoja PMP o cronograma anual)
/**
 * Filas: activo, preventivo, sistema, ubicacion, instalacion, sector, categoria, parte, predictivo, personal, hoja_vida, inspeccion, frecuencia, proxima (fecha → semana de inicio).
 * Repetible (clave de origen); si trae próxima ejecución y frecuencia, marca el cronograma del año de esa fecha desde su semana.
 */
function importar_tareas(array $filas, bool $simular): array {
    $c = ['nuevo' => 0, 'cambio' => 0, 'igual' => 0, 'error' => 0, 'sin_referencia' => 0]; $err = []; $sinFrec = []; $act = []; $marcadas = 0; $vistos = []; $sinF = 0;
    foreach ($filas as $x) {
        $fila = $x['_fila'] ?? null; $cod = trim((string) ($x['activo'] ?? '')); $prev = trim((string) ($x['preventivo'] ?? ''));
        if ($cod === '' || $prev === '' || preg_match('/^[-=>< ]+$|^>>|<<$/', $prev) || preg_match('/^>>|<<$/', trim((string) ($x['parte'] ?? '')))) continue;
        if (!array_key_exists($cod, $act)) { try { $act[$cod] = activo_pim($cod); } catch (Fallo $e) { $act[$cod] = null; } }
        if (!$act[$cod]) { $c['sin_referencia']++; if (count($err) < 100) $err[] = ['fila' => $fila, 'motivo' => "No existe el equipo «{$cod}» en PIM"]; continue; }
        $ft = trim((string) ($x['frecuencia'] ?? '')); $fr = $ft === '' ? null : frec_de_texto($ft); if ($ft !== '' && !$fr) $sinFrec[$ft] = ($sinFrec[$ft] ?? 0) + 1; if (!$fr) $sinF++;
        $parte = trim(implode(' / ', array_filter([trim((string) ($x['parte'] ?? '')), trim((string) ($x['subparte'] ?? ''))], fn($v) => $v !== '' && $v !== '-'))) ?: null;
        $clave = sha1('imp|' . $act[$cod]['id'] . '|' . mb_strtolower((string) $parte) . '|' . mb_strtolower($prev));
        $f = ['activo_id' => $act[$cod]['id'], 'activo_codigo' => $act[$cod]['codigo'], 'activo_nombre' => mb_substr((string) $act[$cod]['nombre'], 0, 200), 'parte' => $parte ? mb_substr($parte, 0, 160) : null,
              'preventivo' => mb_substr($prev, 0, 300), 'frecuencia' => $fr, 'orden_fila' => (int) $fila];
        foreach (['sistema', 'ubicacion', 'instalacion', 'sector', 'categoria', 'predictivo', 'personal', 'hoja_vida', 'inspeccion', 'insumos', 'responsable'] as $k) { $v = trim((string) ($x[$k] ?? '')); $f[$k] = $v === '' ? null : mb_substr($v, 0, CAMPOS_TAREA[$k]); }
        $vistos[$clave] = true;
        $ya = fila('SELECT * FROM pmp_tarea WHERE origen_clave = ?', [$clave]);
        if (!$ya) { $c['nuevo']++; if (!$simular) { $f += ['origen' => 'importado', 'origen_clave' => $clave]; q('INSERT INTO pmp_tarea (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')', array_values($f)); $tid = (int) pdo()->lastInsertId(); } }
        else { $cmb = array_filter($f, fn($v, $k) => (string) ($ya[$k] ?? '') !== (string) ($v ?? '') && $k !== 'orden_fila', ARRAY_FILTER_USE_BOTH); $tid = (int) $ya['id'];
               if ($cmb) { $c['cambio']++; if (!$simular) q('UPDATE pmp_tarea SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($cmb))) . ', modificado = NOW() WHERE id = ?', [...array_values($cmb), $tid]); } else $c['igual']++; }
        $px = fecha_iso((string) ($x['proxima'] ?? ''));
        if ($px && $fr && !$simular && isset($tid)) { [$y, $w] = semana_de($px); if (!fila('SELECT 1 FROM pmp_semana WHERE tarea_id = ? AND anio = ? LIMIT 1', [$tid, $y])) { marcar_desde($tid, $y, $w); $marcadas++; } }
        unset($tid);
    }
    $r = ['cuenta' => $c, 'errores' => $err, 'sin_equivalencia' => $sinFrec ? ['frecuencia' => $sinFrec] : [], 'tareas' => count($vistos), 'cronogramas_marcados' => $marcadas, 'sin_frecuencia' => $sinF];
    if (!$simular) asentar('pmp.importacion', ['cuenta' => $c, 'marcadas' => $marcadas]);
    return $r;
}
