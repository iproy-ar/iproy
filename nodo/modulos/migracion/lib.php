<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Migración de datos desde otro sistema.
 *
 *   origen (CSV · hoja publicada · MySQL) ──▶ columnas ──▶ campos PIM (mapa) ──▶ equivalencias ──▶ Servidor PIM (carga por lotes)
 *
 * El módulo no escribe en la base PIM: arma filas normalizadas y se las pasa al Servidor PIM (pim/admin/migrar.php), que valida,
 * versiona y marca cada registro como traído de otro sistema. La clave de una base MySQL de origen se usa en el pedido y no se guarda.
 */
declare(strict_types=1);

namespace Modulos\Migracion;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, asentar};

const DESTINOS = [
    'servicios' => ['nombre' => 'Servicios (Location)', 'campos' => ['nombre' => ['Nombre del servicio', true], 'codigo' => ['Código', false]]],
    'empresas'  => ['nombre' => 'Empresas y áreas (Organization)', 'campos' => ['codigo' => ['Código', true], 'nombre' => ['Nombre', false], 'tipo' => ['Tipo (empresa | area)', false], 'activo' => ['Activa', false]]],
    'equipos'   => ['nombre' => 'Equipos médicos (Device · PMA)', 'campos' => ['codigo' => ['Código del equipo', true], 'nombre' => ['Nombre', false], 'tipo_texto' => ['Tipo de equipo', false], 'tipo_codigo' => ['Código del tipo', false],
                    'servicio' => ['Servicio donde está', false], 'fabricante' => ['Marca', false], 'modelo' => ['Modelo', false], 'serie' => ['N.º de serie', false], 'estado' => ['Estado (baja = inactivo)', false], 'riesgo' => ['Criticidad o categoría de riesgo (NFPA 99)', false]]],
    'sistemas'  => ['nombre' => 'Sistemas de instalación (Device · Anexo B)', 'campos' => ['codigo' => ['Código de la instalación', true], 'nombre' => ['Nombre', false], 'tipo_codigo' => ['Clase de sistema PIM', true],
                    'servicio' => ['Servicio o lugar', false], 'estado' => ['Estado', false], 'riesgo' => ['Criticidad o categoría de riesgo (NFPA 99)', false]]],
    'ordenes'   => ['nombre' => 'Órdenes de trabajo (Task)', 'campos' => ['numero' => ['Número de orden', true], 'alta' => ['Fecha de alta', true], 'estado' => ['Estado', true], 'servicio' => ['Servicio solicitante', false],
                    'detalle' => ['Detalle del pedido', false], 'categoria' => ['Tipo de tarea o categoría', false], 'empresa' => ['Empresa o área asignada', false], 'tipo_mantenimiento' => ['Tipo de mantenimiento', false],
                    'prioridad' => ['Prioridad', false], 'inicio' => ['Fecha de inicio o atención', false], 'fin' => ['Fecha de fin', false], 'codigo' => ['Código de la orden en el sistema de origen', false],
                    'equipo' => ['Código del equipo (si es de equipamiento médico)', false], 'motivo' => ['Motivo, observación o anotación', false]]],
    // 1.1.0: planes preventivos (hoja PMP u otro cronograma). Los carga el módulo Preventivos, no el Servidor PIM: el activo tiene que existir en PIM
    // 1.2.0: plan preventivo por semanas (preventivos 2.0.0): una fila por tarea, como la hoja de cronograma anual
    'tareas_pmp' => ['nombre' => 'Plan preventivo por semanas: tareas (módulo Preventivos)', 'campos' => ['activo' => ['Código del equipo o instalación (ID unidad)', true], 'preventivo' => ['Tarea preventiva', true],
                    'sistema' => ['Sistema', false], 'ubicacion' => ['Ubicación', false], 'instalacion' => ['Instalación', false], 'sector' => ['Sector', false], 'categoria' => ['Categoría', false],
                    'parte' => ['Parte o componente', false], 'subparte' => ['Subcomponente', false], 'predictivo' => ['Predictivo', false], 'personal' => ['Personal', false], 'hoja_vida' => ['Hoja de vida', false],
                    'inspeccion' => ['Inspección', false], 'insumos' => ['Insumos', false], 'frecuencia' => ['Frecuencia (Diaria, Semanal, Quincenal, Mensual, Bimestral, Trimestral, Semestral, Anual)', false],
                    'proxima' => ['Próxima ejecución (marca el cronograma desde esa semana)', false], 'responsable' => ['Empresa responsable (código)', false]]],
    'planes'    => ['nombre' => 'Planes especiales por visita (módulo Preventivos)', 'campos' => ['activo' => ['Código del equipo o instalación', true], 'preventivo' => ['Tarea preventiva', true],
                    'componente' => ['Componente', false], 'subcomponente' => ['Subcomponente', false], 'frecuencia' => ['Frecuencia (Quincenal, Mensual… o días)', false],
                    'proxima' => ['Próxima ejecución', false], 'responsable' => ['Empresa responsable (código)', false]]],
];
// nombres de columna habituales → campo (para proponer el mapa)
const ALIAS = ['numero' => ['id', 'orden', 'nro', 'numero', 'n_orden', 'nro_orden', 'ot'], 'alta' => ['marca_temporal', 'fecha', 'fecha_alta', 'alta', 'creado', 'timestamp', 'fecha_inicio'], 'estado' => ['estado', 'status', 'situacion'],
    'servicio' => ['servicio_solicitante', 'servicio', 'sector', 'area', 'ubicacion'], 'detalle' => ['detalle', 'descripcion', 'detalle_del_trabajo', 'pedido', 'tarea_a_realizar'], 'categoria' => ['tipo_tarea', 'tipo_de_tarea', 'categoria', 'rubro'],
    'empresa' => ['empresa', 'empresa_asignada', 'responsable', 'proveedor'], 'tipo_mantenimiento' => ['tipo_man', 'tipo_mantenimiento', 'tipo_mant', 'tipo-man'], 'prioridad' => ['prioridad', 'prioridad_auditor', 'urgencia'],
    'inicio' => ['fecha_atencion', 'fecha_proc', 'inicio', 'fecha_proceso'], 'fin' => ['fecha_fin', 'fin', 'fecha-fin', 'finalizado', 'fecha_cierre'], 'codigo' => ['codigo', 'cod', 'sigla'], 'equipo' => ['pma', 'equipo', 'codigo_equipo', 'cod_equipo'],
    'motivo' => ['observacion', 'observaciones', 'nota', 'anotacion', 'motivo', 'rfc'], 'nombre' => ['nombre', 'descripcion', 'denominacion', 'razon_social', 'servicio', 'equipo'], 'fabricante' => ['marca', 'fabricante'], 'modelo' => ['modelo'],
    'serie' => ['serie', 'nro_serie', 'n_serie', 'numero_serie'], 'tipo_texto' => ['tipo', 'tipo_equipo', 'clase', 'categoria'], 'tipo_codigo' => ['tipo_codigo', 'cod_tipo', 'sigla', 'clase_sistema', 'sistema'], 'activo' => ['activo', 'activa', 'estado'], 'tipo' => ['tipo'], 'riesgo' => ['criticidad', 'riesgo', 'categoria_riesgo', 'nfpa'],
    'activo' => ['codigo', 'codigo_rf', 'cod', 'recurso', 'equipo', 'activo'], 'preventivo' => ['preventivo', 'tarea', 'tarea_preventiva', 'tarea_a_realizar'], 'componente' => ['componente'],
    'subcomponente' => ['sub_componente', 'subcomponente'], 'frecuencia' => ['frecuencia', 'periodicidad'], 'proxima' => ['proxima_ejecucion', 'proxima', 'proxima_fecha', 'proxima_ejecucion_'], 'responsable' => ['empresa', 'responsable', 'empresa_responsable'],
    'ubicacion' => ['ubicacion', 'edificio', 'lugar'], 'instalacion' => ['instalacion', 'instalaciones'], 'sector' => ['sector', 'area'], 'parte' => ['partes', 'parte', 'componente', 'elemento'], 'subparte' => ['sub_componente', 'subcomponente'],
    'predictivo' => ['predictivo'], 'personal' => ['personal'], 'hoja_vida' => ['hoja_de_vida', 'hoja_vida'], 'inspeccion' => ['inspeccion'], 'insumos' => ['insumos', 'materiales'], 'sistema' => ['sistema', 'sistemas', 'procesos']];
