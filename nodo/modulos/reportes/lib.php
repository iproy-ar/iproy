<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Reportes: copia de consulta de las órdenes de trabajo.
 *
 *   bus pim/Task/<create|update> ──▶ sincronizar(id): lee el historial de esa orden por la API PIM y deja
 *       rep_orden (cómo está hoy) + rep_movimiento (cada cambio de estado con su fecha).
 *   tarea «reconstruir» (diaria o a pedido): repasa todas las órdenes por si se perdió un evento.
 *
 * Sobre esa copia se arman la Auditoría (todas las órdenes, con filtros y calificación) y el Reporte de la empresa
 * (recibidas, terminadas, pendientes, preventivo/correctivo y movimientos diarios). El Ejecutor solo ve su organización.
 */
declare(strict_types=1);

namespace Modulos\Reportes;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, valor, pim, pim_lista, asentar, estado};

const ESTADOS = ['solicitada' => 'Solicitada', 'en-revision' => 'En revisión', 'asignada' => 'Asignada', 'aceptada' => 'Aceptada', 'lista' => 'Lista', 'en-ejecucion' => 'En ejecución',
    'en-espera' => 'En espera', 'finalizada' => 'Finalizada', 'conformada' => 'Conformada', 'rechazada' => 'Rechazada', 'anulada' => 'Anulada'];
const ABIERTAS = ['solicitada', 'en-revision', 'asignada', 'aceptada', 'lista', 'en-ejecucion', 'en-espera'];
const TERMINADAS = ['finalizada', 'conformada'];
const CERRADAS_SIN_HACER = ['rechazada', 'anulada'];
const PRIORIDADES = ['stat' => 'Emergencia', 'asap' => 'Muy urgente', 'urgent' => 'Urgente', 'routine' => 'Normal'];
const TIPOS = ['correctivo-inmediato' => 'Correctivo inmediato', 'correctivo-diferido' => 'Correctivo diferido', 'preventivo-sistematico' => 'Preventivo sistemático',
    'preventivo-condicional' => 'Preventivo condicional', 'predictivo' => 'Predictivo', 'mejora' => 'Mejora', 'ensayo-reglamentario' => 'Ensayo reglamentario', 'verificacion-tecnica-pma' => 'Verificación técnica'];

/** Clase de trabajo para comparar preventivo contra correctivo. */
function clase(?string $tipo): string {
    if ($tipo === null || $tipo === '') return 'sin-clasificar';
    if (str_starts_with($tipo, 'correctivo')) return 'correctivo';
    if (str_starts_with($tipo, 'preventivo') || $tipo === 'predictivo') return 'preventivo';
    return 'otros';
}
const CLASES = ['correctivo' => 'Correctivo', 'preventivo' => 'Preventivo', 'otros' => 'Otros trabajos', 'sin-clasificar' => 'Sin clasificar'];

function fecha_local(?string $iso): ?string { $t = $iso ? strtotime($iso) : false; return $t ? date('Y-m-d H:i:s', $t) : null; }
function ref_id(?array $r): ?string { $x = (string) ($r['reference'] ?? ''); return $x !== '' ? substr($x, (int) strrpos($x, '/') + 1) : null; }

/** Organización (empresa o área) de una orden: se guarda nombre y código la primera vez que aparece, o si cambió el nombre. */
function anotar_org(?string $id, ?string $nombre): void {
    if (!$id) return;
    $o = fila('SELECT nombre, codigo FROM rep_org WHERE id = ?', [$id]);
    if ($o && ($nombre === null || $o['nombre'] === $nombre) && $o['codigo'] !== null) return;
    $r = pim('GET', "Organization/$id");
    $j = $r['http'] === 200 ? $r['cuerpo'] : [];
    $tipo = null; foreach ((array) ($j['type'] ?? []) as $t) foreach ((array) ($t['coding'] ?? []) as $c) $tipo ??= $c['code'] ?? null;
    q('REPLACE INTO rep_org (id, codigo, nombre, tipo) VALUES (?,?,?,?)', [$id, isset($j['identifier'][0]['value']) ? mb_substr((string) $j['identifier'][0]['value'], 0, 60) : ($o['codigo'] ?? null),
        mb_substr((string) ($j['name'] ?? $nombre ?? $o['nombre'] ?? $id), 0, 160), $tipo !== null ? mb_substr((string) $tipo, 0, 20) : null]);
}

