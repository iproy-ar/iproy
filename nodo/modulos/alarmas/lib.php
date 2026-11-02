<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Máquina de estados de alarma según ANSI/ISA-18.2-2016 (IEC 62682), figura «Alarm state transition diagram»:
 *
 *   normal ──condición──▶ no-reconocida ──reconocer──▶ reconocida ──retorno──▶ normal (o enclavada si «enclavar»)
 *      ▲                      │ retorno                                          enclavada ──restablecer──▶ normal
 *      └──reconocer── rtn-no-reconocida ◀──┘                (rtn-no-reconocida ──condición──▶ no-reconocida)
 *   Cualquier estado ⇄ archivada (shelved, con vencimiento) · suprimida (por diseño) · fuera-servicio (mantenimiento):
 *   en esos tres la condición se sigue evaluando pero no se anuncia; al restaurar se recalcula.
 */
declare(strict_types=1);

namespace Modulos\Alarmas;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, ahora, publicar, asentar, pim};

const ESTADOS = ['normal', 'no-reconocida', 'reconocida', 'rtn-no-reconocida', 'enclavada', 'archivada', 'suprimida', 'fuera-servicio'];
const TIPOS = ['alta', 'alta-alta', 'baja', 'baja-baja'];
const PRIORIDAD_PIM = [1 => 'stat', 2 => 'asap', 3 => 'urgent', 4 => 'routine'];
const SIN_ANUNCIO = ['archivada', 'suprimida', 'fuera-servicio'];

