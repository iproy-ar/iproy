<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Incubadora de software: crea, a partir de la distribución base (../nodo), un sistema de gestión propio,
 * operativo en minutos, estandarizado (PIM / HL7 FHIR R4, ISA-18.2, EN 13306, ISO 14224) y libre (AGPL-3.0-or-later).
 *
 *   php incubar.php nuevo --nombre Colmen [--sigla CLM] [--destino ../../Colmen] [--color "#0b6e4f"] [--plantilla gestion-mantenimiento]
 *                         [--descripcion "…"] [--fuente https://…/colmen]
 *   php incubar.php modulo <id> --nombre "Calibraciones" --campos "equipo:texto!,fecha:fecha!,resultado:opcion(apto|no-apto),patron:texto"
 *                         [--destino ../nodo/modulos] [--ver GM,EJ,CI] [--editar GM]
 *   php incubar.php verificar <carpeta>          licencias, cabeceras SPDX, manifiestos, secretos y datos institucionales
 *   php incubar.php plantillas                    lista las plantillas
 *
 * Reglas que la incubadora garantiza (y `verificar` controla):
 *  - El derivado conserva la licencia AGPL-3.0-or-later y los avisos de copyright del original (AGPL §4-§5) y suma los suyos.
 *  - Los espacios de nombres canónicos de PIM (https://iproy.com.ar/fhir y su alias /pim) NO se renombran: son del estándar, no del producto;
 *    así un nodo Colmen y uno iProy se entienden sin alias.
 *  - El protocolo de colmena («colmena/1») es neutral: los derivados se reconocen entre sí.
 *  - Nunca se copian data/, runtime/, descargas/ ni secretos: el derivado nace vacío y genera sus claves al instalarse.
 */
declare(strict_types=1);

const TEXTO = ['php', 'js', 'mjs', 'json', 'md', 'sql', 'sh', 'ps1', 'bat', 'txt', 'tpl', 'css', 'html', 'yaml', 'yml', 'toml', 'xml', 'svg', 'ini', ''];
const EXCLUIR = ['data', 'runtime', 'descargas', '.git', 'node_modules'];
const PROTEGIDO = [                                  // nunca se renombran (estándar, atribución, protocolo)
    'https://iproy.com.ar/pim', 'https://iproy.com.ar/fhir',
    'Proyecto iProy y colaboradores',
];
const PLANTILLAS = [
    'gestion-mantenimiento' => ['descripcion' => 'Gestión de mantenimiento completa: órdenes, solicitudes, inventario, preventivos, alarmas ISA-18.2, telemetría, MQTT y Modbus.',
                                'modulos' => ['pim', 'telemetria', 'alarmas', 'preventivos', 'mqtt', 'modbus']],
    'mantenimiento-basico'  => ['descripcion' => 'Órdenes, solicitudes, inventario y preventivos (sin integración industrial).',
                                'modulos' => ['pim', 'preventivos']],
    'monitoreo-industrial'  => ['descripcion' => 'Telemetría, alarmas ISA-18.2, MQTT y Modbus sobre el inventario PIM (tipo SCADA liviano).',
                                'modulos' => ['pim', 'telemetria', 'alarmas', 'mqtt', 'modbus']],
    'minima'                => ['descripcion' => 'Solo el núcleo y el Servidor PIM: base para construir módulos propios.',
                                'modulos' => ['pim']],
];

function salir(string $m, int $c = 1): never { fwrite($c ? STDERR : STDOUT, $m . "\n"); exit($c); }
function dice(string $m): void { echo $m, "\n"; }
function args(array $a): array {
    $r = ['_' => []];
    for ($i = 0; $i < count($a); $i++) {
        if (str_starts_with($a[$i], '--')) { $k = substr($a[$i], 2); $r[$k] = (isset($a[$i + 1]) && !str_starts_with($a[$i + 1], '--')) ? $a[++$i] : true; }
        else $r['_'][] = $a[$i];
    }
    return $r;
}
function es_texto(string $f): bool { return in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), TEXTO, true) && !is_link($f); }

