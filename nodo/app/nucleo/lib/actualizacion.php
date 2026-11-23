<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Actualizar sobre lo instalado (1.7.0), como una aplicación que baja su versión nueva y sigue andando:
 *  - el paquete se busca en GitHub (la última versión publicada) o se sube a mano (.zip del código, en partes);
 *  - el administrador toca «Actualizar ahora»: el lanzador del nodo hace un respaldo de la base, detiene el sistema, cambia solo el código
 *    (incubar.php actualizar: data/, runtime/ y descargas/ no se tocan), lo vuelve a iniciar (las migraciones de la base corren solas) y,
 *    si no arranca, vuelve solo a la versión anterior;
 *  - «Volver a la versión anterior» deja el código de antes (los datos siguen siendo los de ahora: las migraciones solo agregan).
 * Todo queda en data/actualizaciones/ y en el diario firmado.
 */
declare(strict_types=1);

namespace Nucleo;

require_once __DIR__ . '/paquete.php';

const ACT_REPO = 'iproy-ar/iproy';
const ACT_HOSTS = ['api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'];
const ACT_PARTE_MAX = 4 * 1048576;

function act_dir(string $sub = ''): string {
    $d = raiz() . '/data/actualizaciones' . ($sub !== '' ? "/$sub" : '');
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}
function act_json(string $nombre, ?array $nuevo = null): array {
    $f = act_dir() . "/$nombre.json";
    if ($nuevo !== null) { escribir_json($f, $nuevo); return $nuevo; }
    return leer_json($f);
}
function act_config(): array { return act_json('config') + ['repositorio' => ACT_REPO, 'revisar_auto' => true]; }
/** El lanzador que hace el trabajo con el sistema detenido (<Producto>-Control.sh o .ps1). */
function act_lanzador(): ?string {
    $n = producto()['nombre'] ?? 'iProy';
    $f = raiz() . '/' . $n . (PHP_OS_FAMILY === 'Windows' ? '-Control.ps1' : '-Control.sh');
    return is_file($f) && is_file(raiz() . '/tools/actualizar.php') ? $f : null;
}

/** Todo lo que muestra la pantalla «Actualizaciones». */
function act_estado(): array {
    $inst = paq_instalado(raiz());
    $paq = act_json('paquete'); if ($paq && !is_file((string) ($paq['archivo'] ?? ''))) $paq = [];
    if ($paq) $paq['comparacion'] = paq_comparar($inst, $paq);
    $disp = act_json('disponible');
    if ($disp && !empty($disp['version'])) $disp['es_nueva'] = version_compare((string) $disp['version'], $inst['base'], '>');
    $hist = array_slice(array_reverse(act_json('historial')), 0, 20);
    $anteriores = array_values(array_filter(glob(raiz() . '.codigo-anterior-*') ?: [], 'is_dir')); usort($anteriores, fn($x, $y) => filemtime($y) <=> filemtime($x) ?: strcmp($y, $x));
    return ['instalado' => $inst, 'paquete' => $paq ?: null, 'disponible' => $disp ?: null, 'historial' => $hist, 'curso' => act_json('estado') ?: null,
            'pedido' => act_json('pedido') ?: null, 'lanzador' => act_lanzador() !== null, 'repositorio' => act_config()['repositorio'], 'revisar_auto' => (bool) act_config()['revisar_auto'],
            'puede_volver' => (bool) $anteriores, 'anterior' => $anteriores ? basename($anteriores[0]) : null, 'sistema' => PHP_OS_FAMILY];
}

