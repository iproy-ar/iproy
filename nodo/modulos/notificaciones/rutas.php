<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use Nucleo\Fallo;
use function Modulos\Notificaciones\{fuentes, validar_regla, vista_regla, encolar_regla, enviar_pendientes, resumen, config_publica, guardar_config, ejemplo};
use function Nucleo\{filas, fila, q, pdo, asentar, modulo};

$regla = fn(int $id) => fila('SELECT * FROM ntf_regla WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe esa regla');
$envio = function (array $e): array {
    $d = json_decode((string) $e['datos'], true) ?: [];
    return ['id' => (int) $e['id'], 'regla_id' => (int) $e['regla_id'], 'regla' => $d['regla'] ?? '', 'tema' => $e['tema'], 'asunto' => $e['asunto'], 'canal' => $e['canal'], 'destino' => $e['destino'],
        'estado' => $e['estado'], 'intentos' => (int) $e['intentos'], 'error' => $e['error'], 'creado' => $e['creado'], 'enviado' => $e['enviado'], 'proximo' => $e['estado'] === 'pendiente' ? $e['proximo'] : null,
        'renglones' => $d['renglones'] ?? [], 'nivel' => $d['nivel'] ?? 'info'];
};
return [
    ['GET', '/fuentes', 'notificaciones.ver', fn(array $c) => ['fuentes' => array_map(fn($f, $k) => $f + ['tema' => $k, 'disponible' => modulo($f['requiere']) !== null], fuentes(), array_keys(fuentes()))]],
    ['GET', '/resumen', 'notificaciones.ver', fn(array $c) => ['resumen' => resumen($c['cfg'])]],
    ['GET', '/reglas', 'notificaciones.ver', fn(array $c) => ['reglas' => array_map('Modulos\Notificaciones\vista_regla', filas('SELECT * FROM ntf_regla ORDER BY activa DESC, nombre'))]],
    ['POST', '/reglas', 'notificaciones.configurar', function (array $c) {
        $f = validar_regla($c['cuerpo'], true);
        $f['modificado_por'] = $c['usuario']['usuario'] ?? null;
        q('INSERT INTO ntf_regla (' . implode(',', array_keys($f)) . ', modificado) VALUES (' . implode(',', array_fill(0, count($f), '?')) . ', NOW())', array_values($f));
        $id = (int) pdo()->lastInsertId();
        asentar('aviso.regla.alta', ['nombre' => $f['nombre'], 'tema' => $f['tema'], 'canal' => $f['canal']], (string) $id);
        return ['_http' => 201, 'regla' => vista_regla(fila('SELECT * FROM ntf_regla WHERE id = ?', [$id]))];
    }],
    ['PUT', '/reglas/{id}', 'notificaciones.configurar', function (array $c) use ($regla) {
        $id = (int) $c['params']['id']; $r = $regla($id);
        $b = $c['cuerpo'];
        // la condición depende del tema y los destinatarios del canal: si llega uno solo, se valida contra lo que ya tiene la regla
        if (array_key_exists('condicion', $b) && !isset($b['tema'])) $b['tema'] = $r['tema'];
        if (isset($b['destino']) && !isset($b['canal'])) $b['canal'] = $r['canal'];
        if (isset($b['canal']) && !isset($b['destino'])) $b['destino'] = $r['destino'];
        if (isset($b['tema']) && !array_key_exists('condicion', $b) && $b['tema'] !== $r['tema']) $b['condicion'] = [];
        $f = validar_regla($b, false);
        if ($f) q('UPDATE ntf_regla SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ', modificado = NOW(), modificado_por = ? WHERE id = ?', [...array_values($f), $c['usuario']['usuario'] ?? null, $id]);
        asentar('aviso.regla.cambio', array_diff_key($f, ['destino' => 1]), (string) $id);
        return ['regla' => vista_regla($regla($id))];
    }],
    ['POST', '/reglas/{id}/borrar', 'notificaciones.configurar', function (array $c) use ($regla) {
        $id = (int) $c['params']['id']; $r = $regla($id);
        q("UPDATE ntf_envio SET estado = 'descartado', error = 'Se borró la regla' WHERE regla_id = ? AND estado = 'pendiente'", [$id]);
        q('DELETE FROM ntf_regla WHERE id = ?', [$id]);
        asentar('aviso.regla.baja', ['nombre' => $r['nombre']], (string) $id);
        return ['borrada' => $id];
    }],
    // Prueba: manda ya mismo un aviso de ejemplo a los destinatarios de la regla y devuelve cómo le fue a cada uno
    ['POST', '/reglas/{id}/probar', 'notificaciones.configurar', function (array $c) use ($regla, $envio) {
        $id = (int) $c['params']['id']; $r = $regla($id);
        $ev = -1 * (int) (microtime(true) * 1000);
        encolar_regla($r, $r['tema'], ejemplo($r['tema']), $ev, true);
        $res = [];
        foreach (filas('SELECT id FROM ntf_envio WHERE regla_id = ? AND evento_id = ?', [$id, $ev]) as $x) {
            enviar_pendientes(['max_intentos' => 1] + $c['cfg'], (int) $x['id']);
            $res[] = $envio(fila('SELECT * FROM ntf_envio WHERE id = ?', [(int) $x['id']]));
        }
        asentar('aviso.regla.prueba', ['resultado' => array_map(fn($e) => $e['estado'], $res)], (string) $id);
        return ['envios' => $res, 'bien' => count(array_filter($res, fn($e) => $e['estado'] === 'enviado')), 'mal' => count(array_filter($res, fn($e) => $e['estado'] !== 'enviado'))];
    }],
    ['GET', '/envios', 'notificaciones.ver', function (array $c) use ($envio) {
        $w = ['1=1']; $a = []; $g = $c['query'];
        foreach (['estado', 'canal', 'tema'] as $k) if (($g[$k] ?? '') !== '') { $w[] = "$k = ?"; $a[] = (string) $g[$k]; }
        if (($g['regla'] ?? '') !== '') { $w[] = 'regla_id = ?'; $a[] = (int) $g['regla']; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($g['desde'] ?? ''))) { $w[] = 'creado >= ?'; $a[] = $g['desde'] . ' 00:00:00'; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($g['hasta'] ?? ''))) { $w[] = 'creado <= ?'; $a[] = $g['hasta'] . ' 23:59:59'; }
        if (($t = trim((string) ($g['q'] ?? ''))) !== '') { $w[] = '(asunto LIKE ? OR destino LIKE ?)'; $l = '%' . addcslashes(mb_substr($t, 0, 60), '%_\\') . '%'; $a[] = $l; $a[] = $l; }
        $sql = ' FROM ntf_envio WHERE ' . implode(' AND ', $w);
        return ['envios' => array_map($envio, filas("SELECT *$sql ORDER BY id DESC LIMIT 500", $a)), 'total' => (int) \Nucleo\valor("SELECT COUNT(*)$sql", $a)];
    }],
    ['POST', '/envios/{id}/reintentar', 'notificaciones.configurar', function (array $c) use ($envio) {
        $id = (int) $c['params']['id'];
        $e = fila('SELECT * FROM ntf_envio WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe ese aviso');
        if ($e['estado'] === 'enviado') throw new Fallo(409, 'Ese aviso ya se envió');
        q("UPDATE ntf_envio SET estado = 'pendiente', intentos = 0, proximo = NOW(), error = NULL WHERE id = ?", [$id]);
        enviar_pendientes($c['cfg'], $id);
        return ['envio' => $envio(fila('SELECT * FROM ntf_envio WHERE id = ?', [$id]))];
    }],
    ['GET', '/config', 'notificaciones.ver', fn(array $c) => ['config' => config_publica($c['cfg'])]],
    ['PUT', '/config', 'notificaciones.configurar', fn(array $c) => ['config' => guardar_config($c['cuerpo'])]],
];
