<?php
/**
 * iProy · Servidor PIM v1.0 · puente de lectura iProy → PIM · 30-10-2026
 *
 * Lee SOLO las vistas iproy_fhir.* (sin datos personales; las mismas que publica la API /fhir v0.3) y refleja en iproy_pim:
 *   organismo y empresas/áreas → Organization · servicios (catálogo + inventario SIRE) → Location
 *   equipos SIRE → Device (pim-pma) · órdenes Req_IPROY → Task (pim-orden), en solo lectura (origen «legado»)
 * Idempotente: cada registro guarda la huella de sus datos de origen; solo cambia (nueva versión + Provenance) si iProy cambió.
 * Los IDs son los mismos ULID determinísticos de la API v0.3: un sistema externo ve el mismo recurso en /fhir y en /pim.
 * Nunca escribe en iProy.
 */
declare(strict_types=1);

namespace IproyPim;

use PDO;

const CLIENTE_IMPORTADOR = 'importador-iproy';
/** Estado de iProy → estado de negocio PIM (⚠ difiere del mapeo de la v0.3, que usaba el estado FHIR directo). */
const ESTADO_LEGADO = ['Revision' => 'en-revision', 'En Proceso' => 'asignada', 'Atencion' => 'en-ejecucion', 'Finalizado' => 'finalizada', 'DENEGADO' => 'rechazada'];

