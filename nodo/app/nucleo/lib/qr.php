<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Código QR sin bibliotecas (núcleo, ola 3c): ISO/IEC 18004, modo byte, corrección M (15 %), versiones 1 a 10 (hasta 213 bytes).
 * Sirve para enlaces cortos (p. ej. abrir un pedido desde el papel o el correo). Salidas: matriz, SVG y PNG (GD).
 * Algoritmo según la norma y la implementación de referencia de Project Nayuki (MIT), escrito de nuevo para iProy.
 */
declare(strict_types=1);

namespace Nucleo;

/** Por versión (1–10), corrección M: [codewords totales, bloques, codewords de corrección por bloque]. */
const QR_M = [1 => [26, 1, 10], [44, 1, 16], [70, 1, 26], [100, 2, 18], [134, 2, 24], [172, 4, 16], [196, 4, 18], [242, 4, 22], [292, 5, 22], [346, 5, 26]];
const QR_ALINEACION = [1 => [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]];

function qr_gf_mul(int $x, int $y): int {
    $z = 0;
    for ($i = 7; $i >= 0; $i--) { $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF; $z ^= (($y >> $i) & 1) * $x; }
    return $z;
}
function qr_rs_divisor(int $grado): array {
    $r = array_fill(0, $grado, 0); $r[$grado - 1] = 1; $raiz = 1;
    for ($i = 0; $i < $grado; $i++) {
        for ($j = 0; $j < $grado; $j++) { $r[$j] = qr_gf_mul($r[$j], $raiz); if ($j + 1 < $grado) $r[$j] ^= $r[$j + 1]; }
        $raiz = qr_gf_mul($raiz, 0x02);
    }
    return $r;
}
function qr_rs_resto(array $datos, array $div): array {
    $r = array_fill(0, count($div), 0);
    foreach ($datos as $b) {
        $f = $b ^ array_shift($r); $r[] = 0;
        foreach ($div as $i => $c) $r[$i] ^= qr_gf_mul($c, $f);
    }
    return $r;
}

