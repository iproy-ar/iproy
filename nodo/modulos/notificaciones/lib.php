<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Avisos: del bus de eventos a las personas.
 *
 *   evento del bus ──(reglas activas de ese tema, con su condición)──▶ ntf_envio «pendiente» (uno por destinatario)
 *   tarea «enviar» (cada 30 s) ──▶ canal (correo | telegram | webhook) ──▶ «enviado», o reintento con espera creciente,
 *   y «fallido» al agotar los intentos. Sin Internet la cola espera: nada se pierde y nada se manda dos veces
 *   (clave única regla + evento + destinatario).
 *
 * El módulo no lee tablas ajenas: lo que avisa es lo que trae el evento. Los secretos (token del bot, clave de firma)
 * viven en data/ y nunca salen por la API.
 */
declare(strict_types=1);

namespace Modulos\Notificaciones;

use Nucleo\Fallo;
use function Nucleo\{q, fila, filas, valor, pdo, cfg_modulo, asentar, http, correo_cfg, enviar_correo, nodo};

const CANALES = ['correo' => 'Correo', 'telegram' => 'Telegram', 'webhook' => 'Webhook'];
const ESTADOS = ['pendiente' => 'En cola', 'enviado' => 'Enviado', 'fallido' => 'Con falla', 'descartado' => 'Silenciado'];
const SECRETOS = ['telegram_token', 'webhook_secreto'];

/** Secretos de los canales: archivo aparte en data/ (permisos restringidos), fuera de la configuración que la API del núcleo muestra al administrador. */
function secretos(?array $nuevo = null): array {
    $f = \Nucleo\dir_datos('modulos') . '/notificaciones.secretos.json';
    if ($nuevo !== null) \Nucleo\escribir_json($f, array_intersect_key($nuevo, array_flip(SECRETOS)), true);
    return \Nucleo\leer_json($f) + array_fill_keys(SECRETOS, '');
}

/** De qué se puede avisar: tema del bus, nombre y los campos con los que se arma la condición. */
function fuentes(): array {
    $prio = ['stat' => 'Emergencia', 'asap' => 'Muy urgente', 'urgent' => 'Urgente', 'routine' => 'Normal'];
    $estados = ['solicitada' => 'Solicitada', 'en-revision' => 'En revisión', 'asignada' => 'Asignada', 'aceptada' => 'Aceptada', 'lista' => 'Lista',
        'en-ejecucion' => 'En ejecución', 'en-espera' => 'En espera', 'finalizada' => 'Finalizada', 'conformada' => 'Conformada', 'rechazada' => 'Rechazada', 'anulada' => 'Anulada'];
    return [
        'alarmas/cambio' => ['nombre' => 'Alarma', 'ayuda' => 'Una alarma se activa, vuelve a normal o alguien la reconoce.', 'requiere' => 'alarmas', 'campos' => [
            'evento' => ['t' => 'Qué pasó', 'op' => ['activada' => 'Se activó', 'retorno' => 'Volvió a normal', 'reconocida' => 'Fue reconocida', 'restablecida' => 'Fue restablecida',
                'archivada' => 'Fue archivada', 'suprimida' => 'Fue suprimida', 'fuera-servicio' => 'Pasó a fuera de servicio', 'restaurada' => 'Fue restaurada'], 'defecto' => ['activada']],
            'prioridad' => ['t' => 'Prioridad', 'op' => ['1' => '1 · Crítica', '2' => '2 · Alta', '3' => '3 · Media', '4' => '4 · Baja']],
        ]],
        'pim/ServiceRequest/create' => ['nombre' => 'Solicitud nueva', 'ayuda' => 'Alguien cargó una solicitud de servicio.', 'requiere' => 'pim', 'campos' => [
            'prioridad' => ['t' => 'Prioridad', 'op' => $prio],
        ]],
        'pim/Task/create' => ['nombre' => 'Orden nueva', 'ayuda' => 'Se creó una orden de trabajo.', 'requiere' => 'pim', 'campos' => [
            'prioridad' => ['t' => 'Prioridad', 'op' => $prio],
            'tipo_mant' => ['t' => 'Tipo de mantenimiento', 'op' => ['correctivo-inmediato' => 'Correctivo inmediato', 'correctivo-diferido' => 'Correctivo diferido',
                'preventivo-sistematico' => 'Preventivo sistemático', 'preventivo-condicional' => 'Preventivo condicional', 'predictivo' => 'Predictivo', 'mejora' => 'Mejora',
                'ensayo-reglamentario' => 'Ensayo reglamentario', 'verificacion-tecnica-pma' => 'Verificación técnica']],
        ]],
        'pim/Task/update' => ['nombre' => 'Orden que cambia de estado', 'ayuda' => 'Una orden pasa a otro estado (por ejemplo, finalizada o en espera).', 'requiere' => 'pim', 'campos' => [
            'estado' => ['t' => 'Estado nuevo', 'op' => $estados, 'defecto' => ['finalizada']],
            'prioridad' => ['t' => 'Prioridad', 'op' => $prio],
        ]],
    ];
}

