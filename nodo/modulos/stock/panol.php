<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Stock 1.2.0 · herramientas del pañol (equivalen a «Pedir Insumos» y «Reportes de Stock» del sistema anterior):
// reposición (punto de pedido, stock de seguridad, ABC), tendencias (Holt), pedidos de insumos, conteo cíclico y reportes.
declare(strict_types=1);

namespace Modulos\Stock;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, valor, pdo, asentar, publicar, cfg_modulo, catalogo_existe_valor, pim, raiz};

/** Egresos que cuentan como consumo [supuesto]: las transferencias, devoluciones al proveedor y ajustes no son consumo. */
const MOTIVOS_CONSUMO = ['OT', 'CONSUMO', 'BAJA'];
const ESTADOS_PEDIDO = ['Solicitado', 'Recibido parcial', 'Recibido', 'Anulado'];
/** Prioridad del pedido = request-priority FHIR (como SupplyRequest.priority), con el nombre que usaba el sistema anterior. */
const PRIORIDADES_PEDIDO = ['routine' => 'Normal', 'urgent' => 'Urgente', 'asap' => 'Lo antes posible', 'stat' => 'Inmediata'];
const DIA_MES = 30.4;

/** Parámetros de reposición: los de Configuración del módulo, que el pedido puede pisar para simular. */
function parametros(array $q = []): array {
    $c = cfg_modulo('stock');
    $p = ['plazo' => (float) ($c['plazo_entrega_dias'] ?? 15), 'nivel' => (float) ($c['nivel_servicio'] ?? 95), 'ciclo' => (float) ($c['ciclo_dias'] ?? 30),
        'ventana' => (int) ($c['ventana_meses'] ?? 3), 'cont_a' => (int) ($c['conteo_dias_a'] ?? 30), 'cont_b' => (int) ($c['conteo_dias_b'] ?? 90), 'cont_c' => (int) ($c['conteo_dias_c'] ?? 180)];
    $lim = ['plazo' => [1, 365], 'nivel' => [50, 99.9], 'ciclo' => [1, 365], 'ventana' => [1, 24], 'cont_a' => [1, 730], 'cont_b' => [1, 730], 'cont_c' => [1, 730]];
    foreach ($lim as $k => [$min, $max]) if (isset($q[$k]) && $q[$k] !== '' && is_numeric($q[$k])) $p[$k] = max($min, min($max, is_int($p[$k]) ? (int) $q[$k] : (float) $q[$k]));
    return $p;
}
/** Factor z del nivel de servicio (los mismos escalones que el sistema anterior). */
function z(float $nivel): float { return $nivel >= 99 ? 2.33 : ($nivel >= 97.5 ? 1.96 : ($nivel >= 95 ? 1.65 : ($nivel >= 90 ? 1.28 : 0.84))); }
/** Meses AAAA-MM entre dos meses, inclusive. */
function meses_entre(string $desde, string $hasta): array {
    $r = []; $d = \DateTimeImmutable::createFromFormat('!Y-m', $desde); $h = \DateTimeImmutable::createFromFormat('!Y-m', $hasta);
    if (!$d || !$h) return [];
    for ($i = 0; $d <= $h && $i < 240; $i++, $d = $d->modify('+1 month')) $r[] = $d->format('Y-m');
    return $r;
}
/** Holt (suavizado doble) con a = 0,5 y b = 0,3: proyección de h períodos, nunca negativa. */
function holt(array $y, int $h): array {
    $y = array_values(array_map('floatval', $y));
    if (count($y) < 2) return ['proy' => array_fill(0, $h, $y[0] ?? 0.0), 'nivel' => $y[0] ?? 0.0, 'pendiente' => 0.0];
    $a = 0.5; $b = 0.3; $L = $y[0]; $T = $y[1] - $y[0];
    for ($i = 1; $i < count($y); $i++) { $Lp = $L; $L = $a * $y[$i] + (1 - $a) * ($L + $T); $T = $b * ($L - $Lp) + (1 - $b) * $T; }
    $out = []; for ($k = 1; $k <= $h; $k++) $out[] = round(max(0, $L + $k * $T), 3);
    return ['proy' => $out, 'nivel' => round($L, 3), 'pendiente' => round($T, 3)];
}
/** Pendiente de la recta de mínimos cuadrados (unidades por período). */
function regresion(array $y): float {
    $n = count($y); if ($n < 2) return 0.0;
    $sx = $sy = $sxy = $sxx = 0.0;
    foreach (array_values($y) as $i => $v) { $sx += $i; $sy += $v; $sxy += $i * $v; $sxx += $i * $i; }
    $d = $n * $sxx - $sx * $sx;
    return $d ? round(($n * $sxy - $sx * $sy) / $d, 4) : 0.0;
}

