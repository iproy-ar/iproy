<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Widgets y tablero.
 *
 *   wid_grupo   grupos del menú (nombre, color, orden)
 *   wid_widget  catálogo: cómo se ve cada acceso (nombre, grupo, ícono, orden, activo) y, si fue creado acá, qué hace
 *   wid_perfil  perfil = lista de widgets que ve una persona · wid_usuario  qué perfil tiene cada usuario
 *   wid_dato    registros de los widgets creados (una tabla propia: el módulo no lee tablas de otros)
 *
 * Un widget creado es una definición, no código: tipo (formulario, sabana, indicadores, pagina) más campos, columnas, filtros,
 * estados y botones. La definición se valida entera al guardar y lleva número de versión.
 */
declare(strict_types=1);

namespace Modulos\Widgets;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, valor, pdo, asentar, puede};

const TIPOS = ['formulario' => 'Formulario', 'sabana' => 'Sábana', 'indicadores' => 'Indicadores', 'pagina' => 'Página'];
const CAMPOS = ['texto' => 'Texto', 'area' => 'Texto largo', 'numero' => 'Número', 'fecha' => 'Fecha', 'hora' => 'Hora', 'lista' => 'Lista', 'casilla' => 'Casilla'];
const ROLES = ['GM', 'EJ', 'SO', 'FT', 'CI'];
const RX_ID = '/^[a-z][a-z0-9_]{1,39}$/';
const RX_COLOR = '/^#[0-9a-fA-F]{6}$/';

function ahora(): string { return date('Y-m-d H:i:s'); }
function txt(mixed $v, int $max, string $que, bool $req = true): string {
    $s = trim((string) ($v ?? ''));
    if ($req && $s === '') throw new Fallo(422, "Falta «{$que}»");
    if (mb_strlen($s) > $max) throw new Fallo(422, "«{$que}»: hasta {$max} caracteres");
    return $s;
}
function id_valido(mixed $v, string $que, bool $guion = false): string {
    $s = strtolower(trim((string) $v));
    if (!preg_match($guion ? '/^[a-z][a-z0-9_-]{1,39}$/' : RX_ID, $s)) throw new Fallo(422, "«{$que}»: minúsculas, números y guion bajo" . ($guion ? ' o medio' : '') . "; empieza con letra (2 a 40)");
    return $s;
}
function color(mixed $v, string $def): string { $s = trim((string) ($v ?? '')); if ($s === '') return $def; if (!preg_match(RX_COLOR, $s)) throw new Fallo(422, 'Color: formato #RRGGBB'); return strtolower($s); }

// ---------------------------------------------------------------- grupos
function grupos(bool $todos = false): array {
    return filas('SELECT id, nombre, color, orden, activo FROM wid_grupo ' . ($todos ? '' : 'WHERE activo = 1 ') . 'ORDER BY orden, nombre');
}
function guardar_grupo(?string $id, array $d, array $u): string {
    $nuevo = $id === null; $id = $nuevo ? id_valido($d['id'] ?? '', 'Id del grupo') : $id;
    $ant = fila('SELECT * FROM wid_grupo WHERE id = ?', [$id]);
    if ($nuevo && $ant) throw new Fallo(409, "Ya existe el grupo «{$id}»");
    if (!$nuevo && !$ant) throw new Fallo(404, 'No existe ese grupo');
    $f = ['nombre' => txt($d['nombre'] ?? ($ant['nombre'] ?? ''), 60, 'Nombre del grupo'), 'color' => color($d['color'] ?? ($ant['color'] ?? null), '#546e7a'),
          'orden' => (int) ($d['orden'] ?? ($ant['orden'] ?? 50)), 'activo' => isset($d['activo']) ? (int) (bool) $d['activo'] : (int) ($ant['activo'] ?? 1)];
    q('INSERT INTO wid_grupo (id, nombre, color, orden, activo, modificado, modificado_por) VALUES (?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), color = VALUES(color), orden = VALUES(orden), activo = VALUES(activo), modificado = VALUES(modificado), modificado_por = VALUES(modificado_por)',
      [$id, $f['nombre'], $f['color'], $f['orden'], $f['activo'], ahora(), $u['usuario'] ?? '']);
    asentar($nuevo ? 'widgets.grupo.alta' : 'widgets.grupo.cambio', $f, $id);
    return $id;
}
function borrar_grupo(string $id): void {
    if (!fila('SELECT id FROM wid_grupo WHERE id = ?', [$id])) throw new Fallo(404, 'No existe ese grupo');
    $n = (int) valor('SELECT COUNT(*) FROM wid_widget WHERE grupo = ?', [$id]);
    if ($n) throw new Fallo(409, "El grupo tiene {$n} widget(s): movelos a otro grupo antes de borrarlo");
    q('DELETE FROM wid_grupo WHERE id = ?', [$id]); asentar('widgets.grupo.baja', [], $id);
}

