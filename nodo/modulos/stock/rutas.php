<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo stock · rutas bajo /api/m/stock/
declare(strict_types=1);

use Nucleo\Fallo;
use function Modulos\Stock\{alta_insumo, validar_insumo, insumo, mover, alta_proveedor, validar_proveedor, resumen, proximo_codigo};
use function Nucleo\{filas, fila, q, asentar};

$SEL_MOV = 'SELECT m.*, i.codigo, i.descripcion, i.unidad, p.nombre AS proveedor FROM stock_movimiento m JOIN stock_insumo i ON i.id = m.insumo_id LEFT JOIN stock_proveedor p ON p.id = m.proveedor_id';

return [
    ['GET', '/resumen', 'stock.ver', fn(array $c) => ['resumen' => resumen()]],

    ['GET', '/insumos', 'stock.ver', function (array $c) {
        $q = $c['query']; $w = []; $a = [];
        if (empty($q['todos'])) $w[] = 'activo = 1';
        if (!empty($q['categoria'])) { $w[] = 'categoria = ?'; $a[] = (string) $q['categoria']; }
        if (!empty($q['bajo'])) $w[] = 'minimo > 0 AND existencia < minimo';
        if (($t = trim((string) ($q['q'] ?? ''))) !== '') { $w[] = '(codigo LIKE ? OR descripcion LIKE ? OR modelo LIKE ?)'; array_push($a, "%$t%", "%$t%", "%$t%"); }
        return ['insumos' => filas('SELECT * FROM stock_insumo' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY categoria, subcategoria, descripcion LIMIT 2000', $a)];
    }],
    ['GET', '/insumos/{id}', 'stock.ver', function (array $c) use ($SEL_MOV) {
        $i = insumo((int) $c['params']['id']) ?? throw new Fallo(404, 'No existe el insumo');
        return ['insumo' => $i, 'movimientos' => filas("$SEL_MOV WHERE m.insumo_id = ? ORDER BY m.fecha DESC, m.id DESC LIMIT 100", [$i['id']])];
    }],
    ['GET', '/codigo', 'stock.ver', fn(array $c) => ['codigo' => proximo_codigo((string) ($c['query']['categoria'] ?? ''), (string) ($c['query']['subcategoria'] ?? ''))]],
    ['POST', '/insumos', 'stock.editar', fn(array $c) => ['_http' => 201] + alta_insumo($c['cuerpo'])],
    ['PUT', '/insumos/{id}', 'stock.editar', function (array $c) {
        $id = (int) $c['params']['id']; insumo($id) ?? throw new Fallo(404, 'No existe el insumo');
        $f = validar_insumo($c['cuerpo'], false);
        if ($f) q('UPDATE stock_insumo SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?', [...array_values($f), $id]);
        asentar('stock.insumo_modificado', $f, (string) (insumo($id)['codigo']));
        return ['insumo' => insumo($id)];
    }],

    ['GET', '/movimientos', 'stock.ver', function (array $c) use ($SEL_MOV) {
        $q = $c['query']; $w = []; $a = [];
        if (in_array($q['tipo'] ?? '', ['ingreso', 'egreso'], true)) { $w[] = 'm.tipo = ?'; $a[] = $q['tipo']; }
        if (!empty($q['clave'])) { $w[] = 'm.clave = ?'; $a[] = (string) $q['clave']; }
        if (!empty($q['orden'])) { $w[] = 'm.orden = ?'; $a[] = (string) $q['orden']; }
        foreach (['desde' => '>=', 'hasta' => '<='] as $k => $op) if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($q[$k] ?? ''))) { $w[] = "m.fecha $op ?"; $a[] = $q[$k]; }
        return ['movimientos' => filas("$SEL_MOV" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY m.fecha DESC, m.clave DESC, m.renglon LIMIT 1000', $a)];
    }],
    ['POST', '/movimientos', 'stock.mover', fn(array $c) => ['_http' => 201] + mover($c['cuerpo'])],

    ['GET', '/proveedores', 'stock.ver', fn(array $c) => ['proveedores' => filas('SELECT p.*, (SELECT MAX(fecha) FROM stock_movimiento m WHERE m.proveedor_id = p.id) AS ultimo_ingreso FROM stock_proveedor p'
        . (empty($c['query']['todos']) ? ' WHERE activo = 1' : '') . ' ORDER BY nombre')]],
    ['POST', '/proveedores', 'stock.editar', fn(array $c) => ['_http' => 201, 'proveedor' => alta_proveedor($c['cuerpo'])]],
    ['PUT', '/proveedores/{id}', 'stock.editar', function (array $c) {
        $id = (int) $c['params']['id']; fila('SELECT 1 FROM stock_proveedor WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe el proveedor');
        $f = validar_proveedor($c['cuerpo'], false);
        if (isset($f['nombre']) && fila('SELECT 1 FROM stock_proveedor WHERE LOWER(nombre) = LOWER(?) AND id <> ?', [$f['nombre'], $id])) throw new Fallo(409, 'Ya existe un proveedor con ese nombre');
        if ($f) q('UPDATE stock_proveedor SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?', [...array_values($f), $id]);
        asentar('stock.proveedor_modificado', array_keys($f), (string) $id);
        return ['proveedor' => fila('SELECT * FROM stock_proveedor WHERE id = ?', [$id])];
    }],
];
