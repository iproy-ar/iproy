<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Inventario (pim 1.7.0): activos y ubicaciones por la API PIM, con el rol del Gestor.
 *  - Alta, edición y estado (en servicio / fuera de circulación / obsoleto / cargado por error). Nada se borra: el Servidor PIM guarda cada
 *    versión con su motivo y el diario del nodo dice quién lo hizo.
 *  - Importar desde .xlsx, .csv o una hoja de Google por enlace, con simulación previa; exportar en el mismo formato; planilla de ejemplo.
 *  - Una celda vacía no borra el dato que ya está; «-» lo borra. La clave de cada fila es el código (con o sin la sigla del establecimiento).
 */
declare(strict_types=1);

namespace Modulos\Pim;

use Nucleo\Fallo;
use function Nucleo\{pim, pim_lista, asentar, planilla_armar, planilla_leer, csv_armar, planilla_de_enlace, nombre_archivo};

const NS = 'https://iproy.com.ar/fhir';
const TIPOS_UBIC = ['bu' => 'Edificio', 'wi' => 'Ala', 'lvl' => 'Nivel o piso', 'ro' => 'Local o sala', 'area' => 'Área', 'bd' => 'Cama'];
const PREFIJO_UBIC = ['bu' => 'EDI', 'wi' => 'ALA', 'lvl' => 'NIV', 'ro' => 'LOC', 'area' => 'ARE', 'bd' => 'CAM'];
const CLASES = ['sistema' => 'Instalación', 'equipo-medico' => 'Equipo médico', 'activo' => 'Otro activo'];
const PERFIL = ['sistema' => 'pim-sistema', 'equipo-medico' => 'pim-pma', 'activo' => 'pim-activo'];
const ESTADOS = ['en-servicio' => 'En servicio', 'fuera-de-circulacion' => 'Fuera de circulación', 'obsoleto' => 'Obsoleto o dado de baja', 'cargado-por-error' => 'Cargado por error'];
const LOTE = 200;

/** Columnas de la planilla: clave → [título, ayuda, ancho]. El orden es el de la planilla de ejemplo y el de la exportación. */
const COL_ACTIVOS = [
    'codigo' => ['Código', 'Identifica al activo. Si ya existe, la fila lo actualiza. Vacío = el sistema propone uno.', 20],
    'nombre' => ['Nombre', 'Obligatorio. Cómo lo conoce la gente: «Tanque de oxígeno líquido».', 34],
    'clase' => ['Clase', 'instalación · equipo médico · otro', 16],
    'sistema' => ['Sistema PIM', 'Obligatorio en instalaciones: código o nombre del Anexo B (hoja «Listas»), p. ej. GAS-O2.', 16],
    'tipo' => ['Tipo', 'Texto libre: tanque, bomba, tablero, monitor multiparamétrico…', 20],
    'ubicacion_codigo' => ['Código de ubicación', 'Código de la hoja «Ubicaciones». Si lo dejás vacío se usa la columna Ubicación.', 18],
    'ubicacion' => ['Ubicación', 'Ruta desde el edificio, separada con «>»: Edificio Ampliación > Sala de gases.', 36],
    'forma_parte_de' => ['Forma parte de', 'Código del activo mayor (ISO 14224): la bomba forma parte del sistema de agua.', 18],
    'fabricante' => ['Fabricante o marca', '', 18],
    'modelo' => ['Modelo', '', 16],
    'serie' => ['N.º de serie', '', 16],
    'categoria_nfpa99' => ['Categoría NFPA 99', '1 lesión grave o muerte · 2 lesión leve · 3 molestia sin lesión · 4 sin impacto en el paciente.', 12],
    'puesta_en_marcha' => ['Puesta en marcha', 'Fecha: AAAA-MM-DD o DD/MM/AAAA.', 14],
    'anmat_pm' => ['N.º ANMAT', 'Equipos médicos: PM-legajo-producto (PM-2430-65).', 14],
    'clase_riesgo_pm' => ['Clase de riesgo ANMAT', 'Equipos médicos: I, II, III o IV.', 12],
    'estado' => ['Estado', 'en servicio · fuera de circulación · obsoleto · cargado por error', 18],
    'motivo_estado' => ['Motivo del estado', 'Obligatorio si no está en servicio: por qué.', 30],
];
const COL_UBIC = [
    'codigo' => ['Código', 'Identifica a la ubicación. Si ya existe, la fila la actualiza. Vacío = el sistema propone uno.', 16],
    'nombre' => ['Nombre', 'Obligatorio: «Edificio Ampliación», «Planta baja», «Sala de gases».', 30],
    'tipo' => ['Tipo', 'edificio · ala · nivel · local · área · cama', 14],
    'dentro_de' => ['Dentro de (código)', 'Código de la ubicación que la contiene. Vacío = depende directamente del establecimiento.', 18],
    'ruta' => ['Ruta (informativa)', 'La arma el sistema al exportar; al importar no se usa.', 40],
    'estado' => ['Estado', 'en uso · fuera de uso', 12],
];
/** Encabezados que se reconocen además de los propios (planillas que ya tenía el establecimiento). */
const SINONIMOS = [
    'codigo' => ['codigo', 'cod', 'codigo_de_inventario', 'codigo_inventario', 'id_unidad', 'equipo_o_instalacion_id_unidad', 'codigo_del_activo', 'codigo_activo', 'codigo_equipo', 'codigo_rfc', 'id'],
    'nombre' => ['nombre', 'denominacion', 'nombre_del_equipo', 'nombre_del_activo', 'equipo', 'activo', 'instalacion', 'descripcion_del_equipo'],
    'clase' => ['clase', 'clase_de_activo', 'que_es'],
    'sistema' => ['sistema', 'sistema_pim', 'clase_de_sistema', 'tipo_de_sistema'],
    'tipo' => ['tipo', 'tipo_de_equipo', 'familia', 'subtipo'],
    'ubicacion_codigo' => ['codigo_de_ubicacion', 'ubicacion_codigo', 'cod_ubicacion', 'codigo_ubicacion'],
    'ubicacion' => ['ubicacion', 'ruta_de_la_ubicacion', 'lugar', 'servicio', 'sector'],
    'forma_parte_de' => ['forma_parte_de', 'padre', 'activo_padre', 'parte_de', 'codigo_padre'],
    'fabricante' => ['fabricante_o_marca', 'fabricante', 'marca'],
    'modelo' => ['modelo'],
    'serie' => ['n_de_serie', 'n_o_de_serie', 'nro_de_serie', 'numero_de_serie', 'nro_serie', 'n_serie', 'serie'],
    'categoria_nfpa99' => ['categoria_nfpa_99', 'categoria_nfpa99', 'nfpa_99', 'nfpa99', 'categoria_de_riesgo', 'riesgo_nfpa', 'criticidad'],
    'puesta_en_marcha' => ['puesta_en_marcha', 'fecha_de_puesta_en_marcha', 'fecha_de_instalacion', 'instalado'],
    'anmat_pm' => ['n_anmat', 'n_o_anmat', 'anmat', 'anmat_pm', 'nro_anmat'],
    'clase_riesgo_pm' => ['clase_de_riesgo_anmat', 'clase_riesgo_pm', 'clase_de_riesgo', 'clase_anmat'],
    'estado' => ['estado', 'situacion'],
    'motivo_estado' => ['motivo_del_estado', 'motivo_estado', 'motivo'],
    'dentro_de' => ['dentro_de_codigo', 'dentro_de', 'depende_de', 'codigo_padre_ubicacion', 'padre_ubicacion'],
    'ruta' => ['ruta_informativa', 'ruta'],
];

function norm(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'º' => 'o', '°' => 'o', 'ª' => 'a']);
    return trim(preg_replace('/[^a-z0-9]+/', '_', $s), '_');
}
function txt(mixed $v): string { return trim(preg_replace('/\s+/u', ' ', (string) ($v ?? ''))); }
function sid(string $t): string { return NS . '/sid/' . $t; }
function sd(string $n): string { return NS . '/StructureDefinition/' . $n; }
function g(string $ruta, int $max = 20000): array { return pim_lista($ruta, ['rol' => 'GM'], $max); }
function pim_gm(string $m, string $r, ?array $c = null, array $o = []): array {
    $x = pim($m, $r, $c, $o + ['rol' => 'GM', 'timeout' => 120]);
    if ($x['http'] >= 400) throw new Fallo($x['http'] === 412 ? 409 : ($x['http'] >= 500 ? 502 : 422), (string) ($x['cuerpo']['issue'][0]['diagnostics'] ?? "El Servidor PIM respondió HTTP {$x['http']}"));
    return $x;
}
function pim_admin(array $cuerpo, int $seg = 60): array {
    $t = (string) (\nodo_cfg()['tick_token'] ?? ''); if ($t === '') throw new Fallo(500, 'El nodo no tiene token interno');
    $c = curl_init(\Nucleo\base_local() . '/pim/admin/migrar.php');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $seg, CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-IPROY-Tick: ' . $t],
        CURLOPT_POSTFIELDS => json_encode($cuerpo, JSON_UNESCAPED_UNICODE)]);
    $r = curl_exec($c); $http = (int) curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    $j = is_string($r) ? json_decode($r, true) : null;
    if (!is_array($j)) throw new Fallo(502, 'El Servidor PIM no respondió');
    if (empty($j['ok'])) throw new Fallo($http >= 400 && $http < 500 ? 422 : 502, (string) ($j['error'] ?? 'El Servidor PIM rechazó el pedido'));
    return $j;
}
function catalogo(): array { static $c = null; return $c ??= pim_admin(['accion' => 'catalogo']); }
function sistemas(): array { return (array) (catalogo()['sistemas'] ?? []); }