const LISTAS = ['estado' => 'Estado de la orden → estado PIM (Tabla 9-2)', 'tipo_mantenimiento' => 'Tipo de mantenimiento → EN 13306', 'prioridad' => 'Prioridad → prioridad PIM', 'categoria' => 'Tipo de tarea o categoría → clase de sistema PIM (Anexo B)', 'riesgo' => 'Criticidad del equipo o sistema → categoría de riesgo NFPA 99 (1 a 4)'];
// equivalencias de fábrica: nombres habituales en sistemas de mantenimiento hospitalario en castellano. Se editan desde la pantalla.
const SEMILLA = [
    'estado' => [['Finalizado', 'finalizada', 'Trabajo terminado'], ['En Proceso', 'asignada', 'Tiene empresa asignada; sin prueba de que se esté ejecutando'], ['En Proceso|con_inicio', 'en-ejecucion', 'Con fecha de inicio o atención: se está ejecutando'],
        ['Atención', 'en-espera', 'Frenada (falta de insumos u otro motivo): PIM exige el motivo'], ['Revisión', 'en-revision', 'A revisar por el gestor antes de seguir'], ['Revisión|con_fin', 'finalizada', 'Terminada y a la espera de la conformidad'],
        ['Denegado', 'rechazada', 'No se hace: PIM exige el motivo'], ['Pendiente', 'solicitada', 'Ingresada, sin tratar'], ['En Curso', 'en-ejecucion', ''], ['Cancelado', 'anulada', ''], ['Anulado', 'anulada', '']],
    'tipo_mantenimiento' => [['Correctivo', 'correctivo-diferido', ''], ['Correctivo|prioridad_a', 'correctivo-inmediato', 'Correctivo con prioridad A: se atiende de inmediato (decisión del 21-11-2026)'], ['Preventivo', 'preventivo-sistematico', ''], ['Predictivo', 'predictivo', ''], ['Mejorativo', 'mejora', ''], ['Mejora', 'mejora', '']],
    'prioridad' => [],
    'riesgo' => [['A', '1', 'Vital: riesgo de vida si falla. Revisar contra el criterio NFPA 99 del establecimiento'], ['B', '2', 'Clínico: la falla demora la atención. Revisar'], ['C', '3', 'Apoyo. Revisar (puede ser 4)'], ['D', '4', 'Sin efecto sobre la atención. Revisar'], ['Alta', '1', ''], ['Media', '2', ''], ['Baja', '3', '']],
    'categoria' => [['Sanitario', 'AGU-SAN', ''], ['Agua (Red)', 'AGU-SAN', ''], ['Electricidad', 'ELE-NORM', ''], ['Luminarias', 'ELE-NORM', ''], ['Electricidad e Iluminación', 'ELE-NORM', ''], ['Climatización Central', 'HVAC-GRAL', ''],
        ['Climatización Split', 'HVAC-GRAL', ''], ['Climatización', 'HVAC-GRAL', ''], ['Ascensores', 'TRV-ASC', ''], ['Red de Incendio', 'INC-EXT', 'Puede ser detección (INC-DET): revisar'], ['Edilicios y Obra Civil', 'EDI-GRAL', ''],
        ['Infraestructura y Estética', 'EDI-GRAL', ''], ['Pintura', 'EDI-GRAL', ''], ['Mobiliario', 'EDI-GRAL', 'Aproximada: el mobiliario no es un sistema de instalación'], ['Vidrios y Carpintería', 'EDI-ABE', ''], ['Aberturas', 'EDI-ABE', ''],
        ['Calderas y Calefacción', 'EST-VAP', 'Aproximada'], ['Gases medicinales', '', 'Sin equivalente único: en PIM es una clase por gas (GAS-O2, GAS-AIRE, GAS-VAC…)'], ['Instalaciones y Fluidos', '', 'Sin equivalente único'],
        ['Logística y Ascensores', '', 'Mezcla dos temas'], ['Movimientos (Logística)', '', 'No es una instalación'], ['Empresa de Limpieza', '', 'No es una instalación'], ['Control de Plagas', '', 'No es una instalación'],
        ['Espacios Verdes (Poda)', '', 'No es una instalación'], ['Poda', '', 'No es una instalación'], ['Tareas No Clasificadas', '', 'Sin clasificar en el origen']],
];

