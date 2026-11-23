<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Ayudante del lanzador para actualizar sobre lo instalado (1.7.0). Corre con el PHP del nodo (frankenphp php-cli) y con el sistema
 * detenido; no toca la base ni data/ (eso lo hacen el respaldo del lanzador y las migraciones al iniciar).
 *   php actualizar.php accion <raiz>                      qué pidió la pantalla (aplicar | volver)
 *   php actualizar.php pedir <raiz> <paquete.zip> [--forzar]   desde la terminal: revisa el paquete y deja el pedido
 *   php actualizar.php preparar <raiz>                    verifica el paquete del pedido y lo extrae; imprime la carpeta del código nuevo
 *   php actualizar.php paso <raiz> <paso> [mensaje]       deja el paso en curso (la pantalla lo muestra al volver)
 *   php actualizar.php volver <raiz>                      vuelve al código anterior guardado al lado de la carpeta
 *   php actualizar.php terminar <raiz> <ok|vuelto|error> [mensaje]   historial, limpieza y fin
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/nucleo/lib/paquete.php';

use function Nucleo\{paq_inspeccionar, paq_instalado, paq_comparar, paq_extraer, paq_borrar};

$a = $argv; $cmd = $a[1] ?? ''; $raiz = isset($a[2]) ? rtrim(realpath($a[2]) ?: $a[2], '/\\') : '';
$fin = function (string $m, int $c = 1): never { fwrite(STDERR, "✘ $m\n"); exit($c); };
if ($raiz === '' || !is_file("$raiz/producto.json")) $fin('uso: actualizar.php <accion|pedir|preparar|paso|volver|terminar> <carpeta-del-producto> …');
$D = "$raiz/data/actualizaciones"; if (!is_dir($D)) mkdir($D, 0700, true);
$lee = fn(string $n) => json_decode((string) @file_get_contents("$D/$n.json"), true) ?: [];
$escribe = function (string $n, array $v) use ($D): void { $t = "$D/$n.json.tmp"; file_put_contents($t, json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); rename($t, "$D/$n.json"); };
$paso = function (string $p, string $m = '', array $extra = []) use ($lee, $escribe): void { $e = $lee('estado'); $escribe('estado', ['activo' => true, 'paso' => $p, 'mensaje' => $m ?: $p, 'ts' => time()] + $extra + $e); };
// carpetas de código guardadas al lado, de la más nueva a la más vieja (por fecha del disco: el nombre pudo armarse con otra zona horaria)
$hermanos = function (string $tipo) use ($raiz): array { $l = array_values(array_filter(glob("$raiz.$tipo-*") ?: [], 'is_dir')); usort($l, fn($x, $y) => filemtime($y) <=> filemtime($x) ?: strcmp($y, $x)); return $l; };