/** Clase del activo de una orden según su perfil PIM: un sistema de instalación (pim-sistema) o un equipo. Se consulta una vez por pasada. */
function clase_de_activo(string $id): string {
    static $c = [];
    if (isset($c[$id])) return $c[$id];
    try { $d = \Nucleo\pim('GET', 'Device/' . rawurlencode($id))['cuerpo'] ?? []; } catch (\Throwable) { $d = []; }
    foreach ((array) ($d['meta']['profile'] ?? []) as $p) if (str_ends_with((string) $p, '/pim-sistema')) return $c[$id] = 'instalacion';
    return $c[$id] = 'equipo';
}
function guardar_orden(array $t): void {
    $ext = [];
    foreach ((array) ($t['extension'] ?? []) as $x) $ext[basename((string) ($x['url'] ?? ''))] = $x['valueCode'] ?? ($x['valueCoding']['code'] ?? null);
    $notas = array_values(array_filter(array_map(fn($n) => trim((string) ($n['text'] ?? '')), (array) ($t['note'] ?? []))));
    $org = ref_id($t['owner'] ?? null);
    anotar_org($org, $t['owner']['display'] ?? null);
    $num = null; foreach ((array) ($t['identifier'] ?? []) as $i) if (($i['use'] ?? '') === 'official' || $num === null) $num = (string) ($i['value'] ?? '');
    $creada = fecha_local($t['authoredOn'] ?? null) ?? fecha_local($t['meta']['lastUpdated'] ?? null) ?? date('Y-m-d H:i:s');
    $d = ['numero' => $num !== null ? mb_substr($num, 0, 40) : null, 'version' => (int) ($t['meta']['versionId'] ?? 0),
        'estado' => mb_substr((string) ($t['businessStatus']['coding'][0]['code'] ?? $t['status'] ?? ''), 0, 20), 'prioridad' => isset($t['priority']) ? mb_substr((string) $t['priority'], 0, 10) : null,
        'tipo_mant' => isset($ext['pim-tipo-mantenimiento']) ? mb_substr((string) $ext['pim-tipo-mantenimiento'], 0, 40) : null,
        'titulo' => mb_substr((string) ($t['code']['text'] ?? $t['code']['coding'][0]['display'] ?? ''), 0, 200) ?: null,
        'tarea' => tarea_de($t),
        'detalle' => isset($t['description']) && trim((string) $t['description']) !== '' ? (string) $t['description'] : ($notas ? implode("\n", $notas) : null),
        'motivo' => isset($t['statusReason']['text']) ? mb_substr((string) $t['statusReason']['text'], 0, 300) : null,
        // instalación si el código es una clase de sistema PIM (Anexo B) o si no nombra ningún activo; si no, equipo médico
        'clase' => str_ends_with((string) ($t['code']['coding'][0]['system'] ?? ''), '/pim-sistema') || ref_id($t['focus'] ?? null) === null ? 'instalacion' : clase_de_activo((string) ref_id($t['focus'])),
        'activo_id' => ref_id($t['focus'] ?? null), 'activo' => isset($t['focus']['display']) ? mb_substr((string) $t['focus']['display'], 0, 160) : null,
        'activo_codigo' => isset($t['focus']['identifier']['value']) ? mb_substr((string) $t['focus']['identifier']['value'], 0, 80) : null,   // 1.5.0: código vigente (Reference.identifier)
        'local_id' => ref_id($t['location'] ?? null), 'lugar' => isset($t['location']['display']) ? mb_substr((string) $t['location']['display'], 0, 160) : null,
        'org_id' => $org, 'creada' => $creada, 'inicio' => fecha_local($t['executionPeriod']['start'] ?? null), 'fin' => fecha_local($t['executionPeriod']['end'] ?? null),
        'actualizada' => fecha_local($t['lastModified'] ?? ($t['meta']['lastUpdated'] ?? null)) ?? $creada];
    $k = array_keys($d);
    q('INSERT INTO rep_orden (id, ' . implode(', ', $k) . ') VALUES (?' . str_repeat(',?', count($k)) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $k)),
        [(string) $t['id'], ...array_values($d)]);
}

