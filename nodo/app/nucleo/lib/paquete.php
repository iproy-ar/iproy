<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Paquetes de actualización (1.7.0): leer un .zip del código (el que publica GitHub o el que arma `git archive`), saber qué versión trae,
 * compararla con la instalada y extraerlo. Sin dependencias del resto del núcleo: lo usan la API (lib/actualizacion.php) y el ayudante
 * de línea de comandos que corre con el sistema detenido (tools/actualizar.php).
 */
declare(strict_types=1);

namespace Nucleo;

const PAQ_MAX = 80 * 1048576;   // un paquete del código pesa unos pocos MB; más que esto no es un paquete de iProy

/** Todas las entradas del .zip: nombre → contenido (ZipArchive o, si el PHP del equipo no lo trae, un lector propio). */
function paq_zip_leer(string $archivo): array {
    if (!is_file($archivo) || filesize($archivo) > PAQ_MAX) throw new \RuntimeException('El paquete no existe o es demasiado grande');
    $o = [];
    if (class_exists(\ZipArchive::class)) {
        $z = new \ZipArchive();
        if ($z->open($archivo) !== true) throw new \RuntimeException('El archivo no es un .zip válido');
        for ($i = 0; $i < $z->numFiles; $i++) { $n = (string) $z->getNameIndex($i); if (!str_ends_with($n, '/')) $o[$n] = (string) $z->getFromIndex($i); }
        $z->close();
        return $o;
    }
    $bin = (string) file_get_contents($archivo);
    $fin = strrpos($bin, "PK\x05\x06"); if ($fin === false) throw new \RuntimeException('El archivo no es un .zip válido');
    $d = unpack('vn/Vtam/Vini', substr($bin, $fin + 10, 10)); $p = $d['ini'];
    for ($k = 0; $k < $d['n']; $k++) {
        $c = unpack('Vfirma/vv1/vv2/vflag/vmet/vt/vf/Vcrc/Vcomp/Vtam/vln/vle/vlc/vdisk/vint/Vext/Vloc', substr($bin, $p, 46));
        $n = substr($bin, $p + 46, $c['ln']); $p += 46 + $c['ln'] + $c['le'] + $c['lc'];
        if (str_ends_with($n, '/')) continue;
        $l = unpack('vln/vle', substr($bin, $c['loc'] + 26, 4)); $dat = substr($bin, $c['loc'] + 30 + $l['ln'] + $l['le'], $c['comp']);
        $o[$n] = $c['met'] === 8 ? (string) @gzinflate($dat) : $dat;
    }
    return $o;
}
/** «HASH FECHA» del archivo BUILD (lo completa `git archive` con export-subst); en una copia de trabajo queda «desarrollo». */
function paq_build(?string $t): array {
    $t = trim((string) $t);
    if (preg_match('/^([0-9a-f]{40})\s+(\S+)/', $t, $m)) return ['compilacion' => substr($m[1], 0, 12), 'fecha' => $m[2]];
    return ['compilacion' => 'desarrollo', 'fecha' => null];
}
function paq_version_base(array $pj): string {
    if (preg_match('/(\d+\.\d+\.\d+)\s*$/', (string) ($pj['base'] ?? ''), $m)) return $m[1];   // producto incubado: «iProy 1.7.0»
    return (string) ($pj['version'] ?? '0.0.0');
}
/** Lo instalado en <raiz>: producto, su versión, la versión de iProy en la que se basa y la compilación. */
function paq_instalado(string $raiz): array {
    $pj = json_decode((string) @file_get_contents("$raiz/producto.json"), true) ?: [];
    return ['producto' => (string) ($pj['nombre'] ?? 'iProy'), 'version' => (string) ($pj['version'] ?? ''), 'base' => paq_version_base($pj)]
        + paq_build(@file_get_contents("$raiz/BUILD"));
}
/** Qué trae un paquete: carpeta raíz dentro del .zip, versión, desde qué versión se puede actualizar, compilación, SHA-256. */
function paq_inspeccionar(string $archivo, ?array $entradas = null): array {
    $e = $entradas ?? paq_zip_leer($archivo);
    $pref = null;
    foreach (array_keys($e) as $n) if (preg_match('#^(.*?)nodo/producto\.json$#', $n, $m) && isset($e[$m[1] . 'incubadora/incubar.php'])) { $pref = $m[1]; break; }
    if ($pref === null) throw new \RuntimeException('No es un paquete de actualización: le faltan nodo/producto.json o incubadora/incubar.php');
    $pj = json_decode($e[$pref . 'nodo/producto.json'], true) ?: throw new \RuntimeException('El producto.json del paquete está dañado');
    return ['prefijo' => $pref, 'producto' => (string) ($pj['nombre'] ?? ''), 'version' => (string) ($pj['version'] ?? ''), 'actualizable_desde' => (string) ($pj['actualizable_desde'] ?? '0.0.0'),
            'archivos' => count($e), 'sha256' => hash_file('sha256', $archivo), 'bytes' => filesize($archivo)] + paq_build($e[$pref . 'nodo/BUILD'] ?? null);
}
/**
 * ¿Se puede instalar sobre lo que hay? tipo: nueva | otra-compilacion | igual | anterior | mayor | incompatible.
 * «anterior» y «mayor» piden confirmación expresa (forzar / confirmar_mayor); «incompatible» no se instala.
 */