/** Reemplazos que conservan mayúsculas: iProy→Colmen, IPROY→COLMEN, iproy→colmen, Iproy→Colmen. */
function mapa(string $de, string $a): array {
    return [$de => $a, strtoupper($de) => strtoupper($a), strtolower($de) => strtolower($a), ucfirst(strtolower($de)) => ucfirst(strtolower($a))];
}
function renombrar_texto(string $s, array $m, array &$cuenta): string {
    $marcas = [];
    foreach (PROTEGIDO as $i => $p) { $t = "\x01P{$i}\x01"; if (str_contains($s, $p)) { $marcas[$t] = $p; $s = str_replace($p, $t, $s); } }
    foreach ($m as $de => $a) $cuenta[$de] = ($cuenta[$de] ?? 0) + substr_count($s, $de);
    $s = strtr($s, $m);
    return strtr($s, $marcas);
}
function copiar(string $orig, string $dest, array $m, array &$cuenta, array &$archivos): void {
    if (!is_dir($dest)) mkdir($dest, 0755, true);
    foreach (scandir($orig) as $n) {
        if ($n === '.' || $n === '..' || in_array($n, EXCLUIR, true)) continue;
        $o = "$orig/$n"; $d = $dest . '/' . strtr($n, $m);
        if (is_link($o)) continue;                                          // enlaces: nunca (pueden apuntar a datos)
        if (is_dir($o)) { copiar($o, $d, $m, $cuenta, $archivos); continue; }
        if (es_texto($o)) file_put_contents($d, renombrar_texto((string) file_get_contents($o), $m, $cuenta));
        else copy($o, $d);
        @chmod($d, fileperms($o) & 0777);
        $archivos[] = $d;
    }
}

/** Logo propio: hexágono (celda de colmena) con degradé del color elegido y la sigla. PNG 192/512 sin dependencias externas (GD). */
function logo(string $sigla, string $color, string $archivo, int $t): void {
    if (!function_exists('imagecreatetruecolor')) { dice("  (sin GD: se conserva el logo de la base en $archivo)"); return; }
    $im = imagecreatetruecolor($t, $t); imagesavealpha($im, true); imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); imagealphablending($im, true);
    [$r, $g, $b] = sscanf(ltrim($color, '#'), '%02x%02x%02x');
    $c = $t / 2; $R = $t * 0.47;
    for ($k = 0; $k < 40; $k++) {                                            // degradé radial por capas
        $f = 1 - $k / 40; $rr = $R * $f; $mix = $k / 40 * 0.55;
        $col = imagecolorallocate($im, (int) ($r + (255 - $r) * $mix), (int) ($g + (255 - $g) * $mix), (int) ($b + (255 - $b) * $mix));
        $pts = []; for ($i = 0; $i < 6; $i++) { $an = deg2rad(60 * $i - 30); $pts[] = (int) round($c + $rr * cos($an)); $pts[] = (int) round($c + $rr * sin($an)); }
        imagefilledpolygon($im, $pts, $col);
    }
    $blanco = imagecolorallocate($im, 255, 255, 255);
    $fuente = null;
    foreach (['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf', 'C:/Windows/Fonts/arialbd.ttf', '/System/Library/Fonts/Supplemental/Arial Bold.ttf'] as $ff) if (is_file($ff)) { $fuente = $ff; break; }
    $txt = mb_strtoupper(mb_substr($sigla, 0, 3));
    if ($fuente && function_exists('imagettftext')) {
        $tam = $t * (strlen($txt) > 2 ? 0.2 : 0.26);
        $bb = imagettfbbox($tam, 0, $fuente, $txt); $w = $bb[2] - $bb[0]; $hh = $bb[1] - $bb[7];
        imagettftext($im, $tam, 0, (int) ($c - $w / 2 - $bb[0]), (int) ($c + $hh / 2), $blanco, $fuente, $txt);
    } else {
        $w = imagefontwidth(5) * strlen($txt); imagestring($im, 5, (int) ($c - $w / 2), (int) ($c - 8), $txt, $blanco);
    }
    imagepng($im, $archivo); imagedestroy($im);
}

