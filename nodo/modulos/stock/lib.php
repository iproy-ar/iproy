<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo stock · reglas: códigos de insumo, validación contra los catálogos y movimientos de varios renglones (con bloqueo de fila).
declare(strict_types=1);

namespace Modulos\Stock;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, valor, pdo, asentar, publicar, catalogo_existe_valor, cfg_modulo};

const TIPOS = ['ingreso' => 'ING', 'egreso' => 'EGR'];

function texto(mixed $v, int $max, bool $req = false, string $campo = 'campo'): ?string {
    $t = trim(preg_replace('/\s+/', ' ', (string) ($v ?? '')));
    if ($t === '') { if ($req) throw new Fallo(422, "Falta «{$campo}»"); return null; }
    if (mb_strlen($t) > $max) throw new Fallo(422, "«{$campo}»: hasta {$max} caracteres");
    return $t;
}
function numero(mixed $v, string $campo, bool $positivo = false): ?float {
    if ($v === null || $v === '') return null;
    if (is_string($v)) $v = str_contains($v, ',') ? str_replace(',', '.', str_replace('.', '', $v)) : $v;
    if (!is_numeric($v)) throw new Fallo(422, "«{$campo}»: tiene que ser un número");
    $n = round((float) $v, 3);
    if ($n < 0 || ($positivo && $n <= 0)) throw new Fallo(422, "«{$campo}»: tiene que ser mayor que cero");
    return $n;
}
/** Código de catálogo obligatorio u opcional, que tiene que existir y estar activo. */
function de_lista(string $cat, mixed $v, bool $req, string $campo): ?string {
    $c = trim((string) ($v ?? ''));
    if ($c === '') { if ($req) throw new Fallo(422, "Elegí «{$campo}»"); return null; }
    if (!catalogo_existe_valor($cat, $c)) throw new Fallo(422, "«{$campo}»: el valor «{$c}» no está en la lista (o está desactivado)");
    return $c;
}

/** Próximo código {n}-{CAT}-{SUB}: n es correlativo general (como en el pañol de MantHMQ). */
function proximo_codigo(string $cat, string $sub): string {
    $sc = valor('SELECT sigla FROM nucleo_catalogo_valor WHERE catalogo = ? AND codigo = ?', ['stock_categoria', $cat]) ?: $cat;
    $ss = valor('SELECT sigla FROM nucleo_catalogo_valor WHERE catalogo = ? AND codigo = ?', ['stock_subcategoria', $sub]) ?: $sub;
    $n = (int) valor("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo, '-', 1) AS UNSIGNED)), 0) FROM stock_insumo") + 1;
    return "{$n}-{$sc}-{$ss}";
}

/** Valida un insumo (alta: todo; cambio: solo lo enviado). La categoría y subcategoría no cambian: son parte del código. */
function validar_insumo(array $d, bool $alta): array {
    $f = [];
    if ($alta) {
        $f['categoria'] = de_lista('stock_categoria', $d['categoria'] ?? null, true, 'Categoría');
        $f['subcategoria'] = de_lista('stock_subcategoria', $d['subcategoria'] ?? null, true, 'Subcategoría');
        if (valor('SELECT padre_codigo FROM nucleo_catalogo_valor WHERE catalogo = ? AND codigo = ?', ['stock_subcategoria', $f['subcategoria']]) !== $f['categoria'])
            throw new Fallo(422, 'La subcategoría no corresponde a la categoría elegida');
    }
    if ($alta || array_key_exists('descripcion', $d)) $f['descripcion'] = texto($d['descripcion'] ?? null, 200, true, 'Descripción');
    if ($alta || array_key_exists('marca', $d)) $f['marca'] = de_lista('fabricante', $d['marca'] ?? null, false, 'Marca');
    if ($alta || array_key_exists('modelo', $d)) $f['modelo'] = texto($d['modelo'] ?? null, 80, false, 'Modelo o medida');
    if ($alta || array_key_exists('unidad', $d)) $f['unidad'] = de_lista('unidad', ($d['unidad'] ?? '') ?: 'u', true, 'Unidad');
    if ($alta || array_key_exists('minimo', $d)) $f['minimo'] = numero($d['minimo'] ?? 0, 'Stock mínimo') ?? 0;
    if ($alta || array_key_exists('precio', $d)) $f['precio'] = numero($d['precio'] ?? null, 'Precio');
    if ($alta || array_key_exists('deposito', $d)) $f['deposito'] = de_lista('stock_deposito', $d['deposito'] ?? null, false, 'Depósito');
    if (!$alta && array_key_exists('activo', $d)) $f['activo'] = (int) (bool) $d['activo'];
    return $f;
}

