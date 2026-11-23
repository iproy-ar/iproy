<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Planillas para importar y exportar (1.7.0): .xlsx (Excel, LibreOffice, Google Sheets), .csv y hojas de Google por enlace.
 * Sin bibliotecas externas: .xlsx con ZipArchive (o un lector de ZIP propio si falta la extensión) y XML.
 *
 *  planilla_armar([['nombre' => 'Activos', 'filas' => [[…], …], 'anchos' => [14, 30, …], 'notas' => […]], …]) → binario .xlsx
 *  planilla_leer($binario, $nombre_archivo) → ['Hoja' => [[celda, …], …], …]   (.xlsx o .csv/.txt/.tsv)
 *  csv_armar($filas) → texto CSV (UTF-8 con BOM, separador «;», como lo abre Excel en castellano)
 *  planilla_de_enlace($url) → binario .xlsx de una hoja de Google compartida «cualquier persona con el enlace» (u otro sitio permitido)
 */
declare(strict_types=1);

namespace Nucleo;

const PLANILLA_MAX_BYTES = 20000000;
const PLANILLA_HOSTS = ['docs.google.com', 'googleusercontent.com', 'drive.google.com', 'onedrive.live.com', 'sharepoint.com', 'dropbox.com', 'dropboxusercontent.com'];