function norm(string $s): string {
    $s = mb_strtolower(trim($s)); $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return preg_replace('/\s+/', ' ', $s);
}
function sembrar(): void {
    static $hecho = false; if ($hecho) return; $hecho = true;
    foreach (SEMILLA as $lista => $l) foreach ($l as [$o, $d, $n])
        q('INSERT IGNORE INTO mig_equivalencia (lista, origen, etiqueta, destino, nota, semilla) VALUES (?,?,?,?,?,1)', [$lista, norm($o), $o, $d, $n]);
}
function equivalencias(): array {
    sembrar(); $o = array_fill_keys(array_keys(LISTAS), []);
    foreach (filas('SELECT lista, origen, etiqueta, destino, nota, semilla FROM mig_equivalencia ORDER BY lista, etiqueta') as $r) $o[$r['lista']][] = $r;
    return $o;
}
function guardar_equivalencias(string $lista, array $l, array $u, array $cat): int {
    if (!isset(LISTAS[$lista])) throw new Fallo(404, 'No existe esa lista de equivalencias');
    $validos = ['estado' => $cat['estados'], 'tipo_mantenimiento' => $cat['tipos'], 'prioridad' => $cat['prioridades'], 'categoria' => array_keys($cat['sistemas']), 'riesgo' => ['1', '2', '3', '4']][$lista]; $n = 0;
    foreach ($l as $e) {
        $et = trim((string) ($e['etiqueta'] ?? ($e['origen'] ?? ''))); if ($et === '') throw new Fallo(422, 'Falta el valor de origen');
        $d = trim((string) ($e['destino'] ?? ''));
        if ($d !== '' && !in_array($d, $validos, true)) throw new Fallo(422, "«{$d}» no es un valor PIM de esta lista");
        if (!empty($e['borrar'])) { q('DELETE FROM mig_equivalencia WHERE lista = ? AND origen = ?', [$lista, norm($et)]); $n++; continue; }
        q('INSERT INTO mig_equivalencia (lista, origen, etiqueta, destino, nota, semilla, modificado, modificado_por) VALUES (?,?,?,?,?,0,?,?)
           ON DUPLICATE KEY UPDATE destino = VALUES(destino), nota = VALUES(nota), etiqueta = VALUES(etiqueta), modificado = VALUES(modificado), modificado_por = VALUES(modificado_por)',
          [$lista, mb_substr(norm($et), 0, 160), mb_substr($et, 0, 160), $d, mb_substr(trim((string) ($e['nota'] ?? '')), 0, 240), date('Y-m-d H:i:s'), (string) ($u['usuario'] ?? '')]); $n++;
    }
    asentar('migracion.equivalencias', ['lista' => $lista, 'cambios' => $n]);
    return $n;
}