function paq_comparar(array $inst, array $paq): array {
    $a = $inst['base']; $n = $paq['version'];
    if (!preg_match('/^\d+\.\d+\.\d+$/', $n)) return ['tipo' => 'incompatible', 'mensaje' => 'El paquete no dice qué versión es'];
    if (version_compare($a, $paq['actualizable_desde'], '<')) return ['tipo' => 'incompatible', 'mensaje' => "Desde la $a no se puede actualizar a la $n: hace falta pasar antes por la {$paq['actualizable_desde']}"];
    $c = version_compare($n, $a);
    if ($c < 0) return ['tipo' => 'anterior', 'mensaje' => "El paquete trae la $n, más vieja que la instalada ($a)"];
    if ($c > 0) return (int) explode('.', $n)[0] !== (int) explode('.', $a)[0]
        ? ['tipo' => 'mayor', 'mensaje' => "Cambio de versión mayor ($a → $n): puede cambiar la forma de trabajo. Leé las notas antes de instalarla"]
        : ['tipo' => 'nueva', 'mensaje' => "Versión nueva: $a → $n"];
    if ($paq['compilacion'] !== 'desarrollo' && $paq['compilacion'] === $inst['compilacion']) return ['tipo' => 'igual', 'mensaje' => "Ya está instalada esta misma compilación ($n · {$paq['compilacion']})"];
    if ($paq['fecha'] && $inst['fecha'] && strtotime($paq['fecha']) < strtotime($inst['fecha'])) return ['tipo' => 'anterior', 'mensaje' => "Es la $n, pero una compilación más vieja que la instalada"];
    return ['tipo' => 'otra-compilacion', 'mensaje' => "Misma versión ($n), otra compilación: {$inst['compilacion']} → {$paq['compilacion']}"];
}
/** Extrae el paquete en $destino (vacío) y devuelve la carpeta que tiene nodo/ e incubadora/. Rechaza rutas que salen de la carpeta. */
function paq_extraer(string $archivo, string $destino): string {
    $e = paq_zip_leer($archivo); $i = paq_inspeccionar($archivo, $e);
    if (is_dir($destino)) paq_borrar($destino);
    mkdir($destino, 0755, true);
    foreach ($e as $n => $dat) {
        if (!str_starts_with($n, $i['prefijo'])) continue;
        $rel = substr($n, strlen($i['prefijo']));
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/') || preg_match('#^[A-Za-z]:#', $rel) || str_contains($rel, "\0")) throw new \RuntimeException("Ruta inválida en el paquete: $n");
        $f = "$destino/$rel"; if (!is_dir(dirname($f))) mkdir(dirname($f), 0755, true);
        file_put_contents($f, $dat);
        if (preg_match('#\.(sh|py)$#', $rel)) @chmod($f, 0755);
    }
    if (!is_file("$destino/incubadora/incubar.php")) throw new \RuntimeException('El paquete no se extrajo completo');
    return $destino;
}
function paq_borrar(string $d): void {
    if (is_link($d) || is_file($d)) { @unlink($d); return; }
    if (!is_dir($d)) return;
    foreach (scandir($d) as $n) if ($n !== '.' && $n !== '..') paq_borrar("$d/$n");
    @rmdir($d);
}
