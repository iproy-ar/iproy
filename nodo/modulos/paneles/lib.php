<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Paneles de instalaciones (2.0.0, Ola 4: Paneles IoT).
 *
 *   bus telemetria/valor  ──▶ pan_punto (último valor y nombre legible de cada señal) + pan_serie (detalle fino) + pan_hora (historial largo)
 *   bus telemetria/serie  ──▶ pan_serie + pan_hora (historial traído en lote desde una fuente; no pasa por alarmas)
 *   bus alarmas/cambio    ──▶ pan_punto.alarma
 *   bus equipos/evento    ──▶ pan_evento (eventos y alarmas que informa el propio equipo, con su inicio y su fin)
 *
 * La portada muestra ÁREAS (Quirófanos, Energía, Ósmosis…). Quién ve un área lo dicen sus reglas: rol, perfil, organización
 * o usuario (cada regla exige todo lo que nombra; alcanza con que se cumpla una). Dentro de cada área hay paneles de un TIPO:
 *   tarjetas  señales sueltas con valor, estado y curva de 24 h (el panel de siempre)
 *   energia   tablero de un medidor: curvas, períodos, referencias en semáforo e informe imprimible / Word
 *   equipos   grilla de equipos (UTAs, enfriadoras…) por sector, con detalle, tendencias y análisis de fallas
 *   sinoptico esquema de la instalación con valores en vivo, variables, tendencias y eventos
 *   eventos   eventos y alarmas de los equipos: indicadores, filtros, lista y ranking
 * El módulo no lee tablas de telemetría ni de alarmas: todo le llega por el bus.
 */
declare(strict_types=1);

namespace Modulos\Paneles;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, valor, pdo, asentar, puede};

const ROLES = ['GM' => 'Gestor', 'EJ' => 'Ejecutor', 'SO' => 'Solicitante', 'FT' => 'Fuente técnica', 'CI' => 'Consulta'];
const ANUNCIA = ['no-reconocida', 'reconocida', 'rtn-no-reconocida', 'enclavada'];
const TIPOS = ['tarjetas' => 'Tarjetas', 'energia' => 'Energía', 'equipos' => 'Equipos', 'sinoptico' => 'Sinóptico', 'eventos' => 'Eventos'];
const REGLA = ['rol', 'perfil', 'organizacion', 'usuario'];
const PLANTILLAS = ['osmosis', 'lista'];
const RX_CLAVE = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

function paso(array $cfg): int { return max(60, min(3600, (int) ($cfg['paso_s'] ?? 300))); }
function detalle_dias(array $cfg): int { return max(2, min(120, (int) ($cfg['detalle_dias'] ?? 35))); }
function ts_de(mixed $t): int { $x = is_numeric($t) ? (int) $t : (strtotime((string) $t) ?: time()); return min($x, time() + 300); }