function alta_insumo(array $d): array {
    $f = validar_insumo($d, true);
    $dup = fila('SELECT codigo FROM stock_insumo WHERE categoria = ? AND subcategoria = ? AND LOWER(descripcion) = LOWER(?) AND (marca <=> ?) AND (modelo <=> ?)',
        [$f['categoria'], $f['subcategoria'], $f['descripcion'], $f['marca'], $f['modelo']]);
    if ($dup) throw new Fallo(409, "Ese insumo ya existe con el código {$dup['codigo']}");
    $inicial = numero($d['existencia_inicial'] ?? null, 'Existencia inicial');
    pdo()->beginTransaction();
    try {
        q('SELECT id FROM stock_insumo ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $f['codigo'] = proximo_codigo($f['categoria'], $f['subcategoria']);
        q('INSERT INTO stock_insumo (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')', array_values($f));
        $id = (int) pdo()->lastInsertId();
        pdo()->commit();
    } catch (\Throwable $e) { if (pdo()->inTransaction()) pdo()->rollBack(); throw $e; }
    asentar('stock.insumo', $f, $f['codigo']);
    $mov = null;
    if ($inicial) $mov = mover(['tipo' => 'ingreso', 'origen' => 'AJUSTE', 'observacion' => 'Existencia inicial al dar de alta el insumo',
        'lineas' => [['insumo' => $id, 'cantidad' => $inicial, 'precio' => $f['precio']]]]);
    return ['insumo' => insumo($id), 'movimiento' => $mov];
}

function insumo(int $id): ?array { return fila('SELECT * FROM stock_insumo WHERE id = ?', [$id]); }

/**
 * Ingreso o egreso de uno o más renglones con un mismo comprobante. Todo o nada (transacción con bloqueo de las filas de insumo).
 * $d: tipo, fecha, origen|motivo, comprobante, expediente, resolucion, proveedor, orden, orden_numero, destino, destino_nombre, solicitante, observacion,
 *     lineas: [{insumo, cantidad, precio?}]
 */
function mover(array $d): array {
    $tipo = (string) ($d['tipo'] ?? '');
    if (!isset(TIPOS[$tipo])) throw new Fallo(422, 'tipo: ingreso | egreso');
    $fecha = (string) ($d['fecha'] ?? '') ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > date('Y-m-d', time() + 86400)) throw new Fallo(422, 'Fecha inválida (no puede ser futura)');
    $cab = ['tipo' => $tipo, 'fecha' => $fecha,
        'origen' => $tipo === 'ingreso' ? de_lista('stock_origen', $d['origen'] ?? null, true, 'Origen') : null,
        'motivo' => $tipo === 'egreso' ? de_lista('stock_motivo_egreso', $d['motivo'] ?? null, true, 'Motivo') : null,
        'comprobante' => texto($d['comprobante'] ?? null, 80, false, 'Comprobante'), 'expediente' => texto($d['expediente'] ?? null, 80, false, 'Expediente'),
        'resolucion' => texto($d['resolucion'] ?? null, 80, false, 'Resolución o disposición'),
        'proveedor_id' => null, 'orden' => texto($d['orden'] ?? null, 64), 'orden_numero' => texto($d['orden_numero'] ?? null, 40),
        'destino' => texto($d['destino'] ?? null, 64), 'destino_nombre' => texto($d['destino_nombre'] ?? null, 200),
        'solicitante' => texto($d['solicitante'] ?? null, 120, false, 'Quién retira'), 'observacion' => texto($d['observacion'] ?? null, 400, false, 'Observación'),
        'usuario' => $GLOBALS['nucleo_usuario']['usuario'] ?? null];
    if (!empty($d['proveedor'])) {
        $cab['proveedor_id'] = (int) $d['proveedor'];
        if (!valor('SELECT 1 FROM stock_proveedor WHERE id = ? AND activo = 1', [$cab['proveedor_id']])) throw new Fallo(422, 'El proveedor no existe o está desactivado');
    }
    if ($tipo === 'egreso' && $cab['motivo'] === 'OT' && !$cab['orden']) throw new Fallo(422, 'Elegí la orden de trabajo');
    $lineas = array_values(array_filter((array) ($d['lineas'] ?? []), fn($l) => !empty($l['insumo'])));
    if (!$lineas) throw new Fallo(422, 'Agregá al menos un renglón con insumo y cantidad');
    if (count($lineas) > 100) throw new Fallo(422, 'Hasta 100 renglones por comprobante');
    $neg = (bool) (cfg_modulo('stock')['permitir_existencia_negativa'] ?? false);

    pdo()->beginTransaction();
    try {
        $pre = TIPOS[$tipo];
        $n = (int) valor("SELECT COALESCE(MAX(CAST(SUBSTRING(clave, 5) AS UNSIGNED)), 0) FROM stock_movimiento WHERE tipo = ? FOR UPDATE", [$tipo]) + 1;
        $clave = sprintf('%s-%06d', $pre, $n);
        $res = [];
        foreach ($lineas as $i => $l) {
            $ins = fila('SELECT * FROM stock_insumo WHERE id = ? FOR UPDATE', [(int) $l['insumo']]) ?? throw new Fallo(422, 'Renglón ' . ($i + 1) . ': el insumo no existe');
            if (!(int) $ins['activo']) throw new Fallo(422, "Renglón " . ($i + 1) . ": {$ins['codigo']} está desactivado");
            $cant = numero($l['cantidad'] ?? null, 'Cantidad (renglón ' . ($i + 1) . ')', true) ?? throw new Fallo(422, 'Renglón ' . ($i + 1) . ': falta la cantidad');
            $precio = $tipo === 'ingreso' ? numero($l['precio'] ?? null, 'Precio (renglón ' . ($i + 1) . ')') : ($ins['precio'] !== null ? (float) $ins['precio'] : null);
            $saldo = round((float) $ins['existencia'] + ($tipo === 'ingreso' ? $cant : -$cant), 3);
            if ($saldo < 0 && !$neg) throw new Fallo(409, "No alcanza la existencia de {$ins['codigo']} ({$ins['descripcion']}): hay " . rtrim(rtrim(number_format((float) $ins['existencia'], 3, ',', ''), '0'), ',') . " {$ins['unidad']}");
            q('UPDATE stock_insumo SET existencia = ?' . ($tipo === 'ingreso' && $precio !== null ? ', precio = ?' : '') . ' WHERE id = ?',
              $tipo === 'ingreso' && $precio !== null ? [$saldo, $precio, $ins['id']] : [$saldo, $ins['id']]);
            $fila = $cab + ['clave' => $clave, 'renglon' => $i + 1, 'insumo_id' => (int) $ins['id'], 'cantidad' => $cant, 'precio' => $precio, 'saldo' => $saldo];
            q('INSERT INTO stock_movimiento (' . implode(',', array_keys($fila)) . ') VALUES (' . implode(',', array_fill(0, count($fila), '?')) . ')', array_values($fila));
            $res[] = ['codigo' => $ins['codigo'], 'cantidad' => $cant, 'saldo' => $saldo, 'bajo_minimo' => $saldo < (float) $ins['minimo']];
        }
        pdo()->commit();
    } catch (\Throwable $e) { if (pdo()->inTransaction()) pdo()->rollBack(); throw $e; }
    asentar('stock.' . $tipo, ['clave' => $clave, 'renglones' => count($res), 'origen' => $cab['origen'], 'motivo' => $cab['motivo'], 'orden' => $cab['orden_numero']], $clave);
    publicar('stock/movimiento', ['clave' => $clave, 'tipo' => $tipo, 'renglones' => $res], 'stock');
    return ['clave' => $clave, 'renglones' => $res];
}