// ====================================================================== nuevo producto
function cmd_nuevo(array $o): void {
    $base = realpath(__DIR__ . '/../nodo') ?: salir('No encuentro la distribución base (../nodo)');
    $bp = json_decode((string) file_get_contents("$base/producto.json"), true) ?: [];
    $nombre = trim((string) ($o['nombre'] ?? '')) ?: salir('Falta --nombre (p. ej. --nombre Colmen)');
    if (!preg_match('/^[A-Za-z][A-Za-z0-9]{2,19}$/', $nombre)) salir('--nombre: 3-20 letras o números, empezando con letra (se usa en archivos, bases y variables)');
    if (strcasecmp($nombre, $bp['nombre'] ?? 'iProy') === 0) salir('El nombre nuevo tiene que ser distinto del de la base');
    $sigla = strtoupper((string) ($o['sigla'] ?? substr($nombre, 0, 3)));
    $plant = (string) ($o['plantilla'] ?? 'gestion-mantenimiento');
    $P = PLANTILLAS[$plant] ?? salir("Plantilla desconocida: $plant (php incubar.php plantillas)");
    $color = (string) ($o['color'] ?? '#0d47a1');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) salir('--color: formato #RRGGBB');
    $destRaiz = rtrim((string) ($o['destino'] ?? __DIR__ . "/../../$nombre"), '/');
    $dest = "$destRaiz/$nombre-Portable";
    if (file_exists($dest)) salir("Ya existe $dest: elegí otro --destino o movelo");
    $m = mapa($bp['nombre'] ?? 'iProy', $nombre);
    dice("Incubando «{$nombre}» desde {$bp['nombre']} {$bp['version']} · plantilla $plant");
    $cuenta = []; $arch = [];
    copiar($base, $dest, $m, $cuenta, $arch);
    // módulos de la plantilla: los demás quedan disponibles pero no se cargan (modulos.disponibles/)
    @mkdir("$dest/modulos.disponibles", 0755, true);
    foreach (glob("$dest/modulos/*", GLOB_ONLYDIR) as $md) if (!in_array(basename($md), $P['modulos'], true)) rename($md, "$dest/modulos.disponibles/" . basename($md));
    // identidad del producto
    $prod = ['nombre' => $nombre, 'sigla' => $sigla, 'version' => '0.1.0',
        'descripcion' => (string) ($o['descripcion'] ?? "$nombre: sistema de gestión basado en {$bp['nombre']} (plantilla $plant)."),
        'licencia' => 'AGPL-3.0-or-later', 'fuente' => (string) ($o['fuente'] ?? ''), 'base' => "{$bp['nombre']} {$bp['version']}",
        'plantilla' => $plant, 'incubado' => date('c'), 'colores' => ['primario' => $color, 'secundario' => aclarar($color, 0.25), 'claro' => aclarar($color, 0.88)]];
    file_put_contents("$dest/producto.json", json_encode($prod, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    logo($sigla, $color, "$dest/app/app/logo.png", 192);
    logo($sigla, $color, "$dest/app/app/logo-512.png", 512);
    // aviso de derivación (AGPL §5: indicar que se modificó y cuándo)
    file_put_contents("$dest/AVISO_DERIVADO.md", "# $nombre\n\n$nombre es una obra derivada de **{$bp['nombre']} {$bp['version']}**, creada con la incubadora el " . date('d-m-Y') .
        ".\n\n- Licencia: GNU AGPL-3.0-or-later (la misma del original). Quien use $nombre por red tiene derecho a recibir su código fuente completo, con las modificaciones.\n" .
        "- Se conservan los avisos de copyright del original («Proyecto iProy y colaboradores»); los cambios propios se agregan con una línea `SPDX-FileCopyrightText` nueva.\n" .
        "- Fuente de $nombre: " . ($prod['fuente'] ?: '**(completar en producto.json → «fuente» antes de ponerlo en servicio)**') . "\n" .
        "- Protocolos y estándares sin cambios: PIM v0.9 (HL7 FHIR R4) con espacio de nombres canónico https://iproy.com.ar/fhir, colmena/1.\n");
    $rep = ['producto' => $prod, 'archivos' => count($arch), 'reemplazos' => $cuenta, 'modulos' => $P['modulos'], 'protegidos' => PROTEGIDO];
    file_put_contents("$destRaiz/INCUBACION.json", json_encode($rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $lanz = "$nombre-Control";
    file_put_contents("$destRaiz/LEEME_$nombre.md", "# $nombre · instalación\n\nGenerado por la incubadora de {$bp['nombre']} el " . date('d-m-Y H:i') . " · plantilla **$plant** (" . implode(', ', $P['modulos']) . ").\n\n" .
        "1. Copiá en `$nombre-Portable/runtime/` el runtime (FrankenPHP + MariaDB) de la base, o bajalo con `tools/bajar-runtime.sh`.\n" .
        "2. `bash $lanz.sh init --nombre \"PC $nombre\" --modo lan` (Windows: `$lanz.bat init -Nombre \"PC $nombre\" -Modo lan`).\n" .
        "3. `bash $lanz.sh start` y creá el administrador: `bash $lanz.sh usuario alta admin AD`.\n4. Abrí http://localhost:8080/ (aplicación) · /pim/ (consola PIM) · /api (núcleo).\n" .
        "5. Colmena: `bash $lanz.sh colmena vincular https://<IP-del-otro-nodo>:8443` y, en el otro nodo, `colmena aceptar <ID>` después de comparar la huella.\n\n" .
        "Reemplazos: " . json_encode($cuenta, JSON_UNESCAPED_UNICODE) . " en " . count($arch) . " archivos.\n");
    dice("✔ $dest");
    dice('  ' . count($arch) . ' archivos · reemplazos ' . json_encode($cuenta));
    dice("  módulos: " . implode(', ', $P['modulos']));
    $v = verificar($dest, true);
    dice($v['ok'] ? '✔ verificación: sin problemas' : '⚠ verificación: ' . count($v['problemas']) . ' observaciones (ver INCUBACION.json)');
    if (!$v['ok']) { $rep['verificacion'] = $v; file_put_contents("$destRaiz/INCUBACION.json", json_encode($rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"); }
    dice("Siguiente: $destRaiz/LEEME_$nombre.md");
}
function aclarar(string $hex, float $f): string {
    [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');
    return sprintf('#%02x%02x%02x', (int) ($r + (255 - $r) * $f), (int) ($g + (255 - $g) * $f), (int) ($b + (255 - $b) * $f));
}

// ====================================================================== generador de módulos
const TIPOS_CAMPO = ['texto' => 'VARCHAR(255)', 'largo' => 'TEXT', 'numero' => 'DOUBLE', 'entero' => 'INT', 'fecha' => 'DATE', 'fechahora' => 'DATETIME', 'si-no' => 'TINYINT', 'opcion' => 'VARCHAR(40)', 'activo' => 'VARCHAR(64)'];
function cmd_modulo(array $o): void {
    $id = (string) ($o['_'][1] ?? '') ?: salir('uso: incubar.php modulo <id> --nombre "…" --campos "nombre:texto!,fecha:fecha,…"');
    if (!preg_match('/^[a-z][a-z0-9_]{1,38}$/', $id)) salir('id: minúsculas, números y _');
    $dest = rtrim((string) ($o['destino'] ?? __DIR__ . '/../nodo/modulos'), '/') . "/$id";
    if (file_exists($dest)) salir("Ya existe $dest");
    $nombre = (string) ($o['nombre'] ?? ucfirst($id));
    $campos = [];
    foreach (array_filter(array_map('trim', explode(',', (string) ($o['campos'] ?? '')))) as $c) {
        if (!preg_match('/^([a-z][a-z0-9_]{0,39}):([a-z\-]+)(?:\(([^)]*)\))?(!)?$/', $c, $x)) salir("Campo inválido: $c (nombre:tipo[(op1|op2)][!])");
        if (!isset(TIPOS_CAMPO[$x[2]])) salir("Tipo desconocido «{$x[2]}»: " . implode(', ', array_keys(TIPOS_CAMPO)));
        $campos[] = ['n' => $x[1], 'tipo' => $x[2], 'op' => ($x[3] ?? '') !== '' ? explode('|', $x[3]) : [], 'req' => ($x[4] ?? '') === '!'];
    }
    if (!$campos) salir('Indicá al menos un campo con --campos');
    $ver = array_filter(explode(',', (string) ($o['ver'] ?? 'GM,EJ,CI'))); $edi = array_filter(explode(',', (string) ($o['editar'] ?? 'GM')));
    $t = "mod_$id";
    @mkdir("$dest/sql", 0755, true);
    $cab = "// SPDX-License-Identifier: AGPL-3.0-or-later\n// SPDX-FileCopyrightText: " . date('Y') . " Proyecto iProy y colaboradores\n";
    file_put_contents("$dest/modulo.json", json_encode(['id' => $id, 'nombre' => $nombre, 'version' => '0.1.0', 'abi' => 1, 'nucleo' => '>=1.0 <2.0', 'estabilidad' => 'pruebas', 'licencia' => 'AGPL-3.0-or-later',
        'descripcion' => "Registro de $nombre generado por la incubadora (" . date('d-m-Y') . '). Altas, cambios, baja lógica, historial en el diario firmado y eventos en el bus.',
        'requiere' => [], 'lib' => 'lib.php', 'rutas' => 'rutas.php', 'capacidades' => ["$id.ver" => array_values($ver), "$id.editar" => array_values($edi)],
        'ui' => ['script' => 'ui.js', 'orden' => 60], 'config_defecto' => new stdClass()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $cols = implode(",\n", array_map(fn($c) => "  `{$c['n']}` " . TIPOS_CAMPO[$c['tipo']] . ($c['req'] ? ' NOT NULL' : ' NULL'), $campos));
    file_put_contents("$dest/sql/001_$id.sql", "-- SPDX-License-Identifier: AGPL-3.0-or-later\nCREATE TABLE IF NOT EXISTS $t (\n  id INT AUTO_INCREMENT PRIMARY KEY,\n$cols,\n  activo TINYINT NOT NULL DEFAULT 1,\n" .
        "  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  creado_por VARCHAR(60) NULL,\n  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n");
    $ns = 'Modulos\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', $id)));
    $def = var_export(array_map(fn($c) => ['tipo' => $c['tipo'], 'op' => $c['op'], 'req' => $c['req']], array_column($campos, null, 'n')), true);
    file_put_contents("$dest/lib.php", "<?php\n$cab" . "declare(strict_types=1);\n\nnamespace $ns;\n\nuse Nucleo\\Fallo;\n\nconst TABLA = '$t';\nconst CAMPOS = $def;\n\n" . <<<'PHP'
/** Valida y normaliza un registro según CAMPOS (generado: editalo con confianza, es tu módulo). */
function validar(array $c, bool $nuevo): array {
    $f = [];
    foreach (CAMPOS as $n => $d) {
        if (!array_key_exists($n, $c)) { if ($nuevo && $d['req']) throw new Fallo(422, "Falta $n"); continue; }
        $v = $c[$n];
        if ($v === '' || $v === null) { if ($d['req']) throw new Fallo(422, "$n es obligatorio"); $f[$n] = null; continue; }
        $f[$n] = match ($d['tipo']) {
            'numero' => is_numeric($v) ? (float) $v : throw new Fallo(422, "$n debe ser numérico"),
            'entero' => is_numeric($v) ? (int) $v : throw new Fallo(422, "$n debe ser entero"),
            'fecha' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : throw new Fallo(422, "$n: AAAA-MM-DD"),
            'fechahora' => strtotime((string) $v) ? date('Y-m-d H:i:s', strtotime((string) $v)) : throw new Fallo(422, "$n: fecha y hora inválida"),
            'si-no' => $v ? 1 : 0,
            'opcion' => in_array($v, $d['op'], true) ? $v : throw new Fallo(422, "$n: " . implode(' | ', $d['op'])),
            default => mb_substr((string) $v, 0, $d['tipo'] === 'largo' ? 20000 : 255),
        };
    }
    return $f;
}
PHP);
    file_put_contents("$dest/rutas.php", "<?php\n$cab" . "declare(strict_types=1);\n\nuse Nucleo\\Fallo;\nuse function $ns\\validar;\nuse const $ns\\TABLA;\nuse function Nucleo\\{filas, fila, q, pdo, asentar, publicar};\n\n\$id_mod = '$id';\n" . <<<'PHP'
return [
    ['GET', '/registros', "$id_mod.ver", fn(array $c) => ['registros' => filas('SELECT * FROM ' . TABLA . ' WHERE activo = 1 ORDER BY id DESC LIMIT 500')]],
    ['GET', '/registros/{id}', "$id_mod.ver", fn(array $c) => ['registro' => fila('SELECT * FROM ' . TABLA . ' WHERE id = ?', [(int) $c['params']['id']]) ?? throw new Fallo(404, 'No existe')]],
    ['POST', '/registros', "$id_mod.editar", function (array $c) use ($id_mod) {
        $f = validar($c['cuerpo'], true) + ['creado_por' => $c['usuario']['usuario'] ?? null];
        q('INSERT INTO ' . TABLA . ' (`' . implode('`,`', array_keys($f)) . '`) VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')', array_values($f));
        $id = (int) pdo()->lastInsertId();
        asentar("$id_mod.alta", $f, (string) $id); publicar("$id_mod/creado", ['id' => $id] + $f);
        return ['_http' => 201, 'registro' => fila('SELECT * FROM ' . TABLA . ' WHERE id = ?', [$id])];
    }],
    ['PUT', '/registros/{id}', "$id_mod.editar", function (array $c) use ($id_mod) {
        $id = (int) $c['params']['id']; fila('SELECT 1 FROM ' . TABLA . ' WHERE id = ?', [$id]) ?? throw new Fallo(404, 'No existe');
        $f = validar($c['cuerpo'], false);
        if ($f) q('UPDATE ' . TABLA . ' SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f))) . ' WHERE id = ?', [...array_values($f), $id]);
        asentar("$id_mod.cambio", $f, (string) $id); publicar("$id_mod/modificado", ['id' => $id] + $f);
        return ['registro' => fila('SELECT * FROM ' . TABLA . ' WHERE id = ?', [$id])];
    }],
    ['DELETE', '/registros/{id}', "$id_mod.editar", function (array $c) use ($id_mod) {        // baja lógica: nada se borra
        q('UPDATE ' . TABLA . ' SET activo = 0 WHERE id = ?', [(int) $c['params']['id']]);
        asentar("$id_mod.baja", [], (string) $c['params']['id']); publicar("$id_mod/baja", ['id' => (int) $c['params']['id']]);
        return ['baja' => true];
    }],
];
PHP);
    $cj = json_encode(array_map(fn($c) => ['n' => $c['n'], 't' => ucfirst(str_replace('_', ' ', $c['n'])), 'tipo' => match ($c['tipo']) { 'numero', 'entero' => 'number', 'fecha' => 'date', 'fechahora' => 'datetime-local', 'largo' => 'textarea', 'si-no' => 'check', 'opcion' => 'select', default => 'text' },
        'op' => $c['op'] ? array_map(fn($x) => [$x, $x], $c['op']) : null, 'req' => $c['req']], $campos), JSON_UNESCAPED_UNICODE);
    $nj = json_encode($nombre, JSON_UNESCAPED_UNICODE);
    file_put_contents("$dest/ui.js", str_replace(['__ID__', '__NOMBRE__', '__CAMPOS__'], [$id, $nj, $cj], "$cab" . <<<'JS'
// Pestaña generada por la incubadora: tabla + alta + edición. Usa la API del módulo (/api/m/__ID__/registros).
(() => {
  const { h, fecha, aviso, tabla, panel, form, api, puede } = Nucleo;
  const CAMPOS = __CAMPOS__;
  Nucleo.pestana({ id: '__ID__', titulo: __NOMBRE__, orden: 60, cap: '__ID__.ver', render: async (el) => {
    const l = (await api('GET', 'm/__ID__/registros')).registros;
    el.innerHTML = `<div class="tarjeta"><h2>${h(__NOMBRE__)}</h2>${tabla([{ t: '#', v: (r) => r.id }, ...CAMPOS.map((c) => ({ t: c.t, v: (r) => r[c.n] ?? '' })), { t: 'Alta', v: (r) => fecha(r.creado) }], l, editar)}
      ${puede('__ID__.editar') ? `<h2 style="margin-top:16px">Nuevo</h2>${form(CAMPOS, 'Guardar', async (d, m) => { await api('POST', 'm/__ID__/registros', d); aviso(m, 'ok', 'Guardado.'); setTimeout(() => Nucleo.ir('__ID__'), 600); })}` : ''}</div>`;
  } });
  function editar(r) {
    if (!puede('__ID__.editar')) return;
    const p = panel('Registro ' + r.id, form(CAMPOS.map((c) => ({ ...c, v: r[c.n] })), 'Guardar cambios', async (d, m) => { await api('PUT', 'm/__ID__/registros/' + r.id, d); aviso(m, 'ok', 'Cambios guardados.'); setTimeout(() => { Nucleo.cerrar(); Nucleo.ir('__ID__'); }, 600); })
      + '<div class="acciones"><button class="b peligro" id="gBaja">Dar de baja</button></div>');
    p.querySelector('#gBaja').onclick = async () => { await api('DELETE', 'm/__ID__/registros/' + r.id); Nucleo.cerrar(); Nucleo.ir('__ID__'); };
  }
})();
JS));
    file_put_contents("$dest/LEEME.md", "# Módulo $id · $nombre\n\nGenerado por la incubadora el " . date('d-m-Y') . ".\n\n- API: `GET|POST /api/m/$id/registros` · `GET|PUT|DELETE /api/m/$id/registros/{id}` (DELETE = baja lógica).\n" .
        "- Capacidades: `$id.ver` (" . implode(', ', $ver) . "), `$id.editar` (" . implode(', ', $edi) . ").\n- Bus: `$id/creado`, `$id/modificado`, `$id/baja`. Diario: `$id.alta|cambio|baja`.\n" .
        "- Campos: " . implode(', ', array_map(fn($c) => $c['n'] . ' (' . $c['tipo'] . ($c['op'] ? ': ' . implode('|', $c['op']) : '') . ($c['req'] ? ', obligatorio' : '') . ')', $campos)) . ".\n\n" .
        "Para activarlo: reiniciá el nodo (o `…-Control.sh nucleo arranque`): el núcleo aplica `sql/001_$id.sql` y la pestaña aparece para los roles con `$id.ver`.\n");
    dice("✔ módulo $id en $dest (" . count($campos) . ' campos). Activalo con «nucleo arranque».');
}

// ====================================================================== verificación
function verificar(string $dir, bool $silencioso = false): array {
    $pr = [];
    $dir = rtrim($dir, '/');
    if (is_dir("$dir/data")) $pr[] = 'contiene data/ (datos y claves de un nodo): no se distribuye';
    $libres = ['AGPL-3.0-or-later', 'AGPL-3.0-only', 'GPL-3.0-or-later', 'GPL-2.0-or-later', 'LGPL-3.0-or-later', 'LGPL-2.1-or-later', 'MPL-2.0', 'EUPL-1.2', 'Apache-2.0', 'MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'ISC', '0BSD', 'CC0-1.0', 'CC-BY-SA-4.0', 'CC-BY-4.0'];
    foreach (glob("$dir/modulos/*/modulo.json") ?: [] as $f) {
        $j = json_decode((string) file_get_contents($f), true);
        $id = basename(dirname($f));
        if (!is_array($j)) { $pr[] = "$id: modulo.json inválido"; continue; }
        foreach (['id', 'nombre', 'version', 'abi', 'licencia'] as $k) if (!isset($j[$k])) $pr[] = "$id: falta «{$k}» en el manifiesto";
        if (isset($j['licencia']) && !in_array($j['licencia'], $libres, true)) $pr[] = "$id: licencia no libre ({$j['licencia']}) → el nodo queda «contaminado»";
    }
    $patrones = ['/-----BEGIN [A-Z ]*PRIVATE KEY-----/' => 'clave privada', '/\b(?:sk|pk)_(?:live|test)_[A-Za-z0-9]{16,}/' => 'token de servicio',
                 '/(?i)(?:password|contrase(?:ñ|n)a|db_pass|secret)\s*[=:]\s*[\'"][^\'"$\s]{8,}[\'"]/' => 'contraseña escrita en el código',
                 '/(?i)hospital\s+dr\.|marcial\s+v\.\s+quiroga/' => 'nombre de una institución (configuración institucional)'];
    $sinSpdx = 0; $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (preg_match('#/(data|runtime|descargas|\.git|LICENCIAS|LICENSES)/#', $p) || !es_texto($p) || $f->getSize() > 2_000_000) continue;
        $s = (string) file_get_contents($p); $n++;
        foreach ($patrones as $rx => $que) if (preg_match($rx, $s, $mm)) $pr[] = substr($p, strlen($dir) + 1) . ": posible $que (" . mb_substr($mm[0], 0, 40) . ')';
        if (preg_match('/\.(php|js|mjs)$/', $p) && preg_match('#/(app/nucleo|app/app|modulos)/#', $p) && !str_contains(substr($s, 0, 400), 'SPDX-License-Identifier')) $sinSpdx++;
    }
    if ($sinSpdx) $pr[] = "$sinSpdx archivos del núcleo o de módulos sin cabecera SPDX-License-Identifier";
    $r = ['ok' => !$pr, 'archivos_revisados' => $n, 'problemas' => $pr];
    if (!$silencioso) { dice("Revisados $n archivos de texto."); foreach ($pr as $x) dice("  ⚠ $x"); dice($pr ? count($pr) . ' observaciones.' : '✔ Sin problemas: licencias libres, sin secretos ni datos institucionales.'); }
    return $r;
}

// ====================================================================== entrada
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'frankenphp-cli' && !str_contains(PHP_SAPI, 'cli')) { http_response_code(404); exit; }
$o = args(array_slice($argv, 1));
match ($o['_'][0] ?? '') {
    'nuevo' => cmd_nuevo($o),
    'modulo' => cmd_modulo($o),
    'verificar' => exit(verificar((string) ($o['_'][1] ?? salir('uso: incubar.php verificar <carpeta>')))['ok'] ? 0 : 2),
    'plantillas' => array_map(fn($k, $v) => dice(str_pad($k, 24) . $v['descripcion'] . ' [' . implode(', ', $v['modulos']) . ']'), array_keys(PLANTILLAS), PLANTILLAS),
    default => salir("Incubadora · uso:\n  php incubar.php nuevo --nombre <Nombre> [--sigla ABC] [--destino carpeta] [--color #RRGGBB] [--plantilla " . implode('|', array_keys(PLANTILLAS)) . "]\n  php incubar.php modulo <id> --nombre \"…\" --campos \"campo:tipo[!],…\"\n  php incubar.php verificar <carpeta>\n  php incubar.php plantillas", 0),
};