try {
    switch ($cmd) {
        case 'accion':
            echo ($lee('pedido')['accion'] ?? 'aplicar'), "\n"; break;

        case 'pedir':
            $zip = $a[3] ?? $fin('falta el paquete .zip');
            $p = paq_inspeccionar($zip); $cmp = paq_comparar(paq_instalado($raiz), $p);
            echo "Paquete: {$p['producto']} {$p['version']} · compilación {$p['compilacion']} · {$cmp['mensaje']}\n";
            if (in_array($cmp['tipo'], ['incompatible', 'igual'], true)) $fin($cmp['mensaje']);
            if (in_array($cmp['tipo'], ['anterior', 'mayor'], true) && !in_array('--forzar', $a, true)) $fin($cmp['mensaje'] . ' (para instalarlo igual: --forzar)');
            if (!is_dir("$D/paquetes")) mkdir("$D/paquetes", 0700, true);
            $dest = "$D/paquetes/" . basename($zip); if (realpath($zip) !== realpath($dest)) copy($zip, $dest);
            $escribe('paquete', $p + ['archivo' => $dest, 'nombre' => basename($dest), 'origen' => 'terminal', 'recibido' => date('c')]);
            $escribe('pedido', ['accion' => 'aplicar', 'archivo' => $dest, 'sha256' => $p['sha256'], 'version' => $p['version'], 'compilacion' => $p['compilacion'], 'tipo' => $cmp['tipo'],
                'por' => 'terminal', 'fecha' => date('c'), 'desde' => paq_instalado($raiz)]);
            $paso('pedido', 'Preparando…', ['accion' => 'aplicar']);
            break;

        case 'preparar':
            $ped = $lee('pedido'); if (($ped['accion'] ?? '') !== 'aplicar') $fin('No hay un pedido de actualización');
            if (!is_file((string) $ped['archivo']) || hash_file('sha256', $ped['archivo']) !== $ped['sha256']) $fin('El paquete no está o cambió desde que se revisó');
            $paso('preparar', 'Revisando y extrayendo el paquete');
            echo paq_extraer($ped['archivo'], "$D/codigo"), "\n";
            break;

        case 'paso':
            $paso($a[3] ?? 'paso', $a[4] ?? ''); break;

        case 'volver':
            $ant = $hermanos('codigo-anterior');
            if (!$ant) $fin('No hay un código anterior guardado');
            $bk = $ant[0]; $desc = "$raiz.codigo-descartado-" . gmdate('Ymd-His'); mkdir($desc, 0755, true);
            $mov = json_decode((string) @file_get_contents("$bk/.movidos.json"), true) ?: array_values(array_diff(scandir($bk), ['.', '..', '.movidos.json']));
            foreach ($mov as $n) if ($n !== '' && !str_contains($n, '/') && file_exists("$raiz/$n")) rename("$raiz/$n", "$desc/$n");   // lo que trajo la versión nueva
            foreach (array_diff(scandir($bk), ['.', '..', '.movidos.json']) as $n) rename("$bk/$n", "$raiz/$n");                        // vuelve el código de antes
            @unlink("$bk/.movidos.json"); @rmdir($bk);
            echo "✔ Código anterior en su lugar. Lo que se sacó quedó en $desc\n";
            break;

        case 'terminar':
            $res = $a[3] ?? 'ok'; $msg = $a[4] ?? '';
            $ped = $lee('pedido'); $ahora = paq_instalado($raiz);
            $bks = glob("$raiz/data/backups/*.sql.gz") ?: []; usort($bks, fn($x, $y) => filemtime($y) <=> filemtime($x));
            $ant = $hermanos('codigo-anterior');
            $h = $lee('historial');
            $h[] = ['fecha' => date('c'), 'accion' => $ped['accion'] ?? 'aplicar', 'resultado' => $res, 'mensaje' => $msg, 'por' => $ped['por'] ?? null,
                    'desde' => isset($ped['desde']) ? $ped['desde']['base'] . ' · ' . $ped['desde']['compilacion'] : null, 'ahora' => $ahora['base'] . ' · ' . $ahora['compilacion'],
                    'respaldo' => $bks ? basename($bks[0]) : null, 'codigo_anterior' => $ant ? basename($ant[0]) : null];
            $escribe('historial', array_slice($h, -100));
            $escribe('estado', ['activo' => false, 'paso' => 'fin', 'resultado' => $res, 'mensaje' => $msg ?: ($res === 'ok' ? 'Actualización instalada' : $res), 'ts' => time(), 'accion' => $ped['accion'] ?? 'aplicar']);
            @unlink("$D/pedido.json");
            paq_borrar("$D/codigo");
            if ($res === 'ok') { $p = $lee('paquete'); if (!empty($p['archivo'])) @unlink((string) $p['archivo']); @unlink("$D/paquete.json"); }
            // se guardan las 3 versiones de código más nuevas y la última descartada
            foreach (array_slice($ant, 3) as $v) paq_borrar($v);
            foreach (array_slice($hermanos('codigo-descartado'), 1) as $v) paq_borrar($v);
            echo "Actualización: $res" . ($msg ? " · $msg" : '') . "\n";
            break;

        default: $fin('comando desconocido: ' . $cmd);
    }
} catch (\Throwable $e) { $fin($e->getMessage()); }