function xml_txt(mixed $v): string { return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function col_letra(int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; }
function col_indice(string $l): int { $n = 0; foreach (str_split(strtoupper($l)) as $c) $n = $n * 26 + (ord($c) - 64); return $n - 1; }

// ---------------------------------------------------------------- escribir
/** Arma un .xlsx con una o varias hojas. La primera fila de cada hoja es el encabezado (negrita, fondo celeste, fija y con filtro).
 *  Una celda puede ser texto, número o ['v' => valor, 'estilo' => 'ejemplo'|'nota'|'titulo']. */
function planilla_armar(array $hojas): string {
    if (!class_exists(\ZipArchive::class)) throw new Fallo(501, 'Falta la extensión zip de PHP para armar la planilla');
    $estilos = ['' => 0, 'encabezado' => 1, 'ejemplo' => 2, 'nota' => 3, 'titulo' => 4];
    $sheets = []; $rels = []; $tipos = []; $nombres = [];
    foreach (array_values($hojas) as $k => $h) {
        $n = $k + 1; $nom = mb_substr(preg_replace('#[\[\]\*\?/\\\\:]#', ' ', (string) $h['nombre']), 0, 31);
        while (in_array($nom, $nombres, true)) $nom = mb_substr($nom, 0, 28) . ' ' . $n;
        $nombres[] = $nom;
        $filas = array_values((array) $h['filas']); $enc = $h['encabezado'] ?? true;
        $rows = ''; $maxc = 0;
        foreach ($filas as $r => $f) {
            $f = array_values((array) $f); $maxc = max($maxc, count($f));
            $rows .= '<row r="' . ($r + 1) . '">';
            foreach ($f as $c => $v) {
                $est = 0;
                if (is_array($v)) { $est = $estilos[$v['estilo'] ?? ''] ?? 0; $v = $v['v'] ?? ''; }
                if ($r === 0 && $enc) $est = 1;
                if ($v === null || $v === '') { if ($est) $rows .= '<c r="' . col_letra($c) . ($r + 1) . '" s="' . $est . '"/>'; continue; }
                $ref = col_letra($c) . ($r + 1); $s = $est ? " s=\"$est\"" : '';
                $rows .= is_int($v) || is_float($v) ? "<c r=\"$ref\"$s><v>$v</v></c>"
                    : "<c r=\"$ref\" t=\"inlineStr\"$s><is><t xml:space=\"preserve\">" . xml_txt($v) . '</t></is></c>';
            }
            $rows .= '</row>';
        }
        $cols = '';
        foreach (array_values((array) ($h['anchos'] ?? [])) as $c => $w) $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . max(4, min(80, (float) $w)) . '" customWidth="1"/>';
        $vista = $enc && count($filas) > 1 ? '<sheetViews><sheetView workbookViewId="0"' . ($k === 0 ? ' tabSelected="1"' : '') . '><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' : '';
        $filtro = $enc && count($filas) > 1 && $maxc > 0 ? '<autoFilter ref="A1:' . col_letra($maxc - 1) . count($filas) . '"/>' : '';
        $sheets[$n] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $vista . ($cols !== '' ? "<cols>$cols</cols>" : '') . '<sheetData>' . $rows . '</sheetData>' . $filtro . '</worksheet>';
        $rels[] = "<Relationship Id=\"rId$n\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$n.xml\"/>";
        $tipos[] = "<Override PartName=\"/xl/worksheets/sheet$n.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
    }
    $m = count($sheets) + 1;
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx'); $z = new \ZipArchive();
    if ($z->open($tmp, \ZipArchive::OVERWRITE) !== true) throw new Fallo(500, 'No se pudo armar la planilla');
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . implode('', $tipos) . '</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $hs = ''; foreach ($nombres as $k => $nom) $hs .= '<sheet name="' . xml_txt($nom) . '" sheetId="' . ($k + 1) . '" r:id="rId' . ($k + 1) . '"/>';
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $hs . '</sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . implode('', $rels) . "<Relationship Id=\"rId$m\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/></Relationships>");
    // 0 normal · 1 encabezado (negrita, celeste) · 2 ejemplo (cursiva gris) · 3 nota (gris, ajusta texto) · 4 título (negrita grande)
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="5"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF0D47A1"/><name val="Calibri"/></font><font><i/><sz val="11"/><color rgb="FF5F6F84"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF5F6F84"/><name val="Calibri"/></font><font><b/><sz val="13"/><color rgb="FF0D47A1"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE3F2FD"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FF90CAF9"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf><xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    foreach ($sheets as $n => $x) $z->addFromString("xl/worksheets/sheet$n.xml", $x);
    $z->close(); $b = (string) file_get_contents($tmp); @unlink($tmp);
    return $b;
}
/** CSV para Excel en castellano: UTF-8 con BOM, separador «;», comillas solo cuando hacen falta. */
function csv_armar(array $filas): string {
    $o = "\xEF\xBB\xBF";
    foreach ($filas as $f) {
        $o .= implode(';', array_map(function ($v) {
            if (is_array($v)) $v = $v['v'] ?? '';
            $v = (string) ($v ?? '');
            return preg_match('/[;"\r\n]/', $v) || $v !== trim($v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
        }, array_values((array) $f))) . "\r\n";
    }
    return $o;
}

// ---------------------------------------------------------------- leer
/** Lee un archivo de planilla. Devuelve las hojas con sus filas (listas de celdas como texto). */
function planilla_leer(string $bin, string $nombre = ''): array {
    if (strlen($bin) > PLANILLA_MAX_BYTES) throw new Fallo(413, 'La planilla es demasiado grande (más de 20 MB): partila en varias');
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    if (str_starts_with($bin, "PK\x03\x04")) return xlsx_leer($bin);
    if (in_array($ext, ['xls', 'ods'], true)) throw new Fallo(415, "El formato .$ext no se lee directamente: guardala como .xlsx o .csv (Archivo › Guardar como)");
    if (str_starts_with(ltrim(substr($bin, 0, 300)), '<')) throw new Fallo(415, 'Eso es una página web, no una planilla');
    return [pathinfo($nombre, PATHINFO_FILENAME) ?: 'Hoja1' => csv_leer($bin)];
}
function csv_leer(string $t): array {
    if (str_starts_with($t, "\xEF\xBB\xBF")) $t = substr($t, 3);
    if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1252');
    $l1 = ''; foreach (preg_split('/\r\n|\n|\r/', $t) as $l) if (trim($l) !== '') { $l1 = $l; break; }
    $sep = ','; $mx = 0;
    foreach ([';', ',', "\t", '|'] as $s) { $n = count(str_getcsv($l1, $s, '"', '')); if ($n > $mx) { $mx = $n; $sep = $s; } }
    $h = fopen('php://temp', 'w+'); fwrite($h, $t); rewind($h); $filas = [];
    while (($f = fgetcsv($h, null, $sep, '"', '')) !== false) $filas[] = $f === [null] ? [] : array_map(fn($x) => (string) $x, $f);
    fclose($h);
    return $filas;
}
/** Archivos de un .xlsx (ZipArchive, o lector propio de ZIP si el PHP del equipo no trae la extensión). */
function zip_archivos(string $bin, array $quiero): array {
    $o = [];
    if (class_exists(\ZipArchive::class)) {
        $tmp = tempnam(sys_get_temp_dir(), 'pl'); file_put_contents($tmp, $bin); $z = new \ZipArchive();
        if ($z->open($tmp) !== true) { @unlink($tmp); throw new Fallo(415, 'El archivo no es un .xlsx válido'); }
        for ($i = 0; $i < $z->numFiles; $i++) { $n = (string) $z->getNameIndex($i); foreach ($quiero as $q) if (preg_match($q, $n)) { $o[$n] = (string) $z->getFromIndex($i); break; } }
        $z->close(); @unlink($tmp);
        return $o;
    }
    $fin = strrpos($bin, "PK\x05\x06"); if ($fin === false) throw new Fallo(415, 'El archivo no es un .xlsx válido');
    $d = unpack('vn/Vtam/Vini', substr($bin, $fin + 10, 10)); $p = $d['ini'];
    for ($k = 0; $k < $d['n']; $k++) {
        $c = unpack('Vfirma/vv1/vv2/vflag/vmet/vt/vf/Vcrc/Vcomp/Vtam/vln/vle/vlc/vdisk/vint/Vext/Vloc', substr($bin, $p, 46));
        $n = substr($bin, $p + 46, $c['ln']); $p += 46 + $c['ln'] + $c['le'] + $c['lc'];
        $ok = false; foreach ($quiero as $q) if (preg_match($q, $n)) $ok = true; if (!$ok) continue;
        $l = unpack('vln/vle', substr($bin, $c['loc'] + 26, 4)); $dat = substr($bin, $c['loc'] + 30 + $l['ln'] + $l['le'], $c['comp']);
        $o[$n] = $c['met'] === 8 ? (string) @gzinflate($dat) : $dat;
    }
    return $o;
}
function xlsx_leer(string $bin): array {
    $f = zip_archivos($bin, ['#^xl/workbook\.xml$#', '#^xl/_rels/workbook\.xml\.rels$#', '#^xl/sharedStrings\.xml$#', '#^xl/styles\.xml$#', '#^xl/worksheets/[^/]+\.xml$#']);
    if (!isset($f['xl/workbook.xml'])) throw new Fallo(415, 'El archivo no es un .xlsx válido (le falta el libro)');
    $sx = fn(string $x) => @simplexml_load_string($x, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT) ?: throw new Fallo(415, 'El .xlsx tiene un XML dañado');
    $ss = [];
    if (isset($f['xl/sharedStrings.xml'])) foreach ($sx($f['xl/sharedStrings.xml'])->si as $si) {
        if (isset($si->t)) { $ss[] = (string) $si->t; continue; }
        $t = ''; foreach ($si->r as $r) $t .= (string) $r->t; $ss[] = $t;
    }
    // estilos con formato de fecha: un número en esa celda es un día de Excel
    $fechas = [];
    if (isset($f['xl/styles.xml'])) {
        $st = $sx($f['xl/styles.xml']); $propios = [];
        foreach ($st->numFmts->numFmt ?? [] as $nf) $propios[(int) $nf['numFmtId']] = (string) $nf['formatCode'];
        $i = 0; foreach ($st->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            $fechas[$i++] = ($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || (isset($propios[$id]) && preg_match('/[dy]/i', preg_replace('/"[^"]*"|\[[^\]]*\]/', '', $propios[$id])));
        }
    }
    $rel = [];
    if (isset($f['xl/_rels/workbook.xml.rels'])) foreach ($sx($f['xl/_rels/workbook.xml.rels'])->Relationship as $r) $rel[(string) $r['Id']] = 'xl/' . ltrim(preg_replace('#^/?xl/#', '', (string) $r['Target']), '/');
    $wb = $sx($f['xl/workbook.xml']); $out = [];
    foreach ($wb->sheets->sheet as $h) {
        $rid = (string) $h->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $ruta = $rel[$rid] ?? null; if (!$ruta || !isset($f[$ruta])) continue;
        $filas = [];
        foreach ($sx($f[$ruta])->sheetData->row as $row) {
            $r = (int) $row['r'] - 1; if ($r < 0) $r = count($filas);
            $fila = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r']; $col = preg_match('/^([A-Z]+)/', $ref, $m) ? col_indice($m[1]) : count($fila);
                $t = (string) $c['t']; $v = isset($c->v) ? (string) $c->v : '';
                $val = match ($t) {
                    's' => $ss[(int) $v] ?? '', 'inlineStr' => (function () use ($c) { if (isset($c->is->t)) return (string) $c->is->t; $x = ''; foreach ($c->is->r as $r) $x .= (string) $r->t; return $x; })(),
                    'b' => $v === '1' ? 'VERDADERO' : 'FALSO', 'str', 'e' => $v,
                    default => ($v !== '' && is_numeric($v) && !empty($fechas[(int) $c['s']])) ? excel_fecha((float) $v) : $v };
                $fila[$col] = $val;
            }
            if ($fila) { $max = max(array_keys($fila)); $l = array_fill(0, $max + 1, ''); foreach ($fila as $k => $x) $l[$k] = $x; $filas[$r] = $l; }
        }
        ksort($filas); $out[(string) $h['name']] = array_values($filas);
    }
    if (!$out) throw new Fallo(415, 'El .xlsx no tiene hojas con datos');
    return $out;
}
/** Día de Excel → AAAA-MM-DD (o AAAA-MM-DD HH:MM si tiene hora). */
function excel_fecha(float $n): string {
    $s = (int) round(($n - 25569) * 86400);
    return fmod($n, 1.0) > 0.00001 ? gmdate('Y-m-d H:i', $s) : gmdate('Y-m-d', $s);
}

// ---------------------------------------------------------------- hoja de Google (u otro sitio) por enlace
/** Baja una planilla compartida por enlace. Para Google Sheets pide el libro entero como .xlsx (todas las pestañas). */
function planilla_de_enlace(string $url): array {
    $url = trim($url);
    if (preg_match('#docs\.google\.com/spreadsheets/d/(e/)?([A-Za-z0-9_-]{20,})#', $url, $m)) {
        $url = $m[1] ? "https://docs.google.com/spreadsheets/d/e/{$m[2]}/pub?output=xlsx" : "https://docs.google.com/spreadsheets/d/{$m[2]}/export?format=xlsx";
        $nombre = 'hoja-de-google.xlsx';
    } else $nombre = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'planilla';
    for ($i = 0; $i < 6; $i++) {
        $p = parse_url($url); $host = strtolower((string) ($p['host'] ?? ''));
        if (($p['scheme'] ?? '') !== 'https' || $host === '') throw new Fallo(422, 'El enlace tiene que empezar con https://');
        $ok = false; foreach (PLANILLA_HOSTS as $h) if ($host === $h || str_ends_with($host, '.' . $h)) $ok = true;
        if (!$ok) throw new Fallo(422, "Por enlace se aceptan hojas de Google, OneDrive o Dropbox; «{$host}» no está en la lista. Bajá el archivo y subilo.");
        $ch = http_ch('GET', $url, null, ['User-Agent' => 'iProy-inventario', 'Accept' => '*/*'], 60, true);
        $buf = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($c, $d) use (&$buf) { $buf .= $d; return strlen($buf) > PLANILLA_MAX_BYTES + 100000 ? 0 : strlen($d); });
        $ok = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL); $err = curl_error($ch);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE); $cuerpo = substr($buf, $hs);
        if ($http >= 300 && $http < 400 && $loc !== '') {
            if (str_contains($loc, 'accounts.google.com') || str_contains($loc, 'ServiceLogin')) throw new Fallo(422, 'La hoja no está compartida: en Google Sheets tocá «Compartir» › «Cualquier persona con el enlace» (Lector) y volvé a intentar');
            $url = $loc; continue;
        }
        if (!$ok && strlen($buf) > PLANILLA_MAX_BYTES) throw new Fallo(413, 'La planilla es demasiado grande (más de 20 MB)');
        if ($http === 0) throw new Fallo(502, 'No se pudo bajar la planilla: ' . $err . '. Si este equipo usa proxy, configuralo en el panel de Redes');
        if ($http === 401 || $http === 403 || $http === 404) throw new Fallo(422, "La hoja respondió HTTP {$http}: compartila como «Cualquier persona con el enlace» (Lector), o bajala como .xlsx y subila");
        if ($http !== 200) throw new Fallo(502, "La hoja respondió HTTP {$http}");
        if (str_starts_with(ltrim(substr($cuerpo, 0, 300)), '<')) throw new Fallo(422, 'El enlace devuelve una página web y no la planilla: compartila como «Cualquier persona con el enlace» (Lector)');
        return ['nombre' => $nombre, 'bin' => $cuerpo];
    }
    throw new Fallo(502, 'Demasiadas redirecciones al bajar la planilla');
}