/** ¿El evento cumple la condición de la regla? Cada campo elegido es una lista de valores aceptados; «texto» busca en lo que trae el evento. */
function cumple(array $cond, array $datos): bool {
    foreach ($cond as $k => $v) {
        if ($k === 'texto') {
            $t = mb_strtolower(trim((string) $v));
            if ($t !== '' && !str_contains(mb_strtolower(implode(' ', array_map(fn($x) => is_scalar($x) ? (string) $x : '', $datos))), $t)) return false;
            continue;
        }
        $lista = array_map('strval', (array) $v);
        if ($lista && !in_array((string) ($datos[$k] ?? ''), $lista, true)) return false;
    }
    return true;
}

/** Texto del aviso, igual para todos los canales: título, renglones «dato: valor» y un nivel que decide el color. */
function componer(string $tema, array $d): array {
    $f = fuentes()[$tema] ?? null;
    $op = fn(string $campo, mixed $v) => $f['campos'][$campo]['op'][(string) $v] ?? (string) $v;
    $r = [];
    $add = function (string $k, mixed $v) use (&$r) { if ($v !== null && $v !== '') $r[] = [$k, is_float($v) ? rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',') : (string) $v]; };
    if ($tema === 'alarmas/cambio') {
        $ev = (string) ($d['evento'] ?? '');
        $titulo = 'Alarma ' . ($d['etiqueta'] ?? '') . ': ' . mb_strtolower($op('evento', $ev));
        $add('Alarma', $d['etiqueta'] ?? null); $add('Qué pasó', $op('evento', $ev)); $add('Mensaje', $d['mensaje'] ?? null);
        $add('Valor', $d['valor'] ?? null); $add('Punto', $d['punto'] ?? null); $add('Prioridad', isset($d['prioridad']) ? $op('prioridad', $d['prioridad']) : null);
        if (!in_array($ev, ['activada', 'retorno'], true)) $add('Usuario', $d['usuario'] ?? null);
        $nivel = $ev === 'activada' ? ((int) ($d['prioridad'] ?? 4) <= 2 ? 'critico' : 'alerta') : ($ev === 'retorno' ? 'ok' : 'info');
        $clave = 'alarma:' . ($d['id'] ?? '?') . ':' . $ev;
    } else {
        $orden = str_starts_with($tema, 'pim/Task/');
        $que = $orden ? 'Orden' : 'Solicitud';
        $num = (string) ($d['numero'] ?? $d['id'] ?? '');
        $titulo = $tema === 'pim/Task/update' ? "$que $num: " . mb_strtolower($op('estado', $d['estado'] ?? '')) : "$que nueva $num";
        $add($que, $num); $add('Estado', isset($d['estado']) ? ($orden ? (fuentes()['pim/Task/update']['campos']['estado']['op'][$d['estado']] ?? $d['estado']) : $d['estado']) : null);
        $add('Prioridad', isset($d['prioridad']) ? (fuentes()['pim/Task/create']['campos']['prioridad']['op'][$d['prioridad']] ?? $d['prioridad']) : null);
        $add('Tipo', isset($d['tipo_mant']) ? (fuentes()['pim/Task/create']['campos']['tipo_mant']['op'][$d['tipo_mant']] ?? $d['tipo_mant']) : null);
        $add('Detalle', $d['descripcion'] ?? null);
        $nivel = in_array($d['prioridad'] ?? '', ['stat', 'asap'], true) ? 'critico' : (in_array($d['estado'] ?? '', ['finalizada', 'conformada'], true) ? 'ok' : 'info');
        $clave = ($orden ? 'orden:' : 'solicitud:') . ($d['id'] ?? '?') . ':' . ($d['estado'] ?? 'alta');
    }
    return ['titulo' => mb_substr($titulo, 0, 190), 'renglones' => $r, 'nivel' => $nivel, 'clave' => mb_substr($clave, 0, 140)];
}