// ---------------------------------------------------------------- Servidor PIM (entrada local, con el token del nodo)
function pim_admin(array $cuerpo, int $seg = 300): array {
    $t = (string) (\nodo_cfg()['tick_token'] ?? ''); if ($t === '') throw new Fallo(500, 'El nodo no tiene token interno');
    $c = curl_init(\Nucleo\base_local() . '/pim/admin/migrar.php');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $seg, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-IPROY-Tick: ' . $t],
        CURLOPT_POSTFIELDS => json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
    $r = curl_exec($c); $http = (int) curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    $j = is_string($r) ? json_decode($r, true) : null;
    if (!is_array($j)) throw new Fallo(502, 'El Servidor PIM no respondió a la carga');
    if (empty($j['ok'])) throw new Fallo($http >= 400 && $http < 500 ? 422 : 502, (string) ($j['error'] ?? 'El Servidor PIM rechazó el pedido'));
    return $j;
}
function catalogo_pim(): array { static $c = null; return $c ??= pim_admin(['accion' => 'catalogo'], 20); }

// ---------------------------------------------------------------- orígenes
function de_csv(string $t, int $max): array {
    if (str_starts_with($t, "\xEF\xBB\xBF")) $t = substr($t, 3);
    if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1252');
    $l1 = strtok($t, "\r\n") ?: ''; $sep = ','; $mx = 0;
    foreach ([';', ',', "\t", '|'] as $s) { $n = count(str_getcsv($l1, $s, '"', '')); if ($n > $mx) { $mx = $n; $sep = $s; } }
    $h = fopen('php://temp', 'w+'); fwrite($h, $t); rewind($h);
    $cab = fgetcsv($h, null, $sep, '"', ''); if (!$cab || count($cab) < 1) throw new Fallo(422, 'El archivo no tiene una fila de encabezados');
    $cols = []; foreach ($cab as $i => $c) { $c = trim((string) $c); if ($c === '') $c = 'columna_' . ($i + 1); while (in_array($c, $cols, true)) $c .= '_2'; $cols[] = $c; }
    $rs = []; $n = 1;
    while (($f = fgetcsv($h, null, $sep, '"', '')) !== false) { $n++; if ($f === [null] || trim(implode('', array_map('strval', $f))) === '') continue;
        $r = ['_fila' => $n]; foreach ($cols as $i => $c) $r[$c] = isset($f[$i]) ? trim((string) $f[$i]) : ''; $rs[] = $r;
        if (count($rs) > $max) throw new Fallo(422, "El origen trae más de {$max} filas: partilo en varios archivos"); }
    fclose($h);
    return [$cols, $rs];
}
/** Dirección https de un host permitido que no resuelva a una red interna. */
function validar_url(string $url, array $hosts): string {
    $p = parse_url($url); $host = strtolower((string) ($p['host'] ?? ''));
    if (($p['scheme'] ?? '') !== 'https' || $host === '' || isset($p['user'])) throw new Fallo(422, 'La dirección tiene que empezar con https://');
    $ok = false; foreach ($hosts as $h) { $h = strtolower((string) $h); if ($host === $h || str_ends_with($host, '.' . $h)) $ok = true; }
    if (!$ok) throw new Fallo(422, "El sitio «{$host}» no está en la lista de sitios permitidos del módulo (ajuste «hosts_url»)");
    $ips = gethostbynamel($host) ?: []; if (!$ips) throw new Fallo(422, "No se pudo resolver «{$host}»");
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new Fallo(422, 'La dirección apunta a una red interna');
    return $url;
}
function de_url(string $url, array $cfg): string {
    $max = (int) $cfg['max_bytes'];
    for ($i = 0; $i < 5; $i++) {
        validar_url($url, (array) $cfg['hosts_url']);
        $c = curl_init($url); $buf = '';
        curl_setopt_array($c, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 40, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HEADER => false, CURLOPT_USERAGENT => 'iProy-migracion',
            CURLOPT_WRITEFUNCTION => function ($ch, $d) use (&$buf, $max) { $buf .= $d; return strlen($buf) > $max ? 0 : strlen($d); }]);
        $ok = curl_exec($c); $http = (int) curl_getinfo($c, CURLINFO_HTTP_CODE); $loc = (string) curl_getinfo($c, CURLINFO_REDIRECT_URL); curl_close($c);
        if ($http >= 300 && $http < 400 && $loc !== '') { $url = $loc; continue; }
        if (!$ok && strlen($buf) > $max) throw new Fallo(422, 'El archivo publicado es demasiado grande');
        if ($http !== 200) throw new Fallo(422, "La hoja publicada respondió HTTP {$http}. Revisá que esté publicada en formato CSV");
        if (stripos(ltrim(substr($buf, 0, 200)), '<!doctype html') === 0 || stripos(ltrim(substr($buf, 0, 200)), '<html') === 0) throw new Fallo(422, 'La dirección devuelve una página web, no un CSV: en la hoja usá «Publicar en la web» y elegí valores separados por comas');
        return $buf;
    }
    throw new Fallo(422, 'Demasiadas redirecciones');
}
function de_mysql(array $d, int $max): array {
    $sql = trim((string) ($d['consulta'] ?? '')); $sql = rtrim($sql, "; \n\r\t");
    if (($tabla = trim((string) ($d['tabla'] ?? ''))) !== '' && $sql === '') { if (!preg_match('/^[A-Za-z0-9_$]+(\.[A-Za-z0-9_$]+)?$/', $tabla)) throw new Fallo(422, 'Nombre de tabla inválido'); $sql = 'SELECT * FROM `' . str_replace('.', '`.`', $tabla) . '`'; }
    if (!preg_match('/^\s*select\b/i', $sql)) throw new Fallo(422, 'La consulta tiene que empezar con SELECT (solo lectura)');
    if (str_contains($sql, ';') || preg_match('/\b(into\s+(outfile|dumpfile)|load_file|sleep\s*\(|benchmark\s*\(|for\s+update|lock\s+in)\b/i', $sql)) throw new Fallo(422, 'La consulta tiene algo que no es de solo lectura');
    $host = trim((string) ($d['host'] ?? '')); $base = trim((string) ($d['base'] ?? '')); $usr = (string) ($d['usuario'] ?? '');
    if ($host === '' || $base === '' || $usr === '') throw new Fallo(422, 'Faltan el servidor, la base o el usuario');
    if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $host) || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $base)) throw new Fallo(422, 'Servidor o base con caracteres no permitidos');
    try {
        $p = new \PDO('mysql:host=' . $host . ';port=' . max(1, min(65535, (int) ($d['puerto'] ?? 3306))) . ';dbname=' . $base . ';charset=utf8mb4', $usr, (string) ($d['clave'] ?? ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_TIMEOUT => 8]);
        $p->exec('SET SESSION TRANSACTION READ ONLY'); $p->beginTransaction();
        $st = $p->query('SELECT * FROM (' . $sql . ') origen_migracion LIMIT ' . ($max + 1));
        $rs = []; $n = 0; while ($f = $st->fetch()) { $n++; $rs[] = ['_fila' => $n] + array_map(fn($v) => $v === null ? '' : trim((string) $v), $f); }
        $p->rollBack();
    } catch (\PDOException $e) {
        $m = $e->getMessage(); throw new Fallo(422, 'La base de origen no aceptó el pedido: ' . (str_contains($m, 'Access denied') ? 'usuario o clave incorrectos' : (str_contains($m, 'Unknown database') ? 'no existe esa base' : (str_contains($m, 'refused') || str_contains($m, 'timed out') || str_contains($m, 'getaddrinfo') ? 'no se pudo conectar al servidor' : 'consulta inválida (' . mb_substr(preg_replace('/^SQLSTATE\[[^\]]*\]:?\s*/', '', $m), 0, 140) . ')'))));
    }
    if (count($rs) > $max) throw new Fallo(422, "La consulta trae más de {$max} filas: acotala con WHERE o cargala por tramos");
    $cols = $rs ? array_values(array_filter(array_keys($rs[0]), fn($k) => $k !== '_fila')) : [];
    return [$cols, $rs];
}
function leer_origen(array $o, array $cfg): array {
    $max = (int) $cfg['max_filas'];
    switch ((string) ($o['tipo'] ?? '')) {
        case 'csv': $t = ($o['subida'] ?? '') !== '' ? (string) file_get_contents(ruta_subida((string) $o['subida'], true)) : (string) ($o['texto'] ?? ''); if (trim($t) === '') throw new Fallo(422, 'El archivo está vacío'); if (strlen($t) > (int) $cfg['max_bytes']) throw new Fallo(422, 'El archivo es demasiado grande'); return de_csv($t, $max);
        case 'url': return de_csv(de_url(trim((string) ($o['url'] ?? '')), $cfg), $max);
        case 'mysql': return de_mysql($o, $max);
    }
    throw new Fallo(422, 'Origen: csv | url | mysql');
}
// Un archivo grande no entra en un solo pedido (el núcleo acepta 2 MB): la pantalla lo manda en partes y después lo nombra por su id.
function ruta_subida(string $id, bool $debeExistir = false): string {
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) throw new Fallo(422, 'Identificador de archivo inválido');
    $r = \Nucleo\dir_datos('migracion') . "/sub_{$id}.csv";
    if ($debeExistir && !is_file($r)) throw new Fallo(422, 'El archivo subido ya no está en el nodo: volvé a elegirlo');
    return $r;
}
function subir(array $c, array $cfg): array {
    foreach (glob(\Nucleo\dir_datos('migracion') . '/sub_*.csv') ?: [] as $v) if (filemtime($v) < time() - 86400) @unlink($v);   // lo subido dura un día
    $id = (string) ($c['id'] ?? ''); $t = (string) ($c['texto'] ?? ''); if ($t === '') throw new Fallo(422, 'La parte está vacía');
    if ($id === '') $id = bin2hex(random_bytes(12)); $r = ruta_subida($id, !empty($c['id']));
    $ya = is_file($r) ? (int) filesize($r) : 0; if ($ya + strlen($t) > (int) $cfg['max_bytes']) { @unlink($r); throw new Fallo(422, 'El archivo es demasiado grande'); }
    if (file_put_contents($r, $t, FILE_APPEND | LOCK_EX) === false) throw new Fallo(500, 'No se pudo guardar el archivo en el nodo'); @chmod($r, 0600);
    return ['id' => $id, 'bytes' => $ya + strlen($t)];
}
function detalle_origen(array $o): string {
    return match ((string) ($o['tipo'] ?? '')) { 'csv' => mb_substr((string) ($o['nombre'] ?? 'texto pegado'), 0, 190), 'url' => mb_substr((string) parse_url((string) ($o['url'] ?? ''), PHP_URL_HOST), 0, 190),
        'mysql' => mb_substr(($o['host'] ?? '') . '/' . ($o['base'] ?? '') . (($o['tabla'] ?? '') !== '' ? '.' . $o['tabla'] : ' (consulta)'), 0, 190), default => '' };
}
function proponer_mapa(string $destino, array $cols): array {
    $m = []; $n = []; foreach ($cols as $c) $n[str_replace([' ', '-', '.'], '_', norm($c))] = $c;
    foreach (array_keys(DESTINOS[$destino]['campos']) as $campo) foreach (array_merge([$campo], ALIAS[$campo] ?? []) as $a) if (isset($n[$a]) && !in_array($n[$a], $m, true)) { $m[$campo] = $n[$a]; break; }
    return $m;
}
function mapa_valido(string $destino, mixed $mapa, array $cols): array {
    if (!isset(DESTINOS[$destino])) throw new Fallo(422, 'Destino desconocido'); $o = [];
    foreach (DESTINOS[$destino]['campos'] as $campo => [$et, $req]) {
        $c = (string) (((array) $mapa)[$campo] ?? '');
        if ($c === '') { if ($req) throw new Fallo(422, "Falta elegir la columna de «{$et}»"); continue; }
        if (!in_array($c, $cols, true)) throw new Fallo(422, "La columna «{$c}» no está en el origen");
        $o[$campo] = $c;
    }
    return $o;
}

