<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
declare(strict_types=1);

use Nucleo\Fallo;
use function Modulos\Mem\procesar;
use function Nucleo\{filas, estado};

return [
    // PCD-15 por HTTP (gateways sin MLLP y pruebas): {"mensaje": "MSH|^~\&|…"} → {"ack": "MSH|…\rMSA|AA|…"}
    ['POST', '/pcd15', 'mem.recibir', function (array $c) {
        $m = (string) ($c['cuerpo']['mensaje'] ?? '');
        if ($m === '' || strlen($m) > 1048576) throw new Fallo(422, 'Falta «mensaje» (HL7 v2 ER7) o supera 1 MB');
        return ['ack' => procesar($m, 'http:' . ($c['usuario']['usuario'] ?? '?'))];
    }],
    ['GET', '/mensajes', 'mem.ver', fn(array $c) => ['mensajes' => filas('SELECT * FROM mem_mensaje ORDER BY id DESC LIMIT 100'),
        'residente' => estado('mem_residente')]],
];