/** Destinatarios de una regla, validados según el canal. */
function destinos(string $canal, string $texto): array {
    $l = array_values(array_unique(array_filter(array_map('trim', preg_split($canal === 'webhook' ? '/[\s,;]+/' : '/[,;\s]+/', $texto) ?: []))));
    if (!$l) throw new Fallo(422, 'Falta a quién avisar');
    if (count($l) > 20) throw new Fallo(422, 'Hasta 20 destinatarios por regla');
    foreach ($l as $d) {
        if (mb_strlen($d) > 400) throw new Fallo(422, 'Destinatario demasiado largo');
        $ok = match ($canal) {
            'correo' => (bool) filter_var($d, FILTER_VALIDATE_EMAIL),
            'telegram' => (bool) preg_match('/^(-?\d{4,20}|@[A-Za-z0-9_]{4,40})$/', $d),
            'webhook' => (bool) preg_match('#^https?://[^\s]+$#i', $d) && filter_var($d, FILTER_VALIDATE_URL),
            default => false,
        };
        if (!$ok) throw new Fallo(422, match ($canal) {
            'correo' => "«{$d}» no es un correo válido",
            'telegram' => "«{$d}» no es un chat de Telegram: va el número de chat o @canal",
            default => "«{$d}» no es una dirección http(s) válida",
        });
    }
    return $l;
}

function validar_regla(array $c, bool $nueva): array {
    $f = [];
    if ($nueva || isset($c['nombre'])) { $f['nombre'] = trim((string) ($c['nombre'] ?? '')); if ($f['nombre'] === '' || mb_strlen($f['nombre']) > 80) throw new Fallo(422, 'Nombre de la regla: de 1 a 80 caracteres'); }
    if ($nueva || isset($c['tema'])) { $f['tema'] = (string) ($c['tema'] ?? ''); if (!isset(fuentes()[$f['tema']])) throw new Fallo(422, 'Elegí de qué querés que te avise'); }
    if ($nueva || isset($c['canal'])) { $f['canal'] = (string) ($c['canal'] ?? ''); if (!isset(CANALES[$f['canal']])) throw new Fallo(422, 'Canal: correo, telegram o webhook'); }
    if ($nueva || isset($c['destino'])) {
        $canal = $f['canal'] ?? null;
        if ($canal === null) throw new Fallo(422, 'Para cambiar los destinatarios indicá también el canal');
        $f['destino'] = implode(', ', destinos($canal, (string) ($c['destino'] ?? '')));
        if (mb_strlen($f['destino']) > 600) throw new Fallo(422, 'Demasiados destinatarios para una regla: repartilos en dos');
    } elseif (isset($f['canal'])) throw new Fallo(422, 'Para cambiar el canal indicá también los destinatarios');
    if ($nueva || array_key_exists('condicion', $c)) {
        $tema = $f['tema'] ?? null; if ($tema === null) throw new Fallo(422, 'Para cambiar la condición indicá también el tema');
        $cond = [];
        foreach ((array) ($c['condicion'] ?? []) as $k => $v) {
            if ($k === 'texto') { $t = mb_substr(trim((string) $v), 0, 80); if ($t !== '') $cond['texto'] = $t; continue; }
            $campo = fuentes()[$tema]['campos'][$k] ?? throw new Fallo(422, "La condición «{$k}» no existe para ese aviso");
            $vs = array_values(array_unique(array_map('strval', (array) $v)));
            foreach ($vs as $x) if (!isset($campo['op'][$x])) throw new Fallo(422, "Valor «{$x}» inválido en «{$campo['t']}»");
            if ($vs) $cond[$k] = $vs;
        }
        $f['condicion'] = json_encode($cond, JSON_UNESCAPED_UNICODE);
    }
    if ($nueva || isset($c['silencio_min'])) { $f['silencio_min'] = (int) ($c['silencio_min'] ?? 0); if ($f['silencio_min'] < 0 || $f['silencio_min'] > 10080) throw new Fallo(422, 'Silencio: de 0 a 10080 minutos (una semana)'); }
    if ($nueva || isset($c['activa'])) $f['activa'] = !isset($c['activa']) || $c['activa'] ? 1 : 0;
    return $f;
}