// ---------------------------------------------------------------- análisis: qué valores trae el origen y con qué se corresponden
function analizar(array $o, string $destino, mixed $mapa, array $cfg): array {
    [$cols, $rs] = leer_origen($o, $cfg);
    if (!isset(DESTINOS[$destino])) throw new Fallo(422, 'Destino desconocido');
    $r = ['columnas' => $cols, 'filas' => count($rs), 'muestra' => array_map(fn($x) => array_map(fn($v) => mb_substr((string) $v, 0, 80), array_diff_key($x, ['_fila' => 1])), array_slice($rs, 0, 5)),
          'mapa_propuesto' => proponer_mapa($destino, $cols), 'campos' => DESTINOS[$destino]['campos']];
    if ($mapa === null || $destino !== 'ordenes') return $r;
    $m = mapa_valido($destino, $mapa, $cols); $eq = indice_equivalencias(); $val = [];
    foreach (['estado' => 'estado', 'tipo_mantenimiento' => 'tipo_mantenimiento', 'prioridad' => 'prioridad', 'categoria' => 'categoria'] as $campo => $lista) {
        if (!isset($m[$campo])) continue; $c = [];
        foreach ($rs as $x) { $v = (string) $x[$m[$campo]]; $k = $v === '' ? '' : $v; $c[$k] ??= ['n' => 0, 'con_fin' => 0, 'con_inicio' => 0]; $c[$k]['n']++;
            if (isset($m['fin']) && $x[$m['fin']] !== '') $c[$k]['con_fin']++; if (isset($m['inicio']) && $x[$m['inicio']] !== '') $c[$k]['con_inicio']++; }
        uasort($c, fn($a, $b) => $b['n'] <=> $a['n']);
        $val[$lista] = array_map(fn($k) => ['valor' => $k, 'filas' => $c[$k]['n'], 'con_fin' => $c[$k]['con_fin'], 'con_inicio' => $c[$k]['con_inicio'],
            'destino' => $k === '' ? '' : ($eq[$lista][norm($k)]['destino'] ?? ($lista === 'prioridad' ? (catalogo_pim()['bandas'][$k] ?? null) : null)), 'nota' => $eq[$lista][norm($k)]['nota'] ?? ''], array_slice(array_keys($c), 0, 120));
    }
    $r['valores'] = $val; $r['mapa'] = $m;
    return $r;
}
function indice_equivalencias(): array { $o = []; foreach (equivalencias() as $lista => $l) foreach ($l as $e) $o[$lista][$e['origen']] = $e; return $o; }

