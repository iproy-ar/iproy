<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Auditoría. Guarda una sola cosa propia: qué empresas o áreas ofrece cada panel («instalacion» = Auditoría Mant,
 * «equipo» = Auditor Bio). Las órdenes se leen de la copia de consulta de «reportes» y se cambian por la API PIM.
 */
declare(strict_types=1);

namespace Modulos\Auditoria;

use Nucleo\Fallo;
use function Nucleo\{q, filas, pdo, asentar};

const PANELES = ['instalacion' => 'Auditoría Mant', 'equipo' => 'Auditor Bio'];

function panel_valido(string $p): string { if (!isset(PANELES[$p])) throw new Fallo(404, 'Panel desconocido: instalacion | equipo'); return $p; }
function areas(string $panel): array { return array_column(filas('SELECT org_id FROM aud_area WHERE panel = ? ORDER BY org_id', [panel_valido($panel)]), 'org_id'); }
function guardar_areas(string $panel, array $orgs, array $u): array {
    $panel = panel_valido($panel);
    $orgs = array_values(array_unique(array_map('strval', $orgs)));
    foreach ($orgs as $o) if (!preg_match('/^[0-9A-Z]{26}$/', $o)) throw new Fallo(422, 'Empresa o área inválida');
    if (count($orgs) > 500) throw new Fallo(422, 'Demasiadas empresas');
    pdo()->beginTransaction();
    try {
        q('DELETE FROM aud_area WHERE panel = ?', [$panel]);
        foreach ($orgs as $o) q('INSERT INTO aud_area (panel, org_id, modificado, modificado_por) VALUES (?,?,?,?)', [$panel, $o, date('Y-m-d H:i:s'), (string) ($u['usuario'] ?? '')]);
        pdo()->commit();
    } catch (\Throwable $e) { if (pdo()->inTransaction()) pdo()->rollBack(); throw $e; }
    asentar('auditoria.areas', ['panel' => $panel, 'empresas' => count($orgs)], $panel);
    return $orgs;
}