function pdo_fhir(): PDO {
    $v = cfg()['priv'];
    return new PDO('mysql:host=' . $v['db_host'] . ';dbname=iproy_fhir;charset=utf8mb4', $v['db_user'], $v['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]);
}
function hay_vista(PDO $f, string $v): bool {
    $q = $f->prepare('SELECT 1 FROM information_schema.views WHERE table_schema = ? AND table_name = ?'); $q->execute(['iproy_fhir', $v]);
    return (bool) $q->fetchColumn();
}

/** Alta o actualización de un registro importado. Devuelve 'nuevo' | 'cambio' | 'igual' | 'propio'. */
function reflejar(string $tabla, string $recurso, string $id, array $campos, callable $fhir): string {
    $huella = sha1(json_encode($campos, JSON_UNESCAPED_UNICODE));
    $r = fila($tabla, $id, true);
    if (!$r) {
        insertar($tabla, ['id' => $id] + $campos + ['origen' => 'legado', 'origen_huella' => $huella]);
        guardar_version($recurso, $id, 1, 'create', CLIENTE_IMPORTADOR, $fhir(fila($tabla, $id)), 'Importado de iProy');
        return 'nuevo';
    }
    if ($r['origen'] !== 'legado') return 'propio';
    if ($r['origen_huella'] === $huella) return 'igual';
    aplicar_cambios($tabla, $id, $campos + ['origen_huella' => $huella]);
    $res = $fhir(fila($tabla, $id));
    guardar_version($recurso, $id, (int) $res['meta']['versionId'], 'update', CLIENTE_IMPORTADOR, $res, 'Sincronizado desde iProy');
    return 'cambio';
}

function fecha_db(?string $v): ?string {
    if ($v === null || $v === '' || str_starts_with($v, '0000')) return null;
    return strlen($v) === 10 ? $v . ' 00:00:00' : $v;
}

function importar(): array {
    @set_time_limit(600);
    $t0 = microtime(true);
    $f = pdo_fhir();
    $p = pdo();
    $cuenta = [];
    $sumar = function (string $k, string $r) use (&$cuenta) { $cuenta[$k] ??= ['nuevo' => 0, 'cambio' => 0, 'igual' => 0, 'propio' => 0]; $cuenta[$k][$r]++; };
    $avisos = [];
    $lote = function (callable $fn) use ($p) { $p->beginTransaction(); try { $fn(); $p->commit(); } catch (\Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; } };

    // ---- Organization: organismo + empresas/áreas responsables de las órdenes
    $o = cfg()['organismo'];
    $lote(function () use ($o, $sumar) {
        $sumar('Organization', reflejar('pim_organizacion', 'Organization', org_organismo_id(),
            ['tipo' => 'organismo', 'codigo' => $o['codigo'], 'nombre' => $o['nombre'], 'refes' => $o['refes'] ?: null, 'parte_de' => null, 'activo' => 1], 'IproyPim\org_fhir'));
    });
    $empresas = [];
    if (hay_vista($f, 'v_ordenes')) {
        foreach ($f->query("SELECT DISTINCT empresa FROM v_ordenes WHERE empresa IS NOT NULL AND empresa <> ''") as $r) $empresas[] = trim((string) $r['empresa']);
        $empresas = array_values(array_unique(array_filter($empresas)));
        $lote(function () use ($empresas, $sumar) {
            foreach ($empresas as $e) {
                $int = in_array($e, (array) cfg()['empresas_internas'], true);
                $sumar('Organization', reflejar('pim_organizacion', 'Organization', ulid_legado('empresa', $e),
                    ['tipo' => $int ? 'area' : 'empresa', 'codigo' => mb_substr($e, 0, 60), 'nombre' => mb_substr($e, 0, 160), 'refes' => null,
                     'parte_de' => $int ? org_organismo_id() : null, 'activo' => 1], 'IproyPim\org_fhir'));
            }
        });
    } else $avisos[] = 'falta la vista iproy_fhir.v_ordenes (¿se importaron los datos de Requerimientos?)';

    // ---- Location: catálogo de servicios de mantenimiento
    $servicios = []; $porNombre = [];
    if (hay_vista($f, 'v_servicios')) {
        $usados = [];
        foreach ($f->query('SELECT clave, nombre, codigo, activo FROM v_servicios ORDER BY clave') as $r) {
            $k = normalizar((string) $r['clave']); if ($k === '') continue;
            $cod = trim((string) ($r['codigo'] ?? '')); if ($cod === '' || isset($usados[$cod])) $cod = (string) $r['clave'];
            $usados[$cod] = true;
            $servicios[$k] = ['id' => ulid_legado('servicio', $k), 'codigo' => mb_substr($cod, 0, 60), 'nombre' => mb_substr((string) ($r['nombre'] ?: $r['clave']), 0, 160),
                'activo' => (int) $r['activo']];
            $porNombre[normalizar((string) $r['nombre'])] = $k; $porNombre[$k] = $k;
        }
        $lote(function () use ($servicios, $sumar) {
            foreach ($servicios as $s) $sumar('Location', reflejar('pim_local', 'Location', $s['id'],
                ['codigo' => $s['codigo'], 'nombre' => $s['nombre'], 'tipo_fisico' => 'area', 'parte_de' => null, 'estado' => $s['activo'] ? 'active' : 'inactive'], 'IproyPim\loc_fhir'));
        });
    } else $avisos[] = 'falta la vista iproy_fhir.v_servicios';
    $mapa = mapa_servicios_sire();
    $loc_de = function (?string $nombre, bool $deSire) use ($servicios, $porNombre, $mapa): ?string {
        $n = trim((string) $nombre); if ($n === '') return null;
        $k = normalizar($n);
        if ($deSire && isset($mapa[$k])) $k = $porNombre[$mapa[$k]] ?? $mapa[$k];
        $k = $porNombre[$k] ?? $k;
        if (isset($servicios[$k])) return $servicios[$k]['id'];
        return $deSire ? ulid_legado('servicio-sire', normalizar($n)) : null;
    };

    // ---- Device: inventario de equipamiento médico (SIRE · PMP_Equipos)
    if (hay_vista($f, 'v_equipos')) {
        $equipos = []; $sire = [];
        foreach ($f->query('SELECT fila, valores FROM v_equipos ORDER BY fila') as $r) {
            $v = json_decode((string) $r['valores'], true); if (!is_array($v)) continue;
            $v = array_map(fn($x) => is_string($x) ? trim($x) : $x, array_slice(array_pad($v, 13, null), 0, 13));
            if ((string) ($v[3] ?? '') === '') continue;
            $equipos[(string) $v[3]] = $v;
            $srv = (string) ($v[4] ?? '');
            if ($srv !== '' && ($lid = $loc_de($srv, true)) && !in_array($lid, array_column($servicios, 'id'), true)) $sire[$lid] = $srv;
        }
        $lote(function () use ($sire, $sumar) {
            foreach ($sire as $id => $n) $sumar('Location', reflejar('pim_local', 'Location', $id,
                ['codigo' => mb_substr('SIRE:' . $n, 0, 60), 'nombre' => mb_substr($n, 0, 160), 'tipo_fisico' => 'area', 'parte_de' => null, 'estado' => 'active'], 'IproyPim\loc_fhir'));
        });
        foreach (array_chunk($equipos, 400, true) as $parte) $lote(function () use ($parte, $loc_de, $sumar) {
            foreach ($parte as $cod => $v) {
                $s = fn($i) => (isset($v[$i]) && (string) $v[$i] !== '') ? mb_substr((string) $v[$i], 0, 120) : null;
                $baja = str_contains(normalizar((string) ($v[12] ?? '')), 'baja');
                $sumar('Device', reflejar('pim_activo', 'Device', ulid_legado('equipo', (string) $cod), [
                    'clase' => 'equipo-medico', 'codigo' => mb_substr((string) $cod, 0, 60), 'nombre' => mb_substr((string) ($v[5] ?: $cod), 0, 200),
                    'tipo_codigo' => $s(2) ? mb_substr($s(2), 0, 40) : null, 'tipo_texto' => $s(1) ?? $s(0), 'local_id' => $loc_de($s(4), true),
                    'fabricante' => $s(6), 'modelo' => $s(7), 'serie' => $s(8), 'estado' => $baja ? 'inactive' : 'active'], 'IproyPim\dev_fhir'));
            }
        });
    } else $avisos[] = 'falta la vista iproy_fhir.v_equipos (inventario SIRE)';

    // ---- Task: órdenes de trabajo de iProy (Req_IPROY), en solo lectura
    if (hay_vista($f, 'v_ordenes')) {
        $tareas = [];
        if (hay_vista($f, 'v_tareas')) foreach ($f->query('SELECT clave, nombre, codigo FROM v_tareas') as $r) $tareas[normalizar((string) $r['clave'])] = $r;
        $q = $f->query('SELECT id, codigo, marca_temporal, servicio_solicitante, detalle, tipo_tarea, empresa, estado, fecha_atencion, fecha_proc, tipo_man, fecha_fin, prioridad FROM v_ordenes ORDER BY id');
        $buf = [];
        $volcar = function () use (&$buf, $lote, $tareas, $loc_de, $sumar) {
            $lote(function () use ($buf, $tareas, $loc_de, $sumar) {
                foreach ($buf as $r) {
                    $tm = normalizar((string) ($r['tipo_man'] ?? ''));
                    $tipoM = str_contains($tm, 'prevent') ? 'preventivo-sistematico' : (str_contains($tm, 'correct') ? 'correctivo-diferido' : (str_contains($tm, 'mejora') ? 'mejora' : null));
                    $tt = trim((string) ($r['tipo_tarea'] ?? '')); $cat = $tareas[normalizar($tt)] ?? null;
                    $sumar('Task', reflejar('pim_orden', 'Task', ulid_legado('orden', (string) $r['id']), [
                        'numero' => (string) $r['id'], 'codigo_legado' => $r['codigo'] !== null ? mb_substr((string) $r['codigo'], 0, 30) : null, 'legado_id' => (int) $r['id'],
                        'local_id' => $loc_de($r['servicio_solicitante'], false),
                        'responsable_id' => trim((string) $r['empresa']) !== '' ? ulid_legado('empresa', trim((string) $r['empresa'])) : null,
                        'estado_negocio' => $en = (ESTADO_LEGADO[(string) $r['estado']] ?? 'solicitada'), 'estado' => ESTADOS_ORDEN[$en],
                        'prioridad' => BANDA_PRIORIDAD[(string) $r['prioridad']] ?? 'routine', 'tipo_mantenimiento' => $tipoM,
                        'categoria_codigo' => $cat ? mb_substr((string) $cat['codigo'], 0, 40) : null, 'categoria_texto' => $tt !== '' ? mb_substr($tt, 0, 160) : null,
                        'descripcion' => $r['detalle'], 'inicio' => fecha_db($r['fecha_atencion'] ?? $r['fecha_proc']), 'fin' => fecha_db($r['fecha_fin']),
                        'autoria' => fecha_db($r['marca_temporal']) ?? date('Y-m-d H:i:s')], 'IproyPim\task_fhir'));
                }
            });
            $buf = [];
        };
        foreach ($q as $r) { $buf[] = $r; if (count($buf) >= 400) $volcar(); }
        if ($buf) $volcar();
    }

    $res = ['ok' => true, 'segundos' => round(microtime(true) - $t0, 2), 'recursos' => $cuenta, 'avisos' => $avisos, 'ts' => date('c')];
    $p->prepare('INSERT INTO pim_importacion (resumen) VALUES (?)')->execute([json_encode($res, JSON_UNESCAPED_UNICODE)]);
    $p->exec('DELETE FROM pim_importacion WHERE ts < NOW() - INTERVAL 30 DAY');
    return $res;
}

/** Equivalencias confirmadas servicio SIRE → servicio de mantenimiento (mismo CSV que usa la API v0.3: data/fhir/mapa_servicios.csv). */
function mapa_servicios_sire(): array {
    $m = [];
    $f = raiz() . '/data/fhir/mapa_servicios.csv';
    if (!is_file($f) || !($h = fopen($f, 'r'))) return $m;
    fgetcsv($h, null, ';', '"', '');
    while (($c = fgetcsv($h, null, ';', '"', '')) !== false)
        if (count($c) >= 3 && trim($c[2]) === '1' && trim($c[1]) !== '') $m[normalizar($c[0])] = normalizar($c[1]);
    fclose($h);
    return $m;
}
