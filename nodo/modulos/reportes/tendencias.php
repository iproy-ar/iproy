<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Reportes 1.2.0 · «Tendencias y Pronóstico» y «Reporte General» (equivalen a rpt_tendencias.js y rpt_general.js del sistema anterior),
// sobre la copia de consulta (rep_orden, rep_insumo). El Ejecutor solo ve su empresa, como en la Auditoría.
declare(strict_types=1);

namespace Modulos\Reportes;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, valor};

/** Áreas críticas por defecto (genéricas); cada institución pone las suyas en Configuración del módulo («areas_criticas»). */
const AREAS_CRITICAS = ['Guardia', 'Quirófanos', 'Terapia intensiva', 'Esterilización', 'Hemoterapia', 'Diálisis'];

function media(array $y): float { return $y ? array_sum($y) / count($y) : 0.0; }
function percentil(array $v, float $p): ?float {
    if (!$v) return null; sort($v); $i = ($p / 100) * (count($v) - 1); $a = (int) floor($i); $b = (int) ceil($i);
    return round($v[$a] + ($v[$b] - $v[$a]) * ($i - $a), 2);
}
/**
 * Holt-Winters aditivo con tendencia amortiguada, con búsqueda de parámetros por error cuadrático (igual que el sistema anterior).
 * Estacional (m = 12) solo con 2·m + 2 períodos o más; si no, Holt amortiguado. Banda p10–p90 = ±1,2816·σ creciente.
 */
function holt_winters(array $y, int $m, int $h): ?array {
    $y = array_values(array_map('floatval', $y)); $n = count($y);
    if ($n < 3) return null;
    $est = $m > 1 && $n >= 2 * $m + 2;
    $A = [0.1, 0.2, 0.3, 0.5, 0.7, 0.9]; $B = [0.01, 0.05, 0.1, 0.2]; $G = $est ? [0.05, 0.1, 0.2, 0.35] : [0.0]; $P = [0.8, 0.9, 0.98];
    $ini = $est ? $m : 1; $mejor = null;
    foreach ($A as $al) foreach ($B as $be) foreach ($G as $ga) foreach ($P as $ph) {
        $s = [];
        if ($est) { $m1 = media(array_slice($y, 0, $m)); $m2 = media(array_slice($y, $m, $m)); $l = $m1; $b = ($m2 - $m1) / $m; for ($i = 0; $i < $m; $i++) $s[$i] = $y[$i] - $m1; }
        else { $l = $y[0]; $b = $y[1] - $y[0]; }
        $sse = 0.0; $cnt = 0;
        for ($t = $ini; $t < $n; $t++) {
            $st = $est ? $s[$t - $m] : 0.0; $e = $y[$t] - ($l + $ph * $b + $st);
            if ($t >= $ini + ($est ? 0 : 2)) { $sse += $e * $e; $cnt++; }
            $lN = $al * ($y[$t] - $st) + (1 - $al) * ($l + $ph * $b); $bN = $be * ($lN - $l) + (1 - $be) * $ph * $b;
            if ($est) $s[$t] = $ga * ($y[$t] - $lN) + (1 - $ga) * $st;
            $l = $lN; $b = $bN;
        }
        if ($cnt && (!$mejor || $sse < $mejor['sse'])) $mejor = compact('sse', 'cnt', 'al', 'be', 'ga', 'ph', 'l', 'b', 's');
    }
    if (!$mejor) return null;
    $sig = sqrt($mejor['sse'] / max(1, $mejor['cnt'] - 3));
    $o = ['p50' => [], 'p10' => [], 'p90' => [], 'modelo' => $est ? "Holt-Winters estacional (m=$m)" : 'Holt (tendencia amortiguada)',
        'parametros' => ['alfa' => $mejor['al'], 'beta' => $mejor['be'], 'gamma' => $mejor['ga'], 'fi' => $mejor['ph']], 'sigma' => round($sig, 3)];
    $damp = 0.0;
    for ($k = 1; $k <= $h; $k++) {
        $damp += $mejor['ph'] ** $k;
        $st = $est ? $mejor['s'][$n - $m + (($k - 1) % $m)] : 0.0;
        $v = max(0, $mejor['l'] + $damp * $mejor['b'] + $st);
        $sh = $sig * sqrt(1 + ($k - 1) * $mejor['al'] ** 2 * (1 + $mejor['be']) ** 2);
        $o['p50'][] = round($v, 2); $o['p10'][] = round(max(0, $v - 1.2816 * $sh), 2); $o['p90'][] = round($v + 1.2816 * $sh, 2);
    }
    return $o;
}
/** Calidad del modelo: se ajusta sin los últimos h y se compara (MAE, WAPE y cuántos reales cayeron en la banda). */
function backtest(array $y, int $m, int $h): ?array {
    if (count($y) < $h + 8) return null;
    $r = holt_winters(array_slice($y, 0, count($y) - $h), $m, $h); if (!$r) return null;
    $real = array_slice($y, -$h); $err = array_map(fn($v, $p) => abs($v - $p), $real, $r['p50']); $den = array_sum($real);
    $dentro = count(array_filter(array_keys($real), fn($i) => $real[$i] >= $r['p10'][$i] && $real[$i] <= $r['p90'][$i]));
    return ['mae' => round(media($err), 2), 'wape' => $den ? round(100 * array_sum($err) / $den, 1) : null, 'cobertura' => round($dentro / $h, 2)];
}

