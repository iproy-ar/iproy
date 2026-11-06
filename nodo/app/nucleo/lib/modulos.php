<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Módulos cargables (como los de Linux): cada carpeta modulos/<id>/ trae un manifiesto modulo.json.
 *
 *  - ABI: el núcleo carga solo módulos con "abi" igual a su ABI; "estabilidad" sigue los niveles de Documentation/ABI
 *    de Linux (estable | pruebas | obsoleto | retirado): un módulo «estable» no rompe su interfaz por 2 años.
 *  - Dependencias: "requiere": ["pim", "telemetria>=1.0"] → orden topológico; si falta una, el módulo queda en «dependencia».
 *  - Licencia: si no es libre (lista SPDX abajo) el nodo queda «contaminado» (tainted), visible en /api/proc y en el ping.
 *  - Migraciones: modulos/<id>/sql/NNN_nombre.sql se aplican una sola vez, en orden, registradas con su sha256.
 *  - Puntos de enganche: "rutas" (API /api/m/<id>/…), "tareas" (planificador), "suscribe" (bus), "ui" (pestaña de la app),
 *    "capacidades" (permisos por rol), "config_defecto" (data/nucleo/modulos/<id>.json lo sobrescribe).
 */
declare(strict_types=1);

namespace Nucleo;

