<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Procesos residentes del nodo (los lanza y detiene <Prod>-Control junto con el worker):
 *   frankenphp php-cli tools/residente.php mqtt
 * MQTT necesita una conexión permanente: el worker de 15 s solo escucha unos segundos por ciclo y, con brokers sin sesiones
 * persistentes, se perderían mensajes. El residente mantiene la sesión, publica cada mensaje en el bus al instante y deja
 * un latido; mientras el latido esté fresco, la tarea «mqtt:escuchar» del planificador no hace nada.
 */
declare(strict_types=1);

if (PHP_SAPI === 'frankenphp' || (PHP_SAPI !== 'cli' && !str_contains(PHP_SAPI, 'cli'))) { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/nucleo/lib/nucleo.php';
Nucleo\cargar();

use Modulos\Mqtt\Cliente;
use function Modulos\Mqtt\{interpretar_tema, interpretar_carga};
use function Nucleo\{publicar, filas, q, estado, cfg_modulo, modulo, incluir, nodo, olvidar_cache, tema_coincide};

$que = $argv[1] ?? '';
if ($que !== 'mqtt') { fwrite(STDERR, "uso: residente.php mqtt\n"); exit(2); }
$parar = false;
if (function_exists('pcntl_async_signals')) { pcntl_async_signals(true); pcntl_signal(SIGTERM, function () use (&$parar) { $parar = true; }); pcntl_signal(SIGINT, function () use (&$parar) { $parar = true; }); }
$espera = 2;
while (!$parar) {
    try {
        olvidar_cache();
        if (!modulo('mqtt')) { estado('mqtt_residente', ['ts' => time(), 'pid' => getmypid(), 'estado' => 'módulo no cargado']); sleep(30); continue; }
        incluir('mqtt');
        $c = cfg_modulo('mqtt');
        if (empty($c['habilitado'])) { estado('mqtt_residente', ['ts' => time(), 'pid' => getmypid(), 'estado' => 'deshabilitado']); sleep(20); continue; }
        $c['cliente_id'] = ($c['cliente_id'] ?: 'nodo-' . strtolower(nodo()['id'])) . '-r';
        $m = new Cliente($c);
        $m->conectar();
        $m->suscribir(array_column((array) $c['suscripciones'], 'filtro'), (int) $c['qos']);
        $espera = 2; $n = 0; $desde = time();
        while (!$parar && time() - $desde < 600) {          // cada 10 min reconecta y relee la configuración
            foreach (filas('SELECT * FROM mqtt_saliente ORDER BY id LIMIT 100') as $s) { $m->publicar($s['tema'], $s['carga'], (int) $c['qos'], (bool) $s['retener']); q('DELETE FROM mqtt_saliente WHERE id = ?', [$s['id']]); }
            foreach ($m->escuchar(5.0) as $msg) foreach ((array) $c['suscripciones'] as $s) {
                if (!tema_coincide($s['filtro'], $msg['tema'])) continue;
                $t = interpretar_tema($msg['tema'], $s['plantilla']); $v = interpretar_carga($msg['carga']);
                if (!$t || !$v || empty($t['activo']) || empty($t['punto'])) continue;
                publicar('telemetria/medicion', ['activo' => $t['activo'], 'punto' => strtoupper($t['punto']), 'origen' => 'mqtt', 'refes' => $t['refes'] ?? null] + $v, 'mqtt');
                $n++; break;
            }
            estado('mqtt_residente', ['ts' => time(), 'pid' => getmypid(), 'estado' => 'conectado', 'broker' => "{$c['broker']}:{$c['puerto']}", 'mediciones' => $n]);
        }
        $m->cerrar();
    } catch (Throwable $e) {
        estado('mqtt_residente', ['ts' => time(), 'pid' => getmypid(), 'estado' => 'error', 'error' => $e->getMessage()]);
        sleep($espera); $espera = min(60, $espera * 2);                  // reintento con espera creciente
    }
}
estado('mqtt_residente', ['ts' => 0, 'pid' => getmypid(), 'estado' => 'detenido']);
