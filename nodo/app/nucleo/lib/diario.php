<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Diario del nodo (append-only, como un journal): cada asiento guarda el hash SHA-256 del anterior y va firmado
 * con la clave ed25519 del nodo. Alterar, borrar o reordenar un asiento rompe la cadena y `verificar` lo detecta.
 * Registra lo que importa auditar: arranques, módulos, usuarios, vínculos de colmena, alarmas, órdenes automáticas.
 */
declare(strict_types=1);

namespace Nucleo;

function asentar(string $tipo, array $detalle = [], ?string $objeto = null, ?string $actor = null): int {
    $actor ??= $GLOBALS['nucleo_usuario']['usuario'] ?? 'sistema';
    $p = pdo();
    valor("SELECT GET_LOCK('nucleo_diario', 10)");
    try {
        $prev = (string) (valor('SELECT hash FROM nucleo_diario ORDER BY id DESC LIMIT 1') ?? str_repeat('0', 64));
        $ts = ahora();
        $det = canonico($detalle);
        $hash = hash('sha256', $prev . "\n" . canonico([$ts, $tipo, $actor, $objeto, $det]));
        q('INSERT INTO nucleo_diario (ts, tipo, actor, objeto, detalle, hash_prev, hash, firma) VALUES (?,?,?,?,?,?,?,?)',
          [$ts, mb_substr($tipo, 0, 60), mb_substr($actor, 0, 80), $objeto !== null ? mb_substr($objeto, 0, 160) : null, $det, $prev, $hash, firmar(hex2bin($hash))]);
        return (int) $p->lastInsertId();
    } finally { valor("SELECT RELEASE_LOCK('nucleo_diario')"); }
}
/** Recalcula la cadena completa y verifica cada firma. */
function verificar_diario(): array {
    $pub = identidad()['firma_pub'];
    $prev = str_repeat('0', 64); $n = 0;
    $s = pdo()->query('SELECT * FROM nucleo_diario ORDER BY id', \PDO::FETCH_ASSOC);
    foreach ($s as $r) {
        $n++;
        $h = hash('sha256', $prev . "\n" . canonico([substr((string) $r['ts'], 0, 23), $r['tipo'], $r['actor'], $r['objeto'], $r['detalle']]));
        if ($r['hash_prev'] !== $prev) return ['ok' => false, 'asientos' => $n, 'roto_en' => (int) $r['id'], 'motivo' => 'falta o se reordenó un asiento anterior'];
        if ($h !== $r['hash']) return ['ok' => false, 'asientos' => $n, 'roto_en' => (int) $r['id'], 'motivo' => 'contenido alterado'];
        if (!verificar_firma(hex2bin($h), $r['firma'], $pub)) return ['ok' => false, 'asientos' => $n, 'roto_en' => (int) $r['id'], 'motivo' => 'firma inválida'];
        $prev = $h;
    }
    return ['ok' => true, 'asientos' => $n, 'ultimo_hash' => $prev, 'clave_publica' => $pub];
}