/** Cantidad pendiente de llegar por insumo (pedidos Solicitado o Recibido parcial). */
function en_pedido(): array {
    $r = [];
    foreach (filas("SELECT l.insumo_id, SUM(GREATEST(l.cantidad - l.recibido, 0)) AS q FROM stock_pedido_linea l JOIN stock_pedido p ON p.id = l.pedido_id
        WHERE p.estado IN ('Solicitado', 'Recibido parcial') GROUP BY l.insumo_id") as $f) $r[(int) $f['insumo_id']] = (float) $f['q'];
    return $r;
}

/**
 * Métricas por insumo (como «Pedir Insumos» del sistema anterior):
 * ventana = últimos n meses COMPLETOS; diario = consumo / (n · 30,4); ss = z · (desvío mensual / √30,4) · √plazo (con menos de 2 meses: diario · 7);
 * punto de pedido = diario · plazo + ss; objetivo = punto + diario · ciclo; sugerido si diario > 0 y existencia + en pedido ≤ punto;
 * ABC por valor consumido en 12 meses, según lo acumulado ANTES del insumo (< 80 % A, < 95 % B, resto C; sin consumo N).
 */
function analitica(array $q = []): array {
    $P = parametros($q);
    $hoy = new \DateTimeImmutable('first day of this month');
    $mesHoy = $hoy->format('Y-m');
    $primero = valor('SELECT MIN(fecha) FROM stock_movimiento');
    $desde = $primero ? substr((string) $primero, 0, 7) : $mesHoy;
    $min24 = $hoy->modify('-23 month')->format('Y-m');
    if ($desde < $min24) $desde = $min24;
    $meses = meses_entre($desde, $mesHoy);
    $n = max(1, min($P['ventana'], count($meses)));
    $cerr = array_slice($meses, 0, -1);
    $vent = count($cerr) >= $n ? array_slice($cerr, -$n) : array_slice($meses, -$n);
    $m12 = array_slice($meses, -12);
    $dias = count($vent) * DIA_MES; $LT = $P['plazo']; $Z = z($P['nivel']); $ciclo = $P['ciclo'];

    $ph = implode(',', array_fill(0, count(MOTIVOS_CONSUMO), '?'));
    $egr = []; $ing = [];
    foreach (filas("SELECT insumo_id, DATE_FORMAT(fecha, '%Y-%m') AS mes, SUM(cantidad) AS q FROM stock_movimiento
        WHERE tipo = 'egreso' AND motivo IN ($ph) AND fecha >= ? GROUP BY insumo_id, mes", [...MOTIVOS_CONSUMO, $desde . '-01']) as $f) $egr[(int) $f['insumo_id']][$f['mes']] = (float) $f['q'];
    foreach (filas("SELECT insumo_id, DATE_FORMAT(fecha, '%Y-%m') AS mes, SUM(cantidad) AS q FROM stock_movimiento
        WHERE tipo = 'ingreso' AND fecha >= ? GROUP BY insumo_id, mes", [$desde . '-01']) as $f) $ing[(int) $f['insumo_id']][$f['mes']] = (float) $f['q'];
    $enPed = en_pedido();
    $items = []; $total12 = 0.0;
    foreach (filas('SELECT id, codigo, categoria, subcategoria, descripcion, unidad, existencia, minimo, precio FROM stock_insumo WHERE activo = 1 ORDER BY codigo') as $i) {
        $id = (int) $i['id']; $e = $egr[$id] ?? [];
        $serie = array_map(fn($m) => $e[$m] ?? 0.0, $vent);
        $tot = array_sum($serie); $media = $tot / count($vent);
        $sd = sqrt(array_sum(array_map(fn($v) => ($v - $media) ** 2, $serie)) / max(1, count($serie) - 1));
        $diario = $tot / $dias;
        $ss = count($serie) >= 2 ? $Z * ($sd / sqrt(DIA_MES)) * sqrt($LT) : $diario * 7;
        $rop = $diario * $LT + $ss;
        $stock = (float) $i['existencia']; $ped = $enPed[$id] ?? 0.0; $disp = $stock + $ped;
        $obj = $rop + $diario * $ciclo;
        $sug = ($diario > 0 && $disp <= $rop) ? max(0, $obj - $disp) : 0;
        $sug = $sug > 0 ? ($i['unidad'] === 'u' ? ceil($sug) : ceil($sug * 10) / 10) : 0;
        $c12 = array_sum(array_map(fn($m) => $e[$m] ?? 0.0, $m12));
        $precio = $i['precio'] !== null ? (float) $i['precio'] : 0.0;
        $v12 = $c12 * $precio; $total12 += $v12;
        $cob = $diario > 0 ? $stock / $diario : null;   // null = no se consume (cobertura infinita si hay existencia)
        $items[] = ['id' => $id, 'codigo' => $i['codigo'], 'categoria' => $i['categoria'], 'subcategoria' => $i['subcategoria'], 'descripcion' => $i['descripcion'],
            'unidad' => $i['unidad'], 'existencia' => $stock, 'minimo' => (float) $i['minimo'], 'precio' => $i['precio'] !== null ? $precio : null,
            'egr' => (object) $e, 'ing' => (object) ($ing[$id] ?? []),
            'mensual' => round($media, 3), 'diario' => round($diario, 4), 'ss' => round($ss, 3), 'rop' => round($rop, 3), 'objetivo' => round($obj, 3),
            'en_pedido' => $ped, 'cobertura' => $cob === null ? null : round($cob, 1), 'sugerido' => $sug, 'consumo12' => $c12, 'valor12' => round($v12, 2),
            'critico' => $diario > 0 && $cob !== null && $cob < $LT, 'sin_stock' => $stock <= 0 && $c12 > 0,
            'bajo_minimo' => (float) $i['minimo'] > 0 && $stock < (float) $i['minimo'], 'negativo' => $stock < 0, 'abc' => 'N'];
    }
    $ord = array_keys(array_filter($items, fn($x) => $x['valor12'] > 0));
    usort($ord, fn($a, $b) => $items[$b]['valor12'] <=> $items[$a]['valor12']);
    $acum = 0.0;
    foreach ($ord as $k) { $p = $total12 ? $acum / $total12 : 1; $acum += $items[$k]['valor12']; $items[$k]['abc'] = $p < 0.8 ? 'A' : ($p < 0.95 ? 'B' : 'C'); }
    $k = ['sin_stock' => 0, 'criticos' => 0, 'bajo_minimo' => 0, 'negativos' => 0, 'sugeridos' => 0, 'valor_reponer' => 0.0, 'sin_precio' => 0];
    foreach ($items as $x) {
        $k['sin_stock'] += (int) $x['sin_stock']; $k['criticos'] += (int) $x['critico']; $k['bajo_minimo'] += (int) $x['bajo_minimo']; $k['negativos'] += (int) $x['negativo'];
        if ($x['sugerido'] > 0) { $k['sugeridos']++; $k['valor_reponer'] += $x['sugerido'] * ($x['precio'] ?? 0); if ($x['precio'] === null) $k['sin_precio']++; }
    }
    $k['valor_reponer'] = round($k['valor_reponer'], 2);
    return ['parametros' => $P, 'z' => $Z, 'meses' => $meses, 'ventana' => $vent, 'kpis' => $k, 'motivos_consumo' => MOTIVOS_CONSUMO,
        'categorias' => array_values(array_unique(array_column($items, 'categoria'))), 'items' => $items];
}

/**
 * Tendencias del consumo (o de los ingresos) en cantidad o en valor, por categoría o por insumo, comparadas con el período anterior de igual largo,
 * con pendiente (regresión) y proyección a 3 meses (Holt). «cual» = código de insumo o de categoría para la serie del gráfico (vacío = todo el pañol).
 */
function tendencias(array $q = []): array {
    $A = analitica($q); $M = $A['meses'];
    $hasta = in_array($q['hasta'] ?? '', $M, true) ? $q['hasta'] : end($M);
    $desde = in_array($q['desde'] ?? '', $M, true) ? $q['desde'] : (count($M) > 9 ? $M[count($M) - 6] : $M[0]);
    if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
    $meses = array_values(array_filter(meses_entre($desde, $hasta), fn($m) => in_array($m, $M, true)));
    $i0 = array_search($meses[0], $M, true); $prev = array_slice($M, max(0, $i0 - count($meses)), $i0 - max(0, $i0 - count($meses)));
    $serie = ($q['serie'] ?? 'egr') === 'ing' ? 'ing' : 'egr'; $valor = ($q['metrica'] ?? 'cant') === 'valor';
    $vista = ($q['vista'] ?? 'categoria') === 'insumo' ? 'insumo' : 'categoria';
    $med = fn(array $x, string $m) => ((array) $x[$serie])[$m] ?? 0.0;
    $mide = fn(array $x, string $m) => $valor ? $med($x, $m) * ($x['precio'] ?? 0) : $med($x, $m);
    $grupos = [];
    foreach ($A['items'] as $x) { $g = $vista === 'categoria' ? $x['categoria'] : $x['codigo']; $grupos[$g]['n'] = $vista === 'categoria' ? $x['categoria'] : $x['codigo'] . ' · ' . $x['descripcion']; $grupos[$g]['it'][] = $x; }
    $filas = [];
    foreach ($grupos as $k => $g) {
        $s = array_map(fn($m) => array_sum(array_map(fn($x) => $mide($x, $m), $g['it'])), $meses);
        $p = array_map(fn($m) => array_sum(array_map(fn($x) => $mide($x, $m), $g['it'])), $prev);
        $tot = array_sum($s); $ant = array_sum($p);
        if ($tot == 0 && $ant == 0) continue;
        $filas[] = ['clave' => (string) $k, 'nombre' => $g['n'], 'periodo' => round($tot, 2), 'anterior' => $prev ? round($ant, 2) : null,
            'variacion' => $prev && $ant ? round(($tot - $ant) / $ant * 100, 1) : null, 'promedio' => round($tot / count($meses), 2),
            'tendencia' => regresion($s), 'proyeccion3' => round(array_sum(holt($s, 3)['proy']), 2), 'serie' => array_map(fn($v) => round($v, 2), $s)];
    }
    usort($filas, fn($a, $b) => $b['periodo'] <=> $a['periodo']);
    $cual = trim((string) ($q['cual'] ?? ''));
    $sel = array_filter($A['items'], fn($x) => $cual === '' || $x['codigo'] === $cual || $x['categoria'] === $cual);
    $todos = array_map(fn($m) => round(array_sum(array_map(fn($x) => $mide($x, $m), $sel)), 2), $meses);
    $h = holt($todos, 3);
    $ult = end($meses); $sig = []; $d = \DateTimeImmutable::createFromFormat('!Y-m', $ult);
    for ($i = 1; $i <= 3; $i++) $sig[] = $d->modify("+$i month")->format('Y-m');
    return ['meses' => $meses, 'anteriores' => $prev, 'serie' => $serie, 'metrica' => $valor ? 'valor' : 'cant', 'vista' => $vista, 'filas' => $filas,
        'grafico' => ['cual' => $cual, 'meses' => $meses, 'valores' => $todos, 'proyeccion' => ['meses' => $sig, 'valores' => $h['proy']], 'pendiente' => regresion($todos)],
        'disponibles' => $M];
}

// ------------------------------------------------------------------ pedidos de insumos

/** ConceptMap normativo pim-banda-a-prioridad (conf/pim-guia): banda A–D → request-priority. Única fuente, como en el Servidor PIM. */
function banda_prioridad(): array {
    static $m = null;
    if ($m !== null) return $m;
    $cm = json_decode((string) @file_get_contents(raiz() . '/conf/pim-guia/ConceptMap-pim-banda-a-prioridad.json'), true);
    $m = [];
    foreach ((array) ($cm['group'][0]['element'] ?? []) as $e) $m[substr((string) $e['code'], 6)] = (string) ($e['target'][0]['code'] ?? '');
    if (array_keys($m) !== ['A', 'B', 'C', 'D'] || array_diff($m, array_keys(PRIORIDADES_PEDIDO))) throw new Fallo(503, 'Falta el ConceptMap pim-banda-a-prioridad en conf/pim-guia');
    return $m;
}
/** Letra del número de pedido = banda PIM de su prioridad (stat → A … routine → D). */
function letra_prioridad(string $prio): string { return (string) (array_search($prio, banda_prioridad(), true) ?: 'D'); }
/** Acepta el código FHIR o el nombre que usaba el sistema anterior («Inmediata», «Lo antes posible», «Urgente», «Normal»). Vacío → null. */
function prioridad_codigo(string $v): ?string {
    $v = trim($v); if ($v === '') return null;
    if (isset(PRIORIDADES_PEDIDO[$v])) return $v;
    $k = array_search(mb_strtolower($v), array_map('mb_strtolower', PRIORIDADES_PEDIDO), true);
    if ($k === false) throw new Fallo(422, 'Prioridad: ' . implode(', ', PRIORIDADES_PEDIDO));
    return (string) $k;
}
/** Valor de una extensión PIM (por el final de su url) en un recurso FHIR. */
function ext_pim(array $r, string $nombre): ?array {
    foreach ((array) ($r['extension'] ?? []) as $e) if (str_ends_with((string) ($e['url'] ?? ''), '/' . $nombre)) return $e;
    return null;
}
/**
 * Prioridad sugerida para un pedido, según PIM y en este orden:
 *  1. la orden de trabajo: su prioridad (la que el Gestor decidió desde el IRU, pim-decision-prioridad);
 *  2. el activo (de la orden o elegido): su IRU (pim-iru → prioridad, banda ajustada si la hay);
 *  3. sin IRU completo: la categoría NFPA 99 del activo (1 → A … 4 → D) [supuesto a validar: equivalencia directa];
 *  4. nada: sin sugerencia (se elige a mano).
 */
function sugerencia_prioridad(string $orden, string $activo): array {
    $r = ['prioridad' => null, 'letra' => null, 'fundamento' => null, 'orden' => null, 'orden_numero' => null, 'activo' => null, 'activo_nombre' => null];
    $ok = fn($x) => (bool) preg_match('/^[A-Za-z0-9.-]{1,64}$/', $x);
    if ($orden !== '') {
        if (!$ok($orden)) throw new Fallo(422, 'Orden inválida');
        $t = pim('GET', 'Task/' . $orden);
        if ($t['http'] !== 200) throw new Fallo(422, 'No existe esa orden de trabajo');
        $T = $t['cuerpo']; $r['orden'] = $orden; $r['orden_numero'] = $T['identifier'][0]['value'] ?? $orden;
        if (str_starts_with((string) ($T['focus']['reference'] ?? ''), 'Device/') && $activo === '') $activo = substr($T['focus']['reference'], 7);
        if (!empty($T['priority']) && isset(PRIORIDADES_PEDIDO[$T['priority']])) {
            $r['prioridad'] = $T['priority']; $r['fundamento'] = 'prioridad PIM de la orden ' . $r['orden_numero'];
        }
    }
    if ($activo !== '') {
        if (!$ok($activo)) throw new Fallo(422, 'Activo inválido');
        $d = pim('GET', 'Device/' . $activo);
        if ($d['http'] === 200) {
            $D = $d['cuerpo']; $r['activo'] = $activo;
            $cod = ''; foreach ((array) ($D['identifier'] ?? []) as $i) { $cod = (string) ($i['value'] ?? ''); if ($cod !== '') break; }
            $r['activo_nombre'] = mb_substr(trim($cod . ' · ' . ($D['deviceName'][0]['name'] ?? ''), ' ·'), 0, 200);
            if (!$r['prioridad'] && ($iru = ext_pim($D, 'pim-iru'))) {
                $v = []; foreach ((array) $iru['extension'] as $x) $v[$x['url']] = $x['valueInteger'] ?? $x['valueCode'] ?? ($x['valueCoding']['code'] ?? null);
                if (!empty($v['prioridad']) && isset(PRIORIDADES_PEDIDO[$v['prioridad']])) {
                    $banda = substr((string) ($v['bandaAjustada'] ?? $v['banda'] ?? ''), 6);
                    $r['prioridad'] = $v['prioridad']; $r['fundamento'] = "IRU del activo: F {$v['F']} + R {$v['R']} + M {$v['M']} + A {$v['A']} = {$v['total']} · banda $banda";
                }
            }
            if (!$r['prioridad'] && ($c = ext_pim($D, 'pim-categoria-riesgo'))) {
                $cat = (int) ($c['valueCoding']['code'] ?? 0);
                if ($cat >= 1 && $cat <= 4) { $r['prioridad'] = banda_prioridad()[['', 'A', 'B', 'C', 'D'][$cat]]; $r['fundamento'] = "categoría NFPA 99 $cat del activo (sin IRU completo)"; }
            }
        } elseif ($orden === '') throw new Fallo(422, 'No existe ese equipo o instalación');
    }
    if ($r['prioridad']) $r['letra'] = letra_prioridad($r['prioridad']);
    return $r;
}

function pedido(int $id): ?array {
    $p = fila('SELECT p.*, v.nombre AS proveedor FROM stock_pedido p LEFT JOIN stock_proveedor v ON v.id = p.proveedor_id WHERE p.id = ?', [$id]);
    if ($p) {
        $p['lineas'] = filas('SELECT l.*, i.codigo, i.descripcion, i.unidad, i.precio, i.existencia FROM stock_pedido_linea l JOIN stock_insumo i ON i.id = l.insumo_id WHERE l.pedido_id = ? ORDER BY l.renglon', [$id]);
        $p['prioridad_texto'] = PRIORIDADES_PEDIDO[$p['prioridad']] ?? $p['prioridad'];
        $p['proveedor_email'] = $p['proveedor_id'] ? valor('SELECT email FROM stock_proveedor WHERE id = ?', [(int) $p['proveedor_id']]) : null;
        $p['enlace'] = enlace_pedido((string) $p['numero']);
    }
    return $p;
}
function pedidos(array $q = []): array {
    $w = []; $a = [];
    if (in_array($q['estado'] ?? '', ESTADOS_PEDIDO, true)) { $w[] = 'p.estado = ?'; $a[] = $q['estado']; }
    if (!empty($q['abiertos'])) $w[] = "p.estado IN ('Solicitado', 'Recibido parcial')";
    if (($t = trim((string) ($q['q'] ?? ''))) !== '') {
        $w[] = '(p.numero LIKE ? OR p.motivo LIKE ? OR p.destino LIKE ? OR EXISTS (SELECT 1 FROM stock_pedido_linea l JOIN stock_insumo i ON i.id = l.insumo_id WHERE l.pedido_id = p.id AND (i.codigo LIKE ? OR i.descripcion LIKE ?)))';
        array_push($a, ...array_fill(0, 5, "%$t%"));
    }
    $ids = array_column(filas('SELECT p.id FROM stock_pedido p' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY p.creado DESC, p.id DESC LIMIT 300', $a), 'id');
    return array_map(fn($id) => pedido((int) $id), $ids);
}
function crear_pedido(array $d): array {
    // prioridad según PIM: la sugiere la orden (su prioridad) o el activo (IRU; si no, NFPA 99); otra elección exige motivo
    $sug = sugerencia_prioridad((string) ($d['orden'] ?? ''), (string) ($d['activo'] ?? ''));
    $prio = prioridad_codigo((string) ($d['prioridad'] ?? '')) ?? $sug['prioridad'] ?? 'routine';
    $motP = texto($d['motivo_prioridad'] ?? null, 300, false, 'Motivo de la prioridad');
    if ($sug['prioridad'] && $prio !== $sug['prioridad'] && !$motP)
        throw new Fallo(422, 'La prioridad sugerida por PIM es «' . PRIORIDADES_PEDIDO[$sug['prioridad']] . '» (' . $sug['fundamento'] . '): para elegir otra escribí el motivo');
    $nec = trim((string) ($d['necesario'] ?? ''));
    if ($nec !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nec)) throw new Fallo(422, '«Se necesita para»: fecha inválida');
    $prov = null;
    if (!empty($d['proveedor'])) { $prov = (int) $d['proveedor']; if (!valor('SELECT 1 FROM stock_proveedor WHERE id = ? AND activo = 1', [$prov])) throw new Fallo(422, 'El proveedor no existe o está desactivado'); }
    $letra = letra_prioridad($prio);
    $cab = ['estado' => 'Solicitado', 'prioridad' => $prio, 'letra' => $letra, 'prioridad_sugerida' => $sug['prioridad'], 'fundamento' => $sug['fundamento'],
        'motivo_prioridad' => $sug['prioridad'] && $prio !== $sug['prioridad'] ? $motP : null,
        'orden' => $sug['orden'], 'orden_numero' => $sug['orden_numero'], 'activo' => $sug['activo'], 'activo_nombre' => $sug['activo_nombre'], 'destino' => texto($d['destino'] ?? null, 160, false, 'Destino'), 'proveedor_id' => $prov, 'necesario' => $nec ?: null,
        'motivo' => texto($d['motivo'] ?? null, 200, true, 'Motivo'), 'observaciones' => texto($d['observaciones'] ?? null, 600, false, 'Observaciones'),
        'creado' => date('Y-m-d H:i:s'), 'creado_por' => $GLOBALS['nucleo_usuario']['usuario'] ?? null];
    $lin = array_values(array_filter((array) ($d['lineas'] ?? []), fn($l) => !empty($l['insumo'])));
    if (!$lin) throw new Fallo(422, 'Agregá al menos un insumo al pedido');
    if (count($lin) > 100) throw new Fallo(422, 'Hasta 100 renglones por pedido');
    $vistos = [];
    foreach ($lin as $i => &$l) {
        $l['insumo'] = (int) $l['insumo'];
        if (isset($vistos[$l['insumo']])) throw new Fallo(422, 'Renglón ' . ($i + 1) . ': el insumo está repetido');
        $vistos[$l['insumo']] = 1;
        if (!valor('SELECT 1 FROM stock_insumo WHERE id = ? AND activo = 1', [$l['insumo']])) throw new Fallo(422, 'Renglón ' . ($i + 1) . ': el insumo no existe o está desactivado');
        $l['cantidad'] = numero($l['cantidad'] ?? null, 'Cantidad (renglón ' . ($i + 1) . ')', true) ?? throw new Fallo(422, 'Renglón ' . ($i + 1) . ': falta la cantidad');
        $l['nota'] = texto($l['nota'] ?? null, 200, false, 'Nota');
    }
    unset($l);
    pdo()->beginTransaction();
    try {
        $base = 'PED-' . date('Ymd') . '-';
        $n = (int) valor('SELECT COALESCE(MAX(CAST(SUBSTRING(numero, 14, 3) AS UNSIGNED)), 0) FROM stock_pedido WHERE numero LIKE ? FOR UPDATE', [$base . '%']) + 1;
        $cab['numero'] = $base . sprintf('%03d', $n) . '-' . $letra;
        q('INSERT INTO stock_pedido (' . implode(',', array_keys($cab)) . ') VALUES (' . implode(',', array_fill(0, count($cab), '?')) . ')', array_values($cab));
        $id = (int) pdo()->lastInsertId();
        foreach ($lin as $i => $l) q('INSERT INTO stock_pedido_linea (pedido_id, renglon, insumo_id, cantidad, nota) VALUES (?, ?, ?, ?, ?)', [$id, $i + 1, $l['insumo'], $l['cantidad'], $l['nota']]);
        pdo()->commit();
    } catch (\Throwable $e) { if (pdo()->inTransaction()) pdo()->rollBack(); throw $e; }
    asentar('stock.pedido', ['numero' => $cab['numero'], 'renglones' => count($lin), 'prioridad' => $prio, 'sugerida' => $sug['prioridad'], 'orden' => $sug['orden_numero']], $cab['numero']);
    publicar('stock/pedido', ['numero' => $cab['numero'], 'estado' => 'Solicitado', 'prioridad' => $prio, 'banda' => $letra, 'orden' => $sug['orden'], 'renglones' => count($lin)], 'stock');
    return pedido($id);
}
/**
 * Cambio de estado: «recibir» con lo recibido por renglón (acumulado, no baja), «completo» o «anular».
 * Con «ingresar» = true lo que llega en esta recepción entra al stock como un ingreso (origen COMPRA salvo otro), con el remito del pedido.
 */
function estado_pedido(int $id, array $d): array {
    $p = pedido($id) ?? throw new Fallo(404, 'No existe el pedido');
    if (!in_array($p['estado'], ['Solicitado', 'Recibido parcial'], true)) throw new Fallo(409, "El pedido {$p['numero']} ya está {$p['estado']}");
    $acc = (string) ($d['accion'] ?? '');
    $usr = $GLOBALS['nucleo_usuario']['usuario'] ?? null;
    if ($acc === 'anular') {
        $mot = texto($d['motivo'] ?? null, 200, true, 'Motivo de la anulación');
        q('UPDATE stock_pedido SET estado = ?, observaciones = CONCAT_WS(" · ", observaciones, ?), actualizado = NOW(), actualizado_por = ? WHERE id = ?', ['Anulado', 'Anulado: ' . $mot, $usr, $id]);
        asentar('stock.pedido_estado', ['estado' => 'Anulado', 'motivo' => $mot], $p['numero']);
        publicar('stock/pedido', ['numero' => $p['numero'], 'estado' => 'Anulado'], 'stock');
        return ['pedido' => pedido($id), 'ingreso' => null];
    }
    if (!in_array($acc, ['recibir', 'completo'], true)) throw new Fallo(422, 'accion: recibir | completo | anular');
    $porLinea = [];
    foreach ((array) ($d['recibidos'] ?? []) as $r) if (isset($r['linea'])) $porLinea[(int) $r['linea']] = $r['recibido'] ?? null;
    $nuevo = []; $delta = [];
    foreach ($p['lineas'] as $l) {
        $ant = (float) $l['recibido'];
        $v = $acc === 'completo' ? max($ant, (float) $l['cantidad']) : (array_key_exists((int) $l['id'], $porLinea) ? numero($porLinea[(int) $l['id']], "Recibido ({$l['codigo']})") ?? $ant : $ant);
        if ($v < $ant) throw new Fallo(422, "{$l['codigo']}: lo recibido no puede bajar (ya figuran " . rtrim(rtrim(number_format($ant, 3, ',', ''), '0'), ',') . ')');
        $nuevo[(int) $l['id']] = $v;
        if ($v > $ant) $delta[] = ['insumo' => (int) $l['insumo_id'], 'cantidad' => round($v - $ant, 3), 'precio' => $l['precio']];
    }
    if (!$delta && $acc === 'recibir') throw new Fallo(422, 'No cargaste ninguna cantidad nueva');
    $completo = true; foreach ($p['lineas'] as $l) if ($nuevo[(int) $l['id']] < (float) $l['cantidad']) $completo = false;
    $est = $completo ? 'Recibido' : 'Recibido parcial';
    $ing = null;
    if (!empty($d['ingresar']) && $delta) {
        $ing = mover(['tipo' => 'ingreso', 'origen' => $d['origen'] ?? 'COMPRA', 'fecha' => $d['fecha'] ?? null, 'comprobante' => $d['remito'] ?? null,
            'orden_compra' => $d['orden_compra'] ?? null, 'expediente' => $d['expediente'] ?? null, 'proveedor' => $p['proveedor_id'],
            'observacion' => "Recepción del pedido {$p['numero']}", 'lineas' => $delta]);
    }
    foreach ($nuevo as $lid => $v) q('UPDATE stock_pedido_linea SET recibido = ? WHERE id = ?', [$v, $lid]);
    q('UPDATE stock_pedido SET estado = ?, actualizado = NOW(), actualizado_por = ? WHERE id = ?', [$est, $usr, $id]);
    asentar('stock.pedido_estado', ['estado' => $est, 'renglones_recibidos' => count($delta), 'ingreso' => $ing['clave'] ?? null], $p['numero']);
    publicar('stock/pedido', ['numero' => $p['numero'], 'estado' => $est], 'stock');
    return ['pedido' => pedido($id), 'ingreso' => $ing];
}

// ------------------------------------------------------------------ conteo cíclico

/** Qué contar: los vencidos según su clase (A cada 30 días, B 90, C 180, sin consumo 365), los de clase A primero. */
function plan_conteo(array $q = []): array {
    $A = analitica($q); $P = $A['parametros'];
    $frec = ['A' => $P['cont_a'], 'B' => $P['cont_b'], 'C' => $P['cont_c'], 'N' => 365];
    $ult = [];
    foreach (filas('SELECT c.insumo_id, c.fecha, c.diferencia FROM stock_conteo c JOIN (SELECT insumo_id, MAX(id) AS id FROM stock_conteo GROUP BY insumo_id) u ON u.id = c.id') as $f) $ult[(int) $f['insumo_id']] = $f;
    $hoy = time(); $plan = [];
    foreach ($A['items'] as $x) {
        if ($x['existencia'] == 0 && $x['consumo12'] == 0) continue;
        $u = $ult[$x['id']] ?? null;
        $dias = $u ? (int) floor(($hoy - strtotime((string) $u['fecha'])) / 86400) : null;
        $vence = $dias === null ? 9999 : $dias - $frec[$x['abc']];
        if ($vence < 0) continue;
        $plan[] = ['id' => $x['id'], 'codigo' => $x['codigo'], 'descripcion' => $x['descripcion'], 'unidad' => $x['unidad'], 'abc' => $x['abc'], 'existencia' => $x['existencia'],
            'ultimo' => $u['fecha'] ?? null, 'dias' => $dias, 'vence' => $vence, 'valor12' => $x['valor12']];
    }
    usort($plan, fn($a, $b) => (strpos('ABCN', $a['abc']) <=> strpos('ABCN', $b['abc'])) ?: ($b['vence'] <=> $a['vence']) ?: ($b['valor12'] <=> $a['valor12']));
    $lote = max(5, min(100, (int) ($q['lote'] ?? 15)));
    $rec = filas('SELECT c.*, i.codigo, i.descripcion, i.unidad FROM stock_conteo c JOIN stock_insumo i ON i.id = c.insumo_id ORDER BY c.fecha DESC, c.id DESC LIMIT 100');
    $d90 = filas('SELECT diferencia FROM stock_conteo WHERE fecha >= NOW() - INTERVAL 90 DAY');
    $pend = ['A' => 0, 'B' => 0, 'C' => 0, 'N' => 0]; foreach ($plan as $r) $pend[$r['abc']]++;
    return ['frecuencia' => $frec, 'pendientes' => $pend, 'lote' => array_slice($plan, 0, $lote), 'total' => count($plan), 'recientes' => $rec,
        'exactitud' => $d90 ? round(count(array_filter($d90, fn($r) => (float) $r['diferencia'] == 0)) / count($d90) * 100, 1) : null, 'contados_90' => count($d90)];
}
/** Guarda un lote contado. No toca el stock salvo que se pida «ajustar» (eso exige stock.editar y genera ingresos o egresos AJUSTE). */
function guardar_conteo(array $d, bool $puedeAjustar): array {
    $ren = array_values(array_filter((array) ($d['renglones'] ?? []), fn($r) => !empty($r['insumo']) && ($r['contado'] ?? '') !== ''));
    if (!$ren) throw new Fallo(422, 'Cargá al menos una cantidad contada');
    $ajustar = !empty($d['ajustar']);
    if ($ajustar && !$puedeAjustar) throw new Fallo(403, 'Ajustar el stock con el conteo requiere permiso para editar el stock');
    $usr = $GLOBALS['nucleo_usuario']['usuario'] ?? null; $res = []; $mas = []; $menos = [];
    foreach ($ren as $i => $r) {
        $ins = insumo((int) $r['insumo']) ?? throw new Fallo(422, 'Renglón ' . ($i + 1) . ': el insumo no existe');
        $cont = numero($r['contado'], "Contado ({$ins['codigo']})") ?? 0.0;
        $sis = (float) $ins['existencia']; $dif = round($cont - $sis, 3);
        $res[] = ['insumo' => (int) $ins['id'], 'codigo' => $ins['codigo'], 'sistema' => $sis, 'contado' => $cont, 'diferencia' => $dif, 'nota' => texto($r['nota'] ?? null, 200, false, 'Nota')];
        if ($ajustar && $dif > 0) $mas[] = ['insumo' => (int) $ins['id'], 'cantidad' => $dif, 'precio' => $ins['precio']];
        if ($ajustar && $dif < 0) $menos[] = ['insumo' => (int) $ins['id'], 'cantidad' => -$dif];
    }
    $aj = []; $ing = $mas ? mover(['tipo' => 'ingreso', 'origen' => 'AJUSTE', 'observacion' => 'Ajuste por conteo cíclico', 'lineas' => $mas])['clave'] : null;
    $egr = $menos ? mover(['tipo' => 'egreso', 'motivo' => 'AJUSTE', 'observacion' => 'Ajuste por conteo cíclico', 'lineas' => $menos])['clave'] : null;
    $aj = array_values(array_filter([$ing, $egr]));
    $ahora = date('Y-m-d H:i:s');
    foreach ($res as $r) q('INSERT INTO stock_conteo (insumo_id, fecha, sistema, contado, diferencia, nota, ajuste, usuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',   // ajuste = el comprobante que corrigió ESE renglón
        [$r['insumo'], $ahora, $r['sistema'], $r['contado'], $r['diferencia'], $r['nota'], $ajustar ? ($r['diferencia'] > 0 ? $ing : ($r['diferencia'] < 0 ? $egr : null)) : null, $usr]);
    asentar('stock.conteo', ['renglones' => count($res), 'con_diferencia' => count(array_filter($res, fn($r) => $r['diferencia'] != 0)), 'ajustes' => $aj]);
    return ['renglones' => $res, 'ajustes' => $aj];
}

// ------------------------------------------------------------------ reportes de stock

const REPORTES = ['bajo_minimo' => 'Stock bajo mínimo', 'ingresos' => 'Ingresos del período', 'egresos' => 'Egresos del período',
    'categoria' => 'Stock por categoría', 'por_ot' => 'Egresos por orden de trabajo', 'inventario' => 'Inventario total'];

/** Filtros comunes: categoría, subcategoría, proveedor, texto, desde/hasta (AAAA-MM-DD). */
function filtros_rep(array $q, string $al = 'i'): array {
    $w = []; $a = [];
    if (!empty($q['categoria'])) { $w[] = "$al.categoria = ?"; $a[] = (string) $q['categoria']; }
    if (!empty($q['subcategoria'])) { $w[] = "$al.subcategoria = ?"; $a[] = (string) $q['subcategoria']; }
    if (($t = trim((string) ($q['q'] ?? ''))) !== '') { $w[] = "($al.codigo LIKE ? OR $al.descripcion LIKE ?)"; array_push($a, "%$t%", "%$t%"); }
    return [$w, $a];
}
function rango(array $q): array {
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($q['desde'] ?? '')) ? $q['desde'] : date('Y-m-01');
    $h = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($q['hasta'] ?? '')) ? $q['hasta'] : date('Y-m-d');
    return $d <= $h ? [$d, $h] : [$h, $d];
}
function reporte(string $tipo, array $q = []): array {
    if (!isset(REPORTES[$tipo])) throw new Fallo(404, 'Reportes: ' . implode(', ', array_keys(REPORTES)));
    [$w, $a] = filtros_rep($q); [$d, $h] = rango($q);
    $W = fn(array $x) => $x ? ' AND ' . implode(' AND ', $x) : '';
    if ($tipo === 'bajo_minimo' || $tipo === 'inventario') {
        $f = filas('SELECT i.codigo, i.descripcion, i.categoria, i.subcategoria, i.unidad, i.existencia, i.minimo, i.precio, ROUND(i.existencia * COALESCE(i.precio, 0), 2) AS valor
            FROM stock_insumo i WHERE i.activo = 1' . ($tipo === 'bajo_minimo' ? ' AND i.minimo > 0 AND i.existencia < i.minimo' : '') . $W($w) . ' ORDER BY i.categoria, i.subcategoria, i.descripcion', $a);
        $cols = ['codigo' => 'Código', 'descripcion' => 'Descripción', 'categoria' => 'Categoría', 'subcategoria' => 'Subcategoría', 'unidad' => 'Unidad', 'existencia' => 'Stock', 'minimo' => 'Mínimo', 'precio' => 'Precio', 'valor' => 'Valor'];
        if ($tipo === 'bajo_minimo') { foreach ($f as &$r) $r['falta'] = round((float) $r['minimo'] - (float) $r['existencia'], 3); unset($r); $cols['falta'] = 'Falta'; }
        return ['tipo' => $tipo, 'titulo' => REPORTES[$tipo], 'columnas' => $cols, 'filas' => $f, 'totales' => ['renglones' => count($f), 'valor' => round(array_sum(array_column($f, 'valor')), 2)]];
    }
    if ($tipo === 'categoria') {
        $f = filas('SELECT i.categoria, COUNT(*) AS insumos, SUM(i.existencia < i.minimo AND i.minimo > 0) AS bajo_minimo, ROUND(SUM(i.existencia * COALESCE(i.precio, 0)), 2) AS valor
            FROM stock_insumo i WHERE i.activo = 1' . $W($w) . ' GROUP BY i.categoria ORDER BY valor DESC', $a);
        return ['tipo' => $tipo, 'titulo' => REPORTES[$tipo], 'columnas' => ['categoria' => 'Categoría', 'insumos' => 'Insumos', 'bajo_minimo' => 'Bajo mínimo', 'valor' => 'Valor'],
            'filas' => $f, 'totales' => ['renglones' => count($f), 'valor' => round(array_sum(array_column($f, 'valor')), 2)]];
    }
    $w[] = 'm.fecha BETWEEN ? AND ?'; array_push($a, $d, $h);
    if (!empty($q['proveedor'])) { $w[] = 'm.proveedor_id = ?'; $a[] = (int) $q['proveedor']; }
    if ($tipo === 'por_ot') {
        $w[] = "m.tipo = 'egreso' AND m.orden IS NOT NULL";
        $f = filas('SELECT m.orden, MAX(m.orden_numero) AS orden_numero, MAX(m.destino_nombre) AS destino, MIN(m.fecha) AS primera, MAX(m.fecha) AS ultima, COUNT(*) AS renglones,
            ROUND(SUM(m.cantidad * COALESCE(m.precio, 0)), 2) AS costo FROM stock_movimiento m JOIN stock_insumo i ON i.id = m.insumo_id WHERE ' . implode(' AND ', $w) . ' GROUP BY m.orden ORDER BY costo DESC LIMIT 1000', $a);
        return ['tipo' => $tipo, 'titulo' => REPORTES[$tipo], 'desde' => $d, 'hasta' => $h,
            'columnas' => ['orden_numero' => 'Orden', 'destino' => 'Servicio', 'primera' => 'Primer egreso', 'ultima' => 'Último egreso', 'renglones' => 'Renglones', 'costo' => 'Costo'],
            'filas' => $f, 'totales' => ['renglones' => count($f), 'valor' => round(array_sum(array_column($f, 'costo')), 2)]];
    }
    $t = $tipo === 'ingresos' ? 'ingreso' : 'egreso'; $w[] = 'm.tipo = ?'; $a[] = $t;
    $f = filas('SELECT m.clave, m.fecha, i.codigo, i.descripcion, i.categoria, i.unidad, m.cantidad, m.precio, ROUND(m.cantidad * COALESCE(m.precio, 0), 2) AS importe, '
        . ($t === 'ingreso' ? 'm.origen, p.nombre AS proveedor, m.comprobante, m.orden_compra, m.expediente' : 'm.motivo, m.orden_numero, m.destino_nombre, m.solicitante')
        . ' FROM stock_movimiento m JOIN stock_insumo i ON i.id = m.insumo_id LEFT JOIN stock_proveedor p ON p.id = m.proveedor_id WHERE ' . implode(' AND ', $w) . ' ORDER BY m.fecha, m.clave, m.renglon LIMIT 5000', $a);
    $cols = ['fecha' => 'Fecha', 'clave' => 'Comprobante', 'codigo' => 'Código', 'descripcion' => 'Descripción', 'categoria' => 'Categoría', 'cantidad' => 'Cantidad', 'unidad' => 'Unidad', 'precio' => 'Precio', 'importe' => 'Importe']
        + ($t === 'ingreso' ? ['origen' => 'Origen', 'proveedor' => 'Proveedor', 'comprobante' => 'Remito o factura', 'orden_compra' => 'Orden de compra', 'expediente' => 'Expediente']
            : ['motivo' => 'Motivo', 'orden_numero' => 'Orden', 'destino_nombre' => 'Servicio', 'solicitante' => 'Retiró']);
    return ['tipo' => $tipo, 'titulo' => REPORTES[$tipo], 'desde' => $d, 'hasta' => $h, 'columnas' => $cols, 'filas' => $f,
        'totales' => ['renglones' => count($f), 'valor' => round(array_sum(array_column($f, 'importe')), 2)]];
}
/** Datos de los gráficos de «Reportes de Stock». «orden» = limita los egresos por categoría a esa orden. */
function graficos(array $q = []): array {
    [$d, $h] = rango($q); [$w, $a] = filtros_rep($q);
    $base = ' FROM stock_movimiento m JOIN stock_insumo i ON i.id = m.insumo_id LEFT JOIN stock_proveedor p ON p.id = m.proveedor_id WHERE m.fecha BETWEEN ? AND ?' . ($w ? ' AND ' . implode(' AND ', $w) : '');
    $A = [$d, $h, ...$a];
    $imp = 'ROUND(SUM(m.cantidad * COALESCE(m.precio, 0)), 2)';
    $k = fila("SELECT $imp AS x" . $base . " AND m.tipo = 'ingreso'", $A)['x'] ?? 0;
    $e = fila("SELECT $imp AS x" . $base . " AND m.tipo = 'egreso'", $A)['x'] ?? 0;
    $ots = (int) valor('SELECT COUNT(DISTINCT m.orden)' . $base . " AND m.tipo = 'egreso' AND m.orden IS NOT NULL", $A);
    $topIns = filas("SELECT i.codigo, i.descripcion, i.unidad, SUM(m.cantidad) AS cantidad, $imp AS importe" . $base . " AND m.tipo = 'egreso' GROUP BY i.id ORDER BY cantidad DESC LIMIT 10", $A);
    $topProv = filas("SELECT p.nombre, $imp AS importe, COUNT(DISTINCT m.clave) AS ingresos" . $base . " AND m.tipo = 'ingreso' AND p.id IS NOT NULL GROUP BY p.id ORDER BY importe DESC LIMIT 10", $A);
    $ordenF = trim((string) ($q['orden'] ?? ''));
    $porCat = filas("SELECT i.categoria, $imp AS importe, SUM(m.cantidad) AS cantidad" . $base . " AND m.tipo = 'egreso'" . ($ordenF !== '' ? ' AND (m.orden = ? OR m.orden_numero = ?)' : '') . ' GROUP BY i.categoria ORDER BY importe DESC',
        $ordenF !== '' ? [...$A, $ordenF, $ordenF] : $A);
    $topOt = filas("SELECT m.orden, MAX(m.orden_numero) AS orden_numero, MAX(m.destino_nombre) AS destino, $imp AS importe" . $base . " AND m.tipo = 'egreso' AND m.orden IS NOT NULL GROUP BY m.orden ORDER BY importe DESC LIMIT 10", $A);
    $mes = filas("SELECT DATE_FORMAT(m.fecha, '%Y-%m') AS mes, ROUND(SUM(CASE WHEN m.tipo = 'ingreso' THEN m.cantidad * COALESCE(m.precio, 0) ELSE 0 END), 2) AS ingresos,
        ROUND(SUM(CASE WHEN m.tipo = 'egreso' THEN m.cantidad * COALESCE(m.precio, 0) ELSE 0 END), 2) AS egresos" . $base . ' GROUP BY mes ORDER BY mes', $A);
    [$w2, $a2] = filtros_rep($q);
    $stockCat = filas('SELECT i.categoria, ROUND(SUM(i.existencia * COALESCE(i.precio, 0)), 2) AS valor, COUNT(*) AS insumos FROM stock_insumo i WHERE i.activo = 1' . ($w2 ? ' AND ' . implode(' AND ', $w2) : '') . ' GROUP BY i.categoria ORDER BY valor DESC', $a2);
    return ['desde' => $d, 'hasta' => $h, 'kpis' => ['ingresos' => (float) $k, 'egresos' => (float) $e, 'ots' => $ots, 'insumo_top' => $topIns[0] ?? null, 'proveedor_top' => $topProv[0] ?? null],
        'egresos_categoria' => $porCat, 'top_ot' => $topOt, 'por_mes' => $mes, 'top_proveedores' => $topProv, 'top_insumos' => $topIns, 'stock_categoria' => $stockCat];
}

// ------------------------------------------------------------------ pedido por correo (HTML y planilla .xlsx, como «Reenviar HTML / XLSX» del sistema anterior)

/** Planilla .xlsx mínima (Office Open XML, una hoja, textos en línea) sin bibliotecas: la abren Excel, LibreOffice y Google Sheets. */
function xlsx(array $filas, string $hoja = 'Pedido'): string {
    if (!class_exists(\ZipArchive::class)) throw new Fallo(501, 'Falta la extensión zip de PHP para armar la planilla');
    $x = fn($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = function (int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };
    $rows = '';
    foreach (array_values($filas) as $r => $f) {
        $rows .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($f) as $c => $v) {
            $ref = $col($c) . ($r + 1);
            $rows .= is_int($v) || is_float($v) ? "<c r=\"$ref\"" . ($r === 0 ? ' s="1"' : '') . "><v>$v</v></c>"
                : "<c r=\"$ref\" t=\"inlineStr\"" . ($r === 0 ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
        }
        $rows .= '</row>';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $z = new \ZipArchive(); $z->open($tmp, \ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $x(mb_substr($hoja, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
    $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rows . '</sheetData></worksheet>');
    $z->close(); $b = (string) file_get_contents($tmp); @unlink($tmp);
    return $b;
}
/** Filas de la planilla del pedido: cabecera del pedido y renglones. */
function filas_pedido(array $p): array {
    $f = [['Pedido', $p['numero']], ['Estado', $p['estado']], ['Prioridad', $p['prioridad_texto'] . ' (banda ' . $p['letra'] . ')'],
        ['Fundamento de la prioridad', (string) ($p['fundamento'] ?? '')], ['Orden de trabajo', (string) ($p['orden_numero'] ?? '')], ['Equipo o instalación', (string) ($p['activo_nombre'] ?? '')],
        ['Motivo', (string) $p['motivo']], ['Destino', (string) ($p['destino'] ?? '')], ['Se necesita para', (string) ($p['necesario'] ?? '')], ['Proveedor sugerido', (string) ($p['proveedor'] ?? '')],
        ['Observaciones', (string) ($p['observaciones'] ?? '')], [], ['Renglón', 'Código', 'Descripción', 'Cantidad', 'Unidad', 'Precio de referencia', 'Nota']];
    foreach ($p['lineas'] as $l) $f[] = [(int) $l['renglon'], $l['codigo'], $l['descripcion'], (float) $l['cantidad'], $l['unidad'], $l['precio'] !== null ? (float) $l['precio'] : '', (string) ($l['nota'] ?? '')];
    return $f;
}
/** Enlace que abre el pedido en la aplicación (lo lleva el código QR): dirección pública del nodo (Configuración › Correo) + #panol_insumos?pedido=… */
function enlace_pedido(string $numero): ?string {
    $base = rtrim((string) (\Nucleo\correo_cfg()['url_app'] ?? ''), '/');
    return $base !== '' ? $base . '/#panol_insumos?pedido=' . rawurlencode($numero) : null;
}
function planilla_pedido(int $id): array {
    $p = pedido($id) ?? throw new Fallo(404, 'No existe el pedido');
    return ['nombre' => $p['numero'] . '.xlsx', 'tipo' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'base64' => base64_encode(xlsx(filas_pedido($p), $p['numero']))];
}
/** Envía el pedido por correo (cuerpo HTML; planilla .xlsx adjunta si se pide) al proveedor o a las direcciones que se indiquen. */
function enviar_pedido(int $id, array $d): array {
    $p = pedido($id) ?? throw new Fallo(404, 'No existe el pedido');
    $para = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) ($d['para'] ?? '') ?: (string) ($p['proveedor_email'] ?? ''))))));
    if (!$para) throw new Fallo(422, 'Escribí a qué correo enviarlo (el proveedor no tiene uno cargado)');
    if (count($para) > 5) throw new Fallo(422, 'Hasta 5 destinatarios');
    foreach ($para as $e) if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw new Fallo(422, "«$e» no es un correo");
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $n = \Nucleo\nodo()['nombre'] ?? 'iProy';
    $cab = ''; foreach (array_slice(filas_pedido($p), 1, 10) as [$k, $v]) if ($v !== '') $cab .= '<tr><td style="padding:6px 10px;color:#5b6b7f;white-space:nowrap">' . $h($k) . '</td><td style="padding:6px 10px;font-weight:600">' . $h($v) . '</td></tr>';
    $ren = ''; foreach ($p['lineas'] as $l) $ren .= '<tr><td style="padding:6px 10px;border-top:1px solid #e6edf5">' . $h($l['codigo']) . '</td><td style="padding:6px 10px;border-top:1px solid #e6edf5">' . $h($l['descripcion']) . ($l['nota'] ? ' · ' . $h($l['nota']) : '')
        . '</td><td style="padding:6px 10px;border-top:1px solid #e6edf5;text-align:right">' . $h(rtrim(rtrim(number_format((float) $l['cantidad'], 3, ',', '.'), '0'), ',')) . ' ' . $h($l['unidad']) . '</td></tr>';
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"></head><body style="margin:0;background:#eef4fb;font-family:Segoe UI,Roboto,Arial,sans-serif"><div style="max-width:640px;margin:0 auto;padding:16px">'
        . '<div style="background:#6d4c41;color:#fff;border-radius:12px 12px 0 0;padding:16px 20px;font-size:18px;font-weight:700">Pedido de insumos ' . $h($p['numero']) . '</div>'
        . '<div style="background:#fff;border:1px solid #dbe6f3;border-top:0;border-radius:0 0 12px 12px;padding:10px"><table style="border-collapse:collapse;font-size:14px">' . $cab . '</table>'
        . '<table style="border-collapse:collapse;width:100%;font-size:14px;margin-top:10px"><tr style="background:#f3f6fa"><th style="padding:6px 10px;text-align:left">Código</th><th style="padding:6px 10px;text-align:left">Descripción</th><th style="padding:6px 10px;text-align:right">Cantidad</th></tr>' . $ren . '</table></div>'
        . ($p['enlace'] ? '<div style="background:#fff;border:1px solid #dbe6f3;border-radius:12px;padding:12px;margin-top:10px;text-align:center;font-size:13px;color:#43536b"><img src="cid:qr-pedido" width="150" height="150" alt="Código QR del pedido" style="display:block;margin:0 auto 6px">'
            . 'Escaneá el código o abrí <a href="' . $h($p['enlace']) . '">el pedido en iProy</a></div>' : '')
        . '<p style="color:#7b8a9c;font-size:12px;text-align:center">' . $h($n) . ' · enviado el ' . date('d/m/Y H:i') . '</p></div></body></html>';
    $texto = "Pedido de insumos {$p['numero']}\n" . implode("\n", array_map(fn($l) => "- {$l['codigo']} {$l['descripcion']}: " . (float) $l['cantidad'] . " {$l['unidad']}", $p['lineas'])) . "\n";
    $adj = !empty($d['planilla']) ? [[$p['numero'] . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', xlsx(filas_pedido($p), $p['numero'])]] : [];
    if ($p['enlace']) { $adj[] = ['qr-' . $p['numero'] . '.png', 'image/png', \Nucleo\qr_png($p['enlace'], 5), 'qr-pedido']; $texto .= "\nAbrir el pedido: {$p['enlace']}\n"; }
    foreach ($para as $e) \Nucleo\enviar_correo($e, "Pedido de insumos {$p['numero']} · {$p['prioridad_texto']}", $texto, $html, $adj);
    q('UPDATE stock_pedido SET enviado = NOW(), enviado_a = ? WHERE id = ?', [mb_substr(implode(', ', $para), 0, 300), $id]);
    asentar('stock.pedido_enviado', ['para' => count($para), 'planilla' => (bool) $adj], $p['numero']);
    return ['pedido' => pedido($id), 'enviados' => count($para), 'sin_qr' => empty($p['enlace'])];   // sin «Dirección de la app» (Configuración › Correo) el correo sale sin QR ni enlace
}