// ---------------------------------------------------------------- lectura: ubicaciones y activos en forma simple
function cod_de(array $r, string $sis): string {
    foreach ((array) ($r['identifier'] ?? []) as $i) if (str_ends_with((string) ($i['system'] ?? ''), '/sid/' . $sis)) return (string) ($i['value'] ?? '');
    return (string) ($r['identifier'][0]['value'] ?? '');
}
function ext(array $r, string $n): mixed {
    foreach ((array) ($r['extension'] ?? []) as $e) if (str_ends_with((string) ($e['url'] ?? ''), '/' . $n)) return $e['valueCoding']['code'] ?? $e['valueCode'] ?? $e['valueDateTime'] ?? $e['valueString'] ?? $e;
    return null;
}
function ref(?array $x): ?string { $r = (string) ($x['reference'] ?? ''); return $r !== '' ? substr($r, (int) strpos($r, '/') + 1) : null; }
/** Árbol de ubicaciones: id → {id, codigo, nombre, tipo, padre, estado, version, ruta, nivel}. El establecimiento (raíz) queda aparte. */
function ubicaciones(): array {
    $l = g('Location?_count=500'); $por = []; $estab = null;
    foreach ($l as $x) {
        $tipo = (string) ($x['physicalType']['coding'][0]['code'] ?? '');
        if ($tipo === 'si') { $estab = ['id' => $x['id'], 'nombre' => (string) ($x['name'] ?? ''), 'codigo' => cod_de($x, 'local')]; continue; }
        $por[$x['id']] = ['id' => $x['id'], 'codigo' => cod_de($x, 'local'), 'nombre' => (string) ($x['name'] ?? ''), 'tipo' => $tipo, 'padre' => ref($x['partOf'] ?? null),
            'estado' => (string) ($x['status'] ?? 'active'), 'version' => (string) ($x['meta']['versionId'] ?? ''), '_r' => $x];
    }
    foreach ($por as $id => &$u) {
        if ($estab && $u['padre'] === $estab['id']) $u['padre'] = null;
        $ruta = [$u['nombre']]; $p = $u['padre']; $n = 0;
        while ($p !== null && isset($por[$p]) && $n++ < 12) { array_unshift($ruta, $por[$p]['nombre']); $p = $por[$p]['padre'] === ($estab['id'] ?? '') ? null : $por[$p]['padre']; }
        $u['ruta'] = implode(' › ', $ruta); $u['nivel'] = count($ruta) - 1;
    }
    unset($u);
    // orden de árbol: cada una debajo de la que la contiene
    $hijos = []; foreach ($por as $u) $hijos[$u['padre'] ?? ''][] = $u['id'];
    foreach ($hijos as &$h) usort($h, fn($a, $b) => strcasecmp($por[$a]['nombre'], $por[$b]['nombre'])); unset($h);
    $orden = []; $vis = [];
    $rec = function (string $p) use (&$rec, &$orden, &$vis, $hijos) { foreach ($hijos[$p] ?? [] as $id) { if (isset($vis[$id])) continue; $vis[$id] = 1; $orden[] = $id; $rec($id); } };
    $rec('');
    foreach ($por as $id => $u) if (!isset($vis[$id])) $orden[] = $id;   // huérfanas (la que las contenía no está): al final
    $out = []; foreach ($orden as $id) $out[$id] = $por[$id];
    return ['estab' => $estab, 'lista' => $out];
}
function clase_de(array $d): string {
    foreach ((array) ($d['meta']['profile'] ?? []) as $p) { if (str_ends_with((string) $p, 'pim-sistema')) return 'sistema'; if (str_ends_with((string) $p, 'pim-pma')) return 'equipo-medico'; }
    return 'activo';
}
function estado_de(array $d): string {
    $s = (string) ($d['status'] ?? 'active');
    if ($s === 'active') return 'en-servicio';
    if ($s === 'entered-in-error') return 'cargado-por-error';
    $c = (string) ($d['statusReason'][0]['coding'][0]['code'] ?? '');
    return $c === 'obsoleto' ? 'obsoleto' : 'fuera-de-circulacion';
}
/** Un activo en forma de fila (las mismas claves que la planilla). */
function fila_activo(array $d, array $U): array {
    $loc = ref($d['location'] ?? null); $u = $loc !== null ? ($U['lista'][$loc] ?? null) : null;
    $cl = clase_de($d);
    $tipo = (string) ($d['type']['text'] ?? '');
    if ($cl === 'sistema' && $tipo === (string) ($d['type']['coding'][0]['display'] ?? '-')) $tipo = '';
    $motivo = preg_replace('/\s*\(desde el \d{2}\/\d{2}\/\d{4}\)$/', '', (string) ($d['statusReason'][0]['text'] ?? ''));
    return ['id' => $d['id'], 'version' => (string) ($d['meta']['versionId'] ?? ''), 'codigo' => cod_de($d, 'activo'), 'nombre' => (string) ($d['deviceName'][0]['name'] ?? ''),
        'clase' => $cl, 'sistema' => $cl === 'sistema' ? (string) ($d['type']['coding'][0]['code'] ?? '') : '', 'tipo' => $tipo,
        'ubicacion_id' => $loc, 'ubicacion_codigo' => $u['codigo'] ?? '', 'ubicacion' => $u['ruta'] ?? '', 'padre_id' => ref($d['parent'] ?? null), 'forma_parte_de' => '',
        'fabricante' => (string) ($d['manufacturer'] ?? ''), 'modelo' => (string) ($d['modelNumber'] ?? ''), 'serie' => (string) ($d['serialNumber'] ?? ''),
        'categoria_nfpa99' => (string) (ext($d, 'pim-categoria-riesgo') ?? ''), 'puesta_en_marcha' => substr((string) (ext($d, 'pim-puesta-en-marcha') ?? ''), 0, 10),
        'anmat_pm' => cod_de(['identifier' => array_values(array_filter((array) ($d['identifier'] ?? []), fn($i) => str_ends_with((string) ($i['system'] ?? ''), '/sid/anmat-pm')))], 'anmat-pm'),
        'clase_riesgo_pm' => (string) (ext($d, 'pim-clase-riesgo-pm') ?? ''), 'estado' => estado_de($d), 'motivo_estado' => $d['status'] !== 'active' ? $motivo : '',
        'legado' => in_array('legado', array_column((array) ($d['meta']['tag'] ?? []), 'code'), true)];
}
function activos(?array $U = null): array {
    $U ??= ubicaciones(); $l = []; $cod = [];
    foreach (g('Device?_count=500') as $d) { $f = fila_activo($d, $U); $f['_r'] = $d; $l[$d['id']] = $f; $cod[$d['id']] = $f['codigo']; }
    foreach ($l as &$f) if ($f['padre_id']) $f['forma_parte_de'] = $cod[$f['padre_id']] ?? '';
    unset($f);
    uasort($l, fn($a, $b) => strnatcasecmp($a['codigo'], $b['codigo']));
    return $l;
}
/** Lo que la pantalla de Inventario necesita de una vez. */
function inventario(): array {
    $U = ubicaciones(); $A = activos($U);
    $por_loc = []; foreach ($A as $a) if ($a['ubicacion_id'] && $a['estado'] === 'en-servicio') $por_loc[$a['ubicacion_id']] = ($por_loc[$a['ubicacion_id']] ?? 0) + 1;
    $aj = pim_admin(['accion' => 'ajustes'])['ajustes'];
    return ['establecimiento' => ['nombre' => $aj['organismo']['nombre'] ?? null, 'sugerido' => $aj['organismo']['sugerido'] ?? ($U['estab']['nombre'] ?? ''), 'sigla' => $aj['sigla'] ?? ''],
        'sistemas' => sistemas(), 'tipos_ubicacion' => TIPOS_UBIC, 'clases' => CLASES, 'estados' => ESTADOS,
        'ubicaciones' => array_values(array_map(fn($u) => array_diff_key($u, ['_r' => 1]) + ['activos' => $por_loc[$u['id']] ?? 0], $U['lista'])),
        'activos' => array_values(array_map(fn($a) => array_diff_key($a, ['_r' => 1]), $A))];
}