// ---------------------------------------------------------------- definición de un widget creado
/** Revisa la definición completa y la devuelve limpia (solo lo que el módulo entiende). */
function validar_definicion(string $tipo, mixed $def, array $cfg, ?string $propio = null): array {
    if (!isset(TIPOS[$tipo])) throw new Fallo(422, 'Tipo de widget: ' . implode(', ', array_keys(TIPOS)));
    if (!is_array($def)) throw new Fallo(422, 'La definición tiene que ser un objeto');
    $o = ['ayuda' => txt($def['ayuda'] ?? '', 300, 'Texto de ayuda', false)];
    if ($tipo === 'pagina') {
        $url = txt($def['url'] ?? '', 500, 'Dirección', false); $texto = txt($def['texto'] ?? '', 8000, 'Texto', false);
        if ($url === '' && $texto === '') throw new Fallo(422, 'Una página necesita una dirección o un texto');
        if ($url !== '' && !preg_match('#^(https://[^\s"\'<>]+|/[^\s"\'<>/][^\s"\'<>]*)$#', $url)) throw new Fallo(422, 'Dirección: https://… o una ruta del mismo nodo que empiece con /');
        return $o + ['url' => $url, 'texto' => $texto, 'embebida' => !empty($def['embebida'])];
    }
    if ($tipo === 'indicadores') {
        $f = id_valido($def['fuente'] ?? '', 'Widget de origen');
        $w = fila("SELECT tipo, definicion FROM wid_widget WHERE id = ? AND origen = 'definido'", [$f]);
        if (!$w || !in_array($w['tipo'], ['formulario', 'sabana'], true)) throw new Fallo(422, "«{$f}» no es un formulario ni una sábana creados acá");
        $campos = array_column(json_decode((string) $w['definicion'], true)['campos'] ?? [], 'tipo', 'id') + ['estado' => 'lista'];
        $tar = [];
        foreach (array_slice(array_values((array) ($def['tarjetas'] ?? [])), 0, 12) as $t) {
            $c = (string) ($t['campo'] ?? '');
            if (!isset($campos[$c])) throw new Fallo(422, "Indicadores: el campo «{$c}» no existe en «{$f}»");
            $op = in_array($t['operacion'] ?? 'contar', ['contar', 'sumar', 'promedio'], true) ? ($t['operacion'] ?? 'contar') : 'contar';
            if ($op !== 'contar' && $campos[$c] !== 'numero') throw new Fallo(422, "Indicadores: «{$c}» no es un número; solo se puede contar");
            $tar[] = ['titulo' => txt($t['titulo'] ?? '', 60, 'Título de la tarjeta'), 'campo' => $c, 'operacion' => $op, 'agrupar' => $op === 'contar'];
        }
        if (!$tar) throw new Fallo(422, 'Indicadores: agregá al menos una tarjeta');
        return $o + ['fuente' => $f, 'tarjetas' => $tar, 'dias' => max(0, min(3660, (int) ($def['dias'] ?? 30)))];
    }
    // formulario y sábana comparten los campos
    $campos = []; $ids = [];
    foreach (array_values((array) ($def['campos'] ?? [])) as $c) {
        $id = id_valido($c['id'] ?? '', 'Id del campo');
        if (in_array($id, ['id', 'numero', 'estado', 'creado', 'creado_por', 'modificado'], true)) throw new Fallo(422, "«{$id}» es un nombre reservado");
        if (isset($ids[$id])) throw new Fallo(422, "El campo «{$id}» está repetido"); $ids[$id] = 1;
        $t = (string) ($c['tipo'] ?? 'texto'); if (!isset(CAMPOS[$t])) throw new Fallo(422, "Campo «{$id}»: tipo desconocido");
        $x = ['id' => $id, 'etiqueta' => txt($c['etiqueta'] ?? '', 60, "Etiqueta de «{$id}»"), 'tipo' => $t, 'requerido' => !empty($c['requerido']), 'ayuda' => txt($c['ayuda'] ?? '', 120, 'Ayuda', false)];
        if ($t === 'lista') {
            $ops = array_values(array_unique(array_filter(array_map(fn($v) => mb_substr(trim((string) $v), 0, 80), (array) ($c['opciones'] ?? [])), fn($v) => $v !== '')));
            if (!$ops) throw new Fallo(422, "Campo «{$id}»: la lista necesita opciones"); if (count($ops) > 300) throw new Fallo(422, "Campo «{$id}»: hasta 300 opciones");
            $x['opciones'] = $ops;
        }
        if ($t === 'numero') { $x['unidad'] = txt($c['unidad'] ?? '', 12, 'Unidad', false); foreach (['minimo', 'maximo'] as $k) if (isset($c[$k]) && $c[$k] !== '' && is_numeric($c[$k])) $x[$k] = (float) $c[$k]; }
        $campos[] = $x;
    }
    if (!$campos) throw new Fallo(422, 'Agregá al menos un campo');
    if (count($campos) > (int) ($cfg['max_campos'] ?? 40)) throw new Fallo(422, 'Demasiados campos');
    $en = fn($l, $que) => array_values(array_filter(array_map('strval', (array) $l), function ($c) use ($ids, $que) {
        if (isset($ids[$c]) || in_array($c, ['numero', 'estado', 'creado', 'creado_por'], true)) return true; throw new Fallo(422, "{$que}: no existe el campo «{$c}»"); }));
    $est = array_values(array_unique(array_filter(array_map(fn($v) => mb_substr(trim((string) $v), 0, 40), (array) ($def['estados'] ?? [])), fn($v) => $v !== '')));
    if (count($est) > 12) throw new Fallo(422, 'Hasta 12 estados');
    $o += ['campos' => $campos, 'estados' => $est, 'boton' => txt($def['boton'] ?? 'Guardar', 30, 'Texto del botón', false) ?: 'Guardar',
           'numerar' => txt($def['numerar'] ?? '', 8, 'Prefijo del número', false)];
    if ($tipo === 'sabana') {
        $o['columnas'] = $en($def['columnas'] ?? array_slice(array_keys($ids), 0, 6), 'Columnas') ?: array_slice(array_keys($ids), 0, 6);
        $o['filtros'] = $en($def['filtros'] ?? [], 'Filtros');
        $o['editables'] = array_values(array_filter($en($def['editables'] ?? [], 'Edición en la fila'), fn($c) => isset($ids[$c]) || $c === 'estado'));
        $o['alta'] = !isset($def['alta']) || !empty($def['alta']);
        $o['descarga'] = !isset($def['descarga']) || !empty($def['descarga']);
        $o['imprimir'] = !empty($def['imprimir']);
    }
    return $o;
}

