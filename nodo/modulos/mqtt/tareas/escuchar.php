<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Conecta, publica lo pendiente (alarmas), escucha unos segundos y convierte cada mensaje en telemetria/medicion. */
declare(strict_types=1);

use Modulos\Mqtt\Cliente;
use function Modulos\Mqtt\{interpretar_tema, interpretar_carga};
use function Nucleo\{filas, q, publicar, nodo, estado};

return function (array $ctx): string {
    $c = $ctx['cfg'];
    if (empty($c['habilitado'])) return 'deshabilitado (modulo config mqtt)';
    $r = estado('mqtt_residente');
    if (($r['ts'] ?? 0) > time() - 30 && ($r['estado'] ?? '') === 'conectado') return 'lo atiende el proceso residente (' . ($r['mediciones'] ?? 0) . ' mediciones)';
    $c['cliente_id'] = $c['cliente_id'] ?: 'nodo-' . strtolower(nodo()['id']);
    $m = new Cliente($c);
    $m->conectar();
    $pub = 0; $med = 0; $ign = 0;
    try {
        foreach (filas('SELECT * FROM mqtt_saliente ORDER BY id LIMIT 200') as $s) {
            $m->publicar($s['tema'], $s['carga'], (int) $c['qos'], (bool) $s['retener']);
            q('DELETE FROM mqtt_saliente WHERE id = ?', [$s['id']]); $pub++;
        }
        $m->suscribir(array_column((array) $c['suscripciones'], 'filtro'), (int) $c['qos']);
        foreach ($m->escuchar((float) $c['segundos_escucha']) as $msg) {
            $ok = false;
            foreach ((array) $c['suscripciones'] as $s) {
                if (!\Nucleo\tema_coincide($s['filtro'], $msg['tema'])) continue;
                $t = interpretar_tema($msg['tema'], $s['plantilla']); $v = interpretar_carga($msg['carga']);
                if (!$t || !$v || empty($t['activo']) || empty($t['punto'])) continue;
                publicar('telemetria/medicion', ['activo' => $t['activo'], 'punto' => strtoupper($t['punto']), 'origen' => 'mqtt', 'refes' => $t['refes'] ?? null] + $v, 'mqtt');
                $ok = true; $med++; break;
            }
            if (!$ok) $ign++;
        }
    } finally { $m->cerrar(); }
    estado('mqtt', ['ultimo' => date('c'), 'mediciones' => $med, 'ignorados' => $ign, 'publicados' => $pub]);
    return "$med mediciones, $pub publicados" . ($ign ? ", $ign ignorados" : '');
};