// ---------------------------------------------------------------- de una fila a un recurso FHIR (alta o cambio)
function clase_txt(string $v): ?string {
    $n = norm($v); if ($n === '') return null;
    if (in_array($n, ['sistema', 'instalacion', 'instalaciones', 'instalacion_hospitalaria'], true) || str_starts_with($n, 'instal')) return 'sistema';
    if (in_array($n, ['equipo_medico', 'equipos_medicos', 'pma', 'equipo_biomedico', 'biomedico', 'electromedicina'], true) || str_contains($n, 'medic')) return 'equipo-medico';
    if (in_array($n, ['otro', 'otros', 'activo', 'general', 'otro_activo'], true)) return 'activo';
    throw new \InvalidArgumentException("clase «{$v}»: usá instalación, equipo médico u otro");
}
function sistema_txt(string $v): ?string {
    $v = trim($v); if ($v === '') return null; $S = sistemas();
    if (isset($S[strtoupper($v)])) return strtoupper($v);
    foreach ($S as $c => $n) if (norm($n) === norm($v) || norm($c) === norm($v)) return $c;
    throw new \InvalidArgumentException("sistema «{$v}» no está en el Anexo B de PIM (hoja «Listas»)");
}
function estado_txt(string $v): string {
    $n = norm($v);
    if ($n === '' || in_array($n, ['en_servicio', 'activo', 'active', 'en_uso', 'operativo', 'si', 'alta'], true)) return 'en-servicio';
    if (str_contains($n, 'error') || str_contains($n, 'duplic')) return 'cargado-por-error';
    if (str_contains($n, 'obsolet') || str_contains($n, 'baja') || str_contains($n, 'descart') || $n === 'inactive') return 'obsoleto';
    if (str_contains($n, 'fuera') || str_contains($n, 'circul') || str_contains($n, 'deposit') || str_contains($n, 'inactiv') || str_contains($n, 'prestad') || str_contains($n, 'reparac')) return 'fuera-de-circulacion';
    throw new \InvalidArgumentException("estado «{$v}»: en servicio, fuera de circulación, obsoleto o cargado por error");
}
function fecha_txt(string $v): ?string {
    $v = trim($v); if ($v === '') return null;
    if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})#', $v, $m) || (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $v, $x) && ($m = [0, $x[3], $x[2], $x[1]])))
        if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) return \Nucleo\excel_fecha((float) $v);
    throw new \InvalidArgumentException("fecha «{$v}»: AAAA-MM-DD o DD/MM/AAAA");
}
function nfpa_txt(string $v): ?int {
    $v = strtoupper(trim($v)); if ($v === '') return null;
    $m = ['1' => 1, '2' => 2, '3' => 3, '4' => 4, 'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4];
    return $m[preg_replace('/^CAT(EGORIA)?\s*/i', '', $v)] ?? throw new \InvalidArgumentException("categoría NFPA 99 «{$v}»: 1, 2, 3 o 4");
}
/** Valor de una celda: null = no tocar (vacía), '' = borrar («-»), o el texto. */
function celda(array $f, string $k): ?string {
    if (!array_key_exists($k, $f)) return null;
    $v = txt($f[$k]); if ($v === '') return null;
    return in_array($v, ['-', '—', '–'], true) ? '' : $v;
}
function con_ext(array $r, string $n, ?array $nueva): array {
    $r['extension'] = array_values(array_filter((array) ($r['extension'] ?? []), fn($e) => !str_ends_with((string) ($e['url'] ?? ''), '/' . $n)));
    if ($nueva) $r['extension'][] = ['url' => sd($n)] + $nueva;
    if (!$r['extension']) unset($r['extension']);
    return $r;
}
/** Arma el Device a partir de lo que ya está ($base, o null si es nuevo) y la fila. $ctx resuelve ubicaciones y activos padre. */
function device_de_fila(array $f, ?array $base, array $ctx): array {
    $r = $base ?? ['resourceType' => 'Device', 'status' => 'active'];
    unset($r['meta']['versionId'], $r['meta']['lastUpdated'], $r['meta']['tag']);
    $nombre = celda($f, 'nombre'); if ($nombre !== null) { if ($nombre === '') throw new \InvalidArgumentException('el nombre no se puede borrar'); $r['deviceName'] = [['name' => mb_substr($nombre, 0, 200), 'type' => 'user-friendly-name']]; }
    if (empty($r['deviceName'][0]['name'])) throw new \InvalidArgumentException('falta el nombre');
    $cl = ($c = celda($f, 'clase')) ? clase_txt($c) : null;
    $sis = ($s = celda($f, 'sistema')) ? sistema_txt($s) : null;
    $cl ??= $base ? clase_de($base) : ($sis ? 'sistema' : 'activo');
    $r['meta'] = ['profile' => [sd(PERFIL[$cl])]];
    $tipo = celda($f, 'tipo');
    $tipoTxt = $tipo !== null ? $tipo : (string) ($base['type']['text'] ?? '');
    if ($cl === 'sistema') {
        $sis ??= ($base && clase_de($base) === 'sistema') ? (string) ($base['type']['coding'][0]['code'] ?? '') : null;
        if (!$sis) throw new \InvalidArgumentException('una instalación necesita el sistema PIM (columna «Sistema PIM», p. ej. GAS-O2)');
        $r['type'] = array_filter(['coding' => [['system' => NS . '/CodeSystem/pim-sistema', 'code' => $sis, 'display' => sistemas()[$sis] ?? null]], 'text' => $tipoTxt !== '' && $tipoTxt !== (sistemas()[$sis] ?? '') ? mb_substr($tipoTxt, 0, 120) : null]);
    } else {
        if ($sis) throw new \InvalidArgumentException('el sistema PIM es solo para instalaciones: poné la clase «instalación» o dejá el sistema vacío');
        if ($tipoTxt !== '') $r['type'] = ['text' => mb_substr($tipoTxt, 0, 120)]; else unset($r['type']);
    }
    // código: el de la fila, el que ya tenía, o uno propuesto
    $cod = celda($f, 'codigo');
    if ($cod === '') throw new \InvalidArgumentException('el código no se puede borrar');
    $cod ??= $base ? cod_de($base, 'activo') : ($ctx['proponer'])($cl, $sis);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-\/]{0,59}$/', $cod)) throw new \InvalidArgumentException("código «{$cod}»: letras, números, guion, punto o barra (hasta 60)");
    $ids = array_values(array_filter((array) ($r['identifier'] ?? []), fn($i) => !preg_match('#/sid/(activo|anmat-pm)$#', (string) ($i['system'] ?? ''))));
    array_unshift($ids, ['use' => 'official', 'system' => sid('activo'), 'value' => $cod]);
    $an = celda($f, 'anmat_pm'); $anPrev = cod_de(['identifier' => array_values(array_filter((array) ($base['identifier'] ?? []), fn($i) => str_ends_with((string) ($i['system'] ?? ''), '/sid/anmat-pm')))], 'anmat-pm');
    $an = $an === null ? $anPrev : $an; if ($an !== '') $ids[] = ['system' => sid('anmat-pm'), 'value' => $an];
    $r['identifier'] = $ids;
    foreach (['fabricante' => 'manufacturer', 'modelo' => 'modelNumber', 'serie' => 'serialNumber'] as $k => $fh) {
        $v = celda($f, $k); if ($v === null) continue; if ($v === '') unset($r[$fh]); else $r[$fh] = mb_substr($v, 0, 120);
    }
    // ubicación: por código, o por ruta (se crea si falta y se pidió)
    $uc = celda($f, 'ubicacion_codigo'); $ur = celda($f, 'ubicacion');
    if ($uc === '' || ($uc === null && $ur === '')) unset($r['location']);
    elseif ($uc !== null || $ur !== null) { $loc = ($ctx['ubicacion'])($uc, $ur); $r['location'] = ['reference' => 'Location/' . $loc]; }
    // forma parte de
    $pa = celda($f, 'forma_parte_de');
    if ($pa === '') unset($r['parent']);
    elseif ($pa !== null) { $pid = ($ctx['activo'])($pa); if ($pid === ($base['id'] ?? null)) throw new \InvalidArgumentException('un activo no puede formar parte de sí mismo'); $r['parent'] = ['reference' => 'Device/' . $pid]; }
    // extensiones del estándar
    $v = celda($f, 'categoria_nfpa99'); if ($v !== null) $r = con_ext($r, 'pim-categoria-riesgo', $v === '' ? null : ['valueCoding' => ['system' => NS . '/CodeSystem/pim-categoria-riesgo', 'code' => (string) nfpa_txt($v)]]);
    $v = celda($f, 'puesta_en_marcha'); if ($v !== null) $r = con_ext($r, 'pim-puesta-en-marcha', $v === '' ? null : ['valueDateTime' => fecha_txt($v)]);
    $v = celda($f, 'clase_riesgo_pm'); if ($v !== null) { $v = strtoupper($v); if ($v !== '' && !in_array($v, ['I', 'II', 'III', 'IV'], true)) throw new \InvalidArgumentException("clase de riesgo ANMAT «{$v}»: I, II, III o IV"); $r = con_ext($r, 'pim-clase-riesgo-pm', $v === '' ? null : ['valueCoding' => ['system' => NS . '/CodeSystem/pim-clase-riesgo-pm', 'code' => $v]]); }
    // la categoría de alcance la deduce el servidor de la clase y el sistema: no se manda (si no, quedaría fija con el valor viejo)
    $r = con_ext($r, 'pim-categoria-alcance', null);
    // si cambia la categoría NFPA 99, la R del IRU se vuelve a deducir de ella
    if ($v = celda($f, 'categoria_nfpa99')) foreach ((array) ($r['extension'] ?? []) as $k => $e) if (str_ends_with((string) ($e['url'] ?? ''), '/pim-iru'))
        $r['extension'][$k]['extension'] = array_values(array_filter((array) $e['extension'], fn($x) => in_array($x['url'] ?? '', ['F', 'M', 'A'], true)));
    // estado
    $e = celda($f, 'estado'); $mo = celda($f, 'motivo_estado');
    if ($e !== null || $mo !== null) {
        $est = $e !== null && $e !== '' ? estado_txt($e) : ($base ? estado_de($base) : 'en-servicio');
        if ($est === 'en-servicio') { $r['status'] = 'active'; unset($r['statusReason']); }
        else {
            $mot = $mo !== null && $mo !== '' ? $mo : preg_replace('/\s*\(desde el \d{2}\/\d{2}\/\d{4}\)$/', '', (string) ($base['statusReason'][0]['text'] ?? ''));
            if ($mot === '') $mot = $base ? '' : 'Cargado desde una planilla como «' . ESTADOS[$est] . '»';
            if ($mot === '') throw new \InvalidArgumentException('para dejarlo «' . ESTADOS[$est] . '» hace falta el motivo (columna «Motivo del estado»)');
            $r['status'] = $est === 'cargado-por-error' ? 'entered-in-error' : 'inactive';
            $r['statusReason'] = [['coding' => [['system' => 'urn:iproy:motivo-estado', 'code' => $est]], 'text' => mb_substr($mot, 0, 280)]];
        }
    }
    unset($r['owner']);   // el dueño por defecto es el establecimiento
    return $r;
}
/** Lo que cambió entre dos versiones de un activo, en palabras (para la simulación). */
function diferencias(array $a, array $b, array $U): array {
    $fa = fila_activo($a, $U); $fb = fila_activo($b, $U); $d = [];
    foreach (['codigo', 'nombre', 'clase', 'sistema', 'tipo', 'ubicacion', 'fabricante', 'modelo', 'serie', 'categoria_nfpa99', 'puesta_en_marcha', 'anmat_pm', 'clase_riesgo_pm', 'estado', 'motivo_estado'] as $k)
        if ((string) $fa[$k] !== (string) $fb[$k]) $d[] = (COL_ACTIVOS[$k][0] ?? $k);
    if (ref($a['parent'] ?? null) !== ref($b['parent'] ?? null)) $d[] = 'Forma parte de';
    return $d;
}
function proximo(array $codigos, string $pre, int $dig = 3): string {
    $max = 0; foreach ($codigos as $c) if (preg_match('/^' . preg_quote($pre, '/') . '(\d+)$/i', (string) $c, $m)) $max = max($max, (int) $m[1]);
    return $pre . str_pad((string) ($max + 1), $dig, '0', STR_PAD_LEFT);
}
function prefijo_activo(string $clase, ?string $sis): string { return $clase === 'sistema' && $sis ? $sis . '-' : ($clase === 'equipo-medico' ? 'EQM-' : 'ACT-'); }
function sin_sigla(string $c, string $sigla): string { return $sigla !== '' && strncasecmp($c, $sigla . '-', strlen($sigla) + 1) === 0 ? substr($c, strlen($sigla) + 1) : $c; }