const LICENCIAS_LIBRES = ['AGPL-3.0-or-later', 'AGPL-3.0-only', 'GPL-3.0-or-later', 'GPL-3.0-only', 'GPL-2.0-or-later', 'LGPL-3.0-or-later',
    'LGPL-2.1-or-later', 'MPL-2.0', 'EUPL-1.2', 'Apache-2.0', 'MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'ISC', '0BSD', 'CC0-1.0'];
const ESTABILIDADES = ['estable', 'pruebas', 'obsoleto', 'retirado'];

function dir_modulos(): string { return raiz() . '/modulos'; }

/** Lee y valida todos los manifiestos (sin ejecutar código de los módulos). */
function manifiestos(): array {
    if (isset($GLOBALS['nucleo_cache']['man'])) return $GLOBALS['nucleo_cache']['man'];
    $m = [];
    foreach (glob(dir_modulos() . '/*/modulo.json') ?: [] as $f) {
        $dir = dirname($f); $id = basename($dir);
        $j = json_decode((string) file_get_contents($f), true);
        $err = [];
        if (!is_array($j)) { $m[$id] = ['id' => $id, 'dir' => $dir, 'errores' => ['modulo.json no es JSON válido']]; continue; }
        if (($j['id'] ?? '') !== $id) $err[] = "el id del manifiesto debe ser «{$id}» (nombre de la carpeta)";
        if (!preg_match('/^[a-z][a-z0-9_]{1,38}$/', $id)) $err[] = 'id: minúsculas, números y _ (2-39)';
        if (!preg_match('/^\d+\.\d+\.\d+$/', (string) ($j['version'] ?? ''))) $err[] = 'version: semver x.y.z';
        if ((int) ($j['abi'] ?? 0) !== ABI) $err[] = 'abi ' . ($j['abi'] ?? '?') . ' incompatible con el núcleo (ABI ' . ABI . ')';
        if (isset($j['nucleo']) && !version_en_rango(VERSION, (string) $j['nucleo'])) $err[] = "requiere núcleo {$j['nucleo']} (este es " . VERSION . ')';
        if (!in_array($j['estabilidad'] ?? 'pruebas', ESTABILIDADES, true)) $err[] = 'estabilidad: ' . implode(' | ', ESTABILIDADES);
        if (($j['estabilidad'] ?? '') === 'retirado') $err[] = 'módulo retirado: ya no se carga';
        if (empty($j['licencia'])) $err[] = 'falta licencia (identificador SPDX)';
        foreach (['rutas', 'lib'] as $k) if (!empty($j[$k]) && !is_file("$dir/{$j[$k]}")) $err[] = "falta el archivo $k: {$j[$k]}";
        foreach ((array) ($j['tareas'] ?? []) as $t) if (!is_file("$dir/" . ($t['archivo'] ?? '-'))) $err[] = 'falta la tarea ' . ($t['archivo'] ?? '?');
        foreach ((array) ($j['suscribe'] ?? []) as $tema => $a) if (!is_file("$dir/$a")) $err[] = "falta el manejador $a de «{$tema}»";
        $j += ['nombre' => $id, 'descripcion' => '', 'requiere' => [], 'capacidades' => [], 'tareas' => [], 'suscribe' => [], 'config_defecto' => [],
               'activo_por_defecto' => true, 'estabilidad' => 'pruebas', 'ui' => null];
        $j['dir'] = $dir; $j['errores'] = $err;
        $j['libre'] = in_array($j['licencia'], LICENCIAS_LIBRES, true);
        $m[$id] = $j;
    }
    ksort($m);
    return $GLOBALS['nucleo_cache']['man'] = $m;
}
/** «>=1.0 <2.0» (rango del manifiesto, esquema de la especificación 0.9.1) contra una versión. */
function version_en_rango(string $v, string $rango): bool {
    foreach (preg_split('/\s+/', trim($rango)) as $c) {
        if ($c === '' || !preg_match('/^(>=|<=|>|<|=)?(\d+(?:\.\d+){0,2})$/', $c, $m)) return false;
        if (!version_compare($v, $m[2], ['>=' => '>=', '<=' => '<=', '>' => '>', '<' => '<', '=' => '==', '' => '=='][$m[1]])) return false;
    }
    return true;
}
function olvidar_cache(): void { $GLOBALS['nucleo_cache'] = []; }

/** Orden de carga (topológico) y estado efectivo de cada módulo. */
function resolver(): array {
    if (isset($GLOBALS['nucleo_cache']['res'])) return $GLOBALS['nucleo_cache']['res'];
    $man = manifiestos();
    $db = [];
    try { foreach (filas('SELECT id, activo FROM nucleo_modulo') as $f) $db[$f['id']] = (int) $f['activo']; } catch (\Throwable) {}
    $estado = [];
    foreach ($man as $id => $m) {
        $quiere = $db[$id] ?? ($m['activo_por_defecto'] ? 1 : 0);
        $estado[$id] = $m['errores'] ? 'error' : ($quiere ? 'pendiente' : 'desactivado');
    }
    $orden = []; $visit = [];
    $ver = function (string $id, array $pila) use (&$ver, &$orden, &$visit, &$estado, $man): bool {
        if (($estado[$id] ?? null) === 'cargado') return true;
        if (($estado[$id] ?? null) !== 'pendiente') return false;
        if (in_array($id, $pila, true)) { $estado[$id] = 'ciclo'; return false; }
        foreach ((array) $man[$id]['requiere'] as $dep) {
            preg_match('/^([a-z0-9_]+)(?:>=(\d+\.\d+(?:\.\d+)?))?$/', (string) $dep, $x);
            $did = $x[1] ?? ''; $min = $x[2] ?? null;
            if (!isset($man[$did]) || !$ver($did, [...$pila, $id]) || ($min && version_compare($man[$did]['version'], $min, '<'))) { $estado[$id] = 'dependencia'; return false; }
        }
        $estado[$id] = 'cargado'; $orden[] = $id; return true;
    };
    foreach (array_keys($man) as $id) $ver($id, []);
    return $GLOBALS['nucleo_cache']['res'] = ['orden' => $orden, 'estado' => $estado];
}
function activos(): array { $r = resolver(); return array_map(fn($id) => manifiestos()[$id], array_combine($r['orden'], $r['orden'])); }
function modulo(string $id): ?array { return activos()[$id] ?? null; }
function contaminado(): array { return array_values(array_map(fn($m) => $m['id'] . ' (' . $m['licencia'] . ')', array_filter(activos(), fn($m) => !$m['libre']))); }

/** Configuración efectiva: config_defecto del manifiesto + data/nucleo/modulos/<id>.json (datos de la institución, nunca en el repositorio). */
function cfg_modulo(string $id, ?array $nuevo = null): array {
    $m = manifiestos()[$id] ?? throw new Fallo(404, "No existe el módulo $id");
    $f = dir_datos('modulos') . "/$id.json";
    if ($nuevo !== null) escribir_json($f, $nuevo, true);
    return array_replace_recursive((array) $m['config_defecto'], leer_json($f));
}
/** Carga la biblioteca del módulo (una vez). */
function incluir(string $id): void {
    static $hecho = [];
    if (isset($hecho[$id])) return;
    $m = modulo($id) ?? throw new Fallo(503, "El módulo $id no está cargado");
    $hecho[$id] = true;
    if (!empty($m['lib'])) require_once $m['dir'] . '/' . $m['lib'];
}
function ctx(string $id, array $extra = []): array {
    return ['modulo' => $id, 'cfg' => cfg_modulo($id), 'nodo' => nodo(), 'usuario' => $GLOBALS['nucleo_usuario'] ?? null] + $extra;
}

// ---------------------------------------------------------------- migraciones
function asegurar_tabla_migraciones(): void {
    pdo()->exec('CREATE TABLE IF NOT EXISTS nucleo_migracion (modulo VARCHAR(40) NOT NULL, numero INT NOT NULL, archivo VARCHAR(160) NOT NULL,
        sha256 CHAR(64) NOT NULL, aplicado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (modulo, numero)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}
/** Aplica las migraciones pendientes de un directorio sql/. Devuelve los archivos aplicados. */
function migrar(string $modulo, string $dir): array {
    asegurar_tabla_migraciones();
    $hechas = []; foreach (filas('SELECT numero, sha256 FROM nucleo_migracion WHERE modulo = ?', [$modulo]) as $f) $hechas[(int) $f['numero']] = $f['sha256'];
    $aplicadas = [];
    foreach (glob("$dir/*.sql") ?: [] as $a) {
        if (!preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/', basename($a), $x)) continue;
        $n = (int) $x[1]; $sql = (string) file_get_contents($a); $sha = hash('sha256', $sql);
        if (isset($hechas[$n])) {
            if ($hechas[$n] !== $sha) asentar('modulo.migracion_alterada', ['archivo' => basename($a)], $modulo);   // nunca se reaplica: se avisa
            continue;
        }
        foreach (dividir_sql($sql) as $st) pdo()->exec($st);
        q('INSERT INTO nucleo_migracion (modulo, numero, archivo, sha256) VALUES (?,?,?,?)', [$modulo, $n, basename($a), $sha]);
        $aplicadas[] = basename($a);
    }
    return $aplicadas;
}
function dividir_sql(string $sql): array {
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', preg_split('/;\s*$/m', $sql)), fn($s) => $s !== ''));
}

/** Arranque: núcleo + módulos activos (migraciones, registro de estado, tareas). Idempotente. */
function arrancar(): array {
    $res = ['nucleo' => migrar('nucleo', dirname(__DIR__) . '/sql')];
    identidad();
    $r = resolver();
    foreach (manifiestos() as $id => $m) {
        $est = $r['estado'][$id]; $err = $m['errores'] ? implode('; ', $m['errores']) : null;
        if ($est === 'cargado') {
            try { if (is_dir($m['dir'] . '/sql')) $res[$id] = migrar($id, $m['dir'] . '/sql'); }
            catch (\Throwable $e) { $est = 'error'; $err = 'migración: ' . $e->getMessage(); }
        }
        if ($est === 'dependencia') $err = 'falta o no cargó una dependencia: ' . implode(', ', (array) $m['requiere']);
        $prev = fila('SELECT version, estado FROM nucleo_modulo WHERE id = ?', [$id]);
        q('INSERT INTO nucleo_modulo (id, version, activo, estado, error) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE version = VALUES(version), estado = VALUES(estado), error = VALUES(error)',
          [$id, $m['version'] ?? '0.0.0', $est === 'desactivado' ? 0 : 1, $est, $err]);
        if (!$prev || $prev['version'] !== ($m['version'] ?? '') || $prev['estado'] !== $est)
            asentar('modulo.estado', ['version' => $m['version'] ?? null, 'estado' => $est, 'error' => $err], $id);
    }
    registrar_tareas();
    $res['catalogos'] = sembrar_catalogos();
    asentar('nucleo.arranque', ['version' => VERSION, 'producto' => producto()['nombre'], 'modulos' => $r['orden'], 'contaminado' => contaminado()]);
    return $res + ['estado' => $r['estado']];
}
function activar_modulo(string $id, bool $si): void {
    if (!isset(manifiestos()[$id])) throw new Fallo(404, "No existe el módulo $id");
    if (!$si) foreach (activos() as $m) if (in_array($id, array_map(fn($d) => preg_replace('/>=.*/', '', $d), (array) $m['requiere']), true))
        throw new Fallo(409, "No se puede desactivar $id: lo requiere {$m['id']}");
    q('INSERT INTO nucleo_modulo (id, version, activo, estado) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE activo = VALUES(activo)',
      [$id, manifiestos()[$id]['version'] ?? '0.0.0', $si ? 1 : 0, $si ? 'pendiente' : 'desactivado']);
    asentar($si ? 'modulo.activado' : 'modulo.desactivado', [], $id);
    olvidar_cache();
}