// ---------------------------------------------------------------- catálogo
function widget(string $id): array {
    return fila('SELECT * FROM wid_widget WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe ese widget');
}
function vista_widget(array $w, bool $completa = true): array {
    $o = ['id' => $w['id'], 'nombre' => $w['nombre'], 'grupo' => $w['grupo'], 'icono' => $w['icono'], 'orden' => (int) $w['orden'], 'activo' => (int) $w['activo'],
          'descripcion' => $w['descripcion'], 'origen' => $w['origen'], 'tipo' => $w['tipo'], 'version' => (int) $w['version']];
    if ($completa && $w['origen'] === 'definido') $o['definicion'] = json_decode((string) $w['definicion'], true) ?: [];
    return $o;
}
function guardar_widget(?string $id, array $d, array $u, array $cfg): string {
    $nuevo = $id === null; $id = $nuevo ? id_valido($d['id'] ?? '', 'Id del widget') : $id;
    $ant = fila('SELECT * FROM wid_widget WHERE id = ?', [$id]);
    if ($nuevo && $ant) throw new Fallo(409, "Ya existe el widget «{$id}»");
    if (!$nuevo && !$ant) throw new Fallo(404, 'No existe ese widget');
    $origen = $ant['origen'] ?? (in_array($d['origen'] ?? 'definido', ['modulo', 'definido'], true) ? ($d['origen'] ?? 'definido') : 'definido');
    $grupo = (string) ($d['grupo'] ?? ($ant['grupo'] ?? ''));
    if ($grupo !== '' && !fila('SELECT id FROM wid_grupo WHERE id = ?', [$grupo])) throw new Fallo(422, "No existe el grupo «{$grupo}»");
    $icono = (string) ($d['icono'] ?? ($ant['icono'] ?? '')); if ($icono !== '' && !preg_match('/^[a-z0-9_-]{1,30}$/', $icono)) throw new Fallo(422, 'Ícono: nombre de la lista de íconos');
    $f = ['nombre' => txt($d['nombre'] ?? ($ant['nombre'] ?? ''), 80, 'Nombre visible'), 'grupo' => $grupo, 'icono' => $icono, 'orden' => (int) ($d['orden'] ?? ($ant['orden'] ?? 50)),
          'activo' => isset($d['activo']) ? (int) (bool) $d['activo'] : (int) ($ant['activo'] ?? 1), 'descripcion' => txt($d['descripcion'] ?? ($ant['descripcion'] ?? ''), 200, 'Descripción', false)];
    $tipo = null; $def = null; $ver = (int) ($ant['version'] ?? 0);
    if ($origen === 'definido') {
        $tipo = (string) ($ant['tipo'] ?? ($d['tipo'] ?? ''));
        if ($ant && isset($d['tipo']) && $d['tipo'] !== $ant['tipo']) throw new Fallo(422, 'El tipo de un widget no se cambia: creá otro');
        if (array_key_exists('definicion', $d) || !$ant) { $def = json_encode(validar_definicion($tipo, $d['definicion'] ?? [], $cfg, $id), JSON_UNESCAPED_UNICODE); $ver++; }
        else { $def = $ant['definicion']; }
        if ($ant && $def !== $ant['definicion']) quitar_campos_huerfanos($id, $ant, $def);
    } else { $ver = max(1, $ver); }
    q('INSERT INTO wid_widget (id, nombre, grupo, icono, orden, activo, descripcion, origen, tipo, definicion, version, modificado, modificado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), grupo = VALUES(grupo), icono = VALUES(icono), orden = VALUES(orden), activo = VALUES(activo), descripcion = VALUES(descripcion),
         definicion = VALUES(definicion), version = VALUES(version), modificado = VALUES(modificado), modificado_por = VALUES(modificado_por)',
      [$id, $f['nombre'], $f['grupo'], $f['icono'], $f['orden'], $f['activo'], $f['descripcion'], $origen, $tipo, $def, max(1, $ver), ahora(), $u['usuario'] ?? '']);
    asentar($nuevo ? 'widgets.widget.alta' : 'widgets.widget.cambio', $f + ['origen' => $origen, 'tipo' => $tipo, 'version' => max(1, $ver)], $id);
    return $id;
}
/** Un indicador que apuntaba a un campo que dejó de existir se queda sin esa tarjeta: se avisa en vez de romper. */
function quitar_campos_huerfanos(string $id, array $ant, string $defNueva): void {
    $ids = array_column(json_decode($defNueva, true)['campos'] ?? [], 'id'); $ids[] = 'estado';
    foreach (filas("SELECT id, definicion FROM wid_widget WHERE origen = 'definido' AND tipo = 'indicadores'") as $w) {
        $d = json_decode((string) $w['definicion'], true) ?: []; if (($d['fuente'] ?? '') !== $id) continue;
        $antes = count($d['tarjetas'] ?? []); $d['tarjetas'] = array_values(array_filter($d['tarjetas'] ?? [], fn($t) => in_array($t['campo'], $ids, true)));
        if (count($d['tarjetas']) !== $antes) { q('UPDATE wid_widget SET definicion = ?, version = version + 1 WHERE id = ?', [json_encode($d, JSON_UNESCAPED_UNICODE), $w['id']]);
            asentar('widgets.widget.ajuste', ['motivo' => "cambió «{$id}»", 'tarjetas' => count($d['tarjetas'])], $w['id']); }
    }
}
function borrar_widget(string $id, bool $conDatos): void {
    $w = widget($id);
    if ($w['origen'] !== 'definido') { q('DELETE FROM wid_widget WHERE id = ?', [$id]); asentar('widgets.widget.restaurado', [], $id); return; }   // vuelve a verse como lo trae el módulo
    $usa = valor("SELECT id FROM wid_widget WHERE origen = 'definido' AND tipo = 'indicadores' AND definicion LIKE ?", ['%"fuente":"' . $id . '"%']);
    if ($usa) throw new Fallo(409, "El widget «{$usa}» toma sus números de este: borralo primero");
    $n = (int) valor('SELECT COUNT(*) FROM wid_dato WHERE widget = ?', [$id]);
    if ($n && !$conDatos) throw new Fallo(409, "El widget tiene {$n} registro(s). Desactivalo, o confirmá que también se borran", 'con_datos', ['registros' => $n]);
    q('DELETE FROM wid_dato WHERE widget = ?', [$id]); q('DELETE FROM wid_widget WHERE id = ?', [$id]);
    foreach (filas('SELECT id, widgets FROM wid_perfil') as $p) { $l = json_decode((string) $p['widgets'], true) ?: []; if (in_array($id, $l, true)) q('UPDATE wid_perfil SET widgets = ? WHERE id = ?', [json_encode(array_values(array_diff($l, [$id]))), $p['id']]); }
    asentar('widgets.widget.baja', ['registros' => $n], $id);
}