function condicion(array $d, float $v, bool $activa): bool {
    $alta = str_starts_with($d['tipo'], 'alta');
    $l = (float) $d['limite']; $b = abs((float) $d['banda']);
    // histéresis (banda muerta): activa al cruzar el límite, se normaliza recién al volver más allá de la banda
    return $alta ? ($activa ? $v > $l - $b : $v > $l) : ($activa ? $v < $l + $b : $v < $l);
}
function estado_de(int $id): array {
    $e = fila('SELECT * FROM alm_estado WHERE def_id = ?', [$id]);
    if (!$e) { q("INSERT INTO alm_estado (def_id, estado) VALUES (?, 'normal')", [$id]); $e = fila('SELECT * FROM alm_estado WHERE def_id = ?', [$id]); }
    return $e;
}
function pasar(array $d, array $e, string $a, string $evento, array $set = [], ?float $valor = null, ?string $nota = null): void {
    $set['estado'] = $a; $set['desde'] = ahora();
    $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
    q("UPDATE alm_estado SET $cols WHERE def_id = ?", [...array_values($set), (int) $d['id']]);
    $u = $GLOBALS['nucleo_usuario']['usuario'] ?? null;
    q('INSERT INTO alm_historial (def_id, ts, evento, de, a, valor, usuario, nota) VALUES (?,?,?,?,?,?,?,?)',
      [(int) $d['id'], ahora(), $evento, $e['estado'], $a, $valor ?? ($e['valor'] !== null ? (float) $e['valor'] : null), $u, $nota !== null ? mb_substr($nota, 0, 255) : null]);
    publicar('alarmas/cambio', ['id' => (int) $d['id'], 'etiqueta' => $d['etiqueta'], 'activo_id' => $d['activo_id'], 'punto' => $d['punto'],
        'evento' => $evento, 'de' => $e['estado'], 'a' => $a, 'prioridad' => (int) $d['prioridad'], 'mensaje' => $d['mensaje'], 'valor' => $valor, 'usuario' => $u], 'alarmas');
    if ($evento !== 'activada' && $evento !== 'retorno') asentar('alarma.' . $evento, ['de' => $e['estado'], 'a' => $a, 'nota' => $nota], $d['etiqueta']);
}
/** Evalúa un valor nuevo (o el último, si $valor es null y vence un retardo). */
function evaluar(array $d, ?float $valor, string $ts): void {
    $e = estado_de((int) $d['id']);
    $valor ??= $e['valor'] !== null ? (float) $e['valor'] : null;
    if ($valor === null) return;
    $c = condicion($d, $valor, (bool) $e['condicion']);
    $set = ['valor' => $valor];
    // retardo de activación (on-delay): la condición tiene que sostenerse retardo_s segundos
    if ($c && !$e['condicion'] && (int) $d['retardo_s'] > 0) {
        $pend = $e['pendiente_desde'] ?? null;
        if (!$pend) { q('UPDATE alm_estado SET pendiente_desde = ?, valor = ? WHERE def_id = ?', [$ts, $valor, $d['id']]); return; }
        if (strtotime($ts) - strtotime((string) $pend) < (int) $d['retardo_s']) { q('UPDATE alm_estado SET valor = ? WHERE def_id = ?', [$valor, $d['id']]); return; }
    }
    $set['pendiente_desde'] = null;
    $antes = (bool) $e['condicion']; $set['condicion'] = $c ? 1 : 0;
    if ($c === $antes || in_array($e['estado'], SIN_ANUNCIO, true)) {
        q('UPDATE alm_estado SET valor = ?, condicion = ?, pendiente_desde = NULL WHERE def_id = ?', [$valor, $set['condicion'], $d['id']]);
        return;
    }
    if ($c) {   // aparece la condición
        if (in_array($e['estado'], ['normal', 'rtn-no-reconocida'], true)) {
            pasar($d, $e, 'no-reconocida', 'activada', $set + ['activada' => $ts], $valor);
            if ($d['genera_orden'] && !$e['orden']) orden($d, $valor);
        } else q('UPDATE alm_estado SET valor = ?, condicion = 1 WHERE def_id = ?', [$valor, $d['id']]);
    } else {    // retorno a normal de la condición
        if ($e['estado'] === 'no-reconocida') pasar($d, $e, 'rtn-no-reconocida', 'retorno', $set, $valor);
        elseif ($e['estado'] === 'reconocida') pasar($d, $e, $d['enclavar'] ? 'enclavada' : 'normal', 'retorno', $set + ($d['enclavar'] ? [] : ['orden' => null]), $valor);
        else q('UPDATE alm_estado SET valor = ?, condicion = 0 WHERE def_id = ?', [$valor, $d['id']]);
    }
}
/** Acciones del operador (todas quedan en el historial y en el diario firmado). */
function accion(int $id, string $acc, array $c, array $cfg): array {
    $d = fila('SELECT * FROM alm_definicion WHERE id = ?', [$id]) ?? throw new Fallo(404, "No existe la alarma $id");
    $e = estado_de($id);
    $nota = trim((string) ($c['nota'] ?? $c['motivo'] ?? '')) ?: null;
    switch ($acc) {
        case 'reconocer':
            if ($e['estado'] === 'no-reconocida') pasar($d, $e, 'reconocida', 'reconocida', [], null, $nota);
            elseif ($e['estado'] === 'rtn-no-reconocida') pasar($d, $e, 'normal', 'reconocida', ['orden' => null], null, $nota);
            else throw new Fallo(409, "La alarma está «{$e['estado']}»: no hay nada que reconocer");
            break;
        case 'restablecer':
            if ($e['estado'] !== 'enclavada') throw new Fallo(409, 'Solo se restablece una alarma enclavada');
            if ($e['condicion']) throw new Fallo(409, 'La condición sigue presente');
            pasar($d, $e, 'normal', 'restablecida', ['orden' => null], null, $nota);
            break;
        case 'archivar': case 'suprimir': case 'fuera-servicio':
            if ($acc !== 'archivar') \Nucleo\exigir('alarmas.configurar');      // suprimir y sacar de servicio es decisión de ingeniería, no del turno
            if (!$nota) throw new Fallo(422, 'Indicá el motivo (queda registrado)');
            if (in_array($e['estado'], SIN_ANUNCIO, true)) throw new Fallo(409, "La alarma ya está «{$e['estado']}»");
            $a = ['archivar' => 'archivada', 'suprimir' => 'suprimida', 'fuera-servicio' => 'fuera-servicio'][$acc];
            $set = ['estado_previo' => $e['estado'], 'motivo' => $nota];
            if ($acc === 'archivar') {
                $min = (int) ($c['minutos'] ?? 60);
                if ($min < 1 || $min > (int) $cfg['archivar_max_min']) throw new Fallo(422, "Archivar: entre 1 y {$cfg['archivar_max_min']} minutos (ISA-18.2: el archivo siempre vence)");
                $set['archivada_hasta'] = date('Y-m-d H:i:s', time() + $min * 60);
            }
            pasar($d, $e, $a, $a, $set, null, $nota);
            break;
        case 'restaurar':
            if ($e['estado'] !== 'archivada') \Nucleo\exigir('alarmas.configurar');
            if (!in_array($e['estado'], SIN_ANUNCIO, true)) throw new Fallo(409, 'La alarma no está archivada, suprimida ni fuera de servicio');
            restaurar($d, $e, $nota);
            break;
        default: throw new Fallo(404, "Acción desconocida: $acc");
    }
    return vista($id);
}
function restaurar(array $d, array $e, ?string $nota = null): void {
    $a = $e['condicion'] ? 'no-reconocida' : 'normal';
    pasar($d, $e, $a, 'restaurada', ['estado_previo' => null, 'archivada_hasta' => null, 'motivo' => null] + ($e['condicion'] ? ['activada' => ahora()] : ['orden' => null]), null, $nota);
    // si al volver la condición sigue presente, se trata como una activación (incluida la orden automática)
    if ($e['condicion'] && $d['genera_orden'] && !$e['orden'] && $e['valor'] !== null) orden($d, (float) $e['valor']);
}
/** Orden automática en PIM para alarmas de prioridad alta (la decide la definición, no el operador). */
function orden(array $d, float $valor): void {
    try {
        $r = pim('POST', 'ServiceRequest', ['resourceType' => 'ServiceRequest', 'status' => 'active', 'intent' => 'order',
            'priority' => PRIORIDAD_PIM[(int) $d['prioridad']] ?? 'urgent', 'subject' => ['reference' => 'Device/' . $d['activo_id']],
            'code' => ['coding' => [['code' => 'ALARMA']], 'text' => 'Alarma ' . $d['etiqueta']],
            'requester' => ['display' => 'Módulo de alarmas del nodo'],
            'note' => [['text' => "[Alarma {$d['etiqueta']} · prioridad {$d['prioridad']}] {$d['mensaje']} — valor $valor en {$d['punto']} (límite {$d['tipo']} {$d['limite']})"
                . ($d['accion'] ? "\nAcción esperada: {$d['accion']}" : '')]]], ['rol' => 'GM']);
        if ($r['http'] === 201) {
            $t = (string) ($r['cab']['x-pim-task'] ?? '');
            q('UPDATE alm_estado SET orden = ? WHERE def_id = ?', [$t, $d['id']]);
            asentar('alarma.orden', ['task' => $t, 'valor' => $valor], $d['etiqueta']);
        } else asentar('alarma.orden_fallida', ['http' => $r['http'], 'error' => $r['cuerpo']['issue'][0]['diagnostics'] ?? null], $d['etiqueta']);
    } catch (\Throwable $x) { asentar('alarma.orden_fallida', ['error' => $x->getMessage()], $d['etiqueta']); }
}
function vista(int $id): array {
    return fila('SELECT d.*, e.estado, e.condicion, e.valor, e.desde, e.activada, e.archivada_hasta, e.motivo, e.orden, e.estado_previo
                 FROM alm_definicion d LEFT JOIN alm_estado e ON e.def_id = d.id WHERE d.id = ?', [$id]) ?? [];
}
function validar_def(array $c, bool $nuevo): array {
    $f = [];
    foreach (['etiqueta', 'activo_id', 'punto', 'tipo', 'mensaje'] as $k) if ($nuevo || isset($c[$k])) {
        $v = trim((string) ($c[$k] ?? '')); if ($v === '') throw new Fallo(422, "Falta $k"); $f[$k] = $v;
    }
    if (isset($f['tipo']) && !in_array($f['tipo'], TIPOS, true)) throw new Fallo(422, 'tipo: ' . implode(' | ', TIPOS));
    if (isset($f['punto'])) { $f['punto'] = strtoupper($f['punto']); if (!preg_match('/^[A-Z0-9\-]{2,40}$/', $f['punto'])) throw new Fallo(422, 'punto: A-Z 0-9 -'); }
    if (isset($f['etiqueta']) && !preg_match('/^[A-Za-z0-9_.\-]{2,60}$/', $f['etiqueta'])) throw new Fallo(422, 'etiqueta: letras, números, _ . - (p. ej. PT-101-H)');
    foreach (['limite', 'banda'] as $k) if ($nuevo && $k === 'limite' || isset($c[$k])) { if (!is_numeric($c[$k] ?? null)) throw new Fallo(422, "$k debe ser numérico"); $f[$k] = (float) $c[$k]; }
    if (isset($c['retardo_s'])) $f['retardo_s'] = max(0, min(3600, (int) $c['retardo_s']));
    if (isset($c['prioridad']) || $nuevo) { $f['prioridad'] = (int) ($c['prioridad'] ?? 3); if ($f['prioridad'] < 1 || $f['prioridad'] > 4) throw new Fallo(422, 'prioridad: 1 (crítica) a 4 (baja)'); }
    foreach (['causa', 'accion'] as $k) if (isset($c[$k])) $f[$k] = mb_substr((string) $c[$k], 0, 500);
    foreach (['enclavar', 'genera_orden', 'habilitada'] as $k) if (isset($c[$k])) $f[$k] = $c[$k] ? 1 : 0;
    return $f;
}
/** Indicadores de desempeño del sistema de alarmas (ISA-18.2 §16, EEMUA 191). */
function kpi(array $cfg): array {
    $act24 = (int) (\Nucleo\valor("SELECT COUNT(*) FROM alm_historial WHERE evento = 'activada' AND ts > DATE_SUB(NOW(), INTERVAL 24 HOUR)") ?? 0);
    $pico = (int) (\Nucleo\valor("SELECT MAX(n) FROM (SELECT COUNT(*) n FROM alm_historial WHERE evento = 'activada' AND ts > DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY FLOOR(UNIX_TIMESTAMP(ts) / 600)) x") ?? 0);
    return [
        'activaciones_24h' => $act24,
        'promedio_por_10min' => round($act24 / 144, 2),
        'pico_10min' => $pico,
        'avalancha' => $pico > 10,                                   // EEMUA 191: más de 10 en 10 min = inundación
        'objetivo' => 'ISA-18.2: ~1 por 10 min es manejable; ≥ 2 exige atención; > 10 en 10 min es avalancha',
        'estancadas' => filas("SELECT d.id, d.etiqueta, e.estado, e.activada FROM alm_estado e JOIN alm_definicion d ON d.id = e.def_id
                               WHERE e.estado IN ('no-reconocida','reconocida','enclavada') AND e.activada < DATE_SUB(NOW(), INTERVAL ? HOUR)", [(int) $cfg['estancada_h']]),
        'intermitentes' => filas("SELECT h.def_id AS id, d.etiqueta, COUNT(*) AS n FROM alm_historial h JOIN alm_definicion d ON d.id = h.def_id
                                  WHERE h.evento = 'activada' AND h.ts > DATE_SUB(NOW(), INTERVAL ? SECOND) GROUP BY h.def_id HAVING n >= ?",
                                  [(int) $cfg['intermitente_s'] * 10, (int) $cfg['intermitente_n']]),
        'peores_10' => filas("SELECT h.def_id AS id, d.etiqueta, d.mensaje, COUNT(*) AS n FROM alm_historial h JOIN alm_definicion d ON d.id = h.def_id
                              WHERE h.evento = 'activada' AND h.ts > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY h.def_id ORDER BY n DESC LIMIT 10"),
        'archivadas' => (int) (\Nucleo\valor("SELECT COUNT(*) FROM alm_estado WHERE estado = 'archivada'") ?? 0),
        'suprimidas_o_fuera' => (int) (\Nucleo\valor("SELECT COUNT(*) FROM alm_estado WHERE estado IN ('suprimida','fuera-servicio')") ?? 0),
    ];
}
