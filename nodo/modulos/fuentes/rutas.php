<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use function Modulos\Fuentes\{fuente, vista, guardar, leer, probar, traer_historial, exportar, importar};
use function Nucleo\{filas, q, asentar};

return [
    ['GET', '/fuentes', 'fuentes.ver', fn(array $c) => ['fuentes' => array_map('Modulos\Fuentes\vista', filas('SELECT * FROM fue_fuente ORDER BY nombre')), 'tipos' => \Modulos\Fuentes\TIPOS]],
    ['POST', '/fuentes', 'fuentes.configurar', fn(array $c) => ['_http' => 201, 'fuente' => guardar(null, $c['cuerpo'], (array) $c['usuario'])]],
    ['PUT', '/fuentes/{id}', 'fuentes.configurar', fn(array $c) => ['fuente' => guardar((int) $c['params']['id'], $c['cuerpo'], (array) $c['usuario'])]],
    ['POST', '/fuentes/{id}/activa', 'fuentes.configurar', function (array $c) {
        $id = (int) $c['params']['id']; fuente($id); $a = !empty($c['cuerpo']['activa']);
        q('UPDATE fue_fuente SET activa = ?, modificado = NOW(), modificado_por = ? WHERE id = ?', [$a ? 1 : 0, (string) ($c['usuario']['usuario'] ?? ''), $id]);
        asentar('fuentes.activa', ['activa' => $a], (string) $id); return ['fuente' => vista(fuente($id))];
    }],
    ['POST', '/fuentes/{id}/borrar', 'fuentes.configurar', function (array $c) {
        $id = (int) $c['params']['id']; $f = fuente($id);
        q('DELETE FROM fue_marca WHERE fuente_id = ?', [$id]); q('DELETE FROM fue_visto WHERE fuente_id = ?', [$id]); q('DELETE FROM fue_fuente WHERE id = ?', [$id]);
        asentar('fuentes.borrada', ['nombre' => $f['nombre']], (string) $id); return ['borrada' => $id];
    }],
    ['POST', '/fuentes/{id}/leer', 'fuentes.configurar', fn(array $c) => ['lectura' => leer(fuente((int) $c['params']['id']), $c['cfg']), 'fuente' => vista(fuente((int) $c['params']['id']))]],
    ['POST', '/fuentes/{id}/historial', 'fuentes.configurar', fn(array $c) => ['historial' => traer_historial((int) $c['params']['id'], (int) ($c['cuerpo']['dias'] ?? 30), $c['cfg'])]],
    ['POST', '/probar', 'fuentes.configurar', fn(array $c) => probar($c['cuerpo'], $c['cfg'])],
    ['GET', '/exportar', 'fuentes.configurar', fn(array $c) => exportar()],
    ['POST', '/importar', 'fuentes.configurar', fn(array $c) => importar($c['cuerpo'], (array) $c['usuario'])],
];