// ------------------------------------------------------------------------------------------------ lo que llega por el bus
function anotar_valor(array $d, array $cfg): void {
    $id = (string) ($d['activo_id'] ?? ''); $p = (string) ($d['punto'] ?? '');
    if ($id === '' || $p === '' || !is_numeric($d['valor'] ?? null)) return;
    $v = (float) $d['valor']; $t = strtotime((string) ($d['ts'] ?? '')) ?: time();
    if ($t > time() + 300) $t = time();                                       // un reloj adelantado no escribe en el futuro
    punto_ultimo($id, $p, $d, $v, date('Y-m-d H:i:s', $t));
    $paso = paso($cfg);
    foreach ([['pan_serie', $paso], ['pan_hora', 3600]] as [$tb, $s])
        q("INSERT INTO $tb (activo_id, punto, ts, n, suma, minimo, maximo) VALUES (?,?,?,1,?,?,?)
           ON DUPLICATE KEY UPDATE n = n + 1, suma = suma + VALUES(suma), minimo = LEAST(minimo, VALUES(minimo)), maximo = GREATEST(maximo, VALUES(maximo))",
          [$id, mb_substr($p, 0, 40), date('Y-m-d H:i:s', intdiv($t, $s) * $s), $v, $v, $v]);
}
function punto_ultimo(string $id, string $p, array $d, float $v, string $ts): void {
    q('INSERT INTO pan_punto (activo_id, punto, activo, nombre, etiqueta, valor, unidad, ts) VALUES (?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE activo = VALUES(activo), nombre = VALUES(nombre), etiqueta = COALESCE(VALUES(etiqueta), etiqueta), valor = IF(VALUES(ts) >= ts, VALUES(valor), valor),
       unidad = IF(VALUES(ts) >= ts, VALUES(unidad), unidad), ts = GREATEST(ts, VALUES(ts))',
      [$id, mb_substr($p, 0, 40), isset($d['activo']) ? mb_substr((string) $d['activo'], 0, 64) : null, isset($d['nombre']) ? mb_substr((string) $d['nombre'], 0, 200) : null,
       ($e = trim((string) ($d['etiqueta'] ?? ''))) !== '' ? mb_substr($e, 0, 80) : null, $v, mb_substr((string) ($d['unidad'] ?? ''), 0, 20), $ts]);
}
/** Historial traído en lote (telemetria/serie): se resume por intervalo en PHP y entra en pocas sentencias. */
function anotar_serie(array $d, array $cfg): int {
    $id = (string) ($d['activo_id'] ?? ''); $p = mb_substr((string) ($d['punto'] ?? ''), 0, 40); $pares = (array) ($d['pares'] ?? []);
    if ($id === '' || $p === '' || !$pares) return 0;
    $paso = paso($cfg); $lim = time() - detalle_dias($cfg) * 86400; $B = ['pan_serie' => [], 'pan_hora' => []]; $ult = null; $n = 0;
    foreach ($pares as $x) {
        if (!is_array($x) || count($x) < 2 || !is_numeric($x[1])) continue;
        $t = ts_de($x[0]); $v = (float) $x[1]; $n++;
        if ($ult === null || $t > $ult[0]) $ult = [$t, $v];
        foreach (['pan_serie' => $paso, 'pan_hora' => 3600] as $tb => $s) {
            if ($tb === 'pan_serie' && $t < $lim) continue;                     // el detalle fino solo guarda las últimas semanas
            $b = intdiv($t, $s) * $s; $a = &$B[$tb][$b];
            $a = $a === null ? [1, $v, $v, $v] : [$a[0] + 1, $a[1] + $v, min($a[2], $v), max($a[3], $v)]; unset($a);
        }
    }
    foreach ($B as $tb => $l) foreach (array_chunk($l, 400, true) as $lote) {
        $vals = []; $args = [];
        foreach ($lote as $b => $a) { $vals[] = '(?,?,?,?,?,?,?)'; array_push($args, $id, $p, date('Y-m-d H:i:s', (int) $b), $a[0], $a[1], $a[2], $a[3]); }
        q("INSERT INTO $tb (activo_id, punto, ts, n, suma, minimo, maximo) VALUES " . implode(',', $vals) . '
           ON DUPLICATE KEY UPDATE n = n + VALUES(n), suma = suma + VALUES(suma), minimo = LEAST(minimo, VALUES(minimo)), maximo = GREATEST(maximo, VALUES(maximo))', $args);
    }
    if ($ult) punto_ultimo($id, $p, $d, $ult[1], date('Y-m-d H:i:s', $ult[0]));   // si la señal nunca informó en vivo, queda elegible para un panel
    return $n;
}
function anotar_alarma(array $d): void {
    $id = (string) ($d['activo_id'] ?? ''); $p = (string) ($d['punto'] ?? ''); $a = (string) ($d['a'] ?? ''); $et = mb_substr((string) ($d['etiqueta'] ?? ''), 0, 80);
    if ($id === '' || $p === '') return;
    if (in_array($a, ANUNCIA, true)) q('UPDATE pan_punto SET alarma = ?, alarma_etiqueta = ?, alarma_prioridad = ? WHERE activo_id = ? AND punto = ?', [$a, $et, (int) ($d['prioridad'] ?? 4), $id, $p]);
    else q('UPDATE pan_punto SET alarma = NULL, alarma_etiqueta = NULL, alarma_prioridad = NULL WHERE activo_id = ? AND punto = ? AND (alarma_etiqueta = ? OR alarma_etiqueta IS NULL)', [$id, $p, $et]);
}
/** Evento o alarma informada por un equipo (equipos/evento): se guarda una vez y se actualiza si cambia (por ejemplo, cuando termina). */
function anotar_evento(array $d): void {
    $k = mb_substr(trim((string) ($d['clave'] ?? '')), 0, 120); $m = trim((string) ($d['mensaje'] ?? ''));
    if ($k === '' || $m === '') return;
    $f = fn($x) => ($x === null || $x === '') ? null : date('Y-m-d H:i:s', ts_de($x));
    $niv = in_array($d['nivel'] ?? '', ['alarma', 'evento'], true) ? $d['nivel'] : 'evento';
    q('INSERT INTO pan_evento (clave, activo, ts, fin, mensaje, categoria, nivel, fuente, recibido) VALUES (?,?,?,?,?,?,?,?,NOW())
       ON DUPLICATE KEY UPDATE activo = VALUES(activo), ts = VALUES(ts), fin = VALUES(fin), mensaje = VALUES(mensaje), categoria = VALUES(categoria), nivel = VALUES(nivel), recibido = NOW()',
      [$k, ($a = trim((string) ($d['activo'] ?? ''))) !== '' ? mb_substr($a, 0, 64) : null, $f($d['ts'] ?? null) ?? date('Y-m-d H:i:s'), $f($d['fin'] ?? null), mb_substr($m, 0, 300),
       ($c = trim((string) ($d['categoria'] ?? ''))) !== '' ? mb_substr($c, 0, 40) : null, $niv, ($o = (string) ($d['fuente'] ?? '')) !== '' ? mb_substr($o, 0, 40) : null]);
}

// ------------------------------------------------------------------------------------------------ permisos
function lista_csv(mixed $v): array { return array_values(array_unique(array_filter(array_map('trim', is_array($v) ? $v : (preg_split('/[,;\s]+/', (string) $v) ?: []))))); }

/** Perfil del usuario (módulo widgets, si está cargado). */
function perfil_de(array $u): ?string {
    static $c = [];
    $k = (string) ($u['usuario'] ?? ''); if ($k === '') return null;
    if (!array_key_exists($k, $c)) { $c[$k] = null; if (\Nucleo\modulo('widgets')) { \Nucleo\incluir('widgets'); $c[$k] = \Modulos\Widgets\perfil_de($k); } }
    return $c[$k];
}
/** Una regla se cumple si se cumple todo lo que nombra (rol, perfil, organización, usuario). Una regla vacía no da acceso. */
function cumple(array $r, array $u): bool {
    $hay = false;
    foreach (REGLA as $k) {
        $v = trim((string) ($r[$k] ?? '')); if ($v === '') continue; $hay = true;
        $de = match ($k) { 'rol' => (string) ($u['rol'] ?? ''), 'perfil' => (string) (perfil_de($u) ?? ''), 'organizacion' => (string) ($u['organizacion'] ?? ''), 'usuario' => (string) ($u['usuario'] ?? '') };
        if (mb_strtolower($v) !== mb_strtolower($de)) return false;
    }
    return $hay;
}
function reglas(mixed $j): array { $r = is_array($j) ? $j : (json_decode((string) ($j ?? ''), true) ?: []); return array_values(array_filter((array) $r, 'is_array')); }
/** ¿Ve el área? Quien configura paneles ve todas; si no, el área tiene que estar activa y, si tiene reglas, cumplir alguna. */
function ve_area(?array $a, ?array $u): bool {
    if (!$u) return false;
    if (puede('paneles.configurar', $u)) return true;
    if (!$a) return true;
    if (!(int) $a['activo']) return false;
    $rs = reglas($a['reglas']);
    if (!$rs) return true;
    foreach ($rs as $r) if (cumple($r, $u)) return true;
    return false;
}
function areas_idx(bool $otra = false): array { static $c = null; if ($c === null || $otra) { $c = []; foreach (filas('SELECT * FROM pan_area ORDER BY orden, nombre') as $a) $c[(int) $a['id']] = $a; } return $c; }

/** ¿Este usuario ve el panel? Primero su área; después las listas propias del panel (usuarios o roles; si no tiene ninguna, todos). */
function ve(array $p, ?array $u): bool {
    if (!$u) return false;
    if (puede('paneles.configurar', $u)) return true;
    if (!$p['activo']) return false;
    if (!empty($p['area_id']) && !ve_area(areas_idx()[(int) $p['area_id']] ?? null, $u)) return false;
    $us = lista_csv($p['usuarios'] ?? ''); $ro = lista_csv($p['roles'] ?? '');
    if ($us && in_array((string) $u['usuario'], $us, true)) return true;
    if ($ro && in_array((string) $u['rol'], $ro, true)) return true;
    return !$us && !$ro;
}

// ------------------------------------------------------------------------------------------------ áreas
function vista_area(array $a, bool $config): array {
    $v = ['id' => (int) $a['id'], 'clave' => $a['clave'], 'nombre' => $a['nombre'], 'descripcion' => $a['descripcion'], 'icono' => $a['icono'], 'color' => $a['color'], 'orden' => (int) $a['orden'], 'activo' => (bool) $a['activo']];
    if ($config) $v += ['reglas' => reglas($a['reglas']), 'modificado' => $a['modificado'], 'modificado_por' => $a['modificado_por']];
    return $v;
}
function guardar_area(?int $id, array $c, array $u): int {
    $clave = strtolower(trim((string) ($c['clave'] ?? '')));
    if (!preg_match(RX_CLAVE, $clave)) throw new Fallo(422, 'Clave del área: minúsculas, números, - y _ (hasta 40)');
    $nombre = trim((string) ($c['nombre'] ?? '')); if ($nombre === '' || mb_strlen($nombre) > 80) throw new Fallo(422, 'Nombre del área: de 1 a 80 caracteres');
    $color = (string) ($c['color'] ?? '#1e88e5'); if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) throw new Fallo(422, 'Color inválido');
    $icono = (string) ($c['icono'] ?? 'paneles'); if (!preg_match('/^[a-z0-9_-]{1,30}$/', $icono)) throw new Fallo(422, 'Ícono inválido');
    $rs = [];
    foreach ((array) ($c['reglas'] ?? []) as $r) {
        if (!is_array($r)) throw new Fallo(422, 'Cada regla es un objeto {rol, perfil, organizacion, usuario}');
        $l = []; foreach (REGLA as $k) { $v = trim((string) ($r[$k] ?? '')); if ($v === '') continue; if (mb_strlen($v) > 80) throw new Fallo(422, "Regla: «{$k}» de hasta 80 caracteres"); $l[$k] = $v; }
        if (isset($l['rol']) && $l['rol'] !== 'AD' && !isset(ROLES[$l['rol']])) throw new Fallo(422, "Rol desconocido: {$l['rol']}");
        if ($l) $rs[] = $l;
    }
    if (count($rs) > 100) throw new Fallo(422, 'Hasta 100 reglas por área');
    $otra = valor('SELECT id FROM pan_area WHERE clave = ?', [$clave]);
    if ($otra && (int) $otra !== $id) throw new Fallo(409, "Ya hay un área con la clave «{$clave}»");
    $d = ['clave' => $clave, 'nombre' => $nombre, 'descripcion' => mb_substr(trim((string) ($c['descripcion'] ?? '')), 0, 200) ?: null, 'icono' => $icono, 'color' => strtolower($color),
        'orden' => max(0, min(999, (int) ($c['orden'] ?? 50))), 'reglas' => $rs ? json_encode($rs, JSON_UNESCAPED_UNICODE) : null, 'activo' => !isset($c['activo']) || $c['activo'] ? 1 : 0, 'modificado_por' => (string) ($u['usuario'] ?? '?')];
    if ($id === null) { q('INSERT INTO pan_area (' . implode(',', array_keys($d)) . ', modificado) VALUES (' . implode(',', array_fill(0, count($d), '?')) . ', NOW())', array_values($d)); $id = (int) pdo()->lastInsertId(); }
    else q('UPDATE pan_area SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($d))) . ', modificado = NOW() WHERE id = ?', [...array_values($d), $id]);
    areas_idx(true);
    asentar('panel.area.guardada', ['clave' => $clave, 'reglas' => count($rs), 'activo' => $d['activo']], (string) $id);
    return $id;
}
function area_por(mixed $x): ?array {
    if ($x === null || $x === '' || $x === 0 || $x === '0') return null;
    foreach (areas_idx() as $a) if ((is_numeric($x) && (int) $a['id'] === (int) $x) || $a['clave'] === (string) $x) return $a;
    throw new Fallo(422, "No existe el área «{$x}»");
}

// ------------------------------------------------------------------------------------------------ paneles
function vista_dec(array $p): array { return $p['vista'] ? (json_decode((string) $p['vista'], true) ?: []) : []; }
/** Códigos de activo que nombra la configuración del panel (activo, activos y los de cada grupo). */
function activos_vista(array $v): array {
    $l = [];
    if (!empty($v['activo'])) $l[] = (string) $v['activo'];
    foreach ((array) ($v['activos'] ?? []) as $a) $l[] = (string) $a;
    foreach ((array) ($v['grupos'] ?? []) as $g) foreach ((array) ($g['activos'] ?? []) as $a) $l[] = (string) $a;
    return array_values(array_unique(array_filter($l, fn($x) => $x !== '')));
}
function validar_vista(string $tipo, mixed $v): ?array {
    if ($v === null || $v === '' || $v === []) return $tipo === 'tarjetas' ? null : throw new Fallo(422, 'Este tipo de panel necesita su configuración («vista»)');
    if (!is_array($v)) throw new Fallo(422, 'La configuración del panel es un objeto JSON');
    if (strlen(json_encode($v)) > 100000) throw new Fallo(422, 'La configuración del panel no puede pasar de 100 kB');
    $txt = fn($x, $max, $que) => is_scalar($x) && mb_strlen((string) $x) <= $max ? (string) $x : throw new Fallo(422, "{$que}: texto de hasta {$max} caracteres");
    foreach (activos_vista($v) as $a) $txt($a, 64, 'Código de activo');
    $rango = function ($r, $que) { if ($r === null) return; if (!is_array($r) || count($r) !== 2) throw new Fallo(422, "{$que}: [mínimo, máximo]");
        foreach ($r as $x) if ($x !== null && !is_numeric($x)) throw new Fallo(422, "{$que}: números"); };
    foreach ((array) ($v['referencias'] ?? []) as $pt => $r) { $txt($pt, 40, 'Señal de referencia'); if (!is_array($r)) throw new Fallo(422, 'Cada referencia es un objeto');
        $rango($r['verde'] ?? null, "Referencia {$pt} (verde)"); $rango($r['amarillo'] ?? null, "Referencia {$pt} (amarillo)"); }
    foreach ((array) ($v['curvas'] ?? []) as $c) { if (!is_array($c) || !isset($c['titulo'], $c['puntos']) || !is_array($c['puntos']) || !$c['puntos'] || count($c['puntos']) > 8) throw new Fallo(422, 'Cada curva: {titulo, puntos: [1 a 8 señales]}');
        $txt($c['titulo'], 80, 'Título de curva'); foreach ($c['puntos'] as $x) $txt($x, 105, 'Señal de la curva'); }
    if (count((array) ($v['curvas'] ?? [])) > 30) throw new Fallo(422, 'Hasta 30 curvas por panel');
    if ($tipo === 'energia' && empty($v['activo'])) throw new Fallo(422, 'El panel de energía necesita «activo» (el código del medidor)');
    if (in_array($tipo, ['equipos'], true) && !activos_vista($v)) throw new Fallo(422, 'El panel de equipos necesita «activos» o «grupos»');
    if ($tipo === 'sinoptico') { if (empty($v['activo'])) throw new Fallo(422, 'El sinóptico necesita «activo»'); if (!in_array($v['plantilla'] ?? 'lista', PLANTILLAS, true)) throw new Fallo(422, 'Plantilla del sinóptico: ' . implode(', ', PLANTILLAS)); }
    return $v;
}
function resolver_activo(string $x): ?string {
    if ($x === '') return null;
    return valor('SELECT activo_id FROM pan_punto WHERE activo_id = ? OR activo = ? ORDER BY activo_id = ? DESC LIMIT 1', [$x, $x, $x]) ?: null;
}
function guardar_panel(?int $id, array $c, array $u): int {
    $nombre = trim((string) ($c['nombre'] ?? ''));
    if ($nombre === '' || mb_strlen($nombre) > 80) throw new Fallo(422, 'Nombre del panel: de 1 a 80 caracteres');
    $color = (string) ($c['color'] ?? '#1e88e5'); if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) throw new Fallo(422, 'Color inválido');
    $roles = lista_csv($c['roles'] ?? []); foreach ($roles as $r) if (!isset(ROLES[$r])) throw new Fallo(422, "Rol desconocido: $r");
    $us = lista_csv($c['usuarios'] ?? []); foreach ($us as $x) if (!preg_match('/^[A-Za-z0-9._@-]{1,60}$/', $x)) throw new Fallo(422, "Usuario inválido: $x");
    if (count($us) > 200) throw new Fallo(422, 'Hasta 200 usuarios por panel');
    $tipo = (string) ($c['tipo'] ?? 'tarjetas'); if (!isset(TIPOS[$tipo])) throw new Fallo(422, 'Tipo de panel: ' . implode(', ', array_keys(TIPOS)));
    $vista = validar_vista($tipo, $c['vista'] ?? null);
    $area = area_por($c['area'] ?? ($c['area_id'] ?? null));
    $clave = isset($c['clave']) && $c['clave'] !== '' && $c['clave'] !== null ? strtolower((string) $c['clave']) : null;
    if ($clave !== null && !preg_match(RX_CLAVE, $clave)) throw new Fallo(422, 'Clave del panel: minúsculas, números, - y _ (hasta 40)');
    if ($clave !== null && ($o = valor('SELECT id FROM pan_panel WHERE clave = ?', [$clave])) && (int) $o !== $id) throw new Fallo(409, "Ya hay un panel con la clave «{$clave}»");
    $its = (array) ($c['items'] ?? []);
    if (count($its) > 60) throw new Fallo(422, 'Hasta 60 señales por panel');
    $limpios = [];
    foreach ($its as $n => $i) {
        $pt = (string) ($i['punto'] ?? ''); $aid = (string) ($i['activo_id'] ?? '');
        if ($aid === '' && !empty($i['activo'])) $aid = (string) (resolver_activo((string) $i['activo']) ?? '');
        if (!fila('SELECT 1 FROM pan_punto WHERE activo_id = ? AND punto = ?', [$aid, $pt])) throw new Fallo(422, "La señal «{$pt}» todavía no envió ningún valor: no se puede agregar");
        $t = trim((string) ($i['titulo'] ?? '')); if ($t === '') $t = $pt; if (mb_strlen($t) > 80) throw new Fallo(422, 'Título de la señal: hasta 80 caracteres');
        $num = fn($v) => ($v === null || $v === '') ? null : (is_numeric($v) ? (float) $v : throw new Fallo(422, "«{$t}»: mínimo y máximo son números"));
        $mi = $num($i['minimo'] ?? null); $ma = $num($i['maximo'] ?? null);
        if ($mi !== null && $ma !== null && $mi > $ma) throw new Fallo(422, "«{$t}»: el mínimo no puede ser mayor que el máximo");
        $limpios[] = [$aid, $pt, $t, $mi, $ma, max(0, min(4, (int) ($i['decimales'] ?? 1))), $n];
    }
    $d = ['nombre' => $nombre, 'descripcion' => mb_substr(trim((string) ($c['descripcion'] ?? '')), 0, 200) ?: null, 'color' => strtolower($color), 'orden' => max(0, min(999, (int) ($c['orden'] ?? 50))),
        'roles' => implode(',', $roles), 'usuarios' => $us ? implode(',', $us) : null, 'activo' => !isset($c['activo']) || $c['activo'] ? 1 : 0, 'modificado_por' => (string) ($u['usuario'] ?? '?'),
        'area_id' => $area ? (int) $area['id'] : null, 'tipo' => $tipo, 'vista' => $vista ? json_encode($vista, JSON_UNESCAPED_UNICODE) : null, 'clave' => $clave];
    $db = pdo(); $db->beginTransaction();
    try {
        if ($id === null) { q('INSERT INTO pan_panel (' . implode(',', array_keys($d)) . ', modificado) VALUES (' . implode(',', array_fill(0, count($d), '?')) . ', NOW())', array_values($d)); $id = (int) $db->lastInsertId(); }
        else q('UPDATE pan_panel SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($d))) . ', modificado = NOW() WHERE id = ?', [...array_values($d), $id]);
        if (array_key_exists('items', $c)) {
            q('DELETE FROM pan_item WHERE panel_id = ?', [$id]);
            foreach ($limpios as $l) q('INSERT INTO pan_item (panel_id, activo_id, punto, titulo, minimo, maximo, decimales, orden) VALUES (?,?,?,?,?,?,?,?)', [$id, ...$l]);
        }
        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }
    asentar('panel.guardado', ['nombre' => $nombre, 'tipo' => $tipo, 'area' => $area['clave'] ?? null, 'senales' => count($limpios), 'roles' => $roles, 'usuarios' => count($us)], (string) $id);
    return $id;
}

function estado_item(array $i, array $cfg): string {
    if ($i['ts'] === null) return 'sin-datos';
    if ($i['alarma'] !== null) return 'alarma';
    if (strtotime((string) $i['ts']) < time() - 60 * max(1, (int) ($cfg['sin_datos_min'] ?? 30))) return 'sin-datos';
    $v = (float) $i['valor'];
    if (($i['minimo'] !== null && $v < (float) $i['minimo']) || ($i['maximo'] !== null && $v > (float) $i['maximo'])) return 'fuera';
    return 'ok';
}
function chispa(string $aid, string $p): array {
    return array_map(fn($s) => round((float) $s['v'], 4), filas("SELECT FLOOR(UNIX_TIMESTAMP(ts) / 3600) h, SUM(suma) / SUM(n) v FROM pan_serie
        WHERE activo_id = ? AND punto = ? AND ts > NOW() - INTERVAL 24 HOUR GROUP BY h ORDER BY h", [$aid, $p]));
}
function items(int $panel, array $cfg, bool $chispa = true): array {
    $l = filas('SELECT i.*, p.valor, p.unidad, p.ts, p.alarma, p.alarma_etiqueta, p.alarma_prioridad, p.nombre equipo, p.activo codigo, p.etiqueta FROM pan_item i
        LEFT JOIN pan_punto p ON p.activo_id = i.activo_id AND p.punto = i.punto WHERE i.panel_id = ? ORDER BY i.orden, i.id', [$panel]);
    $r = [];
    foreach ($l as $i) {
        $x = ['id' => (int) $i['id'], 'titulo' => $i['titulo'], 'activo_id' => $i['activo_id'], 'punto' => $i['punto'], 'equipo' => $i['equipo'], 'codigo' => $i['codigo'], 'etiqueta' => $i['etiqueta'],
            'valor' => $i['ts'] !== null ? (float) $i['valor'] : null, 'unidad' => (string) ($i['unidad'] ?? ''), 'ts' => $i['ts'], 'minimo' => $i['minimo'] !== null ? (float) $i['minimo'] : null,
            'maximo' => $i['maximo'] !== null ? (float) $i['maximo'] : null, 'decimales' => (int) $i['decimales'], 'orden' => (int) $i['orden'],
            'estado' => estado_item($i, $cfg), 'alarma' => $i['alarma'], 'alarma_etiqueta' => $i['alarma_etiqueta'], 'alarma_prioridad' => $i['alarma_prioridad'] !== null ? (int) $i['alarma_prioridad'] : null];
        if ($chispa) $x['chispa'] = chispa($i['activo_id'], $i['punto']);        // curva chica de las últimas 24 h (un valor por hora)
        $r[] = $x;
    }
    return $r;
}
/** Todas las señales de los activos que nombra el panel (equipos, sinóptico, energía), con su estado. Los rangos salen de vista.rangos. */
function puntos_panel(array $v, array $cfg): array {
    $cods = activos_vista($v); if (!$cods) return [];
    $rg = (array) ($v['rangos'] ?? []); $ref = (array) ($v['referencias'] ?? []);
    $l = filas('SELECT activo_id, punto, activo codigo, nombre equipo, etiqueta, valor, unidad, ts, alarma, alarma_etiqueta FROM pan_punto WHERE activo IN (' . implode(',', array_fill(0, count($cods), '?')) . ') ORDER BY activo, punto', $cods);
    return array_map(function ($p) use ($cfg, $rg, $ref) {
        $r = $rg[$p['punto']] ?? ($ref[$p['punto']]['amarillo'] ?? null);
        $p['minimo'] = is_array($r) && isset($r[0]) ? (float) $r[0] : null; $p['maximo'] = is_array($r) && isset($r[1]) ? (float) $r[1] : null;
        $p['valor'] = (float) $p['valor']; $p['estado'] = estado_item($p, $cfg);
        return $p;
    }, $l);
}
function alarmas_activas(array $cods): int {
    if (!$cods) return 0;
    return (int) valor("SELECT COUNT(*) FROM pan_evento WHERE nivel = 'alarma' AND fin IS NULL AND activo IN (" . implode(',', array_fill(0, count($cods), '?')) . ')', $cods);
}
function vista_panel(array $p, array $cfg, bool $config, bool $chispa = true, bool $puntos = true): array {
    $v = vista_dec($p); $tipo = (string) ($p['tipo'] ?? 'tarjetas');
    $it = items((int) $p['id'], $cfg, $chispa && in_array($tipo, ['tarjetas', 'energia'], true));
    $pt = in_array($tipo, ['equipos', 'sinoptico', 'energia'], true) ? puntos_panel($v, $cfg) : [];
    $cu = ['ok' => 0, 'fuera' => 0, 'alarma' => 0, 'sin-datos' => 0];
    foreach ($tipo === 'tarjetas' || $tipo === 'energia' ? $it : $pt as $i) $cu[$i['estado']]++;
    $cods = $tipo === 'eventos' && !activos_vista($v) ? null : activos_vista($v);
    $act = $cods === null ? (int) valor("SELECT COUNT(*) FROM pan_evento WHERE nivel = 'alarma' AND fin IS NULL") : alarmas_activas($cods);
    $a = !empty($p['area_id']) ? (areas_idx()[(int) $p['area_id']] ?? null) : null;
    $r = ['id' => (int) $p['id'], 'clave' => $p['clave'] ?? null, 'nombre' => $p['nombre'], 'descripcion' => $p['descripcion'], 'color' => $p['color'], 'orden' => (int) $p['orden'], 'activo' => (bool) $p['activo'],
        'tipo' => $tipo, 'area' => $a['clave'] ?? null, 'vista' => $v ?: null, 'items' => $it, 'cuenta' => $cu, 'alarmas_activas' => $act];
    if ($puntos) $r['puntos'] = $pt;
    if ($config) $r += ['roles' => lista_csv($p['roles']), 'usuarios' => lista_csv($p['usuarios'] ?? ''), 'modificado' => $p['modificado'], 'modificado_por' => $p['modificado_por']];
    return $r;
}
function paneles(?array $u, array $cfg, bool $chispa = true, ?string $area = null, bool $puntos = true): array {
    $config = puede('paneles.configurar', $u); $aid = null;
    if ($area !== null && $area !== '') { if ($area === '-') $aid = 0; else { $a = area_por($area); if (!ve_area($a, $u)) throw new Fallo(404, 'No existe esa área'); $aid = (int) $a['id']; } }
    $r = [];
    foreach (filas('SELECT * FROM pan_panel ORDER BY orden, nombre') as $p) {
        if ($aid !== null && (int) ($p['area_id'] ?? 0) !== $aid) continue;
        if (ve($p, $u)) $r[] = vista_panel($p, $cfg, $config, $chispa, $puntos);
    }
    return $r;
}
function panel_visible(int $id, ?array $u): array {
    $p = fila('SELECT * FROM pan_panel WHERE id = ?', [$id]);
    if (!$p || !ve($p, $u)) throw new Fallo(404, 'No existe ese panel');
    return $p;
}
/** Portada: las áreas que ve el usuario, con el estado de sus paneles. Los paneles sin área van a «Otros paneles». */
function portada(?array $u, array $cfg): array {
    $config = puede('paneles.configurar', $u); $P = paneles($u, $cfg, false, null, false); $r = [];
    $suma = function (array $ps) { $c = ['ok' => 0, 'fuera' => 0, 'alarma' => 0, 'sin-datos' => 0]; $al = 0;
        foreach ($ps as $p) { if (!$p['activo']) continue; foreach ($p['cuenta'] as $k => $n) $c[$k] += $n; $al += $p['alarmas_activas']; } return [$c, $al]; };
    $corto = fn($p) => ['id' => $p['id'], 'nombre' => $p['nombre'], 'tipo' => $p['tipo'], 'activo' => $p['activo'], 'cuenta' => $p['cuenta'], 'alarmas_activas' => $p['alarmas_activas']];
    foreach (areas_idx() as $a) {
        if (!ve_area($a, $u)) continue;
        $ps = array_values(array_filter($P, fn($p) => $p['area'] === $a['clave']));
        if (!$ps && !$config) continue;                                         // un área vacía no se muestra a quien no configura
        [$c, $al] = $suma($ps);
        $r[] = vista_area($a, false) + ['paneles' => array_map($corto, $ps), 'cuenta' => $c, 'alarmas_activas' => $al];
    }
    $sin = array_values(array_filter($P, fn($p) => $p['area'] === null));
    if ($sin) { [$c, $al] = $suma($sin); $r[] = ['id' => 0, 'clave' => '-', 'nombre' => 'Otros paneles', 'descripcion' => 'Paneles sin área asignada', 'icono' => 'paneles', 'color' => '#607d8b', 'orden' => 999, 'activo' => true,
        'paneles' => array_map($corto, $sin), 'cuenta' => $c, 'alarmas_activas' => $al]; }
    return $r;
}

// ------------------------------------------------------------------------------------------------ historial
/** Período pedido: desde/hasta (AAAA-MM-DD o AAAA-MM-DD HH:MM) u «horas» hacia atrás (1 h a 400 días). */
function rango(array $g, int $def = 24): array {
    $ok = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $v) && strtotime($v) !== false;
    if ($ok($g['desde'] ?? null) && $ok($g['hasta'] ?? null)) {
        $a = strtotime((string) $g['desde']); $b = strtotime((string) $g['hasta']); if ($a > $b) [$a, $b] = [$b, $a];
        if (strlen((string) $g['hasta']) === 10) $b = max($b, strtotime((string) max($g['desde'], $g['hasta']) . ' 23:59:59'));
        if ($b - $a > 400 * 86400) throw new Fallo(422, 'El período no puede pasar de 400 días');
        return [date('Y-m-d H:i:s', $a), date('Y-m-d H:i:s', $b)];
    }
    $h = max(1, min(24 * 400, (int) ($g['horas'] ?? $def)));
    return [date('Y-m-d H:i:s', time() - $h * 3600), date('Y-m-d H:i:s')];
}
/** Serie resumida de una señal: el detalle fino si el período entra en él, si no el historial por hora. */
function serie_punto(string $aid, string $p, string $d0, string $d1, array $cfg, int $max = 500): array {
    $fino = strtotime($d0) >= time() - detalle_dias($cfg) * 86400;
    $tb = $fino ? 'pan_serie' : 'pan_hora'; $base = $fino ? paso($cfg) : 3600;
    $seg = max(1, strtotime($d1) - strtotime($d0));
    $paso = (int) (ceil($seg / max(10, $max) / $base) * $base); if ($paso < $base) $paso = $base;
    $l = filas("SELECT FLOOR(UNIX_TIMESTAMP(ts) / $paso) b, SUM(suma) / SUM(n) v, MIN(minimo) mi, MAX(maximo) ma, SUM(n) n FROM $tb WHERE activo_id = ? AND punto = ? AND ts BETWEEN ? AND ? GROUP BY b ORDER BY b", [$aid, $p, $d0, $d1]);
    return [$paso, array_map(fn($s) => ['ts' => date('Y-m-d H:i:s', (int) $s['b'] * $paso), 'prom' => round((float) $s['v'], 4), 'min' => (float) $s['mi'], 'max' => (float) $s['ma'], 'n' => (int) $s['n']], $l)];
}
function estadistica(array $puntos): ?array {
    if (!$puntos) return null;
    $mi = $puntos[0]; $ma = $puntos[0]; $n = 0; $s = 0.0;
    foreach ($puntos as $x) { if ($x['min'] < $mi['min']) $mi = $x; if ($x['max'] > $ma['max']) $ma = $x; $n += $x['n']; $s += $x['prom'] * $x['n']; }
    return ['min' => $mi['min'], 'min_ts' => $mi['ts'], 'max' => $ma['max'], 'max_ts' => $ma['ts'], 'prom' => round($s / max(1, $n), 4), 'lecturas' => $n];
}
function serie(int $item, array $g, ?array $u, array $cfg): array {
    $i = fila('SELECT i.*, p.unidad, p.nombre equipo FROM pan_item i LEFT JOIN pan_punto p ON p.activo_id = i.activo_id AND p.punto = i.punto WHERE i.id = ?', [$item]) ?? throw new Fallo(404, 'No existe esa señal');
    $p = fila('SELECT * FROM pan_panel WHERE id = ?', [(int) $i['panel_id']]);
    if (!$p || !ve($p, $u)) throw new Fallo(404, 'No existe esa señal');
    [$d0, $d1] = rango($g);
    [$paso, $puntos] = serie_punto($i['activo_id'], $i['punto'], $d0, $d1, $cfg);
    return ['item' => ['id' => (int) $i['id'], 'titulo' => $i['titulo'], 'punto' => $i['punto'], 'equipo' => $i['equipo'], 'unidad' => (string) ($i['unidad'] ?? ''), 'minimo' => $i['minimo'] !== null ? (float) $i['minimo'] : null,
        'maximo' => $i['maximo'] !== null ? (float) $i['maximo'] : null, 'decimales' => (int) $i['decimales']], 'panel' => $p['nombre'], 'desde' => $d0, 'hasta' => $d1, 'paso_s' => $paso, 'puntos' => $puntos, 'resumen' => estadistica($puntos)];
}
/** Varias señales de un panel a la vez (curvas). Cada señal: «CÓDIGO|PUNTO» (o el id del activo); solo las que el panel muestra. */
function curvas(int $panel, array $g, ?array $u, array $cfg): array {
    $p = panel_visible($panel, $u); $v = vista_dec($p);
    $pedidas = array_slice(array_values(array_filter(array_map('trim', explode(',', (string) ($g['p'] ?? ''))))), 0, 17);
    if (!$pedidas) throw new Fallo(422, 'Indicá las señales: p=CÓDIGO|PUNTO,…');
    if (count($pedidas) > 16) throw new Fallo(422, 'Hasta 16 señales por curva');
    $cods = activos_vista($v); $its = filas('SELECT activo_id, punto FROM pan_item WHERE panel_id = ?', [$panel]);
    [$d0, $d1] = rango($g); $max = max(50, min(2000, (int) ($g['n'] ?? 500))); $r = []; $paso = null;
    foreach ($pedidas as $x) {
        [$a, $pt] = array_pad(explode('|', $x, 2), 2, '');
        $s = fila('SELECT activo_id, punto, activo codigo, nombre equipo, etiqueta, unidad FROM pan_punto WHERE (activo_id = ? OR activo = ?) AND punto = ? LIMIT 1', [$a, $a, $pt]);
        if (!$s) { $r[] = ['clave' => $x, 'error' => 'sin datos todavía', 'puntos' => []]; continue; }
        $ok = in_array($s['codigo'], $cods, true) || array_filter($its, fn($i) => $i['activo_id'] === $s['activo_id'] && $i['punto'] === $s['punto']);
        if (!$ok) throw new Fallo(403, "La señal {$x} no es de este panel");
        [$paso, $pu] = serie_punto($s['activo_id'], $s['punto'], $d0, $d1, $cfg, $max);
        $r[] = ['clave' => $x, 'activo' => $s['codigo'], 'equipo' => $s['equipo'], 'punto' => $s['punto'], 'etiqueta' => $s['etiqueta'], 'unidad' => $s['unidad'], 'puntos' => array_map(fn($q) => [$q['ts'], $q['prom'], $q['min'], $q['max']], $pu), 'resumen' => estadistica($pu)];
    }
    return ['panel' => $p['nombre'], 'desde' => $d0, 'hasta' => $d1, 'paso_s' => $paso, 'series' => $r];
}

// ------------------------------------------------------------------------------------------------ eventos de equipos
function eventos(int $panel, array $g, ?array $u): array {
    $p = panel_visible($panel, $u); $cods = activos_vista(vista_dec($p));
    [$d0, $d1] = rango($g, 720);
    $w = 'ts BETWEEN ? AND ?'; $a = [$d0, $d1];
    if ($cods) { $w .= ' AND activo IN (' . implode(',', array_fill(0, count($cods), '?')) . ')'; array_push($a, ...$cods); }
    $base = [$w, $a];
    if (in_array($g['nivel'] ?? '', ['alarma', 'evento'], true)) { $w .= ' AND nivel = ?'; $a[] = $g['nivel']; }
    if (($g['estado'] ?? '') === 'activa') $w .= ' AND fin IS NULL';
    if (($c = trim((string) ($g['categoria'] ?? ''))) !== '') { $w .= ' AND categoria = ?'; $a[] = $c; }
    if (($t = trim((string) ($g['q'] ?? ''))) !== '') { $w .= ' AND (mensaje LIKE ? OR activo LIKE ?)'; array_push($a, "%$t%", "%$t%"); }
    $lim = max(1, min(1000, (int) ($g['limite'] ?? 300)));
    $lista = array_map(fn($e) => $e + ['minutos' => $e['fin'] ? (int) round((strtotime($e['fin']) - strtotime($e['ts'])) / 60) : null], filas("SELECT clave, activo, ts, fin, mensaje, categoria, nivel FROM pan_evento WHERE $w ORDER BY ts DESC LIMIT $lim", $a));
    $total = (int) valor("SELECT COUNT(*) FROM pan_evento WHERE $w", $a);
    [$bw, $ba] = $base; $actW = $cods ? 'activo IN (' . implode(',', array_fill(0, count($cods), '?')) . ') AND ' : '';
    $k = fila("SELECT COUNT(*) total, SUM(nivel = 'alarma') alarmas, SUM(nivel = 'evento') eventos, SUM(ts >= CURDATE()) hoy FROM pan_evento WHERE $bw", $ba);
    return ['panel' => $p['nombre'], 'desde' => $d0, 'hasta' => $d1, 'total' => $total, 'lista' => $lista,
        'kpis' => ['total' => (int) $k['total'], 'alarmas' => (int) $k['alarmas'], 'eventos' => (int) $k['eventos'], 'hoy' => (int) $k['hoy'],
            'activas' => (int) valor("SELECT COUNT(*) FROM pan_evento WHERE {$actW}nivel = 'alarma' AND fin IS NULL", $cods)],
        'ranking' => filas("SELECT mensaje, nivel, COUNT(*) n, MAX(ts) ultima, ROUND(AVG(TIMESTAMPDIFF(MINUTE, ts, fin))) minutos FROM pan_evento WHERE $w GROUP BY mensaje, nivel ORDER BY n DESC, ultima DESC LIMIT 12", $a),
        'categorias' => filas("SELECT COALESCE(categoria, '') categoria, COUNT(*) n FROM pan_evento WHERE $bw GROUP BY categoria ORDER BY n DESC LIMIT 30", $ba),
        'por_dia' => filas("SELECT DATE(ts) dia, SUM(nivel = 'alarma') alarmas, SUM(nivel = 'evento') eventos FROM pan_evento WHERE $w GROUP BY dia ORDER BY dia", $a)];
}

// ------------------------------------------------------------------------------------------------ informe de energía
/** Estado de cada referencia en el período (tiempo en verde, amarillo y rojo), energía consumida y demanda máxima. */
function informe(int $panel, array $g, ?array $u, array $cfg): array {
    $p = panel_visible($panel, $u); $v = vista_dec($p);
    if (($p['tipo'] ?? '') !== 'energia') throw new Fallo(422, 'El informe es de los paneles de energía');
    [$d0, $d1] = rango($g, 24 * 30);
    $aid = resolver_activo((string) $v['activo']) ?? throw new Fallo(422, 'El medidor todavía no envió datos');
    $et = array_column(filas('SELECT punto, etiqueta, unidad FROM pan_punto WHERE activo_id = ?', [$aid]), null, 'punto');
    $dentro = fn($x, $r) => is_array($r) && ($r[0] === null || $x >= $r[0]) && ($r[1] === null || $x <= $r[1]);
    $vars = []; $peor = 'verde'; $orden = ['verde' => 0, 'amarillo' => 1, 'rojo' => 2, 'sin-datos' => 3];
    foreach ((array) ($v['referencias'] ?? []) as $pt => $r) {
        [$paso, $pu] = serie_punto($aid, (string) $pt, $d0, $d1, $cfg, 20000);
        $e = estadistica($pu); $c = ['verde' => 0, 'amarillo' => 0, 'rojo' => 0];
        foreach ($pu as $x) $c[$dentro($x['prom'], $r['verde'] ?? null) ? 'verde' : ($dentro($x['prom'], $r['amarillo'] ?? null) ? 'amarillo' : 'rojo')]++;
        $tot = max(1, array_sum($c)); $pc = array_map(fn($n) => round($n * 100 / $tot, 1), $c);
        $est = !$pu ? 'sin-datos' : ($pc['verde'] >= 95 ? 'verde' : ($pc['verde'] + $pc['amarillo'] >= 95 ? 'amarillo' : 'rojo'));
        if ($orden[$est] > $orden[$peor] && $est !== 'sin-datos') $peor = $est;
        $vars[] = ['punto' => (string) $pt, 'titulo' => (string) ($r['titulo'] ?? ($et[$pt]['etiqueta'] ?? $pt)), 'unidad' => (string) ($r['unidad'] ?? ($et[$pt]['unidad'] ?? '')), 'decimales' => (int) ($r['decimales'] ?? 2),
            'verde' => $r['verde'] ?? null, 'amarillo' => $r['amarillo'] ?? null, 'resumen' => $e, 'porcentaje' => $pc, 'estado' => $est, 'intervalos' => count($pu)];
    }
    $en = null; $E = (array) ($v['energia'] ?? []);
    if (!empty($E['potencia'])) {
        [$paso, $pu] = serie_punto($aid, (string) $E['potencia'], $d0, $d1, $cfg, 20000);
        $kwh = 0.0; foreach ($pu as $x) $kwh += $x['prom'] * $paso / 3600 * (float) ($E['factor'] ?? 1);
        $esp = max(1, (int) ceil((strtotime($d1) - strtotime($d0)) / $paso)); $e = estadistica($pu);
        $en = ['kwh' => round($kwh, 1), 'cobertura' => round(min(100, count($pu) * 100 / $esp), 1), 'demanda_max' => $e['max'] ?? null, 'demanda_max_ts' => $e['max_ts'] ?? null, 'demanda_prom' => $e['prom'] ?? null,
            'unidad' => (string) ($E['unidad'] ?? 'kW'), 'paso_s' => $paso];
        // con un contador de energía (kWh acumulados) el consumo sale de su diferencia, que es exacta; la integral de la potencia queda como control
        if (!empty($E['contador'])) { [, $ct] = serie_punto($aid, (string) $E['contador'], $d0, $d1, $cfg, 20000);
            if (count($ct) > 1 && count($ct) >= 0.8 * count($pu)) {   /* solo si el contador cubre el período como la potencia */ $en['kwh_integrado'] = $en['kwh']; $en['kwh'] = round(end($ct)['max'] - $ct[0]['min'], 1); $en['por_contador'] = true; } }
        if (!empty($E['factor_potencia'])) { [, $fp] = serie_punto($aid, (string) $E['factor_potencia'], $d0, $d1, $cfg, 20000); $en['fp_prom'] = estadistica($fp)['prom'] ?? null; }
    }
    return ['panel' => $p['nombre'], 'activo' => $v['activo'], 'desde' => $d0, 'hasta' => $d1, 'estado' => $peor, 'variables' => $vars, 'energia' => $en];
}

// ------------------------------------------------------------------------------------------------ resumen y paquete
function resumen(?array $u, array $cfg): array {
    $r = ['paneles' => 0, 'senales' => 0, 'ok' => 0, 'fuera' => 0, 'alarma' => 0, 'sin-datos' => 0, 'alarmas_activas' => 0];
    foreach (paneles($u, $cfg, false, null, false) as $p) { if (!$p['activo']) continue; $r['paneles']++; $r['senales'] += array_sum($p['cuenta']); foreach ($p['cuenta'] as $k => $n) $r[$k] += $n; $r['alarmas_activas'] += $p['alarmas_activas']; }
    return $r;
}
/** Paquete de áreas y paneles (formato iproy-paneles/1): las señales van por código de activo, no por id, para poder llevarlo a otro nodo. */
function exportar_paquete(): array {
    $ai = areas_idx();
    $ar = array_map(fn($a) => ['clave' => $a['clave'], 'nombre' => $a['nombre'], 'descripcion' => $a['descripcion'], 'icono' => $a['icono'], 'color' => $a['color'], 'orden' => (int) $a['orden'], 'activo' => (bool) $a['activo'], 'reglas' => reglas($a['reglas'])], array_values($ai));
    $pa = [];
    foreach (filas('SELECT * FROM pan_panel ORDER BY orden, nombre') as $p)
        $pa[] = ['clave' => $p['clave'], 'area' => $ai[(int) ($p['area_id'] ?? 0)]['clave'] ?? null, 'nombre' => $p['nombre'], 'descripcion' => $p['descripcion'], 'tipo' => $p['tipo'], 'color' => $p['color'], 'orden' => (int) $p['orden'],
            'activo' => (bool) $p['activo'], 'roles' => lista_csv($p['roles']), 'usuarios' => lista_csv($p['usuarios'] ?? ''), 'vista' => vista_dec($p) ?: null,
            'items' => array_map(fn($i) => ['activo' => $i['codigo'] ?? $i['activo_id'], 'punto' => $i['punto'], 'titulo' => $i['titulo'], 'minimo' => $i['minimo'] !== null ? (float) $i['minimo'] : null, 'maximo' => $i['maximo'] !== null ? (float) $i['maximo'] : null, 'decimales' => (int) $i['decimales']],
                filas('SELECT i.*, p.activo codigo FROM pan_item i LEFT JOIN pan_punto p ON p.activo_id = i.activo_id AND p.punto = i.punto WHERE i.panel_id = ? ORDER BY i.orden, i.id', [(int) $p['id']]))];
    return ['formato' => 'iproy-paneles/1', 'exportado' => date('c'), 'areas' => $ar, 'paneles' => $pa];
}
/** Importa un paquete: áreas y paneles por clave (si existen se actualizan). Las señales que todavía no informaron se saltean con aviso. */
function importar_paquete(array $x, array $u): array {
    if (($x['formato'] ?? '') !== 'iproy-paneles/1') throw new Fallo(422, 'El archivo no es un paquete de paneles (formato iproy-paneles/1)');
    $r = ['areas' => 0, 'paneles' => 0, 'senales' => 0, 'avisos' => []];
    foreach ((array) ($x['areas'] ?? []) as $a) { $id = valor('SELECT id FROM pan_area WHERE clave = ?', [strtolower((string) ($a['clave'] ?? ''))]); guardar_area($id ? (int) $id : null, (array) $a, $u); $r['areas']++; }
    foreach ((array) ($x['paneles'] ?? []) as $p) {
        $p = (array) $p; if (empty($p['clave'])) { $r['avisos'][] = "Panel «" . ($p['nombre'] ?? '?') . "» sin clave: no se importa"; continue; }
        $its = [];
        foreach ((array) ($p['items'] ?? []) as $i) { $aid = resolver_activo((string) ($i['activo'] ?? ''));
            if (!$aid || !fila('SELECT 1 FROM pan_punto WHERE activo_id = ? AND punto = ?', [$aid, (string) ($i['punto'] ?? '')])) { $r['avisos'][] = "{$p['clave']}: la señal " . ($i['activo'] ?? '?') . '|' . ($i['punto'] ?? '?') . ' todavía no informó; se saltea'; continue; }
            $its[] = ['activo_id' => $aid] + (array) $i; }
        $id = valor('SELECT id FROM pan_panel WHERE clave = ?', [strtolower((string) $p['clave'])]);
        guardar_panel($id ? (int) $id : null, ['items' => $its] + $p, $u); $r['paneles']++; $r['senales'] += count($its);
    }
    asentar('panel.paquete.importado', ['areas' => $r['areas'], 'paneles' => $r['paneles'], 'avisos' => count($r['avisos'])]);
    return $r;
}
/** Opciones para las reglas de un área: roles, perfiles (módulo widgets) y organizaciones de los usuarios. */
function opciones(): array {
    $perf = [];
    if (\Nucleo\modulo('widgets')) { \Nucleo\incluir('widgets'); foreach (filas('SELECT id, nombre FROM wid_perfil ORDER BY nombre') as $p) $perf[] = ['id' => $p['id'], 'nombre' => $p['nombre']]; }
    return ['roles' => ['AD' => 'Administrador'] + ROLES, 'perfiles' => $perf, 'tipos' => TIPOS, 'plantillas' => PLANTILLAS,
        'organizaciones' => array_column(filas("SELECT DISTINCT organizacion FROM nucleo_usuario WHERE organizacion IS NOT NULL AND organizacion <> '' ORDER BY organizacion"), 'organizacion')];
}