/** Matriz QR (filas de booleanos, true = oscuro), sin zona silenciosa. */
function qr_matriz(string $texto): array {
    $bytes = array_values(unpack('C*', $texto) ?: []); $n = count($bytes);
    $ver = 0;
    foreach (QR_M as $v => [$tot, $bl, $ec]) { $cap = ($tot - $bl * $ec) * 8; if (4 + ($v < 10 ? 8 : 16) + 8 * $n <= $cap) { $ver = $v; break; } }
    if (!$ver) throw new Fallo(422, 'El texto es demasiado largo para el código QR (hasta 213 bytes)');
    [$total, $nBloques, $ecLen] = QR_M[$ver]; $capDatos = $total - $nBloques * $ecLen;
    // 1 · bits: modo byte, cantidad, datos, terminador y relleno
    $bits = '0100' . str_pad(decbin($n), $ver < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    foreach ($bytes as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    $bits .= str_repeat('0', min(4, $capDatos * 8 - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    $cw = array_map('bindec', str_split($bits, 8));
    for ($p = 0xEC; count($cw) < $capDatos; $p ^= 0xEC ^ 0x11) $cw[] = $p;
    // 2 · bloques con su corrección Reed-Solomon, intercalados
    $cortos = $nBloques - $total % $nBloques; $largoCorto = intdiv($total, $nBloques); $div = qr_rs_divisor($ecLen);
    $bloques = []; $k = 0;
    for ($i = 0; $i < $nBloques; $i++) {
        $d = array_slice($cw, $k, $largoCorto - $ecLen + ($i < $cortos ? 0 : 1)); $k += count($d);
        $e = qr_rs_resto($d, $div);
        if ($i < $cortos) $d[] = -1;   // lugar vacío para alinear el intercalado
        $bloques[] = array_merge($d, $e);
    }
    $fin = [];
    for ($i = 0; $i < count($bloques[0]); $i++) foreach ($bloques as $j => $b) if ($i !== $largoCorto - $ecLen || $j >= $cortos) $fin[] = $b[$i];
    // 3 · patrones fijos
    $t = $ver * 4 + 17; $m = array_fill(0, $t, array_fill(0, $t, false)); $fn = array_fill(0, $t, array_fill(0, $t, false));
    $pon = function (int $x, int $y, bool $v) use (&$m, &$fn) { $m[$y][$x] = $v; $fn[$y][$x] = true; };
    for ($i = 0; $i < $t; $i++) { $pon(6, $i, $i % 2 === 0); $pon($i, 6, $i % 2 === 0); }
    foreach ([[3, 3], [$t - 4, 3], [3, $t - 4]] as [$cx, $cy])
        for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
            $x = $cx + $dx; $y = $cy + $dy; $d = max(abs($dx), abs($dy));
            if ($x >= 0 && $x < $t && $y >= 0 && $y < $t) $pon($x, $y, $d !== 2 && $d !== 4);
        }
    $al = QR_ALINEACION[$ver]; $na = count($al);
    for ($i = 0; $i < $na; $i++) for ($j = 0; $j < $na; $j++) {
        if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) continue;
        for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $pon($al[$i] + $dx, $al[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
    }
    $formato = function (int $mask) use (&$pon, $t) {
        $d = (0 << 3) | $mask; $r = $d;   // nivel M = 00
        for ($i = 0; $i < 10; $i++) $r = ($r << 1) ^ (($r >> 9) * 0x537);
        $b = (($d << 10) | $r) ^ 0x5412; $bit = fn($i) => (($b >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) $pon(8, $i, $bit($i));
        $pon(8, 7, $bit(6)); $pon(8, 8, $bit(7)); $pon(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $pon(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $pon($t - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $pon(8, $t - 15 + $i, $bit($i));
        $pon(8, $t - 8, true);   // módulo oscuro fijo
    };
    $formato(0);
    if ($ver >= 7) {
        $r = $ver; for ($i = 0; $i < 12; $i++) $r = ($r << 1) ^ (($r >> 11) * 0x1F25);
        $b = ($ver << 12) | $r;
        for ($i = 0; $i < 18; $i++) { $v = (($b >> $i) & 1) === 1; $a = $t - 11 + $i % 3; $c = intdiv($i, 3); $pon($a, $c, $v); $pon($c, $a, $v); }
    }
    // 4 · datos en zigzag
    $i = 0; $nb = count($fin) * 8;
    for ($der = $t - 1; $der >= 1; $der -= 2) {
        if ($der === 6) $der = 5;
        for ($v = 0; $v < $t; $v++) for ($j = 0; $j < 2; $j++) {
            $x = $der - $j; $y = (($der + 1) & 2) === 0 ? $t - 1 - $v : $v;
            if (!$fn[$y][$x] && $i < $nb) { $m[$y][$x] = (($fin[$i >> 3] >> (7 - ($i & 7))) & 1) === 1; $i++; }
        }
    }
    // 5 · máscara con menor penalidad
    $mascara = function (array $base, int $k) use ($fn, $t): array {
        for ($y = 0; $y < $t; $y++) for ($x = 0; $x < $t; $x++) {
            if ($fn[$y][$x]) continue;
            $inv = match ($k) { 0 => ($x + $y) % 2 === 0, 1 => $y % 2 === 0, 2 => $x % 3 === 0, 3 => ($x + $y) % 3 === 0, 4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                5 => $x * $y % 2 + $x * $y % 3 === 0, 6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0, default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0 };
            if ($inv) $base[$y][$x] = !$base[$y][$x];
        }
        return $base;
    };
    $mejor = null; $mejorPen = PHP_INT_MAX;
    for ($k = 0; $k < 8; $k++) {
        $formato($k); $c = $mascara($m, $k);
        foreach ($fn as $y => $f) foreach ($f as $x => $es) if ($es) $c[$y][$x] = $m[$y][$x];   // el formato recién puesto quedó en $m
        $p = qr_penalidad($c);
        if ($p < $mejorPen) { $mejorPen = $p; $mejor = $k; }
    }
    $formato($mejor); $c = $mascara($m, $mejor);
    foreach ($fn as $y => $f) foreach ($f as $x => $es) if ($es) $c[$y][$x] = $m[$y][$x];
    return $c;
}
/** Penalidad de la norma (N1 tramos, N2 bloques 2×2, N3 patrones tipo buscador, N4 proporción de oscuros). */
function qr_penalidad(array $c): int {
    $t = count($c); $p = 0; $oscuros = 0;
    $lineas = [];
    for ($i = 0; $i < $t; $i++) { $lineas[] = $c[$i]; $lineas[] = array_column($c, $i); }
    foreach ($lineas as $l) {
        $s = implode('', array_map(fn($v) => $v ? '1' : '0', $l));
        if (preg_match_all('/0{5,}|1{5,}/', $s, $mm)) foreach ($mm[0] as $r) $p += 3 + strlen($r) - 5;
        $p += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
    }
    for ($y = 0; $y < $t - 1; $y++) for ($x = 0; $x < $t - 1; $x++)
        if ($c[$y][$x] === $c[$y][$x + 1] && $c[$y][$x] === $c[$y + 1][$x] && $c[$y][$x] === $c[$y + 1][$x + 1]) $p += 3;
    foreach ($c as $f) $oscuros += count(array_filter($f));
    return $p + 10 * intdiv(abs($oscuros * 20 - $t * $t * 10), $t * $t);
}
/** SVG con zona silenciosa de 4 módulos; escala libre (viewBox). */
function qr_svg(string $texto, string $titulo = ''): string {
    $m = qr_matriz($texto); $t = count($m); $z = $t + 8; $d = '';
    foreach ($m as $y => $f) foreach ($f as $x => $v) if ($v) $d .= 'M' . ($x + 4) . ',' . ($y + 4) . 'h1v1h-1z';
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $z . ' ' . $z . '" shape-rendering="crispEdges" role="img" aria-label="'
        . htmlspecialchars($titulo ?: 'Código QR', ENT_QUOTES) . '"><rect width="' . $z . '" height="' . $z . '" fill="#fff"/><path d="' . $d . '" fill="#000"/></svg>';
}
/** PNG (para el correo, como imagen dentro del mensaje). */
function qr_png(string $texto, int $escala = 5): string {
    if (!function_exists('imagecreate')) throw new Fallo(501, 'Falta la extensión gd de PHP para el código QR en imagen');
    $m = qr_matriz($texto); $t = count($m); $z = ($t + 8) * $escala;
    $im = imagecreate($z, $z); $bl = imagecolorallocate($im, 255, 255, 255); $ne = imagecolorallocate($im, 0, 0, 0);
    foreach ($m as $y => $f) foreach ($f as $x => $v) if ($v) imagefilledrectangle($im, ($x + 4) * $escala, ($y + 4) * $escala, ($x + 5) * $escala - 1, ($y + 5) * $escala - 1, $ne);
    ob_start(); imagepng($im); imagedestroy($im);
    return (string) ob_get_clean();
}