// ---------------------------------------------------------------- GitHub: la última versión publicada
function act_get(string $url, ?string $archivo = null, int $timeout = 20): array {
    for ($i = 0; $i < 6; $i++) {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array($host, ACT_HOSTS, true)) throw new Fallo(422, "Las actualizaciones se bajan solo de GitHub; «{$host}» no está en la lista");
        $ch = http_ch('GET', $url, null, ['User-Agent' => 'iProy-actualizador', 'Accept' => $archivo ? '*/*' : 'application/vnd.github+json'], $timeout);
        if ($archivo === null) { $r = http_leer($ch, curl_exec($ch)); curl_close($ch); }
        else {
            $fh = fopen($archivo, 'w'); $cab = []; $n = 0;
            curl_setopt_array($ch, [CURLOPT_HEADER => false, CURLOPT_RETURNTRANSFER => false, CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 300,
                CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$cab) { if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $cab[strtolower(trim($k))] = trim($v); } return strlen($l); },
                CURLOPT_WRITEFUNCTION => function ($c, $d) use ($fh, &$n) { $n += strlen($d); return $n > PAQ_MAX ? 0 : fwrite($fh, $d); }]);
            $ok = curl_exec($ch); $r = ['http' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'cab' => $cab, 'cuerpo' => '', 'error' => $ok ? null : curl_error($ch)];
            curl_close($ch); fclose($fh);
        }
        if ($r['http'] >= 300 && $r['http'] < 400 && !empty($r['cab']['location'])) { $url = $r['cab']['location']; continue; }
        if ($r['error']) throw new Fallo(502, 'No se pudo llegar a GitHub: ' . $r['error'] . '. Sin Internet, subí el paquete .zip a mano.');
        if ($r['http'] === 404) throw new Fallo(404, 'Todavía no hay una versión publicada en GitHub');
        if ($r['http'] >= 400) throw new Fallo(502, "GitHub respondió HTTP {$r['http']}");
        return $r;
    }
    throw new Fallo(502, 'Demasiadas redirecciones');
}
/** Pregunta a GitHub cuál es la última versión publicada y la guarda (no baja nada). */
function act_buscar(): array {
    $repo = act_config()['repositorio'];
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) throw new Fallo(422, 'Repositorio inválido');
    $j = json_decode(act_get("https://api.github.com/repos/$repo/releases/latest")['cuerpo'], true);
    if (!is_array($j) || empty($j['tag_name'])) throw new Fallo(502, 'GitHub no devolvió la versión');
    $zip = null;   // un .zip adjunto a la versión (si lo hay) manda; si no, el código fuente que arma GitHub
    // solo el .zip del código (<Producto>-<versión>-codigo-<compilación>.zip); el instalador de Windows, que también es un .zip, no sirve para actualizar.
    // Sin ese archivo adjunto se usa el .zip que arma GitHub con la etiqueta (zipball), que trae lo mismo.
    foreach ((array) ($j['assets'] ?? []) as $a) if (preg_match('/codigo[^\/]*\.zip$/i', (string) ($a['name'] ?? ''))) { $zip = $a['browser_download_url']; break; }
    $d = ['version' => ltrim((string) $j['tag_name'], 'vV'), 'tag' => (string) $j['tag_name'], 'nombre' => (string) ($j['name'] ?? ''), 'fecha' => (string) ($j['published_at'] ?? ''),
          'notas' => mb_substr((string) ($j['body'] ?? ''), 0, 4000), 'url' => (string) ($j['html_url'] ?? ''), 'zip' => $zip ?: (string) ($j['zipball_url'] ?? ''), 'revisado' => date('c')];
    act_json('disponible', $d);
    return $d;
}
/** Una vez por día, desde el tick (sin Internet no pasa nada). */
function act_revisar_diario(): ?string {
    $c = act_config(); if (empty($c['revisar_auto'])) return null;
    $e = estado('nucleo_actualizacion'); if (($e['ts'] ?? 0) > time() - 86400) return null;
    estado('nucleo_actualizacion', ['ts' => time()]);
    try { $d = act_buscar(); return 'GitHub: ' . $d['version']; } catch (\Throwable $x) { return 'sin respuesta de GitHub'; }
}
function act_bajar(): array {
    $d = act_json('disponible'); if (empty($d['zip'])) $d = act_buscar();
    if (empty($d['zip'])) throw new Fallo(404, 'La versión publicada no trae el código');
    $f = act_dir('paquetes') . '/iproy-' . preg_replace('/[^0-9A-Za-z.-]/', '', $d['tag']) . '.zip';
    act_get($d['zip'], $f);
    return act_registrar($f, 'GitHub ' . $d['tag']);
}

// ---------------------------------------------------------------- subir el .zip a mano (en partes: el núcleo admite cuerpos de pocos MB)
function act_subir_parte(string $id, int $parte, int $total, string $nombre): array {
    if (!preg_match('/^[a-z0-9]{8,32}$/', $id) || $parte < 1 || $total < 1 || $parte > $total || $total > 40) throw new Fallo(422, 'Parte inválida');
    $raw = (string) file_get_contents('php://input', false, null, 0, ACT_PARTE_MAX + 1);
    if ($raw === '' || strlen($raw) > ACT_PARTE_MAX) throw new Fallo(413, 'Cada parte llega con hasta 4 MB');
    $f = act_dir('subidas') . "/$id.part";
    if ($parte === 1) @unlink($f);
    elseif (!is_file($f) || (int) (act_json("subida-$id")['parte'] ?? 0) !== $parte - 1) throw new Fallo(409, 'Faltó una parte: volvé a subir el archivo');
    file_put_contents($f, $raw, FILE_APPEND); act_json("subida-$id", ['parte' => $parte]);
    if (filesize($f) > PAQ_MAX) { @unlink($f); throw new Fallo(413, 'El paquete es demasiado grande'); }
    if ($parte < $total) return ['parte' => $parte, 'total' => $total];
    @unlink(act_dir() . "/subida-$id.json");
    $dest = act_dir('paquetes') . '/' . (preg_replace('/[^0-9A-Za-z._-]/', '_', basename($nombre)) ?: 'paquete.zip');
    if (!str_ends_with(strtolower($dest), '.zip')) $dest .= '.zip';
    rename($f, $dest);
    return act_registrar($dest, 'archivo ' . basename($nombre));
}
/** Revisa el paquete y lo deja listo para instalar (uno solo a la vez: el anterior se borra). */
function act_registrar(string $archivo, string $origen): array {
    try { $p = paq_inspeccionar($archivo); } catch (\RuntimeException $e) { @unlink($archivo); throw new Fallo(422, $e->getMessage()); }
    $viejo = (string) (act_json('paquete')['archivo'] ?? '');
    if ($viejo !== '' && $viejo !== $archivo) @unlink($viejo);
    $p += ['archivo' => $archivo, 'nombre' => basename($archivo), 'origen' => $origen, 'recibido' => date('c')];
    act_json('paquete', $p);
    return $p + ['comparacion' => paq_comparar(paq_instalado(raiz()), $p)];
}
function act_descartar(): void { $p = act_json('paquete'); if (!empty($p['archivo'])) @unlink((string) $p['archivo']); @unlink(act_dir() . '/paquete.json'); }