/** Filtros comunes: período, servicio (lugar), empresa y alcance del usuario. */
function filtro_ot(array $g, ?array $u): array {
    [$d, $h] = periodo($g);
    $w = []; $a = [];
    if (($al = alcance($u)) !== null) { $w[] = 'o.org_id = ?'; $a[] = $al; }
    elseif (($org = (string) ($g['org'] ?? '')) !== '') { if ($org === '-') $w[] = 'o.org_id IS NULL'; else { $w[] = 'o.org_id = ?'; $a[] = $org; } }
    if (($s = trim((string) ($g['servicio'] ?? ''))) !== '') { $w[] = 'o.lugar = ?'; $a[] = $s; }
    if (in_array($g['clase'] ?? '', ['equipo', 'instalacion'], true)) { $w[] = 'o.clase = ?'; $a[] = $g['clase']; }
    if (preg_match('/^[A-Z0-9-]{2,20}$/', (string) ($g['tarea'] ?? ''))) { $w[] = 'o.tarea = ?'; $a[] = $g['tarea']; }
    return [$d, $h, $w ? ' AND ' . implode(' AND ', $w) : '', $a];
}
function meses_de(string $d, string $h): array {
    $r = []; for ($x = new \DateTimeImmutable(substr($d, 0, 7) . '-01'), $f = substr($h, 0, 7), $i = 0; $x->format('Y-m') <= $f && $i < 120; $x = $x->modify('+1 month'), $i++) $r[] = $x->format('Y-m');
    return $r;
}
const RE_INSUMOS = '(insum|repuest|material|pañol|panol|compra|proveedor)';