// ---------------------------------------------------------------- perfiles
function perfiles(): array {
    $n = array_column(filas('SELECT perfil, COUNT(*) n FROM wid_usuario GROUP BY perfil'), 'n', 'perfil');
    return array_map(fn($p) => ['id' => $p['id'], 'nombre' => $p['nombre'], 'color' => $p['color'], 'descripcion' => $p['descripcion'], 'activo' => (int) $p['activo'],
        'widgets' => json_decode((string) $p['widgets'], true) ?: [], 'usuarios' => (int) ($n[$p['id']] ?? 0)], filas('SELECT * FROM wid_perfil ORDER BY nombre'));
}
function guardar_perfil(?string $id, array $d, array $u): string {
    $nuevo = $id === null; $id = $nuevo ? id_valido($d['id'] ?? '', 'Id del perfil', true) : $id;   // admite guion medio: los perfiles del sistema anterior se llaman así (empresas-bio)
    $ant = fila('SELECT * FROM wid_perfil WHERE id = ?', [$id]);
    if ($nuevo && $ant) throw new Fallo(409, "Ya existe el perfil «{$id}»");
    if (!$nuevo && !$ant) throw new Fallo(404, 'No existe ese perfil');
    $w = array_key_exists('widgets', $d) ? array_values(array_unique(array_filter(array_map('strval', (array) $d['widgets']), fn($x) => (bool) preg_match('/^[a-z][a-z0-9_.-]{1,59}$/', $x)))) : (json_decode((string) ($ant['widgets'] ?? '[]'), true) ?: []);
    $f = ['nombre' => txt($d['nombre'] ?? ($ant['nombre'] ?? ''), 60, 'Nombre del perfil'), 'color' => color($d['color'] ?? ($ant['color'] ?? null), '#1e88e5'),
          'descripcion' => txt($d['descripcion'] ?? ($ant['descripcion'] ?? ''), 200, 'Descripción', false), 'activo' => isset($d['activo']) ? (int) (bool) $d['activo'] : (int) ($ant['activo'] ?? 1)];
    q('INSERT INTO wid_perfil (id, nombre, color, descripcion, activo, widgets, modificado, modificado_por) VALUES (?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), color = VALUES(color), descripcion = VALUES(descripcion), activo = VALUES(activo), widgets = VALUES(widgets), modificado = VALUES(modificado), modificado_por = VALUES(modificado_por)',
      [$id, $f['nombre'], $f['color'], $f['descripcion'], $f['activo'], json_encode($w), ahora(), $u['usuario'] ?? '']);
    asentar($nuevo ? 'widgets.perfil.alta' : 'widgets.perfil.cambio', $f + ['widgets' => count($w)], $id);
    return $id;
}
function borrar_perfil(string $id): void {
    if (!fila('SELECT id FROM wid_perfil WHERE id = ?', [$id])) throw new Fallo(404, 'No existe ese perfil');
    $n = (int) valor('SELECT COUNT(*) FROM wid_usuario WHERE perfil = ?', [$id]);
    if ($n) throw new Fallo(409, "El perfil lo tienen {$n} persona(s): asignales otro antes de borrarlo");
    q('DELETE FROM wid_perfil WHERE id = ?', [$id]); asentar('widgets.perfil.baja', [], $id);
}
function asignar_perfil(string $usuario, string $perfil, array $u): void {
    if (!fila('SELECT usuario FROM nucleo_usuario WHERE usuario = ?', [$usuario])) throw new Fallo(404, 'No existe ese usuario');
    if ($perfil === '') { q('DELETE FROM wid_usuario WHERE usuario = ?', [$usuario]); asentar('widgets.usuario.perfil', ['perfil' => null], $usuario); return; }
    if (!fila('SELECT id FROM wid_perfil WHERE id = ?', [$perfil])) throw new Fallo(422, "No existe el perfil «{$perfil}»");
    q('INSERT INTO wid_usuario (usuario, perfil, modificado, modificado_por) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE perfil = VALUES(perfil), modificado = VALUES(modificado), modificado_por = VALUES(modificado_por)',
      [$usuario, $perfil, ahora(), $u['usuario'] ?? '']);
    asentar('widgets.usuario.perfil', ['perfil' => $perfil], $usuario);
}
/** Ids de widgets que ve la persona, o null si ve todos (administrador, o sin perfil asignado). */
/** Ola 4: perfil asignado a un usuario (o null). Lo usan otros módulos (por ejemplo, los permisos por área de paneles). */
function perfil_de(string $usuario): ?string { $p = valor('SELECT perfil FROM wid_usuario WHERE usuario = ?', [$usuario]); return $p === null || $p === false ? null : (string) $p; }
function visibles_de(array $u): ?array {
    if (($u['rol'] ?? '') === 'AD') return null;
    $p = fila('SELECT p.widgets, p.activo FROM wid_usuario x JOIN wid_perfil p ON p.id = x.perfil WHERE x.usuario = ?', [(string) ($u['usuario'] ?? '')]);
    if (!$p || !(int) $p['activo']) return null;
    return json_decode((string) $p['widgets'], true) ?: [];
}
function catalogo(array $u): array {
    $vis = visibles_de($u); $ws = [];
    foreach (filas('SELECT * FROM wid_widget ORDER BY orden, nombre') as $w) {
        if ($w['origen'] === 'definido' && (!(int) $w['activo'] || ($vis !== null && !in_array($w['id'], $vis, true)))) continue;   // lo que no ve, no viaja
        $ws[] = vista_widget($w);
    }
    return ['grupos' => grupos(), 'widgets' => $ws, 'visibles' => $vis, 'configura' => puede('widgets.configurar', $u)];
}
/** El usuario puede usar ese widget creado: existe, está activo y entra en su perfil. */
function widget_usable(string $id, array $u): array {
    $w = widget($id);
    if ($w['origen'] !== 'definido') throw new Fallo(404, 'Ese acceso no guarda registros acá');
    if (puede('widgets.configurar', $u)) return $w;
    $vis = visibles_de($u);
    if (!(int) $w['activo'] || ($vis !== null && !in_array($id, $vis, true))) throw new Fallo(403, 'Ese widget no está habilitado para tu perfil');
    return $w;
}