function alta_proveedor(array $d): array {
    $f = validar_proveedor($d, true);
    if (valor('SELECT 1 FROM stock_proveedor WHERE LOWER(nombre) = LOWER(?)', [$f['nombre']])) throw new Fallo(409, 'Ya existe un proveedor con ese nombre');
    q('INSERT INTO stock_proveedor (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')', array_values($f));
    $id = (int) pdo()->lastInsertId(); asentar('stock.proveedor', ['nombre' => $f['nombre']], (string) $id);
    return fila('SELECT * FROM stock_proveedor WHERE id = ?', [$id]);
}
function validar_proveedor(array $d, bool $alta): array {
    $f = [];
    if ($alta || array_key_exists('nombre', $d)) $f['nombre'] = texto($d['nombre'] ?? null, 160, true, 'Nombre o razón social');
    if ($alta || array_key_exists('especialidad', $d)) $f['especialidad'] = de_lista('especialidad', $d['especialidad'] ?? null, false, 'Rubro');
    foreach (['contacto' => [120, 'Contacto'], 'telefono' => [60, 'Teléfono'], 'direccion' => [200, 'Dirección']] as $k => [$m, $t])
        if ($alta || array_key_exists($k, $d)) $f[$k] = texto($d[$k] ?? null, $m, false, $t);
    if ($alta || array_key_exists('email', $d)) {
        $f['email'] = texto($d['email'] ?? null, 160, false, 'Correo');
        if ($f['email'] && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) throw new Fallo(422, 'El correo no es válido');
    }
    if (!$alta && array_key_exists('activo', $d)) $f['activo'] = (int) (bool) $d['activo'];
    return $f;
}