/** Tendencias y Pronóstico: 7 indicadores y 5 vistas (panorama, empresas, series y pronóstico, preventivo, insumos). */
function tendencias_ot(array $g, ?array $u, array $cfg): array {
    [$d, $h, $W, $A] = filtro_ot($g, $u);
    $d0 = "$d 00:00:00"; $d1 = "$h 23:59:59";
    $ab = "'" . implode("','", ABIERTAS) . "'"; $te = "'" . implode("','", TERMINADAS) . "'";
    $ing = (int) valor("SELECT COUNT(*) FROM rep_orden o WHERE o.creada BETWEEN ? AND ?$W", [$d0, $d1, ...$A]);
    $res = array_map('floatval', array_column(filas("SELECT TIMESTAMPDIFF(MINUTE, o.creada, o.fin) / 1440 AS d FROM rep_orden o WHERE o.estado IN ($te) AND o.fin BETWEEN ? AND ? AND o.fin >= o.creada$W", [$d0, $d1, ...$A]), 'd'));
    $backlog = "o.creada <= ? AND (o.estado IN ($ab) OR (o.estado IN ($te) AND o.fin > ?))";   // abierta al cierre del día de corte (como abiertaAl del sistema anterior)
    $bl = (int) valor("SELECT COUNT(*) FROM rep_orden o WHERE $backlog$W", [$d1, $d1, ...$A]);
    $blIns = (int) valor("SELECT COUNT(*) FROM rep_orden o WHERE o.estado = 'en-espera' AND o.motivo REGEXP ? AND o.creada <= ?$W", [RE_INSUMOS, $d1, ...$A]);
    $cl = ['correctivo' => 0, 'preventivo' => 0, 'otros' => 0, 'sin-clasificar' => 0];
    foreach (filas("SELECT o.tipo_mant, COUNT(*) n FROM rep_orden o WHERE o.creada BETWEEN ? AND ?$W GROUP BY o.tipo_mant", [$d0, $d1, ...$A]) as $x) $cl[clase($x['tipo_mant'])] += (int) $x['n'];
    $clas = $cl['correctivo'] + $cl['preventivo'] + $cl['otros'];
    $den = (int) valor("SELECT COUNT(*) FROM rep_orden o WHERE o.estado = 'rechazada' AND o.creada BETWEEN ? AND ?$W", [$d0, $d1, ...$A]);
    $kpis = ['ingresadas' => $ing, 'cerradas' => count($res), 'backlog' => $bl, 'mediana_dias' => percentil($res, 50), 'p90_dias' => percentil($res, 90),
        'correctivo_pct' => $clas ? round($cl['correctivo'] / $clas * 100, 1) : null, 'denegadas' => $den, 'backlog_insumos' => $blIns];

    // series mensuales: ingresadas, cerradas, backlog a fin de mes, preventivo y correctivo
    $M = meses_de($d, $h); $ser = array_fill_keys($M, ['ingresadas' => 0, 'cerradas' => 0, 'preventivo' => 0, 'correctivo' => 0, 'prev_cumplido' => 0, 'backlog' => 0]);
    foreach (filas("SELECT DATE_FORMAT(o.creada, '%Y-%m') m, o.tipo_mant, COUNT(*) n, SUM(o.estado IN ($te)) t FROM rep_orden o WHERE o.creada BETWEEN ? AND ?$W GROUP BY m, o.tipo_mant", [$d0, $d1, ...$A]) as $x) {
        if (!isset($ser[$x['m']])) continue; $ser[$x['m']]['ingresadas'] += (int) $x['n']; $c = clase($x['tipo_mant']);
        if ($c === 'preventivo') { $ser[$x['m']]['preventivo'] += (int) $x['n']; $ser[$x['m']]['prev_cumplido'] += (int) $x['t']; }
        if ($c === 'correctivo') $ser[$x['m']]['correctivo'] += (int) $x['n'];
    }
    foreach (filas("SELECT DATE_FORMAT(o.fin, '%Y-%m') m, COUNT(*) n FROM rep_orden o WHERE o.estado IN ($te) AND o.fin BETWEEN ? AND ?$W GROUP BY m", [$d0, $d1, ...$A]) as $x) if (isset($ser[$x['m']])) $ser[$x['m']]['cerradas'] = (int) $x['n'];
    foreach ($M as $m) { $fin = (new \DateTimeImmutable("$m-01"))->modify('last day of this month')->format('Y-m-d') . ' 23:59:59'; if ($fin > $d1) $fin = $d1;
        $ser[$m]['backlog'] = (int) valor("SELECT COUNT(*) FROM rep_orden o WHERE $backlog$W", [$fin, $fin, ...$A]); }

    // pronóstico de las órdenes ingresadas, por mes (6 meses) o por semana (12 semanas), sin el período en curso si está incompleto
    $freq = ($g['freq'] ?? 'M') === 'W' ? 'W' : 'M';
    if ($freq === 'M') {
        $yy = array_column(array_values($ser), 'ingresadas'); $mm = $M;
        if ($mm && end($mm) === date('Y-m') && $h >= date('Y-m-d')) { array_pop($yy); array_pop($mm); }
        $hw = holt_winters($yy, 12, 6); $sig = [];
        if ($mm) { $x = new \DateTimeImmutable(end($mm) . '-01'); for ($i = 1; $i <= 6; $i++) $sig[] = $x->modify("+$i month")->format('Y-m'); }
    } else {
        [$mm, $yy] = semanas($d, $h, $W, $A);
        $hw = holt_winters($yy, 52, 12); $sig = [];
        if ($mm) { $x = new \DateTimeImmutable(end($mm)); for ($i = 1; $i <= 12; $i++) $sig[] = $x->modify('+' . (7 * $i) . ' days')->format('Y-m-d'); }
    }

    // empresas
    $emp = [];
    if (alcance($u) === null) {
        foreach (filas("SELECT o.org_id, g.nombre, COUNT(*) n, SUM(o.estado IN ($te)) t, SUM(o.estado IN ($ab)) p, SUM(o.tipo_mant LIKE 'correctivo%') c, SUM(o.tipo_mant IS NOT NULL AND o.tipo_mant <> '') k,
            AVG(CASE WHEN o.estado IN ($te) AND o.fin >= o.creada THEN TIMESTAMPDIFF(MINUTE, o.creada, o.fin) / 1440 END) dias, SUM(o.estado = 'rechazada') r
            FROM rep_orden o LEFT JOIN rep_org g ON g.id = o.org_id WHERE o.creada BETWEEN ? AND ?$W GROUP BY o.org_id, g.nombre ORDER BY n DESC LIMIT 40", [$d0, $d1, ...$A]) as $x)
            $emp[] = ['org' => $x['org_id'] ?? '-', 'nombre' => $x['nombre'] ?? 'Sin empresa asignada', 'ingresadas' => (int) $x['n'], 'terminadas' => (int) $x['t'], 'pendientes' => (int) $x['p'],
                'correctivo_pct' => (int) $x['k'] ? round($x['c'] / $x['k'] * 100, 1) : null, 'dias_promedio' => $x['dias'] !== null ? round((float) $x['dias'], 1) : null, 'denegadas' => (int) $x['r']];
    }
    // servicios con más pendientes; las áreas críticas se marcan
    $crit = (array) ($cfg['areas_criticas'] ?? AREAS_CRITICAS);
    $srv = array_map(fn($x) => ['servicio' => $x['lugar'] ?? '(sin servicio)', 'ingresadas' => (int) $x['n'], 'pendientes' => (int) $x['p'], 'critica' => in_array($x['lugar'], $crit, true)],
        filas("SELECT o.lugar, COUNT(*) n, SUM(o.estado IN ($ab)) p FROM rep_orden o WHERE o.creada BETWEEN ? AND ?$W GROUP BY o.lugar ORDER BY p DESC, n DESC LIMIT 25", [$d0, $d1, ...$A]));
    // insumos entregados a las órdenes del período, por mes y los más usados (copia rep_insumo)
    $ins = filas("SELECT DATE_FORMAT(i.fecha, '%Y-%m') m, ROUND(SUM(i.cantidad * COALESCE(i.precio, 0)), 2) costo, COUNT(DISTINCT i.orden_id) ords FROM rep_insumo i JOIN rep_orden o ON o.id = i.orden_id
        WHERE i.fecha BETWEEN ? AND ?$W GROUP BY m ORDER BY m", [$d, $h, ...$A]);
    $top = filas("SELECT i.codigo, MAX(i.descripcion) descripcion, MAX(i.unidad) unidad, SUM(i.cantidad) cantidad, ROUND(SUM(i.cantidad * COALESCE(i.precio, 0)), 2) costo FROM rep_insumo i JOIN rep_orden o ON o.id = i.orden_id
        WHERE i.fecha BETWEEN ? AND ?$W GROUP BY i.codigo ORDER BY costo DESC, cantidad DESC LIMIT 15", [$d, $h, ...$A]);
    return ['desde' => $d, 'hasta' => $h, 'kpis' => $kpis, 'clases' => $cl, 'meses' => $M, 'series' => array_values(array_map(fn($m, $v) => ['mes' => $m] + $v, $M, $ser)),
        'pronostico' => ['serie' => 'ingresadas', 'freq' => $freq, 'meses_base' => $mm, 'base' => $yy, 'meses' => $sig, 'modelo' => $hw, 'control' => backtest($yy, $freq === 'M' ? 12 : 52, $freq === 'M' ? 3 : 8),
            'timesfm' => ($cl = clave_serie($g, $u)) ? timesfm_de($cl, $sig, $mm ? end($mm) : null, $freq) : null, 'serie_clave' => $cl],
        'empresas' => $emp, 'servicios' => $srv, 'areas_criticas' => $crit, 'insumos_mes' => $ins, 'insumos_top' => $top,
        'contrato' => $cfg['fecha_contrato'] ?? null, 'traspaso' => alcance($u) === null ? traspaso($g, $cfg) : null];
}
/** Serie de TimesFM que corresponde a los filtros: total, un servicio o una empresa (no combinados; con clase de activo, ninguna). */
function clave_serie(array $g, ?array $u): ?string {
    $s = trim((string) ($g['servicio'] ?? '')); $o = trim((string) ($g['org'] ?? '')); $t = preg_match('/^[A-Z0-9-]{2,20}$/', (string) ($g['tarea'] ?? '')) ? $g['tarea'] : '';
    if (!empty($g['clase']) || alcance($u) !== null || ($o !== '' && ($s !== '' || $t !== '')) || $o === '-') return null;
    if ($o !== '') return "org:$o";
    return $s !== '' ? ($t !== '' ? "srv:$s|tt:$t" : "srv:$s") : ($t !== '' ? "tt:$t" : 'total');
}
/** Semanas (lunes) del período con las órdenes ingresadas, sin la semana en curso si está incompleta. */
function semanas(string $d, string $h, string $W, array $A): array {
    $c = [];
    foreach (filas("SELECT DATE_SUB(DATE(o.creada), INTERVAL WEEKDAY(o.creada) DAY) s, COUNT(*) n FROM rep_orden o WHERE o.creada BETWEEN ? AND ?$W GROUP BY s", ["$d 00:00:00", "$h 23:59:59", ...$A]) as $x) $c[$x['s']] = (int) $x['n'];
    $ini = new \DateTimeImmutable($d); if ((int) $ini->format('N') > 1) $ini = $ini->modify('next monday');   // primera semana incompleta: tampoco se usa
    $fin = new \DateTimeImmutable($h); $lunesFin = $fin->modify('-' . ((int) $fin->format('N') - 1) . ' days');
    if ((int) $fin->format('N') < 7 || $h >= date('Y-m-d')) $lunesFin = $lunesFin->modify('-7 days');   // semana incompleta: no se usa como base
    $S = []; $Y = [];
    for ($x = $ini, $i = 0; $x <= $lunesFin && $i < 520; $x = $x->modify('+7 days'), $i++) { $k = $x->format('Y-m-d'); $S[] = $k; $Y[] = $c[$k] ?? 0; }
    return [$S, $Y];
}
/** Nombres de las clases de sistema PIM (CodeSystem pim-sistema del Servidor PIM). */
function nombres_tarea(): array {
    static $n = null; if ($n !== null) return $n;
    $n = []; try { foreach ((array) (\Nucleo\pim('GET', 'CodeSystem/pim-sistema')['cuerpo']['concept'] ?? []) as $c) $n[(string) $c['code']] = (string) ($c['display'] ?? $c['code']); } catch (\Throwable) { }
    return $n;
}
/** Tipos de tarea presentes en las órdenes, con su nombre PIM y cantidad. */
function tareas_ot(?array $u): array {
    $W = ''; $A = []; if (($al = alcance($u)) !== null) { $W = ' AND org_id = ?'; $A[] = $al; }
    $N = nombres_tarea();
    return array_map(fn($x) => ['codigo' => $x['tarea'], 'nombre' => $N[$x['tarea']] ?? $x['tarea'], 'ordenes' => (int) $x['n']],
        filas("SELECT tarea, COUNT(*) n FROM rep_orden WHERE tarea IS NOT NULL$W GROUP BY tarea ORDER BY n DESC", $A));
}

/** Reporte General: indicadores, distribuciones, actividad diaria y semanal, MTTR por servicio y costo de insumos. */
function general(array $g, ?array $u): array {
    [$d, $h, $W, $A] = filtro_ot($g, $u);
    $d0 = "$d 00:00:00"; $d1 = "$h 23:59:59"; $P = [$d0, $d1, ...$A];
    $ab = "'" . implode("','", ABIERTAS) . "'"; $te = "'" . implode("','", TERMINADAS) . "'";
    $k = fila("SELECT COUNT(*) total, SUM(o.estado IN ($ab)) abiertas, SUM(o.estado IN ($te)) terminadas, SUM(o.estado = 'en-espera') en_espera, SUM(o.estado = 'rechazada') rechazadas,
        AVG(CASE WHEN o.estado IN ($te) AND o.fin >= o.creada THEN TIMESTAMPDIFF(MINUTE, o.creada, o.fin) END) mttr FROM rep_orden o WHERE o.creada BETWEEN ? AND ?$W", $P);
    $kp = ['total' => (int) $k['total'], 'abiertas' => (int) $k['abiertas'], 'terminadas' => (int) $k['terminadas'], 'en_espera' => (int) $k['en_espera'], 'rechazadas' => (int) $k['rechazadas'],
        'mttr_horas' => $k['mttr'] !== null ? round($k['mttr'] / 60, 1) : null, 'tasa_finalizacion' => (int) $k['total'] ? round($k['terminadas'] / $k['total'] * 100, 1) : null];
    $estados = array_map(fn($x) => ['estado' => $x['estado'], 'texto' => ESTADOS[$x['estado']] ?? $x['estado'], 'n' => (int) $x['n']],
        filas("SELECT o.estado, COUNT(*) n FROM rep_orden o WHERE o.creada BETWEEN ? AND ?$W GROUP BY o.estado ORDER BY n DESC", $P));
    $tipos = array_map(fn($x) => ['tipo' => $x['tipo_mant'], 'texto' => TIPOS[$x['tipo_mant']] ?? ($x['tipo_mant'] ?: 'Sin tipo'), 'n' => (int) $x['n']],
        filas("SELECT o.tipo_mant, COUNT(*) n FROM rep_orden o WHERE o.creada BETWEEN ? AND ?$W GROUP BY o.tipo_mant ORDER BY n DESC", $P));
    $clases = filas("SELECT COALESCE(o.clase, 'instalacion') clase, COUNT(*) n FROM rep_orden o WHERE o.creada BETWEEN ? AND ?$W GROUP BY clase ORDER BY n DESC", $P);
    $srv = filas("SELECT COALESCE(o.lugar, '(sin servicio)') servicio, COUNT(*) n, ROUND(AVG(CASE WHEN o.estado IN ($te) AND o.fin >= o.creada THEN TIMESTAMPDIFF(MINUTE, o.creada, o.fin) END) / 60, 1) mttr_horas
        FROM rep_orden o WHERE o.creada BETWEEN ? AND ?$W GROUP BY servicio ORDER BY n DESC LIMIT 15", $P);
    $emp = filas("SELECT COALESCE(g.nombre, 'Sin empresa asignada') empresa, COUNT(*) n, SUM(o.estado IN ($te)) terminadas FROM rep_orden o LEFT JOIN rep_org g ON g.id = o.org_id
        WHERE o.creada BETWEEN ? AND ?$W GROUP BY empresa ORDER BY n DESC LIMIT 20", $P);
    // por día: los últimos 60 días del período
    $d60 = max($d, date('Y-m-d', strtotime("$h -59 days")));
    $dias = filas("SELECT DATE(o.creada) dia, COUNT(*) n FROM rep_orden o WHERE o.creada BETWEEN ? AND ?$W GROUP BY dia ORDER BY dia", ["$d60 00:00:00", $d1, ...$A]);
    $semanas = filas("SELECT YEARWEEK(o.creada, 3) sem, MIN(DATE(o.creada)) desde, COUNT(*) n, SUM(o.estado IN ($te)) t FROM rep_orden o WHERE o.creada BETWEEN ? AND ?$W GROUP BY sem ORDER BY sem", $P);
    foreach ($semanas as &$s) { $s['tasa'] = (int) $s['n'] ? round($s['t'] / $s['n'] * 100, 1) : null; unset($s['sem']); } unset($s);
    $meses = filas("SELECT DATE_FORMAT(o.creada, '%Y-%m') mes, COUNT(*) n, SUM(o.estado IN ($te)) terminadas FROM rep_orden o WHERE o.creada BETWEEN ? AND ?$W GROUP BY mes ORDER BY mes", $P);
    $ac = 0; foreach ($meses as &$m) { $ac += (int) $m['n']; $m['acumuladas'] = $ac; } unset($m);
    // insumos: la categoría sale del código {n}-CAT-SUB
    $Pi = [$d, $h, ...$A];
    $ot = filas("SELECT i.orden_id, MAX(o.numero) numero, ROUND(SUM(i.cantidad * COALESCE(i.precio, 0)), 2) costo FROM rep_insumo i JOIN rep_orden o ON o.id = i.orden_id WHERE i.fecha BETWEEN ? AND ?$W GROUP BY i.orden_id ORDER BY costo DESC LIMIT 10", $Pi);
    $cat = filas("SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(i.codigo, '-', 2), '-', -1) categoria, ROUND(SUM(i.cantidad * COALESCE(i.precio, 0)), 2) costo FROM rep_insumo i JOIN rep_orden o ON o.id = i.orden_id WHERE i.fecha BETWEEN ? AND ?$W GROUP BY categoria ORDER BY costo DESC", $Pi);
    $evo = filas("SELECT DATE_FORMAT(i.fecha, '%Y-%m') mes, ROUND(SUM(i.cantidad * COALESCE(i.precio, 0)), 2) costo FROM rep_insumo i JOIN rep_orden o ON o.id = i.orden_id WHERE i.fecha BETWEEN ? AND ?$W GROUP BY mes ORDER BY mes", $Pi);
    return ['desde' => $d, 'hasta' => $h, 'kpis' => $kp, 'estados' => $estados, 'tipos' => $tipos, 'clases' => $clases, 'servicios' => $srv, 'empresas' => $emp, 'dias' => $dias,
        'semanas' => $semanas, 'meses' => $meses, 'insumos' => ['por_ot' => $ot, 'por_categoria' => $cat, 'por_mes' => $evo]];
}
/** Servicios (lugares) que aparecen en las órdenes, para el filtro. */
function servicios_ot(?array $u): array {
    $w = ''; $a = []; if (($al = alcance($u)) !== null) { $w = ' AND org_id = ?'; $a[] = $al; }
    return array_column(filas("SELECT lugar FROM rep_orden WHERE lugar IS NOT NULL$w GROUP BY lugar ORDER BY lugar", $a), 'lugar');
}

// ------------------------------------------------------------------ traspaso de contrato (como «Comparar empresas › Traspaso» del sistema anterior)

/** Id de empresa a partir de su id o de su código (la configuración guarda el código, que no cambia entre nodos). */
function org_id(?string $v): ?string {
    $v = trim((string) $v); if ($v === '') return null;
    return (string) (valor('SELECT id FROM rep_org WHERE id = ? OR codigo = ? LIMIT 1', [$v, $v]) ?? '') ?: null;
}
/**
 * «Heredadas» = creadas antes del corte y abiertas ese día; «propias» = creadas desde el corte y asignadas a la entrante.
 * Las heredadas tienen tiempos largos por definición (se miden desde su alta original): por eso se separan.
 */
function traspaso(array $g, array $cfg): ?array {
    $corte = (string) ($g['corte'] ?? '') ?: (string) ($cfg['fecha_contrato'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $corte)) return null;
    $ent = org_id($g['entrante'] ?? null) ?? org_id($cfg['contrato_entrante'] ?? null);
    $sal = org_id($g['saliente'] ?? null) ?? org_id($cfg['contrato_saliente'] ?? null);
    if (!$ent) return ['corte' => $corte, 'falta' => 'Elegí la empresa entrante'];
    $W = ''; $A = [];
    if (($s = trim((string) ($g['servicio'] ?? ''))) !== '') { $W = ' AND o.lugar = ?'; $A[] = $s; }
    $c = "$corte 00:00:00";
    $ab = "'" . implode("','", ABIERTAS) . "'"; $te = "'" . implode("','", TERMINADAS) . "'";
    $her = "o.creada < ? AND (o.estado IN ($ab) OR (o.estado IN ($te) AND o.fin >= ?))";
    $dias = "TIMESTAMPDIFF(MINUTE, o.creada, o.fin) / 1440";
    $n = fn(string $w, array $a) => (int) valor("SELECT COUNT(*) FROM rep_orden o WHERE $w$W", [...$a, ...$A]);
    $d = fn(string $w, array $a) => array_map('floatval', array_column(filas("SELECT $dias d FROM rep_orden o WHERE $w AND o.fin >= o.creada$W", [...$a, ...$A]), 'd'));
    $herResA = $d("$her AND o.estado IN ($te) AND o.fin >= ? AND o.org_id = ?", [$c, $c, $c, $ent]);
    $proRes = $d("o.creada >= ? AND o.org_id = ? AND o.estado IN ($te)", [$c, $ent]);
    $salRes = $sal ? $d("o.creada < ? AND o.org_id = ? AND o.estado IN ($te)", [$c, $sal]) : [];
    $sal12 = $sal ? $d("o.creada < ? AND o.creada >= ? AND o.org_id = ? AND o.estado IN ($te)", [$c, date('Y-m-d', strtotime("$corte -365 days")) . ' 00:00:00', $sal]) : [];
    $T = ['corte' => $corte, 'entrante' => $ent, 'saliente' => $sal,
        'entrante_nombre' => valor('SELECT nombre FROM rep_org WHERE id = ?', [$ent]), 'saliente_nombre' => $sal ? valor('SELECT nombre FROM rep_org WHERE id = ?', [$sal]) : null,
        'heredadas' => $n($her, [$c, $c]), 'heredadas_abiertas' => $n("$her AND o.estado IN ($ab)", [$c, $c]),
        'heredadas_cerradas_entrante' => count($herResA), 'heredadas_entrante_media' => $herResA ? round(media($herResA), 1) : null, 'heredadas_entrante_mediana' => percentil($herResA, 50),
        'heredadas_cerradas_otras' => $n("$her AND o.estado IN ($te) AND o.fin >= ? AND (o.org_id IS NULL OR o.org_id <> ?)", [$c, $c, $c, $ent]),
        'heredadas_cerradas_saliente' => $sal ? $n("$her AND o.estado IN ($te) AND o.fin >= ? AND o.org_id = ?", [$c, $c, $c, $sal]) : 0,
        'propias' => $n('o.creada >= ? AND o.org_id = ?', [$c, $ent]), 'propias_cerradas' => count($proRes),
        'propias_media' => $proRes ? round(media($proRes), 1) : null, 'propias_mediana' => percentil($proRes, 50),
        'saliente_media' => $salRes ? round(media($salRes), 1) : null, 'saliente_mediana' => percentil($salRes, 50), 'saliente_12m_media' => $sal12 ? round(media($sal12), 1) : null];
    // por mes, desde 6 meses antes del corte: backlog heredado y nuevo a fin de mes, y cerradas por la entrante
    $m0 = (new \DateTimeImmutable(substr($corte, 0, 7) . '-01'))->modify('-6 month')->format('Y-m');
    $hasta = max(substr($corte, 0, 7), substr((string) ($g['hasta'] ?? date('Y-m-d')), 0, 7));
    $T['meses'] = meses_de("$m0-01", "$hasta-01"); $T['backlog_heredado'] = []; $T['backlog_nuevo'] = []; $T['cerradas_entrante'] = [];
    $abierta = "o.creada <= ? AND (o.estado IN ($ab) OR (o.estado IN ($te) AND o.fin > ?))";
    foreach ($T['meses'] as $m) {
        $fe = min((new \DateTimeImmutable("$m-01"))->modify('last day of this month')->format('Y-m-d') . ' 23:59:59', date('Y-m-d H:i:s'));
        $T['backlog_heredado'][] = $n("$abierta AND o.creada < ?", [$fe, $fe, $c]);
        $T['backlog_nuevo'][] = $n("$abierta AND o.creada >= ?", [$fe, $fe, $c]);
        $T['cerradas_entrante'][] = $n("o.estado IN ($te) AND o.org_id = ? AND DATE_FORMAT(o.fin, '%Y-%m') = ?", [$ent, $m]);
    }
    return $T;
}

// ------------------------------------------------------------------ Google TimesFM: series para el cálculo externo y corridas guardadas

/**
 * Series para el pronóstico externo (Google TimesFM), hasta el último período COMPLETO y densas desde su primer dato:
 *  mensuales: total, servicios, empresas, tipos de tarea (clase de sistema PIM) y servicio × tarea;
 *  semanales (lunes): total, servicios y tipos de tarea.
 */
function series_pronostico(array $g, ?array $u): array {
    $W = ''; $A = []; if (($al = alcance($u)) !== null) { $W = ' AND o.org_id = ?'; $A[] = $al; }
    $finM = date('Y-m', strtotime('first day of last month'));
    $hoy = new \DateTimeImmutable('today'); $finW = $hoy->modify('-' . ((int) $hoy->format('N') - 1 + 7) . ' days')->format('Y-m-d');   // lunes de la última semana completa
    $lim = fn($k, $def) => max(0, min(80, (int) ($g[$k] ?? $def)));
    $ts = $lim('top_servicios', 20); $te = $lim('top_empresas', 15); $tt = $lim('top_tareas', 20); $tc = $lim('top_combos', 40);
    $semanal = !isset($g['semanal']) || (string) $g['semanal'] !== '0';
    $N = nombres_tarea(); $series = [];
    $mes = "DATE_FORMAT(o.creada, '%Y-%m')"; $sem = "DATE_SUB(DATE(o.creada), INTERVAL WEEKDAY(o.creada) DAY)";
    $armar = function (string $clave, string $nombre, string $freq, string $w, array $a) use (&$series, $finM, $finW, $W, $A, $mes, $sem) {
        $p = $freq === 'M' ? $mes : $sem; $fin = $freq === 'M' ? $finM : $finW;
        $c = []; foreach (filas("SELECT $p k, COUNT(*) n FROM rep_orden o WHERE $w$W GROUP BY k", [...$a, ...$A]) as $x) if ($x['k'] <= $fin) $c[$x['k']] = (int) $x['n'];
        if (!$c) return;
        $P = []; $x = min(array_keys($c));
        if ($freq === 'M') $P = meses_de("$x-01", "$fin-01");
        else for ($d = new \DateTimeImmutable($x), $i = 0; $d->format('Y-m-d') <= $fin && $i < 600; $d = $d->modify('+7 days'), $i++) $P[] = $d->format('Y-m-d');
        $series[] = ['serie' => $clave, 'nombre' => $nombre, 'freq' => $freq, 'desde' => $P[0], 'ultimo' => end($P), 'y' => array_map(fn($k) => $c[$k] ?? 0, $P)];
    };
    $srv = array_column(filas("SELECT o.lugar, COUNT(*) n FROM rep_orden o WHERE o.lugar IS NOT NULL$W GROUP BY o.lugar ORDER BY n DESC LIMIT $ts", $A), 'lugar');
    $tar = array_column(filas("SELECT o.tarea, COUNT(*) n FROM rep_orden o WHERE o.tarea IS NOT NULL$W GROUP BY o.tarea ORDER BY n DESC LIMIT $tt", $A), 'tarea');
    foreach ($semanal ? ['M', 'W'] : ['M'] as $f) {
        $armar('total', 'Todo el establecimiento', $f, '1', []);
        foreach ($srv as $s) $armar("srv:$s", $s, $f, 'o.lugar = ?', [$s]);
        foreach ($tar as $t) $armar("tt:$t", $N[$t] ?? $t, $f, 'o.tarea = ?', [$t]);
    }
    foreach (filas("SELECT o.org_id, MAX(g.nombre) nombre, COUNT(*) n FROM rep_orden o JOIN rep_org g ON g.id = o.org_id WHERE 1$W GROUP BY o.org_id ORDER BY n DESC LIMIT $te", $A) as $e)
        $armar('org:' . $e['org_id'], (string) $e['nombre'], 'M', 'o.org_id = ?', [$e['org_id']]);
    foreach (filas("SELECT o.lugar, o.tarea, COUNT(*) n FROM rep_orden o WHERE o.lugar IS NOT NULL AND o.tarea IS NOT NULL$W GROUP BY o.lugar, o.tarea ORDER BY n DESC LIMIT $tc", $A) as $x)
        $armar("srv:{$x['lugar']}|tt:{$x['tarea']}", $x['lugar'] . ' · ' . ($N[$x['tarea']] ?? $x['tarea']), 'M', 'o.lugar = ? AND o.tarea = ?', [$x['lugar'], $x['tarea']]);
    return ['ultimo_completo' => $finM, 'ultima_semana' => $finW, 'series' => $series];
}
/** Guarda una corrida: {modelo, nota?, filas: [{serie, freq, periodo AAAA-MM-DD, p10, p50, p90, ultimo_dato}]}. */
function guardar_pronosticos(array $d, ?array $u): array {
    $mod = trim((string) ($d['modelo'] ?? ''));
    if (!preg_match('/^[\w.\-() ]{3,80}$/u', $mod)) throw new Fallo(422, 'Falta el nombre del modelo (p. ej. timesfm-2.5-200m-pytorch)');
    $f = (array) ($d['filas'] ?? []);
    if (!$f) throw new Fallo(422, 'La corrida no trae filas');
    if (count($f) > 20000) throw new Fallo(422, 'Hasta 20.000 filas por corrida');
    $num = fn($v, $k, $i) => is_numeric($v) && $v >= 0 && $v < 1e9 ? round((float) $v, 2) : throw new Fallo(422, "Fila " . ($i + 1) . ": «$k» inválido");
    $ok = [];
    foreach (array_values($f) as $i => $x) {
        $s = (string) ($x['serie'] ?? '');
        if (!preg_match('/^(total|org:[A-Za-z0-9.-]{1,64}|tt:[A-Z0-9-]{2,20}|srv:[^|]{1,160}(\|tt:[A-Z0-9-]{2,20})?)$/u', $s)) throw new Fallo(422, 'Fila ' . ($i + 1) . ': serie inválida');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($x['periodo'] ?? ''))) throw new Fallo(422, 'Fila ' . ($i + 1) . ': período inválido');
        $p50 = $num($x['p50'] ?? null, 'p50', $i);
        $ok[] = [$s, ($x['freq'] ?? 'M') === 'W' ? 'W' : 'M', $x['periodo'], isset($x['p10']) ? $num($x['p10'], 'p10', $i) : null, $p50, isset($x['p90']) ? $num($x['p90'], 'p90', $i) : null,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($x['ultimo_dato'] ?? '')) ? $x['ultimo_dato'] : null];
    }
    q('INSERT INTO rep_corrida (modelo, creada, usuario, series, filas, nota) VALUES (?, NOW(), ?, ?, ?, ?)',
        [$mod, $u['usuario'] ?? null, count(array_unique(array_column($ok, 0))), count($ok), mb_substr(trim((string) ($d['nota'] ?? '')), 0, 200) ?: null]);
    $id = (int) \Nucleo\pdo()->lastInsertId();
    foreach (array_chunk($ok, 500) as $lote)
        q('INSERT IGNORE INTO rep_pronostico (corrida_id, serie, freq, periodo, p10, p50, p90, ultimo_dato) VALUES ' . implode(',', array_fill(0, count($lote), '(?,?,?,?,?,?,?,?)')),
          array_merge(...array_map(fn($r) => [$id, ...$r], $lote)));
    \Nucleo\asentar('reporte.pronostico', ['corrida' => $id, 'modelo' => $mod, 'filas' => count($ok)]);
    return ['corrida' => $id, 'guardadas' => count($ok)];
}
/** La corrida más nueva que tenga esa serie, alineada a los meses pedidos (null donde no haya valor). */
function timesfm_de(string $serie, array $meses, ?string $ultimoBase, string $freq = 'M'): ?array {
    $c = fila("SELECT c.id, c.modelo, c.creada, MAX(p.ultimo_dato) ultimo FROM rep_pronostico p JOIN rep_corrida c ON c.id = p.corrida_id WHERE p.serie = ? AND p.freq = ? GROUP BY c.id ORDER BY c.id DESC LIMIT 1", [$serie, $freq]);
    if (!$c) return null;
    $fmt = $freq === 'M' ? '%Y-%m' : '%Y-%m-%d';
    $v = []; foreach (filas("SELECT DATE_FORMAT(periodo, '$fmt') m, p10, p50, p90 FROM rep_pronostico WHERE corrida_id = ? AND serie = ? AND freq = ?", [$c['id'], $serie, $freq]) as $x) $v[$x['m']] = $x;
    $o = ['serie' => $serie, 'modelo' => $c['modelo'], 'corrida' => $c['creada'], 'ultimo_dato' => $c['ultimo'], 'p10' => [], 'p50' => [], 'p90' => [], 'n' => 0,
        'desactualizado' => $ultimoBase && $c['ultimo'] && ($freq === 'M' ? substr($c['ultimo'], 0, 7) : $c['ultimo']) < $ultimoBase];
    foreach ($meses as $m) { $x = $v[$m] ?? null; if ($x) $o['n']++;
        foreach (['p10', 'p50', 'p90'] as $k) $o[$k][] = $x && $x[$k] !== null ? (float) $x[$k] : null; }
    return $o['n'] ? $o : ['serie' => $serie, 'modelo' => $c['modelo'], 'corrida' => $c['creada'], 'n' => 0, 'sin_solape' => true];
}