// ---------------------------------------------------------------- registros de los widgets creados
function limpiar_datos(array $def, array $d, ?array $solo = null, array $base = []): array {
    $o = $base;
    foreach ($def['campos'] as $c) {
        $k = $c['id']; if ($solo !== null && !in_array($k, $solo, true)) continue;
        if (!array_key_exists($k, $d)) { if ($solo === null && $c['requerido'] && $c['tipo'] !== 'casilla') throw new Fallo(422, "Falta «{$c['etiqueta']}»"); continue; }
        $v = $d[$k];
        if ($c['tipo'] === 'casilla') { $o[$k] = (bool) $v; continue; }
        $s = trim((string) ($v ?? ''));
        if ($s === '') { if ($c['requerido']) throw new Fallo(422, "Falta «{$c['etiqueta']}»"); $o[$k] = null; continue; }
        switch ($c['tipo']) {
            case 'numero':
                $n = str_replace(',', '.', $s); if (!is_numeric($n)) throw new Fallo(422, "«{$c['etiqueta']}»: escribí un número");
                $n = (float) $n; if (isset($c['minimo']) && $n < $c['minimo']) throw new Fallo(422, "«{$c['etiqueta']}»: mínimo {$c['minimo']}");
                if (isset($c['maximo']) && $n > $c['maximo']) throw new Fallo(422, "«{$c['etiqueta']}»: máximo {$c['maximo']}");
                $o[$k] = $n; break;
            case 'fecha': if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || !strtotime($s)) throw new Fallo(422, "«{$c['etiqueta']}»: fecha inválida"); $o[$k] = $s; break;
            case 'hora': if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s)) throw new Fallo(422, "«{$c['etiqueta']}»: hora inválida"); $o[$k] = $s; break;
            case 'lista': if (!in_array($s, $c['opciones'], true)) throw new Fallo(422, "«{$c['etiqueta']}»: elegí un valor de la lista"); $o[$k] = $s; break;
            case 'area': if (mb_strlen($s) > 4000) throw new Fallo(422, "«{$c['etiqueta']}»: hasta 4.000 caracteres"); $o[$k] = $s; break;
            default: if (mb_strlen($s) > 300) throw new Fallo(422, "«{$c['etiqueta']}»: hasta 300 caracteres"); $o[$k] = $s;
        }
    }
    return $o;
}
function vista_dato(array $r, array $def): array {
    return ['id' => (int) $r['id'], 'numero' => ($def['numerar'] ?? '') . $r['numero'], 'estado' => $r['estado'], 'creado' => $r['creado'], 'creado_por' => $r['creado_por'],
            'modificado' => $r['modificado'], 'revision' => (int) $r['revision'], 'datos' => json_decode((string) $r['datos'], true) ?: []];
}
function crear_dato(array $w, array $d, array $u): array {
    $def = json_decode((string) $w['definicion'], true);
    $datos = limpiar_datos($def, (array) ($d['datos'] ?? $d));
    $estado = (string) ($d['estado'] ?? ($def['estados'][0] ?? ''));
    if ($estado !== '' && !in_array($estado, $def['estados'], true)) throw new Fallo(422, 'Estado desconocido');
    $db = pdo(); $db->beginTransaction();
    try {
        $num = 1 + (int) valor('SELECT COALESCE(MAX(numero), 0) FROM wid_dato WHERE widget = ? FOR UPDATE', [$w['id']]);
        q('INSERT INTO wid_dato (widget, numero, estado, datos, creado, creado_por) VALUES (?,?,?,?,?,?)', [$w['id'], $num, $estado, json_encode($datos, JSON_UNESCAPED_UNICODE), ahora(), (string) ($u['usuario'] ?? '')]);
        $id = (int) $db->lastInsertId(); $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }
    asentar('widgets.dato.alta', ['widget' => $w['id'], 'numero' => $num], (string) $id);
    return vista_dato(fila('SELECT * FROM wid_dato WHERE id = ?', [$id]), $def);
}
function cambiar_dato(array $w, int $id, array $d, array $u): array {
    $def = json_decode((string) $w['definicion'], true);
    $r = fila('SELECT * FROM wid_dato WHERE id = ? AND widget = ?', [$id, $w['id']]) ?? throw new Fallo(404, 'No existe ese registro');
    if (isset($d['revision']) && (int) $d['revision'] !== (int) $r['revision']) throw new Fallo(409, 'Otra persona cambió este registro: volvé a cargarlo', 'revision');
    $todo = puede('widgets.configurar', $u); $perm = $w['tipo'] === 'sabana' ? ($def['editables'] ?? []) : [];
    $cambia = array_keys((array) ($d['datos'] ?? []));
    if (!$todo && array_diff($cambia, $perm)) throw new Fallo(403, 'Ese dato no se edita desde la fila');
    $datos = limpiar_datos($def, (array) ($d['datos'] ?? []), $cambia, json_decode((string) $r['datos'], true) ?: []);
    $estado = $r['estado'];
    if (array_key_exists('estado', $d)) {
        if (!$todo && !in_array('estado', $perm, true)) throw new Fallo(403, 'El estado no se cambia desde la fila');
        $estado = (string) $d['estado']; if ($estado !== '' && !in_array($estado, $def['estados'], true)) throw new Fallo(422, 'Estado desconocido');
    }
    q('UPDATE wid_dato SET datos = ?, estado = ?, modificado = ?, modificado_por = ?, revision = revision + 1 WHERE id = ?', [json_encode($datos, JSON_UNESCAPED_UNICODE), $estado, ahora(), (string) ($u['usuario'] ?? ''), $id]);
    asentar('widgets.dato.cambio', ['widget' => $w['id'], 'campos' => $cambia, 'estado' => $estado !== $r['estado'] ? $estado : null], (string) $id);
    return vista_dato(fila('SELECT * FROM wid_dato WHERE id = ?', [$id]), $def);
}
function listar_datos(array $w, array $query, array $cfg): array {
    $def = json_decode((string) $w['definicion'], true); $max = (int) ($cfg['max_filas'] ?? 2000);
    $cond = ['widget = ?']; $a = [$w['id']];
    $f = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($query[$k] ?? '')) ? (string) $query[$k] : null;
    if ($d = $f('desde')) { $cond[] = 'creado >= ?'; $a[] = $d . ' 00:00:00'; }
    if ($h = $f('hasta')) { $cond[] = 'creado <= ?'; $a[] = $h . ' 23:59:59'; }
    if (($e = (string) ($query['estado'] ?? '')) !== '') { $cond[] = 'estado = ?'; $a[] = $e; }
    $rs = filas('SELECT * FROM wid_dato WHERE ' . implode(' AND ', $cond) . ' ORDER BY id DESC LIMIT ' . ($max + 1), $a);
    $recortado = count($rs) > $max; $rs = array_slice($rs, 0, $max);
    $o = array_map(fn($r) => vista_dato($r, $def), $rs);
    // filtros por campo y búsqueda: sobre lo ya leído (las columnas viven dentro del JSON)
    foreach ((array) ($query['f'] ?? []) as $k => $v) { $v = (string) $v; if ($v === '') continue; $o = array_values(array_filter($o, fn($x) => (string) ($x['datos'][$k] ?? '') === $v)); }
    if (($t = mb_strtolower(trim((string) ($query['q'] ?? '')))) !== '') $o = array_values(array_filter($o, fn($x) => str_contains(mb_strtolower($x['numero'] . ' ' . implode(' ', array_map('strval', array_filter($x['datos'], 'is_scalar')))), $t)));
    $porE = []; foreach ($o as $x) { $k = $x['estado'] === '' ? '—' : $x['estado']; $porE[$k] = ($porE[$k] ?? 0) + 1; }
    return ['registros' => $o, 'total' => count($o), 'recortado' => $recortado, 'por_estado' => $porE];
}
function indicadores(array $w, array $cfg): array {
    $def = json_decode((string) $w['definicion'], true); $f = widget($def['fuente']); $fdef = json_decode((string) $f['definicion'], true);
    $q = $def['dias'] ? ['desde' => date('Y-m-d', time() - 86400 * (int) $def['dias'])] : [];
    $rs = listar_datos($f, $q, $cfg)['registros']; $et = array_column($fdef['campos'], 'etiqueta', 'id') + ['estado' => 'Estado'];
    $tar = [];
    foreach ($def['tarjetas'] as $t) {
        $vals = array_map(fn($r) => $t['campo'] === 'estado' ? $r['estado'] : ($r['datos'][$t['campo']] ?? null), $rs);
        if ($t['operacion'] === 'contar') { $g = []; foreach ($vals as $v) { $k = $v === null || $v === '' ? '—' : (is_bool($v) ? ($v ? 'Sí' : 'No') : (string) $v); $g[$k] = ($g[$k] ?? 0) + 1; } arsort($g);
            $tar[] = ['titulo' => $t['titulo'], 'campo' => $t['campo'], 'etiqueta' => $et[$t['campo']] ?? $t['campo'], 'operacion' => 'contar', 'valor' => count($rs), 'grupos' => array_slice($g, 0, 12, true)]; }
        else { $n = array_values(array_filter($vals, 'is_numeric')); $s = array_sum($n);
            $tar[] = ['titulo' => $t['titulo'], 'campo' => $t['campo'], 'etiqueta' => $et[$t['campo']] ?? $t['campo'], 'operacion' => $t['operacion'], 'valor' => $n ? round($t['operacion'] === 'sumar' ? $s : $s / count($n), 2) : null, 'muestras' => count($n)]; }
    }
    return ['fuente' => ['id' => $f['id'], 'nombre' => $f['nombre']], 'dias' => (int) $def['dias'], 'registros' => count($rs), 'tarjetas' => $tar];
}