function resumen(): array {
    $r = fila('SELECT COUNT(*) AS insumos, SUM(existencia < minimo AND minimo > 0) AS bajo_minimo, SUM(existencia <= 0) AS sin_existencia,
        ROUND(SUM(existencia * COALESCE(precio, 0)), 2) AS valor FROM stock_insumo WHERE activo = 1');
    $r['mov_30'] = (int) valor('SELECT COUNT(DISTINCT clave) FROM stock_movimiento WHERE fecha >= CURDATE() - INTERVAL 30 DAY');
    $r['mas_usados'] = filas("SELECT i.codigo, i.descripcion, i.unidad, SUM(m.cantidad) AS cantidad FROM stock_movimiento m JOIN stock_insumo i ON i.id = m.insumo_id
        WHERE m.tipo = 'egreso' AND m.fecha >= CURDATE() - INTERVAL 90 DAY GROUP BY i.id ORDER BY cantidad DESC LIMIT 8");
    $r['bajo'] = filas('SELECT id, codigo, descripcion, unidad, existencia, minimo FROM stock_insumo WHERE activo = 1 AND minimo > 0 AND existencia < minimo ORDER BY existencia / minimo, codigo LIMIT 50');
    foreach (['insumos', 'bajo_minimo', 'sin_existencia'] as $k) $r[$k] = (int) $r[$k];
    $r['valor'] = (float) $r['valor'];
    return $r;
}