function vista_regla(array $r): array {
    $r['id'] = (int) $r['id']; $r['activa'] = (bool) $r['activa']; $r['silencio_min'] = (int) $r['silencio_min'];
    $r['condicion'] = json_decode((string) $r['condicion'], true) ?: [];
    $f = fuentes()[$r['tema']] ?? null;
    $r['tema_nombre'] = $f['nombre'] ?? $r['tema'];
    $partes = [];
    foreach ($r['condicion'] as $k => $v) $partes[] = $k === 'texto' ? "contiene «{$v}»" : implode(' o ', array_map(fn($x) => $f['campos'][$k]['op'][$x] ?? $x, (array) $v));
    $r['cuando'] = $r['tema_nombre'] . ($partes ? ': ' . implode(' · ', $partes) : ' (todas)');
    $r['canal_nombre'] = CANALES[$r['canal']] ?? $r['canal'];
    return $r;
}

/** Un evento del bus contra las reglas activas de su tema: deja en cola un aviso por destinatario. */
function encolar(array $ev, array $cfg): int {
    $n = 0;
    if (!empty($ev['datos']['legado'])) return 0;   // una carga desde otro sistema no avisa: serían miles de avisos por órdenes viejas
    foreach (filas('SELECT * FROM ntf_regla WHERE tema = ? AND activa = 1', [$ev['tema']]) as $r) {
        $datos = (array) ($ev['datos'] ?? []);
        if (!cumple(json_decode((string) $r['condicion'], true) ?: [], $datos)) continue;
        $n += encolar_regla($r, $ev['tema'], $datos, (int) $ev['id']);
    }
    return $n;
}
function encolar_regla(array $r, string $tema, array $datos, int $evento_id, bool $prueba = false): int {
    $m = componer($tema, $datos);
    if ($prueba) { $m['titulo'] = 'PRUEBA · ' . $m['titulo']; $m['clave'] = 'prueba:' . $evento_id; }
    $n = 0;
    foreach (array_map('trim', explode(',', (string) $r['destino'])) as $dest) {
        if ($dest === '') continue;
        // silencio: mismo motivo, misma regla y mismo destinatario dentro de la ventana → queda registrado, no se envía
        $callado = !$prueba && (int) $r['silencio_min'] > 0 && valor("SELECT 1 FROM ntf_envio WHERE regla_id = ? AND clave = ? AND destino = ? AND estado <> 'descartado'
            AND creado > NOW() - INTERVAL ? MINUTE LIMIT 1", [(int) $r['id'], $m['clave'], $dest, (int) $r['silencio_min']]);
        $s = q('INSERT IGNORE INTO ntf_envio (regla_id, evento_id, clave, tema, asunto, datos, canal, destino, estado, proximo, error) VALUES (?,?,?,?,?,?,?,?,?,NOW(),?)',
            [(int) $r['id'], $evento_id, $m['clave'], $tema, $m['titulo'], json_encode(['renglones' => $m['renglones'], 'nivel' => $m['nivel'], 'evento' => $datos, 'regla' => $r['nombre']], JSON_UNESCAPED_UNICODE),
             $r['canal'], $dest, $callado ? 'descartado' : 'pendiente', $callado ? "Silenciado: ya se avisó en los últimos {$r['silencio_min']} min" : null]);
        $n += $s->rowCount();
    }
    return $n;
}

// ------------------------------------------------------------------------------------------------ canales
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function texto_plano(string $titulo, array $renglones, string $pie): string {
    $t = $titulo . "\n" . str_repeat('-', min(60, mb_strlen($titulo))) . "\n";
    foreach ($renglones as [$k, $v]) $t .= "$k: $v\n";
    return $t . "\n" . $pie . "\n";
}
/** Correo HTML claro, legible en el celular, sin imágenes externas. */
function html_correo(string $titulo, array $renglones, string $nivel, string $pie, string $url): string {
    $col = ['critico' => ['#c62828', '#e53935'], 'alerta' => ['#e65100', '#fb8c00'], 'ok' => ['#2e7d32', '#43a047'], 'info' => ['#0d47a1', '#1e88e5']][$nivel] ?? ['#0d47a1', '#1e88e5'];
    $tr = '';
    foreach ($renglones as $i => [$k, $v]) $tr .= '<tr><td style="padding:9px 14px;color:#5b6b7f;font-size:13px;white-space:nowrap;vertical-align:top;border-top:1px solid #e6edf5">' . esc($k)
        . '</td><td style="padding:9px 14px;color:#12263f;font-size:14px;font-weight:600;border-top:1px solid #e6edf5">' . nl2br(esc($v)) . '</td></tr>';
    $boton = $url !== '' ? '<p style="margin:18px 0 0"><a href="' . esc($url) . '" style="display:inline-block;background:' . $col[0] . ';color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-size:14px;font-weight:600">Abrir iProy</a></p>' : '';
    return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;background:#eef4fb;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif"><div style="max-width:560px;margin:0 auto;padding:16px">'
        . '<div style="background:' . $col[0] . ';background:linear-gradient(135deg,' . $col[0] . ',' . $col[1] . ');color:#fff;border-radius:12px 12px 0 0;padding:18px 20px;font-size:18px;font-weight:700;line-height:1.3">' . esc($titulo) . '</div>'
        . '<div style="background:#fff;border-radius:0 0 12px 12px;padding:6px 6px 18px;border:1px solid #dbe6f3;border-top:0"><table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse">' . $tr . '</table>'
        . '<div style="padding:0 14px">' . $boton . '</div></div>'
        . '<p style="color:#7b8a9c;font-size:12px;text-align:center;margin:12px 0 0">' . esc($pie) . '</p></div></body></html>';
}

/** Entrega un aviso por su canal. Devuelve null si salió bien o el motivo de la falla. */
function entregar(array $e, array $cfg): ?string {
    $d = json_decode((string) $e['datos'], true) ?: [];
    $renglones = (array) ($d['renglones'] ?? []); $nivel = (string) ($d['nivel'] ?? 'info');
    $n = nodo();
    $pie = 'Aviso automático de ' . ($n['nombre'] ?? 'iProy') . ' · regla «' . ($d['regla'] ?? '') . '» · ' . date('d/m/Y H:i', strtotime((string) $e['creado']));
    try {
        if ($e['canal'] === 'correo') {
            $url = (string) (correo_cfg()['url_app'] ?? '');
            enviar_correo($e['destino'], $e['asunto'], texto_plano($e['asunto'], $renglones, $pie), html_correo($e['asunto'], $renglones, $nivel, $pie, $url));
            return null;
        }
        if ($e['canal'] === 'telegram') {
            $tok = (string) secretos()['telegram_token'];
            if ($tok === '') return 'Falta cargar el token del bot de Telegram';
            $ico = ['critico' => '🔴', 'alerta' => '🟠', 'ok' => '🟢', 'info' => '🔵'][$nivel] ?? '🔵';
            $t = "$ico <b>" . esc($e['asunto']) . "</b>\n";
            foreach ($renglones as [$k, $v]) $t .= esc($k) . ': <b>' . esc($v) . "</b>\n";
            $t .= "\n<i>" . esc($pie) . '</i>';
            $base = rtrim((string) ($cfg['telegram_api'] ?? '') ?: 'https://api.telegram.org', '/');
            $r = http('POST', "$base/bot$tok/sendMessage", json_encode(['chat_id' => $e['destino'], 'text' => mb_substr($t, 0, 4000), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]),
                ['Content-Type' => 'application/json'], 15);
            if ($r['http'] === 200) return null;
            $j = json_decode($r['cuerpo'], true);
            return 'Telegram: ' . ($r['error'] ?: ($j['description'] ?? ('respuesta ' . $r['http'])));
        }
        if ($e['canal'] === 'webhook') {
            $cuerpo = json_encode(['id' => (int) $e['id'], 'tema' => $e['tema'], 'titulo' => $e['asunto'], 'nivel' => $nivel, 'renglones' => array_map(fn($x) => ['dato' => $x[0], 'valor' => $x[1]], $renglones),
                'texto' => texto_plano($e['asunto'], $renglones, $pie), 'evento' => $d['evento'] ?? new \stdClass(), 'nodo' => $n['id'] ?? null, 'creado' => $e['creado']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $cab = ['Content-Type' => 'application/json', 'User-Agent' => 'iProy-Avisos/1.0', 'X-iProy-Aviso' => (string) $e['id']];
            if (($sec = (string) secretos()['webhook_secreto']) !== '') $cab['X-iProy-Firma'] = 'sha256=' . hash_hmac('sha256', $cuerpo, $sec);
            $r = http('POST', $e['destino'], $cuerpo, $cab, 15);
            return $r['http'] >= 200 && $r['http'] < 300 ? null : 'Webhook: ' . ($r['error'] ?: 'respuesta ' . $r['http']);
        }
        return 'Canal desconocido';
    } catch (\Throwable $x) { return mb_substr($x->getMessage(), 0, 280); }
}

/** Procesa la cola. Devuelve cuántos salieron, cuántos fallaron en este paso y cuántos siguen esperando. */
function enviar_pendientes(array $cfg, ?int $solo_id = null): array {
    $esperas = array_values(array_map('intval', (array) ($cfg['reintentos_min'] ?? [1, 5, 15, 60]))) ?: [5];
    $max = max(1, (int) ($cfg['max_intentos'] ?? 5));
    $lote = $solo_id !== null ? filas("SELECT * FROM ntf_envio WHERE id = ? AND estado = 'pendiente'", [$solo_id])
        : filas("SELECT * FROM ntf_envio WHERE estado = 'pendiente' AND proximo <= NOW() ORDER BY proximo, id LIMIT " . max(1, min(200, (int) ($cfg['por_tick'] ?? 20))));
    $ok = 0; $mal = 0;
    foreach ($lote as $e) {
        // se marca el intento antes de salir: si el proceso se corta a mitad de un envío, el próximo paso no lo repite enseguida
        $i = (int) $e['intentos'] + 1;
        q('UPDATE ntf_envio SET intentos = ?, proximo = NOW() + INTERVAL ? MINUTE WHERE id = ?', [$i, $esperas[min($i, count($esperas)) - 1], (int) $e['id']]);
        $err = entregar($e, $cfg);
        if ($err === null) { q("UPDATE ntf_envio SET estado = 'enviado', enviado = NOW(), error = NULL WHERE id = ?", [(int) $e['id']]); $ok++; continue; }
        $mal++;
        q('UPDATE ntf_envio SET estado = ?, error = ? WHERE id = ?', [$i >= $max ? 'fallido' : 'pendiente', mb_substr($err, 0, 300), (int) $e['id']]);
        if ($i >= $max) asentar('aviso.fallido', ['canal' => $e['canal'], 'asunto' => $e['asunto'], 'error' => mb_substr($err, 0, 200)], (string) $e['id']);
    }
    return ['enviados' => $ok, 'fallidos' => $mal, 'pendientes' => (int) valor("SELECT COUNT(*) FROM ntf_envio WHERE estado = 'pendiente'")];
}
function depurar(array $cfg): int {
    $d = max(7, (int) ($cfg['retener_dias'] ?? 90));
    return q("DELETE FROM ntf_envio WHERE estado <> 'pendiente' AND creado < NOW() - INTERVAL ? DAY LIMIT 500", [$d])->rowCount();
}

/** Configuración visible: los secretos se informan como «cargado: sí/no», nunca su valor. */
function config_publica(array $cfg): array {
    $c = correo_cfg(); $s = secretos();
    return [
        'correo' => ['listo' => (bool) $c['activo'], 'remitente' => (string) $c['remitente']],
        'telegram' => ['listo' => $s['telegram_token'] !== ''],
        'webhook' => ['listo' => true, 'firmado' => $s['webhook_secreto'] !== ''],
        'reintentos_min' => array_values((array) $cfg['reintentos_min']), 'max_intentos' => (int) $cfg['max_intentos'], 'retener_dias' => (int) $cfg['retener_dias'],
    ];
}
function guardar_config(array $c): array {
    $archivo = \Nucleo\leer_json(\Nucleo\dir_datos('modulos') . '/notificaciones.json');
    $sec = secretos(); $toca = false;
    foreach (SECRETOS as $k) {
        if (isset($c[$k]) && trim((string) $c[$k]) !== '') {
            $v = trim((string) $c[$k]);
            if ($k === 'telegram_token' && !preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/', $v)) throw new Fallo(422, 'El token del bot no tiene el formato que entrega BotFather (números:letras)');
            if ($k === 'webhook_secreto' && (strlen($v) < 16 || strlen($v) > 128)) throw new Fallo(422, 'La clave de firma del webhook debe tener entre 16 y 128 caracteres');
            $sec[$k] = $v; $toca = true;
        }
        if (!empty($c['borrar_' . $k])) { $sec[$k] = ''; $toca = true; }
    }
    if (isset($c['max_intentos'])) { $n = (int) $c['max_intentos']; if ($n < 1 || $n > 10) throw new Fallo(422, 'Intentos: de 1 a 10'); $archivo['max_intentos'] = $n; }
    if (isset($c['retener_dias'])) { $n = (int) $c['retener_dias']; if ($n < 7 || $n > 730) throw new Fallo(422, 'Historial: de 7 a 730 días'); $archivo['retener_dias'] = $n; }
    if ($toca) secretos($sec);
    $cfg = cfg_modulo('notificaciones', $archivo);
    asentar('avisos.config', ['telegram' => $sec['telegram_token'] !== '', 'webhook_firmado' => $sec['webhook_secreto'] !== '', 'max_intentos' => $cfg['max_intentos'], 'retener_dias' => $cfg['retener_dias']]);
    return config_publica($cfg);
}

function resumen(array $cfg): array {
    $por = [];
    foreach (filas("SELECT estado, COUNT(*) n FROM ntf_envio WHERE creado > NOW() - INTERVAL 7 DAY GROUP BY estado") as $x) $por[$x['estado']] = (int) $x['n'];
    return [
        'reglas' => (int) valor('SELECT COUNT(*) FROM ntf_regla'), 'reglas_activas' => (int) valor('SELECT COUNT(*) FROM ntf_regla WHERE activa = 1'),
        'pendientes' => (int) valor("SELECT COUNT(*) FROM ntf_envio WHERE estado = 'pendiente'"),
        'enviados_24h' => (int) valor("SELECT COUNT(*) FROM ntf_envio WHERE estado = 'enviado' AND enviado > NOW() - INTERVAL 1 DAY"),
        'enviados_7d' => $por['enviado'] ?? 0, 'fallidos_7d' => $por['fallido'] ?? 0, 'silenciados_7d' => $por['descartado'] ?? 0,
        'canales' => config_publica($cfg),
    ];
}

/** Datos de ejemplo para el botón «Probar»: el aviso llega con la misma forma que uno real. */
function ejemplo(string $tema): array {
    return match ($tema) {
        'alarmas/cambio' => ['id' => 0, 'etiqueta' => 'EJEMPLO-PRESION-BAJA', 'punto' => 'PRESION', 'evento' => 'activada', 'de' => 'normal', 'a' => 'no-reconocida', 'prioridad' => 2, 'mensaje' => 'Presión por debajo del mínimo (ejemplo)', 'valor' => 3.4],
        'pim/ServiceRequest/create' => ['id' => 'ejemplo', 'numero' => 'SOL-EJEMPLO', 'estado' => 'active', 'prioridad' => 'urgent', 'descripcion' => 'Pérdida de agua en el local 12 (ejemplo)'],
        'pim/Task/create' => ['id' => 'ejemplo', 'numero' => 'OT-EJEMPLO', 'estado' => 'solicitada', 'prioridad' => 'urgent', 'tipo_mant' => 'correctivo-inmediato', 'descripcion' => 'Revisar pérdida de agua en el local 12 (ejemplo)'],
        default => ['id' => 'ejemplo', 'numero' => 'OT-EJEMPLO', 'estado' => 'finalizada', 'prioridad' => 'routine', 'tipo_mant' => 'correctivo-diferido', 'descripcion' => 'Cambio de luminaria (ejemplo)'],
    };
}