/** Tipo de tarea = clase de sistema PIM de la orden (Task.code del sistema pim-sistema); null si no tiene. */
function tarea_de(array $t): ?string {
    foreach ((array) ($t['code']['coding'] ?? []) as $c) if (str_ends_with((string) ($c['system'] ?? ''), '/pim-sistema') && preg_match('/^[A-Z0-9-]{2,20}$/', (string) ($c['code'] ?? ''))) return (string) $c['code'];
    return null;
}
/** Una sola vez (1.4.0): completa el tipo de tarea de las órdenes que ya estaban en la copia, leyendo solo id y código de cada orden. */
function completar_tareas(int $max = 200000): int {
    if (!empty(estado('reportes_tarea')['ok'])) return 0;
    $n = 0;
    foreach (pim_lista('Task?_count=200&_elements=id,code', [], $max) as $t)
        if (($ta = tarea_de($t)) !== null) $n += q('UPDATE rep_orden SET tarea = ? WHERE id = ? AND (tarea IS NULL OR tarea <> ?)', [$ta, (string) ($t['id'] ?? ''), $ta])->rowCount();
    estado('reportes_tarea', ['ok' => date('Y-m-d H:i:s'), 'completadas' => $n]);
    return $n;
}
/** Pone al día una orden con su historial completo. Devuelve si cambió algo. */
function sincronizar(string $id): bool {
    if (!preg_match('/^[0-9A-Za-z\-\.]{1,64}$/', $id)) return false;
    $r = pim('GET', "Task/$id/_history?_count=500");
    if ($r['http'] !== 200) return false;
    $vers = [];
    foreach ((array) ($r['cuerpo']['entry'] ?? []) as $e) if (($e['resource']['resourceType'] ?? '') === 'Task') $vers[(int) ($e['resource']['meta']['versionId'] ?? 0)] = $e['resource'];
    if (!$vers) return false;
    ksort($vers);
    $antes = null; $cambio = false;
    foreach ($vers as $v => $t) {
        $est = (string) ($t['businessStatus']['coding'][0]['code'] ?? $t['status'] ?? '');
        if ($est !== $antes) {
            $cambio = q('INSERT IGNORE INTO rep_movimiento (orden_id, version, ts, de, a) VALUES (?,?,?,?,?)',
                [$id, $v, fecha_local($t['lastModified'] ?? ($t['meta']['lastUpdated'] ?? null)) ?? date('Y-m-d H:i:s'), $antes, mb_substr($est, 0, 20)])->rowCount() > 0 || $cambio;
            $antes = $est;
        }
    }
    $ult = end($vers);
    $ya = (int) (valor('SELECT version FROM rep_orden WHERE id = ?', [$id]) ?? -1);
    if ($ya !== (int) ($ult['meta']['versionId'] ?? 0)) { guardar_orden($ult); $cambio = true; }
    // sin período de ejecución informado, los tiempos salen de los movimientos
    q("UPDATE rep_orden o SET inicio = COALESCE(inicio, (SELECT MIN(ts) FROM rep_movimiento WHERE orden_id = o.id AND a = 'en-ejecucion')),
        fin = COALESCE(fin, (SELECT MAX(ts) FROM rep_movimiento WHERE orden_id = o.id AND a = 'finalizada')) WHERE o.id = ?", [$id]);
    return $cambio;
}

/** Una sola vez (1.5.0): completa el código del activo de las órdenes que ya estaban en la copia, con la lista de activos. */
function completar_codigos(): int {
    if (!empty(estado('reportes_codigos')['ok'])) return 0;
    $n = 0;
    if ((int) valor('SELECT COUNT(*) FROM rep_orden WHERE activo_id IS NOT NULL AND activo_codigo IS NULL') > 0)
        foreach (pim_lista('Device?_count=500&_elements=id,identifier,deviceName', [], 50000) as $d) {
            $cod = ''; foreach ((array) ($d['identifier'] ?? []) as $i) if (str_ends_with((string) ($i['system'] ?? ''), '/sid/activo')) $cod = (string) ($i['value'] ?? '');
            if ($cod !== '') $n += q('UPDATE rep_orden SET activo_codigo = ? WHERE activo_id = ? AND (activo_codigo IS NULL OR activo_codigo <> ?)', [mb_substr($cod, 0, 80), (string) $d['id'], $cod])->rowCount();
        }
    estado('reportes_codigos', ['ok' => date('Y-m-d H:i:s'), 'completadas' => $n]);
    return $n;
}
function reconstruir(int $max = 20000): array {
    $n = 0; $act = 0; $tareas = completar_tareas(); completar_codigos();
    $tengo = [];
    foreach (filas('SELECT id, version FROM rep_orden') as $x) $tengo[$x['id']] = (int) $x['version'];
    foreach (pim_lista('Task?_count=200&_elements=id,meta', [], $max) as $t) {
        $n++; $id = (string) ($t['id'] ?? '');
        if ($id === '' || ($tengo[$id] ?? -1) === (int) ($t['meta']['versionId'] ?? 0)) continue;
        if (sincronizar($id)) $act++;
    }
    estado('reportes', ['ultima' => date('Y-m-d H:i:s'), 'revisadas' => $n, 'actualizadas' => $act]);
    return ['revisadas' => $n, 'actualizadas' => $act, 'tareas_completadas' => $tareas];
}

// ------------------------------------------------------------------------------------------------ consultas
/** A qué organización está limitado el usuario (el Ejecutor, a la suya). null = ve todas. */
function alcance(?array $u): ?string {
    if (($u['rol'] ?? '') !== 'EJ') return null;
    $cod = trim((string) ($u['organizacion'] ?? ''));
    if ($cod === '') throw new Fallo(403, 'Tu usuario no tiene una empresa o área asignada');
    return (string) (valor('SELECT id FROM rep_org WHERE codigo = ?', [$cod]) ?? 'ninguna');   // todavía sin órdenes: no ve nada (nunca las «sin empresa»)
}
function periodo(array $g): array {
    $ok = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
    $hasta = $ok($g['hasta'] ?? null) ? $g['hasta'] : date('Y-m-d');
    $desde = $ok($g['desde'] ?? null) ? $g['desde'] : date('Y-m-d', strtotime($hasta . ' -29 days'));
    if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
    if ((strtotime($hasta) - strtotime($desde)) / 86400 > 1830) throw new Fallo(422, 'El período no puede superar los cinco años');
    return [$desde, $hasta];
}
const SEL = "o.*, g.nombre org, g.codigo org_codigo, c.estrellas, c.nota calif_nota, c.usuario calif_por, c.ts calif_ts";
const DESDE = ' FROM rep_orden o LEFT JOIN rep_org g ON g.id = o.org_id LEFT JOIN rep_calificacion c ON c.orden_id = o.id';

function vista_orden(array $o): array {
    $h = fn(?string $a, ?string $b) => $a && $b ? round((strtotime($b) - strtotime($a)) / 3600, 2) : null;
    return ['id' => $o['id'], 'numero' => $o['numero'], 'estado' => $o['estado'], 'estado_t' => ESTADOS[$o['estado']] ?? $o['estado'], 'prioridad' => $o['prioridad'], 'prioridad_t' => PRIORIDADES[$o['prioridad']] ?? ($o['prioridad'] ?? ''),
        'tipo' => $o['tipo_mant'], 'tipo_t' => TIPOS[$o['tipo_mant']] ?? ($o['tipo_mant'] ?? ''), 'clase' => clase($o['tipo_mant']), 'titulo' => $o['titulo'], 'detalle' => $o['detalle'], 'motivo' => $o['motivo'],
        'activo' => $o['activo'], 'activo_codigo' => $o['activo_codigo'] ?? null, 'activo_id' => $o['activo_id'], 'clase' => $o['clase'] ?? null, 'lugar' => $o['lugar'], 'org_id' => $o['org_id'], 'org' => $o['org'], 'creada' => $o['creada'], 'inicio' => $o['inicio'], 'fin' => $o['fin'], 'actualizada' => $o['actualizada'],
        'horas_respuesta' => $h($o['creada'], $o['inicio']), 'horas_resolucion' => $h($o['creada'], $o['fin']),
        'estrellas' => $o['estrellas'] !== null ? (int) $o['estrellas'] : null, 'calif_nota' => $o['calif_nota'], 'calif_por' => $o['calif_por'], 'calif_ts' => $o['calif_ts']];
}

/** El código a la vista de una orden (Ola 1): número corto, código del servicio y tercer tramo (clase de sistema PIM en instalaciones o código del equipo).
 *  Devuelve el número y el resto si el texto tiene esa forma: «368», «368-APA», «368-APA-HEJa-GAS-001-TAN-1» o el número PIM «4V3-000368»; si no, null. */
function codigo_orden(string $q): ?array {
    $q = trim($q); if ($q === '' || mb_strlen($q) > 120) return null;
    if (preg_match('/^(\d{1,9})(?:-(.+))?$/u', $q, $m)) return ['numero' => (int) $m[1], 'resto' => $m[2] ?? ''];
    if (preg_match('/^([A-Z0-9]{2,8})-0*(\d{1,9})$/i', $q, $m)) return ['numero' => (int) $m[2], 'resto' => '', 'prefijo' => strtoupper($m[1])];
    return null;
}
/** Expresión para el número PIM (NODO-000368): por el número corto, o exacta si vino el número PIM entero. */
function regexp_codigo(array $c): string { return (isset($c['prefijo']) ? '^' . preg_quote($c['prefijo']) : '') . '-0*' . $c['numero'] . '$'; }
/** Busca órdenes por su código a la vista o su número, en toda la copia (todas las fechas), respetando el alcance del usuario. */
function buscar_codigo(string $q, ?array $u): array {
    $c = codigo_orden($q) ?? throw new Fallo(422, 'Escribí el código de la orden (por ejemplo 368 o 368-APA-HEJa-GAS-001-TAN-1) o su número PIM');
    $w = ['o.numero REGEXP ?']; $a = [regexp_codigo($c)];
    if (($al = alcance($u)) !== null) { $w[] = 'o.org_id = ?'; $a[] = $al; }
    return ['numero' => $c['numero'], 'resto' => $c['resto'], 'ordenes' => array_map('Modulos\Reportes\vista_orden',
        filas('SELECT ' . SEL . DESDE . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY o.creada DESC LIMIT 20', $a))];
}
/** Auditoría: órdenes del período según la fecha elegida, con filtros; el resumen se calcula sobre todo lo filtrado. */
function auditoria(array $g, ?array $u, array $cfg): array {
    [$desde, $hasta] = periodo($g);
    $campo = ['creada' => 'o.creada', 'finalizada' => 'o.fin', 'movida' => 'o.actualizada'][$g['fecha'] ?? 'creada'] ?? 'o.creada';
    $w = ["$campo >= ?", "$campo <= ?"]; $a = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];
    // Ola P (P4): buscar por el código a la vista (número-servicio-tercer tramo, p. ej. 368-APA-HEJa-GAS-001-TAN-1) o por el número solo: va a esa orden, sin importar el período
    // 1.5.0: un código de activo (ACT-014, TAN-2) tiene la misma forma que un número corto: se busca por los dos
    $cod = codigo_orden((string) ($g['q'] ?? '')); if ($cod) { $w = []; $a = []; $w[] = '(o.numero REGEXP ? OR o.activo_codigo = ?)'; $a[] = regexp_codigo($cod); $a[] = trim((string) $g['q']); unset($g['q']); }
    if (($al = alcance($u)) !== null) { $w[] = 'o.org_id = ?'; $a[] = $al; }
    elseif (($g['org'] ?? '') !== '') { if ($g['org'] === '-') $w[] = 'o.org_id IS NULL'; else { $w[] = 'o.org_id = ?'; $a[] = (string) $g['org']; } }
    if (($g['prioridad'] ?? '') !== '') { $w[] = 'o.prioridad = ?'; $a[] = (string) $g['prioridad']; }
    if (($g['tipo'] ?? '') !== '') { $w[] = 'o.tipo_mant = ?'; $a[] = (string) $g['tipo']; }
    if (in_array($g['clase'] ?? '', ['equipo', 'instalacion'], true)) { $w[] = 'o.clase = ?'; $a[] = (string) $g['clase']; }
    if (($g['lugar'] ?? '') !== '') { $w[] = 'o.lugar = ?'; $a[] = mb_substr((string) $g['lugar'], 0, 200); }   // el servicio o lugar exacto
    if (($t = trim((string) ($g['q'] ?? ''))) !== '') {
        $l = '%' . addcslashes(mb_substr($t, 0, 60), '%_\\') . '%';
        $w[] = '(o.numero LIKE ? OR o.titulo LIKE ? OR o.detalle LIKE ? OR o.activo LIKE ? OR o.activo_codigo LIKE ? OR o.lugar LIKE ?)'; array_push($a, $l, $l, $l, $l, $l, $l);
    }
    $base = DESDE . ' WHERE ' . implode(' AND ', $w);
    // el resumen no depende del filtro de estado: así los indicadores sirven para cambiarlo
    $res = ['total' => 0, 'abiertas' => 0, 'en_espera' => 0, 'terminadas' => 0, 'sin_conformar' => 0, 'rechazadas' => 0, 'urgentes_abiertas' => 0, 'sin_calificar' => 0, 'por_estado' => []];
    foreach (filas("SELECT o.estado, COUNT(*) n, SUM(o.prioridad IN ('stat','asap','urgent')) urg, SUM(c.estrellas IS NULL) sinc$base GROUP BY o.estado", $a) as $x) {
        $n = (int) $x['n']; $res['total'] += $n; $res['por_estado'][$x['estado']] = $n;
        if (in_array($x['estado'], ABIERTAS, true)) { $res['abiertas'] += $n; $res['urgentes_abiertas'] += (int) $x['urg']; }
        if ($x['estado'] === 'en-espera') $res['en_espera'] += $n;
        if (in_array($x['estado'], TERMINADAS, true)) { $res['terminadas'] += $n; $res['sin_calificar'] += (int) $x['sinc']; }
        if ($x['estado'] === 'finalizada') $res['sin_conformar'] += $n;
        if (in_array($x['estado'], CERRADAS_SIN_HACER, true)) $res['rechazadas'] += $n;
    }
    $m = fila("SELECT AVG(TIMESTAMPDIFF(MINUTE, o.creada, o.fin)) res, AVG(TIMESTAMPDIFF(MINUTE, o.creada, o.inicio)) resp, AVG(c.estrellas) est, COUNT(c.estrellas) nest$base AND o.fin IS NOT NULL", $a) ?? [];
    $res['horas_resolucion'] = isset($m['res']) ? round((float) $m['res'] / 60, 1) : null;
    $res['horas_respuesta'] = isset($m['resp']) ? round((float) $m['resp'] / 60, 1) : null;
    $res['estrellas'] = isset($m['est']) ? round((float) $m['est'], 2) : null; $res['calificadas'] = (int) ($m['nest'] ?? 0);

    $grupo = ['abiertas' => ABIERTAS, 'terminadas' => TERMINADAS, 'rechazadas' => CERRADAS_SIN_HACER][$g['grupo'] ?? ''] ?? null;
    $w2 = ''; $a2 = $a;
    if ($grupo) { $w2 .= ' AND o.estado IN (' . implode(',', array_fill(0, count($grupo), '?')) . ')'; $a2 = [...$a2, ...$grupo]; }
    if (($g['estado'] ?? '') !== '') { $w2 .= ' AND o.estado = ?'; $a2[] = (string) $g['estado']; }
    if (($g['estados'] ?? '') !== '') {   // varios estados a la vez (un panel puede agruparlos a su modo)
        $es = array_values(array_intersect(array_map('trim', explode(',', (string) $g['estados'])), array_keys(ESTADOS)));
        if ($es) { $w2 .= ' AND o.estado IN (' . implode(',', array_fill(0, count($es), '?')) . ')'; $a2 = [...$a2, ...$es]; } else $w2 .= ' AND 1 = 0'; }
    if (($g['grupo'] ?? '') === 'urgentes') { $w2 .= " AND o.prioridad IN ('stat','asap','urgent') AND o.estado IN (" . implode(',', array_fill(0, count(ABIERTAS), '?')) . ')'; $a2 = [...$a2, ...ABIERTAS]; }
    if (($g['grupo'] ?? '') === 'sin-calificar') { $w2 .= ' AND c.estrellas IS NULL AND o.estado IN (' . implode(',', array_fill(0, count(TERMINADAS), '?')) . ')'; $a2 = [...$a2, ...TERMINADAS]; }
    $max = max(100, min(20000, (int) ($cfg['max_filas'] ?? 3000)));
    // por páginas (limite + desde_fila) para paneles que cargan de a poco; sin «limite» se comporta como siempre
    $lim = isset($g['limite']) ? max(1, min(500, (int) $g['limite'])) : $max; $off = isset($g['limite']) ? max(0, (int) ($g['desde_fila'] ?? 0)) : 0;
    $total = (int) valor("SELECT COUNT(*)$base$w2", $a2);
    $r = ['desde' => $desde, 'hasta' => $hasta, 'resumen' => $res, 'total' => $total,
        'ordenes' => array_map('Modulos\Reportes\vista_orden', filas('SELECT ' . SEL . "$base$w2 ORDER BY $campo DESC, o.id DESC LIMIT $lim OFFSET $off", $a2))];
    if (!empty($g['con_lugares'])) $r['lugares'] = array_column(filas("SELECT o.lugar, COUNT(*) n$base AND o.lugar IS NOT NULL AND o.lugar <> '' GROUP BY o.lugar ORDER BY o.lugar LIMIT 500", $a), 'n', 'lugar');
    return $r;
}

function orden(string $id, ?array $u): array {
    $o = fila('SELECT ' . SEL . DESDE . ' WHERE o.id = ?', [$id]) ?? throw new Fallo(404, 'No existe esa orden en los reportes');
    if (($al = alcance($u)) !== null && $o['org_id'] !== $al) throw new Fallo(404, 'No existe esa orden en los reportes');
    return vista_orden($o) + ['movimientos' => array_map(fn($m) => ['ts' => $m['ts'], 'de' => $m['de'], 'a' => $m['a'], 'de_t' => $m['de'] ? (ESTADOS[$m['de']] ?? $m['de']) : '', 'a_t' => ESTADOS[$m['a']] ?? $m['a']],
        filas('SELECT ts, de, a FROM rep_movimiento WHERE orden_id = ? ORDER BY version', [$id]))];
}

function calificar(string $id, array $c, array $u): array {
    $o = fila('SELECT estado, numero FROM rep_orden WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe esa orden en los reportes');
    if (!empty($c['borrar'])) { q('DELETE FROM rep_calificacion WHERE orden_id = ?', [$id]); asentar('reporte.calificacion.borrada', [], (string) ($o['numero'] ?? $id)); return orden($id, $u); }
    $e = (int) ($c['estrellas'] ?? 0);
    if ($e < 1 || $e > 5) throw new Fallo(422, 'La calificación va de 1 a 5 estrellas');
    if (!in_array($o['estado'], TERMINADAS, true)) throw new Fallo(409, 'Solo se califican las órdenes finalizadas o conformadas');
    $nota = mb_substr(trim((string) ($c['nota'] ?? '')), 0, 300) ?: null;
    q('REPLACE INTO rep_calificacion (orden_id, estrellas, nota, usuario, ts) VALUES (?,?,?,?,NOW())', [$id, $e, $nota, (string) ($u['usuario'] ?? '?')]);
    asentar('reporte.calificacion', ['estrellas' => $e, 'nota' => $nota], (string) ($o['numero'] ?? $id));
    return orden($id, $u);
}

function organizaciones(?array $u): array {
    $al = alcance($u);
    return filas('SELECT g.id, g.nombre, g.codigo, COUNT(o.id) ordenes FROM rep_org g JOIN rep_orden o ON o.org_id = g.id' . ($al !== null ? ' WHERE g.id = ?' : '') . ' GROUP BY g.id, g.nombre, g.codigo ORDER BY g.nombre', $al !== null ? [$al] : []);
}

/** Reporte de la empresa: qué recibió, qué terminó y qué tiene pendiente en el período, y los movimientos día por día. */
function empresa(array $g, ?array $u): array {
    [$desde, $hasta] = periodo($g);
    $al = alcance($u);
    $org = $al ?? (string) ($g['org'] ?? '');
    if ($org === '') throw new Fallo(422, 'Elegí una empresa o área');
    $sin = $al === null && $org === '-';       // «sin empresa asignada»: solo quien ve todo
    $e = $sin ? null : fila('SELECT id, nombre, codigo FROM rep_org WHERE id = ?', [$org]);
    if (!$sin && !$e && $al === null) throw new Fallo(404, 'No hay órdenes de esa empresa o área');
    $wo = $sin ? 'o.org_id IS NULL' : 'o.org_id = ?'; $ao = $sin ? [] : [$org];
    $d0 = $desde . ' 00:00:00'; $d1 = $hasta . ' 23:59:59';
    $n = fn(string $sql, array $a = []) => (int) valor('SELECT COUNT(*) FROM rep_orden o WHERE ' . $wo . ' AND ' . $sql, [...$ao, ...$a]);
    $ab = "'" . implode("','", ABIERTAS) . "'"; $te = "'" . implode("','", TERMINADAS) . "'";
    $res = ['recibidas' => $n('o.creada BETWEEN ? AND ?', [$d0, $d1]), 'terminadas' => $n("o.fin BETWEEN ? AND ? AND o.estado IN ($te)", [$d0, $d1]),
        'pendientes' => $n("o.estado IN ($ab)"), 'en_espera' => $n("o.estado = 'en-espera'"), 'urgentes' => $n("o.estado IN ($ab) AND o.prioridad IN ('stat','asap','urgent')"),
        'pendientes_viejas' => $n("o.estado IN ($ab) AND o.creada < NOW() - INTERVAL 30 DAY")];
    $m = fila("SELECT AVG(TIMESTAMPDIFF(MINUTE, o.creada, o.fin)) res, AVG(TIMESTAMPDIFF(MINUTE, o.creada, o.inicio)) resp, AVG(c.estrellas) est, COUNT(c.estrellas) nest
        FROM rep_orden o LEFT JOIN rep_calificacion c ON c.orden_id = o.id WHERE $wo AND o.fin BETWEEN ? AND ? AND o.estado IN ($te)", [...$ao, $d0, $d1]) ?? [];
    $res['horas_resolucion'] = isset($m['res']) ? round((float) $m['res'] / 60, 1) : null; $res['horas_respuesta'] = isset($m['resp']) ? round((float) $m['resp'] / 60, 1) : null;
    $res['estrellas'] = isset($m['est']) ? round((float) $m['est'], 2) : null; $res['calificadas'] = (int) ($m['nest'] ?? 0);

    // preventivo contra correctivo, sobre lo recibido en el período
    $clases = array_fill_keys(array_keys(CLASES), ['recibidas' => 0, 'terminadas' => 0]);
    foreach (filas("SELECT o.tipo_mant, COUNT(*) n, SUM(o.estado IN ($te)) t FROM rep_orden o WHERE $wo AND o.creada BETWEEN ? AND ? GROUP BY o.tipo_mant", [...$ao, $d0, $d1]) as $x) {
        $c = clase($x['tipo_mant']); $clases[$c]['recibidas'] += (int) $x['n']; $clases[$c]['terminadas'] += (int) $x['t'];
    }
    $pend = [];
    foreach (filas("SELECT o.estado, COUNT(*) n FROM rep_orden o WHERE $wo AND o.estado IN ($ab) GROUP BY o.estado", $ao) as $x) $pend[] = ['estado' => $x['estado'], 'estado_t' => ESTADOS[$x['estado']] ?? $x['estado'], 'n' => (int) $x['n']];

    // movimientos del período: cada cambio de estado, con los datos de su orden (sirve para la tabla diaria y para el CSV completo)
    $movs = filas("SELECT m.ts, m.de, m.a, o.id, o.numero, o.titulo, o.detalle, o.activo, o.lugar, o.prioridad, o.tipo_mant, o.creada, o.estado
        FROM rep_movimiento m JOIN rep_orden o ON o.id = m.orden_id WHERE $wo AND m.ts BETWEEN ? AND ? ORDER BY m.ts DESC, m.id DESC LIMIT 20000", [...$ao, $d0, $d1]);
    $dias = [];
    foreach ($movs as &$x) {
        $dia = substr($x['ts'], 0, 10);
        $dias[$dia] ??= ['dia' => $dia, 'ingresaron' => 0, 'iniciaron' => 0, 'finalizaron' => 0, 'en_espera' => 0, 'otros' => 0, 'total' => 0];
        $k = $x['de'] === null ? 'ingresaron' : (['en-ejecucion' => 'iniciaron', 'finalizada' => 'finalizaron', 'en-espera' => 'en_espera'][$x['a']] ?? 'otros');
        $dias[$dia][$k]++; $dias[$dia]['total']++;
        $x['movimiento'] = $x['de'] === null ? 'Ingresó' : (['en-ejecucion' => 'Inició', 'finalizada' => 'Finalizó', 'en-espera' => 'Pasó a espera'][$x['a']] ?? ('Pasó a ' . mb_strtolower(ESTADOS[$x['a']] ?? $x['a'])));
        $x['clave'] = $k; $x['estado_t'] = ESTADOS[$x['estado']] ?? $x['estado']; $x['prioridad_t'] = PRIORIDADES[$x['prioridad']] ?? ($x['prioridad'] ?? ''); $x['tipo_t'] = TIPOS[$x['tipo_mant']] ?? ($x['tipo_mant'] ?? '');
        unset($x['de'], $x['tipo_mant']);
    }
    unset($x);
    krsort($dias);
    return ['desde' => $desde, 'hasta' => $hasta, 'empresa' => $e ?? ($sin ? ['id' => '-', 'nombre' => 'Sin empresa asignada', 'codigo' => null] : ['id' => $org, 'nombre' => (string) ($u['organizacion'] ?? ''), 'codigo' => $u['organizacion'] ?? null]), 'resumen' => $res,
        'clases' => array_map(fn($k, $v) => ['clase' => $k, 'nombre' => CLASES[$k]] + $v, array_keys($clases), $clases), 'pendientes' => $pend, 'dias' => array_values($dias), 'movimientos' => $movs,
        'insumos' => ranking_insumos($wo, $ao, $desde, $hasta)];
}

function situacion(): array {
    return ['ordenes' => (int) valor('SELECT COUNT(*) FROM rep_orden'), 'movimientos' => (int) valor('SELECT COUNT(*) FROM rep_movimiento'),
        'sin_empresa' => (int) valor('SELECT COUNT(*) FROM rep_orden WHERE org_id IS NULL'), 'ultima' => estado('reportes') ?: null,
        'estados' => ESTADOS, 'prioridades' => PRIORIDADES, 'tipos' => TIPOS];
}

// ---------------------------------------------------------------- insumos de pañol por orden (copia propia, desde el evento del stock)
function anotar_insumos(array $d): void {
    $orden = trim((string) ($d['orden'] ?? '')); $clave = trim((string) ($d['clave'] ?? ''));
    if (($d['tipo'] ?? '') !== 'egreso' || $orden === '' || $clave === '') return;
    $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['fecha'] ?? '')) ? (string) $d['fecha'] : date('Y-m-d');
    foreach (array_values((array) ($d['renglones'] ?? [])) as $i => $r) {
        if (!isset($r['codigo'], $r['cantidad'])) continue;
        q('INSERT INTO rep_insumo (clave, renglon, orden_id, fecha, codigo, descripcion, unidad, cantidad, precio, observacion) VALUES (?,?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE cantidad = VALUES(cantidad), precio = VALUES(precio)',
          [mb_substr($clave, 0, 20), $i + 1, mb_substr($orden, 0, 64), $f, mb_substr((string) $r['codigo'], 0, 40), isset($r['descripcion']) ? mb_substr((string) $r['descripcion'], 0, 200) : null,
           isset($r['unidad']) ? mb_substr((string) $r['unidad'], 0, 20) : null, (float) $r['cantidad'], isset($r['precio']) && is_numeric($r['precio']) ? (float) $r['precio'] : null,
           isset($d['observacion']) && $d['observacion'] !== null ? mb_substr((string) $d['observacion'], 0, 400) : null]);
    }
}
/** Nota de pañol de varias órdenes: lo entregado a cada una (el Ejecutor, solo las de su empresa). */
function insumos_de(array $ids, ?array $u): array {
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn($x) => (bool) preg_match('/^[0-9A-Za-z._-]{1,64}$/', $x)))); if (!$ids) return [];
    if (count($ids) > 200) throw new Fallo(422, 'Hasta 200 órdenes por consulta');
    $w = 'i.orden_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $a = $ids;
    if (($al = alcance($u)) !== null) { $w .= ' AND o.org_id = ?'; $a[] = $al; }
    $o = [];
    foreach (filas("SELECT i.* FROM rep_insumo i JOIN rep_orden o ON o.id = i.orden_id WHERE $w ORDER BY i.fecha, i.clave, i.renglon", $a) as $r)
        $o[$r['orden_id']][] = ['clave' => $r['clave'], 'fecha' => $r['fecha'], 'codigo' => $r['codigo'], 'descripcion' => $r['descripcion'], 'unidad' => $r['unidad'], 'cantidad' => (float) $r['cantidad'], 'observacion' => $r['observacion']];
    return $o;
}
/** Ranking de insumos entregados a las órdenes de una empresa en el período (para prever compras). */
function ranking_insumos(string $wo, array $ao, string $desde, string $hasta): array {
    return array_map(fn($r) => ['codigo' => $r['codigo'], 'descripcion' => $r['descripcion'], 'unidad' => $r['unidad'], 'cantidad' => (float) $r['cant'], 'ordenes' => (int) $r['ords'],
            'costo' => $r['costo'] !== null ? round((float) $r['costo'], 2) : null],
        filas("SELECT i.codigo, MAX(i.descripcion) descripcion, MAX(i.unidad) unidad, SUM(i.cantidad) cant, COUNT(DISTINCT i.orden_id) ords, SUM(i.cantidad * i.precio) costo
               FROM rep_insumo i JOIN rep_orden o ON o.id = i.orden_id WHERE $wo AND i.fecha BETWEEN ? AND ? GROUP BY i.codigo ORDER BY cant DESC, ords DESC LIMIT 100", [...$ao, $desde, $hasta]));
}

require_once __DIR__ . '/tendencias.php';   // 1.2: Tendencias y Pronóstico, Reporte General
require_once __DIR__ . '/docx.php';   // Ola P (P4): .docx nativo
