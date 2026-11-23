<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Servidor PIM · carga de datos traídos de otro sistema (hoja de cálculo o base MySQL), por lotes.
 *
 * Recibe filas ya normalizadas (las arma el módulo «migracion» del núcleo con las equivalencias que eligió el administrador) y las
 * refleja con la misma función del puente de lectura: idempotente (huella de los datos de origen), con versión y Provenance,
 * y marcadas con origen «legado». Un registro creado en este nodo (origen «pim») nunca se pisa.
 * Con «simular» valida todo y no escribe nada. Con «solo_nuevos» da de alta lo que falta y no cambia lo que ya existe.
 */
declare(strict_types=1);

namespace IproyPim;

const CLIENTE_MIGRADOR = 'migrador';
const DESTINOS_MIG = ['servicios', 'empresas', 'equipos', 'sistemas', 'ordenes'];

function mig_txt(mixed $v, int $max): ?string { $s = trim((string) ($v ?? '')); return $s === '' ? null : mb_substr($s, 0, $max); }
function mig_fecha(mixed $v): ?string {
    $s = trim((string) ($v ?? '')); if ($s === '' || str_starts_with($s, '0000')) return null;
    if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $s, $m)) $s = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[3], $m[2], $m[1], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0);
    $t = strtotime($s); if ($t === false || $t < 0) throw new \InvalidArgumentException("fecha inválida «{$s}»");
    return date('Y-m-d H:i:s', $t);
}
function mig_local(?string $nombre): ?string { return $nombre === null ? null : ulid_legado('mig-servicio', normalizar($nombre)); }
function mig_empresa(?string $cod): ?string { return $cod === null ? null : ulid_legado('empresa', trim($cod)); }