// ---------------------------------------------------------------- operaciones de pantalla
function alta_activo(array $datos, array $u): array { return guardar_activo(null, $datos, $u); }
/** Alta o cambio de un activo desde la pantalla (mismas reglas que la planilla). Devuelve el activo y cuántas órdenes lo nombran. */
function guardar_activo(?string $id, array $datos, array $u): array {
    $U = ubicaciones(); $A = activos($U); $sigla = (string) (pim_admin(['accion' => 'ajustes'])['ajustes']['sigla'] ?? '');
    $base = $id !== null ? ($A[$id]['_r'] ?? throw new Fallo(404, 'Ese activo no existe')) : null;
    // la pantalla elige por id: se pasan a código (las mismas reglas que la planilla)
    if (array_key_exists('ubicacion_id', $datos)) { $ui = (string) $datos['ubicacion_id']; $datos['ubicacion_codigo'] = $ui === '' ? '-' : ($U['lista'][$ui]['codigo'] ?? throw new Fallo(422, 'Esa ubicación no existe')); unset($datos['ubicacion']); }
    if (array_key_exists('padre_id', $datos)) { $pi = (string) $datos['padre_id']; $datos['forma_parte_de'] = $pi === '' ? '-' : ($A[$pi]['codigo'] ?? throw new Fallo(422, 'El activo mayor no existe')); }
    if ($base && isset($datos['version']) && (string) $datos['version'] !== (string) ($base['meta']['versionId'] ?? '')) throw new Fallo(409, 'Otra persona modificó este activo mientras lo editabas: cerrá y volvé a abrirlo');
    $ctx = contexto($U, $A, $sigla, !empty($datos['crear_ubicacion']));
    try { $r = device_de_fila($datos, $base, $ctx); } catch (\InvalidArgumentException $e) { throw new Fallo(422, ucfirst($e->getMessage())); }
    ($ctx['crear_pendientes'])();
    $r = ($ctx['resolver'])($r);
    if ($base) {
        $antes = fila_activo($base, $U);
        $x = pim_gm('PUT', 'Device/' . $id, $r + ['id' => $id], ['if_match' => 'W/"' . $base['meta']['versionId'] . '"', 'cab' => !empty($A[$id]['legado']) ? ['X-PIM-Adoptar' => 'si'] : []]);
        $dif = diferencias($base, $x['cuerpo'], ubicaciones());
        if ($dif) asentar('inventario.cambio', ['codigo' => cod_de($x['cuerpo'], 'activo'), 'antes' => $antes['codigo'], 'cambios' => $dif], $id, $u['usuario'] ?? null);
    } else {
        $x = pim_gm('POST', 'Device', $r);
        asentar('inventario.alta', ['codigo' => cod_de($x['cuerpo'], 'activo'), 'nombre' => $x['cuerpo']['deviceName'][0]['name'] ?? ''], $x['cuerpo']['id'], $u['usuario'] ?? null);
    }
    $d = $x['cuerpo'];
    return ['activo' => fila_activo($d, ubicaciones()), 'ordenes' => ordenes_de($d['id'])];
}
/** Cambiar el estado: en servicio, fuera de circulación, obsoleto o cargado por error. Nunca borra. */
function estado_activo(string $id, array $d, array $u): array {
    $est = (string) ($d['estado'] ?? ''); if (!isset(ESTADOS[$est])) throw new Fallo(422, 'estado: ' . implode(' | ', array_keys(ESTADOS)));
    $mot = txt($d['motivo'] ?? '');
    if ($est !== 'en-servicio' && mb_strlen($mot) < 4) throw new Fallo(422, 'Contá brevemente el motivo (por ejemplo: «reemplazado por el equipo ACT-014», «enviado a reparar al fabricante»)');
    $r = guardar_activo($id, ['estado' => $est, 'motivo_estado' => $est === 'en-servicio' ? null : $mot, 'version' => $d['version'] ?? null], $u);
    asentar('inventario.estado', ['codigo' => $r['activo']['codigo'], 'estado' => $est, 'motivo' => $mot], $id, $u['usuario'] ?? null);
    return $r;
}
function ordenes_de(string $id): array {
    $c = fn(string $q) => (int) (pim('GET', "Task?focus=Device/$id$q&_summary=count", null, ['rol' => 'GM'])['cuerpo']['total'] ?? 0);
    return ['total' => $c(''), 'abiertas' => $c('&status=requested,received,accepted,ready,in-progress,on-hold')];
}
/** Historial del activo: versiones con fecha y motivo, y las órdenes más recientes. */
function ficha(string $id): array {
    $h = pim('GET', "Device/$id/_history?_count=50", null, ['rol' => 'GM'])['cuerpo'];
    $U = ubicaciones(); $vers = [];
    $ent = (array) ($h['entry'] ?? []); usort($ent, fn($a, $b) => (int) ($a['resource']['meta']['versionId'] ?? 0) <=> (int) ($b['resource']['meta']['versionId'] ?? 0)); $ant = null;   // de la más vieja a la más nueva
    foreach ($ent as $e) {
        $res = $e['resource'] ?? null; if (!$res) continue;
        $vers[] = ['version' => (string) ($res['meta']['versionId'] ?? ''), 'fecha' => (string) ($e['response']['lastModified'] ?? $res['meta']['lastUpdated'] ?? ''),
                   'accion' => ($e['request']['method'] ?? '') === 'POST' ? 'Alta' : 'Cambio', 'cambios' => $ant ? diferencias($ant, $res, $U) : [], 'estado' => estado_de($res), 'codigo' => cod_de($res, 'activo')];
        $ant = $res;
    }
    $prov = pim('GET', "Provenance?target=Device/$id&_count=50", null, ['rol' => 'GM'])['cuerpo'];
    $motivos = []; foreach ((array) ($prov['entry'] ?? []) as $e) { $p = $e['resource']; $v = (string) preg_replace('#.*/_history/#', '', (string) ($p['target'][0]['reference'] ?? '')); if (!empty($p['reason'][0]['text'])) $motivos[$v] = (string) $p['reason'][0]['text']; }
    foreach ($vers as &$v) if (isset($motivos[$v['version']])) $v['motivo'] = $motivos[$v['version']]; unset($v);
    $ord = pim('GET', "Task?focus=Device/$id&_sort=-authored-on&_count=10", null, ['rol' => 'GM'])['cuerpo'];
    return ['versiones' => array_reverse($vers), 'ordenes' => ordenes_de($id), 'ultimas' => array_map(fn($e) => ['numero' => $e['resource']['identifier'][0]['value'] ?? '', 'estado' => $e['resource']['businessStatus']['coding'][0]['code'] ?? '',
        'alta' => $e['resource']['authoredOn'] ?? '', 'titulo' => $e['resource']['code']['text'] ?? ''], (array) ($ord['entry'] ?? []))];
}
function guardar_ubicacion(?string $id, array $d, array $u): array {
    $U = ubicaciones(); $base = $id !== null ? ($U['lista'][$id]['_r'] ?? throw new Fallo(404, 'Esa ubicación no existe')) : null;
    $codigos = array_column($U['lista'], 'codigo');
    $tipo = (string) ($d['tipo'] ?? ($base ? $U['lista'][$id]['tipo'] : 'ro')); if (!isset(TIPOS_UBIC[$tipo])) throw new Fallo(422, 'tipo: ' . implode(' | ', array_keys(TIPOS_UBIC)));
    $nombre = txt($d['nombre'] ?? ($base['name'] ?? '')); if (mb_strlen($nombre) < 2) throw new Fallo(422, 'Escribí el nombre de la ubicación');
    $cod = txt($d['codigo'] ?? '') ?: ($base ? cod_de($base, 'local') : proximo($codigos, PREFIJO_UBIC[$tipo] . '-'));
    $padre = array_key_exists('dentro_de', $d) ? (txt($d['dentro_de']) ?: null) : ($base ? $U['lista'][$id]['padre'] : null);
    if ($padre !== null && !isset($U['lista'][$padre])) throw new Fallo(422, 'La ubicación que la contiene no existe');
    if ($padre !== null && $id !== null) { $p = $padre; $n = 0; while ($p !== null && $n++ < 30) { if ($p === $id) throw new Fallo(422, 'Una ubicación no puede quedar dentro de sí misma ni de una que depende de ella'); $p = $U['lista'][$p]['padre'] ?? null; } }
    $estado = isset($d['estado']) ? ($d['estado'] === 'inactive' ? 'inactive' : 'active') : (string) ($base['status'] ?? 'active');
    $r = ($base ?? []) + ['resourceType' => 'Location'];
    unset($r['meta']);
    $r['identifier'] = [['use' => 'official', 'system' => sid('local'), 'value' => $cod]]; $r['name'] = $nombre; $r['status'] = $estado;
    $r['physicalType'] = ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/location-physical-type', 'code' => $tipo]]];
    if ($padre) $r['partOf'] = ['reference' => 'Location/' . $padre]; else unset($r['partOf']);
    unset($r['type']);
    if ($base) $x = pim_gm('PUT', 'Location/' . $id, $r + ['id' => $id], ['if_match' => 'W/"' . $base['meta']['versionId'] . '"', 'cab' => ['X-PIM-Adoptar' => 'si']]);
    else $x = pim_gm('POST', 'Location', $r);
    asentar($base ? 'inventario.ubicacion_cambio' : 'inventario.ubicacion_alta', ['codigo' => $cod, 'nombre' => $nombre, 'estado' => $estado], $x['cuerpo']['id'], $u['usuario'] ?? null);
    $L = ubicaciones()['lista'];
    return ['ubicacion' => array_diff_key($L[$x['cuerpo']['id']] ?? [], ['_r' => 1])];
}
function establecimiento(string $nombre, array $u): array {
    $a = pim_admin(['accion' => 'ajustes', 'cambio' => ['organismo_nombre' => $nombre]])['ajustes'];
    asentar('inventario.establecimiento', ['nombre' => $a['organismo']['nombre'] ?? $nombre], null, $u['usuario'] ?? null);
    return ['establecimiento' => ['nombre' => $a['organismo']['nombre'] ?? null, 'sugerido' => $a['organismo']['sugerido'] ?? '', 'sigla' => $a['sigla'] ?? '']];
}