// ---------------------------------------------------------------- carga
function fila_pim(string $destino, array $x, array $m, array $eq, array $cat, array &$sinEq): array {
    $f = ['_fila' => $x['_fila']]; foreach ($m as $campo => $col) $f[$campo] = $x[$col] ?? '';
    if (in_array($destino, ['equipos', 'sistemas'], true) && ($rv = trim((string) ($f['riesgo'] ?? ''))) !== '') {   // 1 a 4 directo, o por la tabla de equivalencias
        $rr = in_array($rv, ['1', '2', '3', '4'], true) ? $rv : ($eq['riesgo'][norm($rv)]['destino'] ?? '');
        if ($rr === '') $sinEq['riesgo'][$rv] = ($sinEq['riesgo'][$rv] ?? 0) + 1; $f['categoria_riesgo'] = $rr; }
    unset($f['riesgo']);
    if ($destino !== 'ordenes') return $f;
    $e = norm((string) ($f['estado'] ?? '')); $conFin = ($f['fin'] ?? '') !== ''; $conIni = ($f['inicio'] ?? '') !== '';
    $d = ($conFin ? ($eq['estado'][$e . '|con_fin']['destino'] ?? null) : null) ?? ($conIni ? ($eq['estado'][$e . '|con_inicio']['destino'] ?? null) : null) ?? ($eq['estado'][$e]['destino'] ?? '');
    if ($d === '') $sinEq['estado'][(string) $f['estado']] = ($sinEq['estado'][(string) $f['estado']] ?? 0) + 1;
    $f['estado_pim'] = $d;
    if (!in_array($d, ['finalizada', 'conformada'], true) && $d !== '' && $conFin && !in_array($d, ['rechazada', 'anulada'], true)) $f['fin'] = '';   // una orden abierta no lleva fecha de fin
    $tm = norm((string) ($f['tipo_mantenimiento'] ?? ''));
    $esA = strtoupper(trim((string) ($f['prioridad'] ?? ''))) === 'A';   // variante «|prioridad_a»: mismo tipo, otra clase PIM cuando la prioridad de origen es la máxima
    if ($tm !== '') { $t = ($esA ? ($eq['tipo_mantenimiento'][$tm . '|prioridad_a']['destino'] ?? null) : null) ?? $eq['tipo_mantenimiento'][$tm]['destino'] ?? (in_array($tm, $cat['tipos'], true) ? $tm : ''); if ($t === '') $sinEq['tipo_mantenimiento'][(string) $f['tipo_mantenimiento']] = ($sinEq['tipo_mantenimiento'][(string) $f['tipo_mantenimiento']] ?? 0) + 1; $f['tipo_mantenimiento'] = $t; }
    $p = trim((string) ($f['prioridad'] ?? ''));
    if ($p !== '') { $pp = $eq['prioridad'][norm($p)]['destino'] ?? ($cat['bandas'][$p] ?? (in_array($p, $cat['prioridades'], true) ? $p : '')); if ($pp === '') $sinEq['prioridad'][$p] = ($sinEq['prioridad'][$p] ?? 0) + 1; $f['prioridad'] = $pp; }
    $c = trim((string) ($f['categoria'] ?? '')); unset($f['categoria']);
    if ($c !== '') { $f['categoria_texto'] = $c; $cc = $eq['categoria'][norm($c)]['destino'] ?? (isset($cat['sistemas'][$c]) ? $c : ''); if ($cc !== '') $f['categoria_codigo'] = $cc; else $sinEq['categoria'][$c] = ($sinEq['categoria'][$c] ?? 0) + 1; }
    return $f;
}
function cargar(array $o, string $destino, mixed $mapa, bool $simular, bool $crearFaltantes, array $u, array $cfg, array $op = []): array {
    @set_time_limit(900);
    [$cols, $rs] = leer_origen($o, $cfg); $m = mapa_valido($destino, $mapa, $cols);
    if ($destino === 'planes' || $destino === 'tareas_pmp') {   // los guarda el módulo Preventivos (tiene que estar instalado)
        \Nucleo\incluir('preventivos');
        $filas = array_map(function ($x) use ($m) { $f = ['_fila' => $x['_fila']]; foreach ($m as $campo => $col) $f[$campo] = $x[$col] ?? ''; return $f; }, $rs);
        $r = $destino === 'planes' ? \Modulos\Preventivos\importar_planes($filas, $simular, ($op['agrupar'] ?? true) !== false, !empty($op['habilitar']))
            : \Modulos\Preventivos\importar_tareas($filas, $simular);
        $res = ['destino' => $destino, 'simulada' => $simular, 'filas' => count($filas), 'cuenta' => $r['cuenta'] + ['cambio' => 0, 'propio' => 0], 'previos' => [], 'errores' => $r['errores'],
                'sin_equivalencia' => $r['sin_equivalencia'], 'mapa' => $m, ($destino === 'planes' ? 'planes' : 'pmp') => $r];
        q('INSERT INTO mig_corrida (creado, creado_por, origen, origen_detalle, destino, simulada, filas, resumen) VALUES (?,?,?,?,?,?,?,?)',
          [date('Y-m-d H:i:s'), (string) ($u['usuario'] ?? ''), (string) $o['tipo'], detalle_origen($o), $destino, (int) $simular, count($filas), json_encode($res, JSON_UNESCAPED_UNICODE)]);
        $res['corrida'] = (int) \Nucleo\pdo()->lastInsertId();
        if (!$simular) asentar('migracion.carga', ['destino' => $destino, 'origen' => (string) $o['tipo'], 'filas' => count($filas), 'cuenta' => $r['cuenta']], (string) $res['corrida']);
        return $res;
    }
    $eq = indice_equivalencias(); $cat = catalogo_pim(); $sinEq = [];
    $filas = array_map(function ($x) use ($destino, $m, $eq, $cat, &$sinEq) { return fila_pim($destino, $x, $m, $eq, $cat, $sinEq); }, $rs);
    $tot = ['nuevo' => 0, 'cambio' => 0, 'igual' => 0, 'propio' => 0, 'error' => 0, 'sin_referencia' => 0]; $err = []; $previos = [];
    $enviar = function (string $dest, array $l, bool $soloNuevos = false) use ($simular, $cfg, $crearFaltantes) { $c = ['nuevo' => 0, 'cambio' => 0, 'igual' => 0, 'propio' => 0, 'error' => 0, 'sin_referencia' => 0]; $e = [];
        foreach (array_chunk($l, max(50, min(1000, (int) $cfg['lote']))) as $parte) { $r = pim_admin(['accion' => 'lote', 'destino' => $dest, 'filas' => $parte, 'simular' => $simular, 'solo_nuevos' => $soloNuevos, 'asumir' => $crearFaltantes]);
            foreach ($c as $k => $_) $c[$k] += (int) ($r['cuenta'][$k] ?? 0); $e = array_merge($e, $r['errores']); }
        return [$c, array_slice($e, 0, 100)]; };
    if ($destino === 'ordenes' && $crearFaltantes) {   // primero lo que las órdenes nombran: servicios y empresas
        $sv = []; $em = []; foreach ($filas as $f) { if (($f['servicio'] ?? '') !== '') $sv[norm($f['servicio'])] = ['nombre' => $f['servicio']]; if (($f['empresa'] ?? '') !== '') $em[trim($f['empresa'])] = ['codigo' => trim($f['empresa']), 'nombre' => trim($f['empresa'])]; }
        if ($sv) { [$c] = $enviar('servicios', array_values($sv), true); $previos['servicios'] = $c; }
        if ($em) { [$c] = $enviar('empresas', array_values($em), true); $previos['empresas'] = $c; }
    }
    [$tot, $err] = $enviar($destino, $filas);
    foreach ($sinEq as $k => $v) { arsort($v); $sinEq[$k] = array_slice($v, 0, 40, true); }
    $res = ['destino' => $destino, 'simulada' => $simular, 'filas' => count($filas), 'cuenta' => $tot, 'previos' => $previos, 'errores' => $err, 'sin_equivalencia' => $sinEq, 'mapa' => $m];
    q('INSERT INTO mig_corrida (creado, creado_por, origen, origen_detalle, destino, simulada, filas, resumen) VALUES (?,?,?,?,?,?,?,?)',
      [date('Y-m-d H:i:s'), (string) ($u['usuario'] ?? ''), (string) $o['tipo'], detalle_origen($o), $destino, (int) $simular, count($filas), json_encode($res, JSON_UNESCAPED_UNICODE)]);
    $res['corrida'] = (int) \Nucleo\pdo()->lastInsertId();
    if (!$simular) asentar('migracion.carga', ['destino' => $destino, 'origen' => (string) $o['tipo'], 'filas' => count($filas), 'cuenta' => $tot], (string) $res['corrida']);
    return $res;
}
function corridas(): array {
    return array_map(fn($r) => ['id' => (int) $r['id'], 'creado' => $r['creado'], 'creado_por' => $r['creado_por'], 'origen' => $r['origen'], 'origen_detalle' => $r['origen_detalle'], 'destino' => $r['destino'],
        'simulada' => (int) $r['simulada'], 'filas' => (int) $r['filas'], 'cuenta' => json_decode((string) $r['resumen'], true)['cuenta'] ?? []], filas('SELECT * FROM mig_corrida ORDER BY id DESC LIMIT 40'));
}