/** Una fila normalizada → [tabla, recurso, id, campos, función FHIR]. Lanza InvalidArgumentException con el motivo si la fila no sirve. */
function mig_fila(string $destino, array $f): array {
    switch ($destino) {
        case 'servicios':
            $n = mig_txt($f['nombre'] ?? null, 160) ?? throw new \InvalidArgumentException('falta el nombre');
            return ['pim_local', 'Location', mig_local($n), ['codigo' => mig_txt($f['codigo'] ?? null, 60) ?? mb_substr($n, 0, 60), 'nombre' => $n, 'tipo_fisico' => 'area', 'parte_de' => null, 'estado' => 'active'], 'IproyPim\loc_fhir'];
        case 'empresas':
            $c = mig_txt($f['codigo'] ?? null, 60) ?? throw new \InvalidArgumentException('falta el código');
            $tipo = in_array($f['tipo'] ?? 'empresa', ['empresa', 'area'], true) ? ($f['tipo'] ?? 'empresa') : 'empresa';
            return ['pim_organizacion', 'Organization', mig_empresa($c), ['tipo' => $tipo, 'codigo' => $c, 'nombre' => mig_txt($f['nombre'] ?? null, 160) ?? $c, 'activo' => (int) !in_array(normalizar((string) ($f['activo'] ?? 'si')), ['no', '0', 'false', 'inactivo', 'baja'], true), 'parte_de' => null], 'IproyPim\org_fhir'];
        case 'equipos':
        case 'sistemas':
            $c = mig_txt($f['codigo'] ?? null, 60) ?? throw new \InvalidArgumentException('falta el código');
            $sis = $destino === 'sistemas'; $tc = mig_txt($f['tipo_codigo'] ?? null, 40);
            if ($sis && ($tc === null || !isset(SISTEMAS[$tc]))) throw new \InvalidArgumentException('la clase de sistema «' . ($tc ?? '') . '» no es del Anexo B de PIM');
            $baja = str_contains(normalizar((string) ($f['estado'] ?? '')), 'baja') || in_array(normalizar((string) ($f['estado'] ?? '')), ['inactivo', 'inactive'], true);
            $c = sin_sigla($c);   // el id sale del código propio: cambiar la sigla no duplica el recurso
            return ['pim_activo', 'Device', ulid_legado($sis ? 'mig-sistema' : 'equipo', $c), ['clase' => $sis ? 'sistema' : 'equipo-medico', 'codigo' => mb_substr(con_sigla($c), 0, 60), 'nombre' => mig_txt($f['nombre'] ?? null, 200) ?? $c,
                'tipo_codigo' => $tc, 'tipo_texto' => mig_txt($f['tipo_texto'] ?? null, 120), 'local_id' => mig_local(mig_txt($f['servicio'] ?? null, 160)),
                'fabricante' => mig_txt($f['fabricante'] ?? null, 120), 'modelo' => mig_txt($f['modelo'] ?? null, 120), 'serie' => mig_txt($f['serie'] ?? null, 120), 'estado' => $baja ? 'inactive' : 'active']
                + (in_array((string) ($f['categoria_riesgo'] ?? ''), ['1', '2', '3', '4'], true) ? ['categoria_riesgo' => (int) $f['categoria_riesgo']] : []), 'IproyPim\dev_fhir'];
        case 'ordenes':
            $n = mig_txt($f['numero'] ?? null, 30) ?? throw new \InvalidArgumentException('falta el número de orden');
            $en = (string) ($f['estado_pim'] ?? ''); if (!isset(ESTADOS_ORDEN[$en])) throw new \InvalidArgumentException('estado sin equivalencia PIM («' . mig_txt($f['estado'] ?? null, 40) . '»)');
            $tm = mig_txt($f['tipo_mantenimiento'] ?? null, 30); if ($tm !== null && !in_array($tm, TIPOS_MANT, true)) throw new \InvalidArgumentException("tipo de mantenimiento «{$tm}» fuera de EN 13306");
            $pr = mig_txt($f['prioridad'] ?? null, 10) ?? 'routine'; if (!in_array($pr, PRIORIDADES, true)) throw new \InvalidArgumentException("prioridad «{$pr}» inválida");
            $alta = mig_fecha($f['alta'] ?? null) ?? throw new \InvalidArgumentException('falta la fecha de alta'); $fin = mig_fecha($f['fin'] ?? null);
            if ($fin !== null && $fin < $alta) throw new \InvalidArgumentException('la fecha de fin es anterior al alta');
            $motivo = mig_txt($f['motivo'] ?? null, 255);
            if (in_array($en, CON_MOTIVO, true) && $motivo === null) $motivo = 'Sin motivo registrado en el sistema de origen';
            $eq = mig_txt($f['equipo'] ?? null, 60);
            return ['pim_orden', 'Task', ulid_legado('orden', $n), ['numero' => $n, 'codigo_legado' => mig_txt($f['codigo'] ?? null, 30), 'legado_id' => ctype_digit($n) && (int) $n > 0 && (int) $n < 4294967295 ? (int) $n : null,
                'activo_id' => $eq === null ? null : ulid_legado('equipo', sin_sigla($eq)), 'local_id' => mig_local(mig_txt($f['servicio'] ?? null, 160)), 'responsable_id' => mig_empresa(mig_txt($f['empresa'] ?? null, 60)),
                'estado_negocio' => $en, 'estado' => ESTADOS_ORDEN[$en], 'motivo_estado' => $motivo, 'prioridad' => $pr, 'tipo_mantenimiento' => $tm,
                'categoria_codigo' => mig_txt($f['categoria_codigo'] ?? null, 40), 'categoria_texto' => mig_txt($f['categoria_texto'] ?? null, 160), 'descripcion' => mig_txt($f['detalle'] ?? null, 5000),
                'inicio' => mig_fecha($f['inicio'] ?? null), 'fin' => $fin, 'autoria' => $alta], 'IproyPim\task_fhir'];
    }
    throw new \InvalidArgumentException('destino desconocido');
}

/** Carga (o simula) un lote. Devuelve cuántos nuevos, cambiados, iguales, propios y con error, más los primeros errores. */
function migrar_lote(string $destino, array $filas, bool $simular, bool $soloNuevos = false, bool $asumir = false): array {
    if (!in_array($destino, DESTINOS_MIG, true)) throw new Fallo(422, 'invalid', 'Destino: ' . implode(' | ', DESTINOS_MIG));
    if (count($filas) > 1000) throw new Fallo(422, 'too-costly', 'Hasta 1000 filas por lote');
    $c = ['nuevo' => 0, 'cambio' => 0, 'igual' => 0, 'propio' => 0, 'error' => 0, 'sin_referencia' => 0]; $err = []; $ok = [];
    foreach ($filas as $i => $f) {
        try { $ok[$i] = mig_fila($destino, (array) $f); }
        catch (\InvalidArgumentException $e) { $c['error']++; if (count($err) < 50) $err[] = ['fila' => (int) ($f['_fila'] ?? $i + 1), 'motivo' => $e->getMessage()]; }
    }
    // referencias que no existen todavía (servicio, empresa, equipo): la fila entra igual, sin esa referencia, y se cuenta
    $existe = function (string $tabla, ?string $id) { static $m = []; if ($id === null) return true; return $m[$tabla . $id] ??= existe($tabla, $id); };
    $p = pdo(); if (!$simular) $p->beginTransaction();
    try {
        foreach ($ok as $i => [$tabla, $recurso, $id, $campos, $fhir]) {
            foreach (['local_id' => 'pim_local', 'responsable_id' => 'pim_organizacion', 'activo_id' => 'pim_activo'] as $k => $t)
                if (array_key_exists($k, $campos) && $campos[$k] !== null && !($simular && $asumir && $k !== 'activo_id') && !$existe($t, $campos[$k])) { $campos[$k] = null; $c['sin_referencia']++; }   // al simular con «crear lo que falte», servicio y empresa se dan por creados
            if ($soloNuevos && existe($tabla, $id)) { $c['igual']++; continue; }   // lo que ya está no se toca (altas derivadas de otra carga)
            if ($simular) { $r = fila($tabla, $id, true); $c[!$r ? 'nuevo' : ($r['origen'] !== 'legado' ? 'propio' : ($r['origen_huella'] === sha1(json_encode($campos, JSON_UNESCAPED_UNICODE)) ? 'igual' : 'cambio'))]++; continue; }
            try { $c[reflejar($tabla, $recurso, $id, $campos, $fhir)]++; }
            catch (\PDOException $e) { $c['error']++; if (count($err) < 50) $err[] = ['fila' => (int) ($filas[$i]['_fila'] ?? $i + 1), 'motivo' => str_contains($e->getMessage(), 'Duplicate') ? 'ya existe otro registro con ese código o número' : 'la base rechazó la fila']; }
        }
        if (!$simular) $p->commit();
    } catch (\Throwable $e) { if (!$simular && $p->inTransaction()) $p->rollBack(); throw $e; }
    return ['destino' => $destino, 'simulado' => $simular, 'filas' => count($filas), 'cuenta' => $c, 'errores' => $err];
}