// ---------------------------------------------------------------- aplicar y volver atrás: lo hace el lanzador, con el sistema detenido
function act_aplicar(array $o, array $u): array {
    $p = act_json('paquete'); if (empty($p['archivo']) || !is_file($p['archivo'])) throw new Fallo(404, 'Primero buscá la actualización o subí el paquete');
    $cmp = paq_comparar(paq_instalado(raiz()), $p);
    if ($cmp['tipo'] === 'incompatible' || $cmp['tipo'] === 'igual') throw new Fallo(409, $cmp['mensaje']);
    if ($cmp['tipo'] === 'anterior' && empty($o['forzar'])) throw new Fallo(409, $cmp['mensaje'] . '. Para instalarla igual, confirmá «Instalar una versión anterior».', 'anterior');
    if ($cmp['tipo'] === 'mayor' && empty($o['confirmar_mayor'])) throw new Fallo(409, $cmp['mensaje'], 'mayor');
    if (hash_file('sha256', $p['archivo']) !== $p['sha256']) throw new Fallo(409, 'El paquete cambió desde que se revisó: volvé a subirlo');
    return act_lanzar(['accion' => 'aplicar', 'archivo' => $p['archivo'], 'sha256' => $p['sha256'], 'version' => $p['version'], 'compilacion' => $p['compilacion'], 'tipo' => $cmp['tipo']], $u);
}
function act_volver(array $u): array {
    if (!act_estado()['puede_volver']) throw new Fallo(404, 'No hay una versión anterior guardada');
    return act_lanzar(['accion' => 'volver'], $u);
}
function act_lanzar(array $pedido, array $u): array {
    $l = act_lanzador(); if (!$l) throw new Fallo(501, 'Este nodo no tiene el lanzador con actualizaciones (instalá esta versión una vez a mano)');
    $cur = act_json('estado');
    if (!empty($cur['activo']) && ($cur['ts'] ?? 0) > time() - 900) throw new Fallo(409, 'Ya hay una actualización en curso');
    $pedido += ['por' => $u['usuario'] ?? null, 'fecha' => date('c'), 'desde' => paq_instalado(raiz())];
    act_json('pedido', $pedido);
    act_json('estado', ['activo' => true, 'paso' => 'pedido', 'mensaje' => 'Preparando…', 'ts' => time(), 'accion' => $pedido['accion']]);
    asentar('actualizacion.' . $pedido['accion'], array_diff_key($pedido, ['archivo' => 1, 'desde' => 1]) + ['desde' => $pedido['desde']['base'] . ' ' . $pedido['desde']['compilacion']], null, $u['usuario'] ?? null);
    $log = raiz() . '/data/logs/actualizacion.log';
    $arg = $pedido['accion'] === 'volver' ? '--volver' : '--pedido';
    if (PHP_OS_FAMILY === 'Windows') {
        $arg = ltrim($arg, '-');   // en PowerShell «--algo» se toma como nombre de parámetro
        $cmd = 'start "" /B powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ' . escapeshellarg($l) . ' actualizar ' . $arg . ' >> ' . escapeshellarg($log) . ' 2>&1';
        $h = popen($cmd, 'r'); if ($h) pclose($h);
    } else {
        $cmd = (is_executable('/usr/bin/setsid') || is_executable('/bin/setsid') ? 'setsid ' : '') . 'nohup bash ' . escapeshellarg($l) . ' actualizar ' . $arg . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';
        exec($cmd);
    }
    return ['lanzado' => true, 'accion' => $pedido['accion']];
}
