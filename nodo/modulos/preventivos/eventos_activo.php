<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Un activo cambió en el Servidor PIM: sus tareas y planes muestran el código y el nombre vigentes; si salió de servicio se pausan. */
declare(strict_types=1);

use function Nucleo\{q, asentar};

return function (array $ev, array $ctx): void {
    $d = $ev['datos']; $id = (string) ($d['id'] ?? ''); if ($id === '') return;
    $nom = isset($d['nombre']) ? mb_substr((string) $d['nombre'], 0, 200) : null; $cod = isset($d['codigo']) ? mb_substr((string) $d['codigo'], 0, 80) : null;
    q('UPDATE pmp_tarea SET activo_codigo = COALESCE(?, activo_codigo), activo_nombre = COALESCE(?, activo_nombre) WHERE activo_id = ?', [$cod, $nom, $id]);
    q('UPDATE prev_plan SET activo_nombre = COALESCE(?, activo_nombre) WHERE activo_id = ?', [$nom, $id]);
    q('UPDATE pmp_orden SET activo_nombre = COALESCE(?, activo_nombre) WHERE activo_id = ?', [$nom, $id]);
    $est = (string) ($d['estado'] ?? '');
    if ($est === 'inactive' || $est === 'entered-in-error') {
        $t = q("UPDATE pmp_tarea SET activa = 0, pausa = 'baja', modificado = NOW() WHERE activo_id = ? AND activa = 1", [$id])->rowCount();
        $p = q("UPDATE prev_plan SET habilitado = 0, pausa = 'baja' WHERE activo_id = ? AND habilitado = 1", [$id])->rowCount();
        if ($t + $p) asentar('preventivos.pausa_baja', ['tareas' => $t, 'planes' => $p, 'codigo' => $cod], $id, 'sistema');
    } elseif ($est === 'active') {
        $t = q("UPDATE pmp_tarea SET activa = 1, pausa = NULL, modificado = NOW() WHERE activo_id = ? AND pausa = 'baja'", [$id])->rowCount();
        $p = q("UPDATE prev_plan SET habilitado = 1, pausa = NULL WHERE activo_id = ? AND pausa = 'baja'", [$id])->rowCount();
        if ($t + $p) asentar('preventivos.reanuda', ['tareas' => $t, 'planes' => $p, 'codigo' => $cod], $id, 'sistema');
    }
};
