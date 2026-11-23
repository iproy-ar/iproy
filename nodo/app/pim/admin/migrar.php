<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Servidor PIM · entrada de la carga por lotes y de los ajustes que se cambian desde la pantalla.
 * Solo desde el propio equipo y con el token del worker (X-IPROY-Tick): la llama el módulo «migracion» del núcleo,
 * que ya comprobó que quien la pide es el administrador del nodo. Responde un resumen JSON (cuentas y motivos, sin datos).
 */
declare(strict_types=1);

namespace IproyPim;

require dirname(__DIR__) . '/lib/nucleo.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/modelo.php';
require dirname(__DIR__) . '/lib/api.php';
require dirname(__DIR__) . '/lib/importar.php';
require dirname(__DIR__) . '/lib/migrar.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$n = is_file(raiz() . '/data/php/nodo.php') ? (require raiz() . '/data/php/nodo.php') : [];
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || empty($n['tick_token'])
    || !hash_equals((string) $n['tick_token'], (string) ($_SERVER['HTTP_X_IPROY_TICK'] ?? ''))) {
    http_response_code(403); echo '{"ok":false,"error":"solo el nodo"}'; exit;
}
$j = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($j)) { http_response_code(400); echo '{"ok":false,"error":"cuerpo inválido"}'; exit; }
try {
    $a = (string) ($j['accion'] ?? '');
    if ($a === 'ajustes') { echo json_encode(['ok' => true, 'ajustes' => mig_ajustes(isset($j['cambio']) && is_array($j['cambio']) ? $j['cambio'] : null)]); exit; }
    if ($a === 'catalogo') { echo json_encode(['ok' => true, 'estados' => array_keys(ESTADOS_ORDEN), 'con_motivo' => CON_MOTIVO, 'tipos' => TIPOS_MANT, 'prioridades' => PRIORIDADES, 'sistemas' => SISTEMAS, 'bandas' => banda_prioridad()], JSON_UNESCAPED_UNICODE); exit; }
    if ($a !== 'lote') throw new Fallo(422, 'invalid', 'accion: lote | ajustes | catalogo');
    $lock = fopen(raiz() . '/data/pim/run/importar.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new Fallo(409, 'conflict', 'hay otra carga en curso');
    @set_time_limit(300);
    echo json_encode(['ok' => true] + migrar_lote((string) ($j['destino'] ?? ''), (array) ($j['filas'] ?? []), !empty($j['simular']), !empty($j['solo_nuevos']), !empty($j['asumir'])), JSON_UNESCAPED_UNICODE);
} catch (Fallo $e) {
    http_response_code($e->http); echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $t) {
    error_log('[pim-migrar] ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
    http_response_code(500); echo '{"ok":false,"error":"falló la carga (detalle en data/logs/php_errors.log)"}';
}
