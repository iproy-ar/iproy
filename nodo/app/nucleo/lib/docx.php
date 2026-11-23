<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Ola P (P4), en el núcleo desde la Ola 4: documento .docx nativo (Office Open XML) sin bibliotecas, a partir de un modelo simple. Lo abren Word, LibreOffice y Google Docs.
// Modelo: {titulo, subtitulo?, bloques: [{tipo: h2|p|tabla|firmas|espacio, texto?, cab?: [..], filas?: [[..]], items?: [..]}]}
declare(strict_types=1);

namespace Nucleo;


/** Nombre de archivo para descargar: sin tildes ni eñes (algunos navegadores descartan el nombre si no es ASCII) y sin caracteres raros. */
function nombre_archivo(string $n, string $ext): string {
    $a = strtr($n, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'â' => 'a', 'ê' => 'e', 'ô' => 'o', 'ã' => 'a', 'õ' => 'o', 'ç' => 'c', 'Ç' => 'C', 'º' => 'o', 'ª' => 'a']);   // sin depender del locale del servidor
    $a = preg_replace('/[^A-Za-z0-9._-]+/', '_', $a) ?: 'documento';
    $a = trim(substr($a, 0, 150), '_.') ?: 'documento';
    return str_ends_with(strtolower($a), '.' . $ext) ? $a : $a . '.' . $ext;
}
function docx(array $m): string {
    if (!class_exists(\ZipArchive::class)) throw new Fallo(501, 'Falta la extensión zip de PHP para armar el documento');
    $x = fn($t) => htmlspecialchars(mb_substr((string) $t, 0, 4000), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $run = function (string $t, bool $neg = false, ?int $tam = null, ?string $color = null) use ($x): string {
        $pr = ($neg ? '<w:b/>' : '') . ($color ? "<w:color w:val=\"$color\"/>" : '') . ($tam ? "<w:sz w:val=\"$tam\"/><w:szCs w:val=\"$tam\"/>" : '');
        $l = explode("\n", $t); $o = '';
        foreach ($l as $i => $s) $o .= ($i ? '<w:r><w:br/></w:r>' : '') . '<w:r>' . ($pr ? "<w:rPr>$pr</w:rPr>" : '') . '<w:t xml:space="preserve">' . $x($s) . '</w:t></w:r>';
        return $o;
    };
    $par = fn(string $runs, string $estilo = '', string $extra = '') => '<w:p>' . ($estilo || $extra ? '<w:pPr>' . ($estilo ? "<w:pStyle w:val=\"$estilo\"/>" : '') . $extra . '</w:pPr>' : '') . $runs . '</w:p>';
    $ancho = 9638;   // A4 con márgenes de 2 cm, en vigésimos de punto
    $tabla = function (?array $cab, array $filas) use ($run, $par, $ancho): string {
        $n = max(1, count($cab ?? ($filas[0] ?? [1]))); $w = intdiv($ancho, $n);
        $bord = '<w:tblBorders>' . implode('', array_map(fn($b) => "<w:$b w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"999999\"/>", ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'])) . '</w:tblBorders>';
        $o = "<w:tbl><w:tblPr><w:tblW w:w=\"$ancho\" w:type=\"dxa\"/>$bord<w:tblLayout w:type=\"fixed\"/><w:tblCellMar><w:left w:w=\"80\" w:type=\"dxa\"/><w:right w:w=\"80\" w:type=\"dxa\"/></w:tblCellMar></w:tblPr><w:tblGrid>" . str_repeat("<w:gridCol w:w=\"$w\"/>", $n) . '</w:tblGrid>';
        $celda = fn($v, bool $c) => "<w:tc><w:tcPr><w:tcW w:w=\"$w\" w:type=\"dxa\"/>" . ($c ? '<w:shd w:val="clear" w:color="auto" w:fill="E8F1FB"/>' : '') . '</w:tcPr>' . $par($run((string) ($v ?? ''), $c, 20), '', '<w:spacing w:before="20" w:after="20"/>') . '</w:tc>';
        if ($cab) $o .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>' . implode('', array_map(fn($v) => $celda($v, true), $cab)) . '</w:tr>';
        foreach ($filas as $f) $o .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>' . implode('', array_map(fn($v) => $celda($v, false), array_pad(array_slice(array_values((array) $f), 0, $n), $n, ''))) . '</w:tr>';
        return $o . '</w:tbl>' . $par('');
    };
    $cuerpo = $par($run((string) ($m['titulo'] ?? 'Documento')), 'Titulo');
    if (!empty($m['subtitulo'])) $cuerpo .= $par($run((string) $m['subtitulo'], false, 20, '555555'));
    foreach (array_slice((array) ($m['bloques'] ?? []), 0, 400) as $b) {
        $t = (string) ($b['tipo'] ?? 'p');
        if ($t === 'h2') $cuerpo .= $par($run((string) ($b['texto'] ?? '')), 'Titulo2');
        elseif ($t === 'p') $cuerpo .= $par($run((string) ($b['texto'] ?? '')));
        elseif ($t === 'espacio') $cuerpo .= str_repeat($par(''), max(1, min(10, (int) ($b['lineas'] ?? 1))));
        elseif ($t === 'tabla') $cuerpo .= $tabla(isset($b['cab']) ? array_slice((array) $b['cab'], 0, 12) : null, array_slice((array) ($b['filas'] ?? []), 0, 500));
        elseif ($t === 'firmas') {
            $it = array_slice((array) ($b['items'] ?? []), 0, 4); $n = max(1, count($it)); $w = intdiv($ancho, $n);
            $cuerpo .= $par('') . $par('') . "<w:tbl><w:tblPr><w:tblW w:w=\"$ancho\" w:type=\"dxa\"/><w:tblLayout w:type=\"fixed\"/></w:tblPr><w:tblGrid>" . str_repeat("<w:gridCol w:w=\"$w\"/>", $n) . '</w:tblGrid><w:tr>'
                . implode('', array_map(fn($s) => "<w:tc><w:tcPr><w:tcW w:w=\"$w\" w:type=\"dxa\"/></w:tcPr>" . $par($run('______________________')  , '', '<w:jc w:val="center"/>') . $par($run((string) $s, false, 18), '', '<w:jc w:val="center"/>') . '</w:tc>', $it)) . '</w:tr></w:tbl>';
        }
    }
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>'
        . $cuerpo . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr></w:body></w:document>';
    $estilos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri" w:eastAsia="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="es-AR"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="80"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Titulo"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="60"/></w:pPr><w:rPr><w:b/><w:color w:val="0D47A1"/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Titulo2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="80"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:color w:val="1E3A5F"/><w:sz w:val="26"/><w:szCs w:val="26"/></w:rPr></w:style>'
        . '<w:style w:type="table" w:default="1" w:styleId="TablaNormal"><w:name w:val="Normal Table"/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style></w:styles>';
    $ahora = gmdate('Y-m-d\TH:i:s\Z');
    $partes = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
        'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'word/document.xml' => $doc, 'word/styles.xml' => $estilos,
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . $x($m['titulo'] ?? '') . '</dc:title><dc:creator>iProy</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $ahora . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $ahora . '</dcterms:modified></cp:coreProperties>',
        'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>iProy</Application></Properties>',
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'docx'); $z = new \ZipArchive();
    if ($z->open($tmp, \ZipArchive::OVERWRITE) !== true) throw new Fallo(500, 'No se pudo armar el documento');
    foreach ($partes as $n => $c) $z->addFromString($n, $c);
    $z->close(); $b = (string) file_get_contents($tmp); @unlink($tmp);
    return $b;
}