// ---------------------------------------------------------------- exportar e importar definiciones
function exportar(array $ids): array {
    $ws = []; foreach ($ids as $id) { $w = fila('SELECT * FROM wid_widget WHERE id = ?', [(string) $id]); if (!$w || $w['origen'] !== 'definido') continue; $ws[] = vista_widget($w); }   // lo que no fue creado acá se saltea
    $gs = array_values(array_filter(grupos(true), fn($g) => in_array($g['id'], array_column($ws, 'grupo'), true)));
    return ['formato' => 'iproy-widgets', 'version' => 1, 'grupos' => $gs, 'widgets' => $ws];
}
function importar(array $p, array $u, array $cfg): array {
    if (($p['formato'] ?? '') !== 'iproy-widgets' || (int) ($p['version'] ?? 0) !== 1) throw new Fallo(422, 'El archivo no es un paquete de widgets de iProy (formato iproy-widgets, versión 1)');
    $hechos = [];
    foreach ((array) ($p['grupos'] ?? []) as $g) if (!fila('SELECT id FROM wid_grupo WHERE id = ?', [(string) ($g['id'] ?? '')])) guardar_grupo(null, (array) $g, $u);
    $ws = (array) ($p['widgets'] ?? []); usort($ws, fn($a, $b) => (($a['tipo'] ?? '') === 'indicadores') <=> (($b['tipo'] ?? '') === 'indicadores'));   // los indicadores van después de su origen
    foreach ($ws as $w) {
        $id = (string) ($w['id'] ?? ''); $ex = fila('SELECT origen FROM wid_widget WHERE id = ?', [$id]);
        if ($ex && $ex['origen'] !== 'definido') throw new Fallo(409, "«{$id}» ya es un acceso de un módulo");
        guardar_widget($ex ? $id : null, ['id' => $id, 'origen' => 'definido'] + (array) $w, $u, $cfg); $hechos[] = $id;
    }
    return $hechos;
}