// ---------------------------------------------------------------- contexto compartido por la pantalla y la planilla
/** Resuelve ubicaciones y activos por código o por ruta; propone códigos; junta las ubicaciones que hay que crear. */
function contexto(array &$U, array $A, string $sigla, bool $crearUbic, array $deLaPlanilla = [], array $planU = []): array {
    $porCod = []; foreach ($U['lista'] as $x) $porCod[norm($x['codigo'])] = $x['id'];
    $porRuta = []; $porNombre = [];
    // al revisar una planilla, las ubicaciones de su hoja «Ubicaciones» todavía no existen: cuentan como si existieran (si no, se propondría crearlas dos veces)
    foreach (array_merge(array_values($U['lista']), array_values($planU)) as $x) { $porRuta[norm(str_replace('›', '>', $x['ruta']))] = $x['id']; $porNombre[norm($x['nombre'])][] = $x['id']; }
    $codAct = []; foreach ($A as $a) { $codAct[norm($a['codigo'])] = $a['id']; $codAct[norm(sin_sigla($a['codigo'], $sigla))] = $a['id']; }
    $usados = array_map(fn($a) => sin_sigla($a['codigo'], $sigla), $A);
    $usadosUbic = array_column($U['lista'], 'codigo');
    $pend = [];   // ubicaciones a crear: clave temporal «nueva:n» → [nombre, tipo, padre(clave), codigo]
    $ctx = [];
    $ctx['ubicacion'] = function (?string $cod, ?string $ruta) use (&$porCod, &$porRuta, &$porNombre, &$pend, &$usadosUbic, $crearUbic, $deLaPlanilla): string {
        if ($cod !== null && $cod !== '') {
            if (isset($porCod[norm($cod)])) return $porCod[norm($cod)];
            if (isset($deLaPlanilla[norm($cod)])) return $deLaPlanilla[norm($cod)];
            throw new \InvalidArgumentException("no existe la ubicación con código «{$cod}»");
        }
        $partes = array_values(array_filter(array_map('trim', preg_split('/\s*(?:>|›|»|\/|\\\\)\s*/u', (string) $ruta)), fn($x) => $x !== ''));
        if (!$partes) throw new \InvalidArgumentException('ubicación vacía');
        $k = norm(implode('>', $partes)); $k = norm(implode(' > ', $partes));
        if (isset($porRuta[$k])) return $porRuta[$k];
        if (count($partes) === 1 && count($porNombre[norm($partes[0])] ?? []) === 1) return $porNombre[norm($partes[0])][0];
        if (count($partes) === 1 && count($porNombre[norm($partes[0])] ?? []) > 1) throw new \InvalidArgumentException("hay varias ubicaciones llamadas «{$partes[0]}»: usá la ruta completa (Edificio > Sala) o el código");
        if (!$crearUbic) throw new \InvalidArgumentException('no existe la ubicación «' . implode(' › ', $partes) . '» (marcá «Crear las ubicaciones que falten» o cargala antes)');
        // se crea la cadena que falta: el primer nivel como edificio, el último como local, los del medio como niveles
        $padre = null; $acum = [];
        foreach ($partes as $i => $nom) {
            $acum[] = $nom; $kk = norm(implode(' > ', $acum));
            if (isset($porRuta[$kk])) { $padre = $porRuta[$kk]; continue; }
            $tipo = count($partes) === 1 ? 'area' : ($i === 0 ? 'bu' : ($i === count($partes) - 1 ? 'ro' : 'lvl'));
            $cl = 'nueva:' . count($pend); $cod = proximo($usadosUbic, PREFIJO_UBIC[$tipo] . '-'); $usadosUbic[] = $cod;
            $pend[$cl] = ['nombre' => mb_substr($nom, 0, 160), 'tipo' => $tipo, 'padre' => $padre, 'codigo' => $cod, 'ruta' => implode(' › ', $acum)];
            $porRuta[$kk] = $cl; $padre = $cl;
        }
        return $padre;
    };
    $ctx['activo'] = function (string $cod) use ($codAct, $deLaPlanilla): string {
        return $codAct[norm($cod)] ?? $deLaPlanilla['act:' . norm($cod)] ?? throw new \InvalidArgumentException("no existe el activo «{$cod}» del que forma parte");
    };
    $ctx['proponer'] = function (string $cl, ?string $sis) use (&$usados): string { $c = proximo($usados, prefijo_activo($cl, $sis)); $usados[] = $c; return $c; };
    $ctx['pendientes'] = function () use (&$pend) { return $pend; };
    $ids = [];
    $ctx['crear_pendientes'] = function () use (&$pend, &$ids, &$U) {
        foreach ($pend as $cl => $p) {
            if (isset($ids[$cl])) continue;
            $r = ['resourceType' => 'Location', 'status' => 'active', 'name' => $p['nombre'], 'identifier' => [['use' => 'official', 'system' => sid('local'), 'value' => $p['codigo']]],
                  'physicalType' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/location-physical-type', 'code' => $p['tipo']]]]];
            if ($p['padre']) $r['partOf'] = ['reference' => 'Location/' . ($ids[$p['padre']] ?? $p['padre'])];
            $ids[$cl] = pim_gm('POST', 'Location', $r)['cuerpo']['id'];
        }
    };
    $ctx['resolver'] = function (array $r) use (&$ids): array {
        foreach (['location' => 'Location/', 'parent' => 'Device/'] as $k => $pre) if (isset($r[$k]['reference']) && str_starts_with($r[$k]['reference'], $pre . 'nueva:')) {
            $cl = substr($r[$k]['reference'], strlen($pre)); $r[$k]['reference'] = $pre . ($ids[$cl] ?? throw new Fallo(500, 'No se creó la ubicación ' . $cl));
        }
        return $r;
    };
    return $ctx;
}

// ---------------------------------------------------------------- planilla de ejemplo y exportación
function hoja_listas(): array {
    $f = [['Lista', 'Valor', 'Significado']];
    foreach (['instalación' => 'Instalación hospitalaria (gases, electricidad, climatización, agua…). Lleva sistema PIM.', 'equipo médico' => 'Equipo o producto médico (PMA). Puede llevar N.º ANMAT y clase de riesgo.', 'otro' => 'Herramientas, mobiliario, vehículos y todo lo demás.'] as $k => $v) $f[] = ['Clase', $k, $v];
    foreach (sistemas() as $c => $n) $f[] = ['Sistema PIM (Anexo B)', $c, $n];
    foreach (['edificio', 'ala', 'nivel', 'local', 'área', 'cama'] as $t) $f[] = ['Tipo de ubicación', $t, ['edificio' => 'Un edificio del establecimiento', 'ala' => 'Ala o pabellón de un edificio', 'nivel' => 'Piso o planta', 'local' => 'Sala, oficina, quirófano, sala de máquinas', 'área' => 'Sector o área sin límites físicos', 'cama' => 'Cama o puesto de paciente'][$t]];
    foreach (['1' => 'Falla que puede causar lesión grave o muerte de pacientes o personal', '2' => 'Falla que puede causar lesión leve', '3' => 'Falla que causa molestias, sin lesión', '4' => 'Falla sin impacto en la atención del paciente'] as $k => $v) $f[] = ['Categoría NFPA 99', $k, $v];
    foreach (['en servicio' => 'En uso: se le pueden pedir trabajos', 'fuera de circulación' => 'Guardado, prestado o en reparación externa: no recibe órdenes nuevas', 'obsoleto' => 'Dado de baja para siempre: no recibe órdenes nuevas', 'cargado por error' => 'Duplicado o mal cargado: no recibe órdenes nuevas'] as $k => $v) $f[] = ['Estado', $k, $v];
    return ['nombre' => 'Listas', 'filas' => $f, 'anchos' => [22, 22, 80]];
}
function hoja_instrucciones(string $estab): array {
    $f = [[['v' => 'Inventario de ' . $estab . ' · cómo completar esta planilla', 'estilo' => 'titulo']], [''],
        ['1. Primero la hoja «Ubicaciones»: edificios, pisos y salas, cada uno «dentro de» otro por su código.'],
        ['2. Después la hoja «Activos»: cada fila es una instalación, un equipo médico u otro activo.'],
        ['3. Una fila con un código que ya existe ACTUALIZA ese activo; con un código nuevo, lo da de alta.'],
        ['4. Una celda vacía no cambia nada. Para borrar un dato, escribí un guion: -'],
        ['5. Las filas grises de ejemplo se pueden borrar o reemplazar.'],
        ['6. Nada se elimina: para sacar de uso un activo, poné el estado «fuera de circulación» u «obsoleto» y el motivo.'],
        ['7. En iProy: Inventario › Importar › elegí esta planilla › «Revisar» muestra lo que va a cambiar antes de guardar.'],
        ['8. Desde Google Sheets: Compartir › «Cualquier persona con el enlace» (Lector) y pegá el enlace en «Importar».'],
        [''], [['v' => 'Columnas de «Activos»', 'estilo' => 'titulo']]];
    foreach (COL_ACTIVOS as [$t, $a]) $f[] = [$t, ['v' => $a, 'estilo' => 'nota']];
    $f[] = ['']; $f[] = [['v' => 'Columnas de «Ubicaciones»', 'estilo' => 'titulo']];
    foreach (COL_UBIC as [$t, $a]) $f[] = [$t, ['v' => $a, 'estilo' => 'nota']];
    return ['nombre' => 'Instrucciones', 'filas' => $f, 'anchos' => [28, 100], 'encabezado' => false];
}
function encabezados(array $cols): array { return array_map(fn($c) => $c[0], array_values($cols)); }
function ejemplo(array $fila, bool $gris): array { return array_map(fn($v) => $gris ? ['v' => $v, 'estilo' => 'ejemplo'] : $v, $fila); }
/** Planilla de ejemplo (.xlsx con Instrucciones, Ubicaciones, Activos y Listas; o .csv de una hoja). */
function plantilla(string $formato, string $hoja = 'activos'): array {
    $ubic = [encabezados(COL_UBIC),
        ejemplo(['EDI-001', 'Edificio Ampliación', 'edificio', '', '', 'en uso'], true),
        ejemplo(['NIV-001', 'Planta baja', 'nivel', 'EDI-001', '', 'en uso'], true),
        ejemplo(['LOC-001', 'Sala de gases', 'local', 'NIV-001', '', 'en uso'], true),
        ejemplo(['LOC-002', 'Quirófano 1', 'local', 'NIV-001', '', 'en uso'], true)];
    $act = [encabezados(COL_ACTIVOS),
        ejemplo(['GAS-O2-001', 'Tanque de oxígeno líquido', 'instalación', 'GAS-O2', 'Tanque criogénico', 'LOC-001', '', '', 'Air Liquide', 'TC-6000', 'A-12345', '1', '2019-05-20', '', '', 'en servicio', ''], true),
        ejemplo(['GAS-O2-002', 'Manifold de oxígeno de reserva', 'instalación', 'GAS-O2', 'Central de cilindros', '', 'Edificio Ampliación > Planta baja > Sala de gases', 'GAS-O2-001', '', '', '', '1', '', '', '', 'en servicio', ''], true),
        ejemplo(['EQM-001', 'Monitor multiparamétrico', 'equipo médico', '', 'Monitor', 'LOC-002', '', '', 'Mindray', 'iMEC 10', 'SN-998877', '2', '2021-03-01', 'PM-2430-65', 'II', 'en servicio', ''], true),
        ejemplo(['ACT-001', 'Escalera telescópica', 'otro', '', 'Herramienta', '', 'Edificio Ampliación', '', '', '', '', '', '', '', '', 'fuera de circulación', 'Prestada al servicio de Obras hasta fin de mes'], true)];
    if ($formato === 'csv') return ['nombre' => $hoja === 'ubicaciones' ? 'ubicaciones_ejemplo.csv' : 'activos_ejemplo.csv', 'tipo' => 'text/csv', 'base64' => base64_encode(csv_armar(array_map(fn($f) => array_map(fn($c) => is_array($c) ? $c['v'] : $c, $f), $hoja === 'ubicaciones' ? $ubic : $act)))];
    $estab = (string) (pim_admin(['accion' => 'ajustes'])['ajustes']['organismo']['sugerido'] ?? 'el establecimiento');
    $bin = planilla_armar([hoja_instrucciones($estab), ['nombre' => 'Ubicaciones', 'filas' => $ubic, 'anchos' => array_column(array_values(COL_UBIC), 2)],
        ['nombre' => 'Activos', 'filas' => $act, 'anchos' => array_column(array_values(COL_ACTIVOS), 2)], hoja_listas()]);
    return ['nombre' => 'inventario_planilla_de_ejemplo.xlsx', 'tipo' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'base64' => base64_encode($bin)];
}
function exportar(string $formato, string $hoja = 'activos', bool $todos = true): array {
    $U = ubicaciones(); $A = activos($U); $sigla = '';
    $tipoTxt = ['bu' => 'edificio', 'wi' => 'ala', 'lvl' => 'nivel', 'ro' => 'local', 'area' => 'área', 'bd' => 'cama'];
    $estTxt = ['en-servicio' => 'en servicio', 'fuera-de-circulacion' => 'fuera de circulación', 'obsoleto' => 'obsoleto', 'cargado-por-error' => 'cargado por error'];
    $clTxt = ['sistema' => 'instalación', 'equipo-medico' => 'equipo médico', 'activo' => 'otro'];
    $ub = [encabezados(COL_UBIC)];
    foreach ($U['lista'] as $u) $ub[] = [$u['codigo'], $u['nombre'], $tipoTxt[$u['tipo']] ?? $u['tipo'], $u['padre'] ? ($U['lista'][$u['padre']]['codigo'] ?? '') : '', str_replace(' › ', ' > ', $u['ruta']), $u['estado'] === 'active' ? 'en uso' : 'fuera de uso'];
    $ac = [encabezados(COL_ACTIVOS)];
    foreach ($A as $a) {
        if (!$todos && $a['estado'] !== 'en-servicio') continue;
        $ac[] = [$a['codigo'], $a['nombre'], $clTxt[$a['clase']], $a['sistema'], $a['tipo'], $a['ubicacion_codigo'], str_replace(' › ', ' > ', $a['ubicacion']), $a['forma_parte_de'],
                 $a['fabricante'], $a['modelo'], $a['serie'], $a['categoria_nfpa99'] !== '' ? (int) $a['categoria_nfpa99'] : '', $a['puesta_en_marcha'], $a['anmat_pm'], $a['clase_riesgo_pm'], $estTxt[$a['estado']], $a['motivo_estado']];
    }
    $fecha = date('Y-m-d');
    if ($formato === 'csv') return ['nombre' => ($hoja === 'ubicaciones' ? 'ubicaciones_' : 'activos_') . $fecha . '.csv', 'tipo' => 'text/csv', 'base64' => base64_encode(csv_armar($hoja === 'ubicaciones' ? $ub : $ac))];
    $bin = planilla_armar([['nombre' => 'Ubicaciones', 'filas' => $ub, 'anchos' => array_column(array_values(COL_UBIC), 2)], ['nombre' => 'Activos', 'filas' => $ac, 'anchos' => array_column(array_values(COL_ACTIVOS), 2)],
        hoja_listas(), hoja_instrucciones($U['estab']['nombre'] ?? 'el establecimiento')]);
    return ['nombre' => "inventario_$fecha.xlsx", 'tipo' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'base64' => base64_encode($bin)];
}

// ---------------------------------------------------------------- importar
/** Encabezados de la planilla → claves propias. Devuelve [mapa índice → clave, columnas no reconocidas]. */
function mapear(array $cab, array $cols): array {
    $propios = []; foreach ($cols as $k => [$t]) $propios[norm($t)] = $k;
    $m = []; $no = [];
    foreach ($cab as $i => $h) {
        $n = norm((string) $h); if ($n === '') continue;
        $k = $propios[$n] ?? null;
        if (!$k) foreach (SINONIMOS as $kk => $l) if (isset($cols[$kk]) && in_array($n, $l, true)) { $k = $kk; break; }
        if ($k && !in_array($k, $m, true)) $m[$i] = $k; else $no[] = (string) $h;
    }
    return [$m, $no];
}
/** Separa las hojas de la planilla en ubicaciones y activos (por el nombre de la hoja o, si no, por sus encabezados). */
function hojas(array $libro): array {
    $u = null; $a = null;
    foreach ($libro as $nom => $filas) {
        $n = norm((string) $nom); if (str_contains($n, 'lista') || str_contains($n, 'instruc')) continue;
        $cab = []; $ini = 0; foreach ($filas as $i => $f) if (array_filter($f, fn($x) => trim((string) $x) !== '')) { $cab = $f; $ini = $i; break; }
        if (!$cab) continue;
        [$mu] = mapear($cab, COL_UBIC); [$ma] = mapear($cab, COL_ACTIVOS);
        $esU = str_contains($n, 'ubicac') || (in_array('dentro_de', $mu, true) && !in_array('clase', $ma, true) && !in_array('sistema', $ma, true));
        if ($esU && $u === null) $u = [$nom, $cab, array_slice($filas, $ini + 1, null, true), $ini];
        elseif (!$esU && $a === null) $a = [$nom, $cab, array_slice($filas, $ini + 1, null, true), $ini];
    }
    return ['ubicaciones' => $u, 'activos' => $a];
}
function filas_de(?array $h, array $cols): array {
    if (!$h) return [[], [], null];
    [$nom, $cab, $filas, $ini] = $h; [$m, $no] = mapear($cab, $cols); $o = [];
    foreach ($filas as $i => $f) {
        $r = ['_fila' => $i + 1]; $vacia = true;
        foreach ($m as $c => $k) { $v = (string) ($f[$c] ?? ''); $r[$k] = $v; if (trim($v) !== '') $vacia = false; }
        if (!$vacia) $o[] = $r;
    }
    return [$o, $no, $nom];
}
function obtener_libro(array $d): array {
    if (!empty($d['enlace'])) { $x = planilla_de_enlace((string) $d['enlace']); return [planilla_leer($x['bin'], $x['nombre']), 'enlace'] ; }
    if (!empty($d['base64'])) {
        $bin = base64_decode((string) $d['base64'], true); if ($bin === false || $bin === '') throw new Fallo(422, 'El archivo llegó vacío o dañado');
        return [planilla_leer($bin, (string) ($d['nombre'] ?? 'planilla.xlsx')), (string) ($d['nombre'] ?? 'archivo')];
    }
    if (!empty($d['texto'])) return [['Pegado' => \Nucleo\csv_leer((string) $d['texto'])], 'texto pegado'];
    throw new Fallo(422, 'Elegí un archivo (.xlsx o .csv) o pegá el enlace de la hoja de Google');
}
/** Revisa (simular) o aplica una planilla. Devuelve, por hoja, qué entra nuevo, qué cambia, qué queda igual y qué filas tienen errores. */
function importar(array $d, array $u): array {
    @set_time_limit(600);
    [$libro, $origen] = obtener_libro($d);
    $simular = !empty($d['simular']); $crearUbic = !empty($d['crear_ubicaciones']);
    $H = hojas($libro);
    [$fu, $noU, $hU] = filas_de($H['ubicaciones'], COL_UBIC);
    [$fa, $noA, $hA] = filas_de($H['activos'], COL_ACTIVOS);
    if (!$fu && !$fa) throw new Fallo(422, 'La planilla no tiene filas con datos. Bajá la «Planilla de ejemplo» para ver las columnas.');
    if ($fa && !array_intersect(['nombre', 'codigo'], array_keys($fa[0])))
        throw new Fallo(422, 'En la hoja de activos no encontré las columnas «Código» ni «Nombre». Bajá la «Planilla de ejemplo» para ver los encabezados.');
    $U = ubicaciones(); $A = activos($U);
    $sigla = (string) (pim_admin(['accion' => 'ajustes'])['ajustes']['sigla'] ?? '');
    $res = ['origen' => $origen, 'simulado' => $simular, 'hojas' => ['ubicaciones' => $hU, 'activos' => $hA], 'columnas_ignoradas' => array_values(array_unique(array_merge($noU, $noA))),
            'ubicaciones' => ['nuevas' => 0, 'cambian' => 0, 'iguales' => 0, 'errores' => []], 'activos' => ['nuevos' => 0, 'cambian' => 0, 'iguales' => 0, 'errores' => [], 'detalle' => []],
            'ubicaciones_a_crear' => []];
    // ---- ubicaciones de la planilla: primero las que no dependen de otra, después sus hijas
    $porCod = []; foreach ($U['lista'] as $x) $porCod[norm($x['codigo'])] = $x['id'];
    $tipoTxt = ['edificio' => 'bu', 'ala' => 'wi', 'pabellon' => 'wi', 'nivel' => 'lvl', 'piso' => 'lvl', 'planta' => 'lvl', 'local' => 'ro', 'sala' => 'ro', 'oficina' => 'ro', 'area' => 'area', 'sector' => 'area', 'cama' => 'bd'];
    $nuevasPlanilla = []; $planU = []; $pendU = $fu; $vuelta = 0; $usadosU = array_column($U['lista'], 'codigo');
    while ($pendU && $vuelta++ < 20) {
        $quedan = [];
        foreach ($pendU as $f) {
            try {
                $nom = txt($f['nombre'] ?? ''); $cod = txt($f['codigo'] ?? '');
                $t = norm((string) ($f['tipo'] ?? '')); $tipo = $t === '' ? null : ($tipoTxt[$t] ?? (isset(TIPOS_UBIC[$t]) ? $t : throw new \InvalidArgumentException("tipo «{$f['tipo']}»: edificio, ala, nivel, local, área o cama")));
                $dentro = txt($f['dentro_de'] ?? '');
                $padre = null;
                if ($dentro !== '') { $padre = $porCod[norm($dentro)] ?? $nuevasPlanilla[norm($dentro)] ?? null; if ($padre === null) { $quedan[] = $f; continue; } }
                $id = $cod !== '' ? ($porCod[norm($cod)] ?? null) : null;
                if ($id === null && $nom === '') throw new \InvalidArgumentException('falta el nombre');
                $estado = isset($f['estado']) && trim((string) $f['estado']) !== '' ? (str_contains(norm((string) $f['estado']), 'fuera') || norm((string) $f['estado']) === 'inactiva' ? 'inactive' : 'active') : null;
                if ($id === null) {
                    $tipo ??= 'ro'; if ($cod === '') { $cod = proximo($usadosU, PREFIJO_UBIC[$tipo] . '-'); } $usadosU[] = $cod;
                    $res['ubicaciones']['nuevas']++;
                    $clave = 'nueva:u' . count($nuevasPlanilla);
                    if (!$simular) $clave = guardar_ubicacion(null, ['codigo' => $cod, 'nombre' => $nom, 'tipo' => $tipo, 'dentro_de' => str_starts_with((string) $padre, 'nueva:') ? null : $padre, 'estado' => $estado], $u)['ubicacion']['id'];
                    $nuevasPlanilla[norm($cod)] = $clave; $planU[$clave] = ['id' => $clave, 'nombre' => $nom, 'padre' => $padre];
                } else {
                    $x = $U['lista'][$id]; $cambio = [];
                    if ($nom !== '' && $nom !== $x['nombre']) $cambio['nombre'] = $nom;
                    if ($tipo !== null && $tipo !== $x['tipo']) $cambio['tipo'] = $tipo;
                    if ($dentro !== '' && $padre !== $x['padre']) $cambio['dentro_de'] = $padre;
                    if ($estado !== null && $estado !== $x['estado']) $cambio['estado'] = $estado;
                    if (!$cambio) { $res['ubicaciones']['iguales']++; continue; }
                    $res['ubicaciones']['cambian']++;
                    if (!$simular) guardar_ubicacion($id, $cambio, $u);
                }
            } catch (\InvalidArgumentException | Fallo $e) { $res['ubicaciones']['errores'][] = ['fila' => $f['_fila'], 'codigo' => txt($f['codigo'] ?? ''), 'motivo' => $e->getMessage()]; }
        }
        if (count($quedan) === count($pendU)) { foreach ($quedan as $f) $res['ubicaciones']['errores'][] = ['fila' => $f['_fila'], 'codigo' => txt($f['codigo'] ?? ''), 'motivo' => 'no existe la ubicación «' . txt($f['dentro_de'] ?? '') . '» que la contiene']; break; }
        $pendU = $quedan;
    }
    if (!$simular && $fu) { $U = ubicaciones(); $nuevasPlanilla = []; $planU = []; }
    $rutaPlan = function (string $cl) use (&$planU, $U, &$rutaPlan): string {
        $p = $planU[$cl]; $pa = $p['padre'];
        $pr = $pa === null ? '' : (isset($planU[$pa]) ? $rutaPlan($pa) : (string) ($U['lista'][$pa]['ruta'] ?? ''));
        return ($pr !== '' ? $pr . ' › ' : '') . $p['nombre'];
    };
    foreach (array_keys($planU) as $cl) $planU[$cl]['ruta'] = $rutaPlan($cl);
    // ---- activos
    $planCod = []; foreach ($fa as $i => $f) if (($c = txt($f['codigo'] ?? '')) !== '') $planCod[norm($c)] = $i;
    $deLaPlanilla = $nuevasPlanilla;
    foreach ($fa as $i => $f) if (($c = txt($f['codigo'] ?? '')) !== '') $deLaPlanilla['act:' . norm($c)] = 'nueva:a' . $i;
    $ctx = contexto($U, $A, $sigla, $crearUbic, $deLaPlanilla, $planU);
    $codExist = []; foreach ($A as $a) { $codExist[norm($a['codigo'])] = $a['id']; $codExist[norm(sin_sigla($a['codigo'], $sigla))] = $a['id']; }
    $repetidos = [];
    $ops = [];   // [fila, base|null, recurso, padre pendiente]
    foreach ($fa as $i => $f) {
        try {
            $c = txt($f['codigo'] ?? '');
            if ($c !== '') { $k = norm(sin_sigla($c, $sigla)); if (isset($repetidos[$k])) throw new \InvalidArgumentException("el código «{$c}» está repetido (también en la fila {$repetidos[$k]})"); $repetidos[$k] = $f['_fila']; }
            $id = $c !== '' ? ($codExist[norm($c)] ?? $codExist[norm(sin_sigla($c, $sigla))] ?? null) : null;
            $base = $id ? $A[$id]['_r'] : null;
            $pa = celda($f, 'forma_parte_de'); $padrePend = null;
            if ($pa !== null && $pa !== '' && !isset($codExist[norm($pa)]) && !isset($codExist[norm(sin_sigla($pa, $sigla))])) {   // el padre está en la misma planilla: se asigna en una segunda pasada
                if (!isset($planCod[norm($pa)])) throw new \InvalidArgumentException("no existe el activo «{$pa}» del que forma parte");
                $padrePend = $pa; $f['forma_parte_de'] = null; unset($f['forma_parte_de']);
            }
            $r = device_de_fila($f, $base, $ctx);
            if ($base) {
                $dif = diferencias($base, $r + ['meta' => $base['meta'], 'id' => $id], $U);
                if (!$dif && !$padrePend) { $res['activos']['iguales']++; continue; }
                $res['activos']['cambian']++;
                if (count($res['activos']['detalle']) < 300) $res['activos']['detalle'][] = ['fila' => $f['_fila'], 'codigo' => cod_de($r, 'activo'), 'nombre' => $r['deviceName'][0]['name'], 'accion' => 'cambia', 'que' => $dif];
            } else {
                $res['activos']['nuevos']++;
                if (count($res['activos']['detalle']) < 300) $res['activos']['detalle'][] = ['fila' => $f['_fila'], 'codigo' => cod_de($r, 'activo'), 'nombre' => $r['deviceName'][0]['name'], 'accion' => 'nuevo', 'que' => []];
            }
            $ops[] = [$f['_fila'], $base, $r, $padrePend, $id, !empty($id) && !empty($A[$id]['legado'])];
        } catch (\InvalidArgumentException $e) { $res['activos']['errores'][] = ['fila' => $f['_fila'], 'codigo' => txt($f['codigo'] ?? ''), 'motivo' => $e->getMessage()]; }
    }
    $res['ubicaciones_a_crear'] = array_values(array_map(fn($p) => $p['ruta'] . ' (' . $p['codigo'] . ')', ($ctx['pendientes'])()));
    if ($simular) return $res + ['listo' => true];
    // ---- aplicar: ubicaciones que faltan, activos por lotes, después los «forma parte de» dentro de la planilla
    ($ctx['crear_pendientes'])();
    $creados = []; $hechos = 0;
    foreach (array_chunk($ops, LOTE) as $lote) {
        $entries = [];
        foreach ($lote as [$fila, $base, $r, , $id, $adoptar]) {
            $r = ($ctx['resolver'])($r);
            if (isset($r['parent']['reference']) && str_starts_with($r['parent']['reference'], 'Device/nueva:')) unset($r['parent']);
            $entries[] = $base ? ['resource' => $r + ['id' => $id], 'request' => ['method' => 'PUT', 'url' => "Device/$id", 'ifMatch' => 'W/"' . $base['meta']['versionId'] . '"', 'adoptar' => $adoptar]]
                               : ['resource' => $r, 'request' => ['method' => 'POST', 'url' => 'Device']];
        }
        $b = pim_gm('POST', '', ['resourceType' => 'Bundle', 'type' => 'batch', 'entry' => $entries])['cuerpo'];
        foreach ((array) ($b['entry'] ?? []) as $k => $e) {
            $st = (int) ($e['response']['status'] ?? 0);
            if ($st >= 200 && $st < 300) { $hechos++; $cr = $e['resource']; $creados[norm(cod_de($cr, 'activo'))] = $cr; continue; }
            $res['activos']['errores'][] = ['fila' => $lote[$k][0], 'codigo' => cod_de($lote[$k][2], 'activo'), 'motivo' => (string) ($e['response']['outcome']['issue'][0]['diagnostics'] ?? $e['response']['status'] ?? 'rechazada')];
        }
    }
    // segunda pasada: «forma parte de» con un activo de la misma planilla
    foreach ($ops as [$fila, , $r, $padrePend]) {
        if (!$padrePend) continue;
        $hijo = $creados[norm(cod_de($r, 'activo'))] ?? null; $padre = $creados[norm($padrePend)] ?? null;
        if (!$padre) foreach ($creados as $kk => $cc) if (norm(sin_sigla(cod_de($cc, 'activo'), $sigla)) === norm(sin_sigla($padrePend, $sigla))) $padre = $cc;
        if (!$hijo || !$padre) { $res['activos']['errores'][] = ['fila' => $fila, 'codigo' => cod_de($r, 'activo'), 'motivo' => "no se pudo unir con «{$padrePend}»"]; continue; }
        $h = $hijo; unset($h['meta']['versionId'], $h['meta']['lastUpdated']); $h['parent'] = ['reference' => 'Device/' . $padre['id']];
        try { pim_gm('PUT', 'Device/' . $hijo['id'], $h, ['if_match' => 'W/"' . $hijo['meta']['versionId'] . '"']); }
        catch (Fallo $e) { $res['activos']['errores'][] = ['fila' => $fila, 'codigo' => cod_de($r, 'activo'), 'motivo' => $e->getMessage()]; }
    }
    asentar('inventario.importacion', ['origen' => mb_substr($origen, 0, 120), 'ubicaciones' => array_diff_key($res['ubicaciones'], ['errores' => 1]) + ['errores' => count($res['ubicaciones']['errores'])],
        'activos' => array_diff_key($res['activos'], ['errores' => 1, 'detalle' => 1]) + ['errores' => count($res['activos']['errores'])], 'guardados' => $hechos], null, $u['usuario'] ?? null);
    return $res + ['guardados' => $hechos, 'listo' => true];
}
