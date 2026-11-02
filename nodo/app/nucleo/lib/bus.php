<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Bus de eventos del nodo. Temas jerárquicos con «/» y comodines MQTT («+» un nivel, «#» el resto), así un tema del
 * bus se corresponde 1:1 con uno de MQTT o Sparkplug. Cada suscriptor tiene su cursor: lee a su ritmo, no pierde
 * eventos si estuvo caído (como un consumer group). Un evento que falla 5 veces seguidas se descarta al diario.
 *
 * Temas del sistema: telemetria/medicion · alarmas/cambio · pim/<Tipo>/<create|update> · colmena/<par>/<tema> · nucleo/…
 */
declare(strict_types=1);

namespace Nucleo;

function publicar(string $tema, array $datos, ?string $origen = null): int {
    if (!preg_match('#^[a-z0-9_\-]+(/[A-Za-z0-9_\-.]+){0,8}$#', $tema)) throw new Fallo(422, "Tema inválido: $tema (niveles separados por /, sin comodines)");
    q('INSERT INTO nucleo_evento (ts, tema, origen, datos) VALUES (?,?,?,?)',
      [ahora(), $tema, mb_substr($origen ?? ($GLOBALS['nucleo_modulo_actual'] ?? ($GLOBALS['nucleo_usuario']['usuario'] ?? 'nucleo')), 0, 60), json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)]);
    return (int) pdo()->lastInsertId();
}
function tema_coincide(string $patron, string $tema): bool {
    $p = explode('/', $patron); $t = explode('/', $tema);
    foreach ($p as $i => $n) {
        if ($n === '#') return true;
        if (!isset($t[$i])) return false;
        if ($n !== '+' && $n !== $t[$i]) return false;
    }
    return count($p) === count($t);
}
function eventos(int $desde, ?string $patron = null, int $max = 200): array {
    $r = [];
    foreach (filas('SELECT * FROM nucleo_evento WHERE id > ? ORDER BY id LIMIT ' . max(1, min(1000, $max * 5)), [$desde]) as $e) {
        if ($patron && !tema_coincide($patron, $e['tema'])) continue;
        $e['id'] = (int) $e['id']; $e['datos'] = json_decode($e['datos'], true);
        $r[] = $e; if (count($r) >= $max) break;
    }
    return $r;
}
/** Entrega los eventos pendientes a los módulos suscriptos (lo llama el planificador en cada tick). */
function despachar(float $presupuesto = 5.0): array {
    $fin = microtime(true) + $presupuesto; $n = 0; $err = [];
    $ultimo = (int) (valor('SELECT MAX(id) FROM nucleo_evento') ?? 0);
    foreach (activos() as $id => $m) foreach ((array) $m['suscribe'] as $patron => $archivo) {
        $sus = "modulo:$id:$patron";
        $s = fila('SELECT * FROM nucleo_suscripcion WHERE suscriptor = ?', [$sus]);
        if (!$s) { q('INSERT INTO nucleo_suscripcion (suscriptor, cursor_id) VALUES (?, ?)', [$sus, $ultimo]); continue; }   // empieza desde ahora
        $cursor = (int) $s['cursor_id']; $fallos = (int) $s['fallos'];
        if ($cursor >= $ultimo) continue;
        incluir($id);
        $fn = require $m['dir'] . '/' . $archivo;
        while (microtime(true) < $fin) {
            $lote = filas('SELECT * FROM nucleo_evento WHERE id > ? ORDER BY id LIMIT 200', [$cursor]);
            if (!$lote) break;
            foreach ($lote as $e) {
                if (tema_coincide($patron, $e['tema'])) {
                    $GLOBALS['nucleo_modulo_actual'] = $id;
                    try { $fn(['id' => (int) $e['id'], 'ts' => $e['ts'], 'tema' => $e['tema'], 'origen' => $e['origen'], 'datos' => json_decode($e['datos'], true)], ctx($id)); $n++; $fallos = 0; }
                    catch (\Throwable $x) {
                        $fallos++;
                        q('UPDATE nucleo_suscripcion SET fallos = ?, ultimo_error = ? WHERE suscriptor = ?', [$fallos, texto_corto($x->getMessage(), 500), $sus]);
                        if ($fallos < 5) { $err[] = "$sus: " . $x->getMessage(); break 2; }
                        asentar('bus.descartado', ['evento' => (int) $e['id'], 'tema' => $e['tema'], 'error' => texto_corto($x->getMessage(), 300)], $sus);
                        $fallos = 0;
                    } finally { unset($GLOBALS['nucleo_modulo_actual']); }
                }
                $cursor = (int) $e['id'];
                q('UPDATE nucleo_suscripcion SET cursor_id = ?, fallos = ? WHERE suscriptor = ?', [$cursor, $fallos, $sus]);
                if (microtime(true) >= $fin) break 2;
            }
        }
    }
    return ['entregados' => $n, 'errores' => $err];
}
/** Retención: borra eventos viejos que ya leyeron todos los suscriptores. */
function limpiar_eventos(): int {
    $dias = max(1, (int) (cfg()['retencion_eventos_dias'] ?? 7));
    $min = (int) (valor('SELECT MIN(cursor_id) FROM nucleo_suscripcion') ?? PHP_INT_MAX);
    $n = q('DELETE FROM nucleo_evento WHERE ts < DATE_SUB(NOW(), INTERVAL ? DAY) AND id <= ?', [$dias, $min])->rowCount();
    q('DELETE FROM nucleo_sobre_visto WHERE ts < DATE_SUB(NOW(), INTERVAL 9 DAY)');
    q("DELETE FROM nucleo_rpc WHERE creado < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    return $n;
}