/** Ajustes del nodo que el administrador cambia desde la pantalla. Solo los de esta lista. */
function mig_ajustes(?array $cambio = null): array {
    $f = datos() . '/config.json'; $u = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    if ($cambio !== null) {
        if (array_key_exists('exponer_detalle', $cambio)) $u['exponer_detalle'] = (bool) $cambio['exponer_detalle'];
        if (array_key_exists('organismo_nombre', $cambio)) {   // 1.7.0: nombre del establecimiento (raíz de las ubicaciones y dueño de los activos)
            $nn = trim(preg_replace('/\s+/', ' ', (string) $cambio['organismo_nombre']));
            if (mb_strlen($nn) < 3 || mb_strlen($nn) > 120) throw new Fallo(422, 'invalid', 'El nombre del establecimiento lleva entre 3 y 120 caracteres');
            $u['organismo'] = array_replace(cfg()['organismo'], is_array($u['organismo'] ?? null) ? $u['organismo'] : [], ['nombre' => $nn]);
            unset($u['organismo']['_sin_nombre']);
        }
        if (array_key_exists('sigla', $cambio)) { $sg = trim((string) $cambio['sigla']);
            if ($sg !== '' && !preg_match(SIGLA_RE, $sg)) throw new Fallo(422, 'invalid', 'La sigla lleva 4 caracteres: letras o números, y empieza con una letra (ejemplo: HEJa)');
            $u['sigla'] = $sg; }
        $tmp = $f . '.tmp'; file_put_contents($tmp, json_encode($u, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); rename($tmp, $f);
    }
    $sg = (string) ($u['sigla'] ?? ''); $sg = preg_match(SIGLA_RE, $sg) ? $sg : '';
    $con = $sg === '' ? 0 : (int) pdo()->query("SELECT COUNT(*) FROM pim_activo WHERE codigo LIKE " . pdo()->quote(addcslashes($sg, '%_\\') . '-%'))->fetchColumn();
    $tot = (int) pdo()->query("SELECT COUNT(*) FROM pim_activo")->fetchColumn();
    $on = (string) ($u['organismo']['nombre'] ?? '');
    if ($cambio !== null && array_key_exists('organismo_nombre', $cambio)) {   // el organismo y el sitio raíz toman el nombre nuevo ya (con su versión)
        $o = ['codigo' => (string) ($u['organismo']['codigo'] ?? 'ORG'), 'nombre' => $on, 'refes' => $u['organismo']['refes'] ?? null];
        reflejar('pim_organizacion', 'Organization', org_organismo_id(), ['tipo' => 'organismo', 'codigo' => $o['codigo'], 'nombre' => $o['nombre'], 'refes' => $o['refes'] ?: null, 'parte_de' => null, 'activo' => 1], 'IproyPim\org_fhir');
    }
    return ['exponer_detalle' => (bool) ($u['exponer_detalle'] ?? false), 'sigla' => $sg, 'recursos' => $tot, 'recursos_con_sigla' => $con,
            'organismo' => ['nombre' => organismo_sin_nombre($on) ? null : $on, 'sugerido' => cfg()['organismo']['nombre'], 'codigo' => (string) ($u['organismo']['codigo'] ?? cfg()['organismo']['codigo'])]];
}
