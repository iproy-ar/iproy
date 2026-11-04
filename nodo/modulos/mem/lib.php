<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Módulo «mem»: el Gestor de Mantenimiento como DMIC de IHE MEM-DMC (suplemento Rev. 1.5, 19-09-2024).
 *
 * PCD-15 «Communicate Device Management Information Observation»: HL7 v2.6 ORU^R44^ORU_R44 (Rev. 1.3: ORU^R01), sin PID ni PV1,
 * OBR-4 = 69135^MDC_OBS_MEM^MDC, MSH-21 = IHE_PCD_015. Se traduce a FHIR con la API del Servidor PIM (los mismos roles,
 * validaciones, historial y Provenance que cualquier cliente externo):
 *   EUI-64 (OBX-18, OBR-3 o MSH-3)       → Device (pim-pma) con identifier sid/eui64; se da de alta si no existe
 *   67880 MDC_ATTR_ID_MODEL               → Device.manufacturer y Device.modelNumber
 *   cada otro OBX                         → Observation (pim-medicion) con el código MDC; el DeviceMetric se crea con la primera medición
 *   68513 MDC_ATTR_LS_LOCATION (PL)       → Device.location si el local existe
 *   autoprueba con falla                  → ServiceRequest (reasonReference = la Observation) → orden correctiva
 *   batería ≤ «bateria_critica» (%)       → ServiceRequest → orden correctiva (1.4.0), sin depender de una alarma configurada
 *   MLLP sobre TLS (1.4.0)                → «tls»: true; certificado propio del nodo o el indicado; certificado de cliente opcional
 *   alimentación, batería, autoprueba     → telemetria/medicion (ALIMENTACION, BATERIA, AUTOPRUEBA) para las alarmas ISA-18.2
 * Inconsistencias del suplemento que se aceptan (PIM 0.9.2, 9.8): hora en OBX-14 o en OBX-7; nombre en 0^MDCX_LS_ATTR_NAME o 68512.
 */
declare(strict_types=1);

namespace Modulos\Mem;

use Nucleo\Fallo;
use function Nucleo\{pim, q, cfg_modulo, modulo, publicar, dir_datos};

const SB = "\x0B"; const EB = "\x1C"; const CR = "\r";
const UNIDADES_MDC = ['MDC_DIM_CENTI_M' => 'cm', 'MDC_DIM_PERCENT' => '%', 'MDC_DIM_MIN' => 'min', 'MDC_DIM_HR' => 'h', 'MDC_DIM_SEC' => 's',
    'MDC_DIM_X_VOLT' => 'V', 'MDC_DIM_MILLI_VOLT' => 'mV', 'MDC_DIM_X_AMPS' => 'A', 'MDC_DIM_MILLI_AMPS' => 'mA', 'MDC_DIM_DEGC' => 'Cel',
    'MDC_DIM_DIMLESS' => '1', 'MDC_DIM_MILLI_AMP_HR' => 'mA.h', 'MDC_DIM_X_WATT_HR' => 'W.h'];
const SID = 'https://iproy.com.ar/fhir/sid/';
const SD = 'https://iproy.com.ar/fhir/StructureDefinition/';
const CS = 'https://iproy.com.ar/fhir/CodeSystem/';

// ------------------------------------------------------------------ HL7 v2
/** Mensaje ER7 → lista de segmentos [nombre, campos[]] con la numeración de HL7 (MSH-1 es el separador). */
function partir(string $msg): array {
    $msg = trim(str_replace(["\r\n", "\n"], CR, $msg), CR . " \t\x0B\x1C");
    if (!str_starts_with($msg, 'MSH') || strlen($msg) < 8) throw new Fallo(422, 'El mensaje no empieza con MSH');
    $fs = $msg[3];
    $seg = [];
    foreach (explode(CR, $msg) as $l) {
        if (trim($l) === '') continue;
        $c = explode($fs, $l);
        $n = $c[0];
        if ($n === 'MSH') array_splice($c, 1, 0, [$fs]);                           // MSH-1 = separador, MSH-2 = caracteres de codificación
        $seg[] = [$n, $c];
    }
    return $seg;
}
function campo(array $s, int $n): string { return (string) ($s[1][$n] ?? ''); }
function comp(string $campo, int $n): string { return (string) (explode('^', explode('~', $campo)[0])[$n - 1] ?? ''); }
function seg(array $segs, string $nombre): ?array { foreach ($segs as $s) if ($s[0] === $nombre) return $s; return null; }
/** TS de HL7 v2 (AAAAMMDDhhmmss[.s][±zzzz]) → dateTime FHIR. */
function ts(string $v): ?string {
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})?(\d{2})?(\d{2})?(?:\.\d+)?([+-]\d{4})?$/', trim($v), $m)) return null;
    $z = isset($m[7]) && $m[7] !== '' ? substr($m[7], 0, 3) . ':' . substr($m[7], 3) : '-03:00';
    return sprintf('%s-%s-%sT%s:%s:%s%s', $m[1], $m[2], $m[3], ($m[4] ?? '') ?: '00', ($m[5] ?? '') ?: '00', ($m[6] ?? '') ?: '00', $z);
}
/** EUI-64 dentro de un campo CX/EI: el componente anterior al tipo «EUI-64». */
function eui(string $campo): ?string {
    $c = explode('^', $campo);
    foreach ($c as $i => $x) if (strtoupper($x) === 'EUI-64' && $i > 0 && preg_match('/^[0-9A-Fa-f]{16}$/', $c[$i - 1])) return strtoupper($c[$i - 1]);
    return null;
}
function ack(array $segs, string $codigo, string $texto = '', string $err = ''): string {
    $msh = seg($segs, 'MSH');
    $ctrl = $msh ? campo($msh, 10) : '';
    $ver = $msh && campo($msh, 12) !== '' ? campo($msh, 12) : '2.6';
    $trig = $msh ? (comp(campo($msh, 9), 2) ?: 'R44') : 'R44';
    $l = ['MSH|^~\&|' . ($msh ? campo($msh, 5) : '') . '|' . ($msh ? campo($msh, 6) : '') . '|' . ($msh ? campo($msh, 3) : '') . '|' . ($msh ? campo($msh, 4) : '')
          . '|' . date('YmdHisO') . '||ACK^' . $trig . '^ACK|' . substr(bin2hex(random_bytes(8)), 0, 16) . '|P|' . $ver,
          'MSA|' . $codigo . '|' . $ctrl . ($texto !== '' ? '|' . str_replace(['|', '^', '~', '\\', '&', "\r", "\n"], ' ', mb_substr($texto, 0, 80)) : '')];
    if ($codigo !== 'AA') $l[] = 'ERR|||' . ($err ?: ($codigo === 'AR' ? '200^Unsupported message type^HL70357' : '207^Application internal error^HL70357')) . '|E||||'
        . str_replace(['|', '^', '~', '\\', '&', "\r", "\n"], ' ', mb_substr($texto, 0, 200));
    return implode(CR, $l) . CR;
}

// ------------------------------------------------------------------ traducción a FHIR
/** Procesa un PCD-15 y devuelve el ACK (nunca lanza: los errores van en el ACK). */
function procesar(string $msg, string $origen = 'mllp'): string {
    $segs = [];
    try {
        $segs = partir($msg);
        $msh = seg($segs, 'MSH');
        $tipo = campo($msh, 9);
        $c = cfg_modulo('mem');
        $okTipo = str_starts_with($tipo, 'ORU^R44') || (!empty($c['aceptar_r01']) && str_starts_with($tipo, 'ORU^R01'));
        if (!$okTipo) { registrar($segs, 'AR', "Tipo no soportado: $tipo", $origen); return ack($segs, 'AR', "Tipo no soportado: $tipo (se espera ORU^R44)"); }
        if (seg($segs, 'PID') || seg($segs, 'PV1')) { registrar($segs, 'AR', 'PCD-15 no admite PID ni PV1', $origen); return ack($segs, 'AR', 'PCD-15 no lleva datos de pacientes (PID/PV1)', '100^Segment sequence error^HL70357'); }
        $r = traducir($segs, $c);
        if (!str_contains(campo($msh, 21), 'IHE_PCD_015')) $r['detalle'] .= ' · MSH-21 sin IHE_PCD_015';   // se registra, no se rechaza (gateways Rev. 1.3)
        registrar($segs, 'AA', $r['detalle'], $origen, $r['eui'], $r['device'], $r['n']);
        return ack($segs, 'AA');
    } catch (\Throwable $e) {
        try { registrar($segs, 'AE', $e->getMessage(), $origen); } catch (\Throwable) {}
        return ack($segs, 'AE', $e->getMessage());
    }
}
function registrar(array $segs, string $ack, string $detalle, string $origen, ?string $eui = null, ?string $dev = null, int $n = 0): void {
    $msh = $segs ? seg($segs, 'MSH') : null;
    q('INSERT INTO mem_mensaje (ts, control, tipo, eui64, device_id, observaciones, ack, detalle, origen) VALUES (NOW(),?,?,?,?,?,?,?,?)',
      [$msh ? mb_substr(campo($msh, 10), 0, 40) : null, $msh ? mb_substr(campo($msh, 9), 0, 30) : null, $eui, $dev, $n, $ack, mb_substr($detalle, 0, 255), mb_substr($origen, 0, 60)]);
}

function traducir(array $segs, array $c): array {
    $msh = seg($segs, 'MSH'); $obr = seg($segs, 'OBR');
    if (!$obr || comp(campo($obr, 4), 1) !== '69135') throw new Fallo(422, 'OBR-4 debe ser 69135^MDC_OBS_MEM^MDC');
    $obx = array_values(array_filter($segs, fn($s) => $s[0] === 'OBX'));
    // EUI-64: OBX-18 del equipo, OBR-3, OBR-2 o MSH-3
    $eui = null;
    foreach ($obx as $o) if ($eui = eui(campo($o, 18))) break;
    if (!$eui) foreach ($obx as $o) if (comp(campo($o, 3), 1) === '67972' && ($eui = eui(campo($o, 5)))) break;   // MDC_ATTR_SYS_ID (0.9.4)
    $eui ??= eui(campo($obr, 3)) ?? eui(campo($obr, 2)) ?? eui(campo($msh, 3));
    if (!$eui) throw new Fallo(422, 'No se encontró el EUI-64 del equipo (OBX-18, OBR-3 o MSH-3)');
    $tsDef = ts(campo($obr, 7)) ?? ts(campo($msh, 7)) ?? date('c');
    $fab = null; $mod = null; $nombre = null;
    foreach ($obx as $o) {
        $cod = comp(campo($o, 3), 1); $ref = comp(campo($o, 3), 2);
        if ($cod === '67880') {                                                   // «manufacturer=X model=Y» o texto libre
            $v = campo($o, 5);
            if (preg_match('/manufacturer\s*=\s*(.+?)\s+model\s*=\s*(.+)$/i', $v, $m)) { $fab = trim($m[1]); $mod = trim($m[2]); } else $mod = trim($v);
        }
        if ($cod === '68512' || $ref === 'MDCX_LS_ATTR_NAME') $nombre = trim(campo($o, 5));
    }
    $nombre = $nombre ?: trim(comp(campo($obr, 3), 2)) ?: ($mod ?: 'Equipo ' . $eui);
    // Device
    $r = pim('GET', 'Device?identifier=' . rawurlencode(SID . 'eui64|' . $eui) . '&_count=1', null, ['rol' => 'GM', 'exigir' => true]);
    $dev = $r['cuerpo']['entry'][0]['resource'] ?? null;
    if (!$dev) {
        if (empty($c['alta_automatica'])) throw new Fallo(404, "El equipo $eui no está en el inventario (alta automática deshabilitada)");
        $dev = pim('POST', 'Device', array_filter(['resourceType' => 'Device', 'meta' => ['profile' => [SD . 'pim-pma'], 'tag' => [['system' => CS . 'pim-origen', 'code' => 'mem-dmc']]],
            'identifier' => [['system' => SID . 'activo', 'value' => 'MEM-' . $eui], ['system' => SID . 'eui64', 'value' => $eui]],
            'status' => 'active', 'manufacturer' => $fab, 'modelNumber' => $mod,
            'deviceName' => [['name' => mb_substr($nombre, 0, 200), 'type' => 'user-friendly-name']]], fn($x) => $x !== null), ['rol' => 'GM', 'exigir' => true])['cuerpo'];
    }
    $devRef = 'Device/' . $dev['id'];
    $codigo = '';
    foreach ((array) ($dev['identifier'] ?? []) as $i) if (($i['system'] ?? '') === SID . 'activo') $codigo = (string) $i['value'];
    // Observaciones
    $entradas = []; $derivados = []; $falla = null; $critica = null; $ubic = null;
    foreach ($obx as $o) {
        $cod = comp(campo($o, 3), 1); $ref = comp(campo($o, 3), 2); $vt = campo($o, 2); $v = campo($o, 5);
        if (in_array($cod, ['67880', '67972'], true) || ($v === '' && $vt !== 'NM')) continue;   // identidad del equipo o nodo MDS sin valor
        $code = ctype_digit($cod) && $cod !== '0' ? ['coding' => [['system' => 'urn:iso:std:iso:11073:10101', 'code' => $cod, 'display' => $ref ?: null]]] : ['text' => $ref ?: 'MDCX_SIN_CODIGO'];
        $code['coding'] = isset($code['coding']) ? [array_filter($code['coding'][0], fn($x) => $x !== null)] : null; $code = array_filter($code, fn($x) => $x !== null);
        $t = ts(campo($o, 14)) ?? ts(campo($o, 7)) ?? $tsDef;
        $ob = ['resourceType' => 'Observation', 'meta' => ['tag' => [['system' => CS . 'pim-origen', 'code' => 'mem-dmc']]], 'status' => 'final',
               'code' => $code, 'device' => ['reference' => $devRef], 'effectiveDateTime' => $t];
        switch ($vt) {
            case 'NM':
                if (!is_numeric($v)) continue 2;
                $u = comp(campo($o, 6), 2); $uc = UNIDADES_MDC[$u] ?? '1';
                $ob['valueQuantity'] = ['value' => (float) $v, 'unit' => $u ?: '1', 'system' => 'http://unitsofmeasure.org', 'code' => $uc];
                break;
            case 'CWE': case 'CE': case 'CNE':
                $ob['valueCodeableConcept'] = ['text' => comp($v, 2) ?: comp($v, 1)]; break;
            case 'PL':
                $pl = array_values(array_filter(explode('^', $v), fn($x) => trim($x) !== ''));
                $ob['valueString'] = implode(' › ', $pl);
                if ($cod === '68513') $ubic = array_values(array_filter([comp($v, 9), comp($v, 2), comp($v, 1)], fn($x) => trim($x) !== ''));
                break;
            default: $ob['valueString'] = mb_substr($v, 0, 200);
        }
        $entradas[] = ['resource' => $ob, 'request' => ['method' => 'POST', 'url' => 'Observation']];
        // valores normalizados para telemetría y alarmas
        $txt = strtoupper($v . ' ' . $ref);
        if ($cod === '67925') $derivados[] = ['ALIMENTACION', str_contains($txt, 'BATT') ? 1 : (str_contains(strtoupper($v), 'OFF') ? 2 : 0), '1'];
        if (str_contains($ref, 'BATT') && $vt === 'NM' && is_numeric($v)) {
            $derivados[] = ['BATERIA', (float) $v, '%'];
            if ((float) $v <= (float) ($c['bateria_critica'] ?? 20)) $critica = [count($entradas) - 1, (float) $v];
        }
        if (str_contains($ref, 'SELF_TEST') || str_contains($ref, 'SELFTEST')) {
            $mal = (bool) preg_match('/FAIL|ERR|FALL|NOK/', strtoupper($v));
            $derivados[] = ['AUTOPRUEBA', $mal ? 1 : 0, '1'];
            if ($mal) $falla = count($entradas) - 1;
        }
    }
    $ids = [];
    if ($entradas) {
        $b = pim('POST', '', ['resourceType' => 'Bundle', 'type' => 'batch', 'entry' => $entradas], ['rol' => 'FT', 'exigir' => true])['cuerpo'];
        foreach ((array) ($b['entry'] ?? []) as $i => $e) {
            if (!str_starts_with((string) ($e['response']['status'] ?? ''), '201')) throw new Fallo(422, 'Observation rechazada: ' . ($e['response']['outcome']['issue'][0]['diagnostics'] ?? '?'));
            $ids[$i] = $e['resource']['id'];
        }
    }
    if ($codigo !== '' && modulo('telemetria'))
        foreach ($derivados as [$p, $val, $u]) publicar('telemetria/medicion', ['activo' => $codigo, 'punto' => $p, 'valor' => $val, 'unidad' => $u,
            'ts' => date('Y-m-d H:i:s'), 'origen' => 'mem-dmc'], 'mem');
    $detalle = count($ids) . ' observaciones';
    // autoprueba con falla o batería crítica → solicitud y orden correctiva (una sola abierta por equipo y motivo)
    if ($falla !== null && !empty($c['orden_autoprueba'])
        && pedir_orden($devRef, 'Autoprueba con falla (IHE MEM-DMC)', $ids[$falla], 'El equipo ' . $nombre . ' (EUI-64 ' . $eui . ') informó una autoprueba con falla por IHE PCD-15.'))
        $detalle .= ' · orden por autoprueba';
    if ($critica !== null && !empty($c['orden_bateria'])
        && pedir_orden($devRef, 'Batería crítica (IHE MEM-DMC)', $ids[$critica[0]], 'El equipo ' . $nombre . ' (EUI-64 ' . $eui . ') informó batería al ' . $critica[1] . ' % (umbral ' . ($c['bateria_critica'] ?? 20) . ' %) por IHE PCD-15.'))
        $detalle .= ' · orden por batería crítica';
    // ubicación (68513, tipo PL): actualiza Device.location si el local existe
    if ($ubic && !empty($c['actualizar_ubicacion'])) {
        foreach ($ubic as $nom) {
            $l = pim('GET', 'Location?name:exact=' . rawurlencode($nom) . '&_count=1', null, ['rol' => 'GM', 'exigir' => true])['cuerpo']['entry'][0]['resource'] ?? null;
            if (!$l) continue;
            $d = pim('GET', $devRef, null, ['rol' => 'GM', 'exigir' => true])['cuerpo'];
            if (($d['location']['reference'] ?? '') !== 'Location/' . $l['id']) {
                $d['location'] = ['reference' => 'Location/' . $l['id']];
                $u = pim('PUT', $devRef, $d, ['rol' => 'GM', 'if_match' => 'W/"' . ($d['meta']['versionId'] ?? '1') . '"']);
                if ($u['http'] < 300) $detalle .= ' · ubicación: ' . $nom;
            }
            break;
        }
    }
    return ['eui' => $eui, 'device' => $dev['id'], 'n' => count($ids), 'detalle' => $detalle];
}

/** Pide una orden correctiva para el equipo si no hay otra abierta con el mismo motivo. La prioridad la pone el IRU del activo. */
function pedir_orden(string $devRef, string $titulo, string $obsId, string $nota): bool {
    $abiertas = pim('GET', 'Task?focus=' . rawurlencode($devRef) . '&status=requested,received,accepted,ready,in-progress,on-hold&_count=50', null, ['rol' => 'GM', 'exigir' => true])['cuerpo'];
    foreach ((array) ($abiertas['entry'] ?? []) as $e) if (str_contains((string) ($e['resource']['code']['text'] ?? ''), $titulo)) return false;
    pim('POST', 'ServiceRequest', ['resourceType' => 'ServiceRequest', 'status' => 'active', 'intent' => 'order', 'subject' => ['reference' => $devRef],
        'code' => ['text' => $titulo], 'reasonReference' => [['reference' => 'Observation/' . $obsId]], 'note' => [['text' => $nota]],
        'extension' => [['url' => SD . 'pim-estado-declarado', 'valueCoding' => ['system' => CS . 'pim-valores', 'code' => 'degradado']]],
        'requester' => ['display' => 'IHE MEM-DMC']], ['rol' => 'GM', 'exigir' => true]);
    return true;
}

// ------------------------------------------------------------------ servidor MLLP (lo usa tools/residente.php mem)
function ip_permitida(string $ip, array $lista): bool {
    if (!$lista) return true;
    foreach ($lista as $red) {
        if (!str_contains($red, '/')) { if ($ip === $red) return true; continue; }
        [$base, $bits] = explode('/', $red, 2);
        $a = @inet_pton($ip); $b = @inet_pton($base); if ($a === false || $b === false || strlen($a) !== strlen($b)) continue;
        $bits = (int) $bits; $ok = true;
        for ($i = 0; $i < strlen($a) && $bits > 0; $i++, $bits -= 8) { $m = $bits >= 8 ? 0xFF : (0xFF << (8 - $bits)) & 0xFF; if ((ord($a[$i]) & $m) !== (ord($b[$i]) & $m)) { $ok = false; break; } }
        if ($ok) return true;
    }
    return false;
}
/** Atiende conexiones MLLP (varias a la vez) durante $segundos; devuelve la cantidad de mensajes procesados. */
/** Certificado del canal MLLP: el indicado en la configuración o uno propio del nodo (EC P-256, 10 años), en data/nucleo/mem-tls. */
function tls_certificado(array $c): array {
    if (!empty($c['tls_cert']) && !empty($c['tls_clave'])) return [(string) $c['tls_cert'], (string) $c['tls_clave']];
    $d = dir_datos('mem-tls'); $cert = "$d/cert.pem"; $clave = "$d/clave.pem";
    if (!is_file($cert) || !is_file($clave)) {
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']) ?: throw new \RuntimeException('openssl: no se pudo crear la clave TLS');
        $csr = openssl_csr_new(['commonName' => gethostname() ?: 'nodo-pim'], $k, ['digest_alg' => 'sha256']);
        $x = openssl_csr_sign($csr, null, $k, 3650, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        openssl_pkey_export($k, $pem); openssl_x509_export($x, $crt);
        file_put_contents($clave, $pem, LOCK_EX); @chmod($clave, 0600); file_put_contents($cert, $crt, LOCK_EX);
    }
    return [$cert, $clave];
}
/** Contexto TLS 1.2+ del servidor MLLP; con «tls_cliente» exige certificado de cliente firmado por «tls_ca». */
function tls_contexto(array $c) {
    [$cert, $clave] = tls_certificado($c);
    $ssl = ['local_cert' => $cert, 'local_pk' => $clave, 'disable_compression' => true, 'honor_cipher_order' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER') ? STREAM_CRYPTO_METHOD_TLSv1_3_SERVER : 0)];
    if (!empty($c['tls_cliente'])) {
        if (empty($c['tls_ca']) || !is_file((string) $c['tls_ca'])) throw new \RuntimeException('tls_cliente exige tls_ca (archivo PEM de la autoridad que firma los certificados de los gateways)');
        $ssl += ['verify_peer' => true, 'verify_peer_name' => false, 'allow_self_signed' => false, 'cafile' => (string) $c['tls_ca']];
    } else $ssl += ['verify_peer' => false];
    return stream_context_create(['ssl' => $ssl]);
}
function servir(array $c, int $segundos, callable $latido, callable $parar): int {
    $tls = !empty($c['tls']);
    $ctx = $tls ? tls_contexto($c) : stream_context_create();
    $srv = @stream_socket_server('tcp://' . $c['host'] . ':' . (int) $c['puerto'], $en, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
    if (!$srv) throw new \RuntimeException("No se pudo escuchar en {$c['host']}:{$c['puerto']}: $es");
    stream_set_blocking($srv, false);
    $cli = []; $buf = []; $n = 0; $hasta = time() + $segundos;
    try {
        while (!$parar() && time() < $hasta) {
            $r = array_merge([$srv], $cli); $w = null; $e = null;
            if (@stream_select($r, $w, $e, 1) === false) break;
            foreach ($r as $s) {
                if ($s === $srv) {
                    $x = @stream_socket_accept($srv, 0, $peer);
                    if (!$x) continue;
                    $ip = preg_replace('/:\d+$/', '', (string) $peer); $ip = trim($ip, '[]');
                    if (count($cli) >= 20 || !ip_permitida($ip, (array) $c['ips_permitidas'])) { fclose($x); continue; }
                    if ($tls) {                                   // negociación TLS (bloqueante, máx. 10 s por conexión)
                        stream_set_blocking($x, true); stream_set_timeout($x, 10);
                        if (@stream_socket_enable_crypto($x, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER') ? STREAM_CRYPTO_METHOD_TLSv1_3_SERVER : 0)) !== true) { @fclose($x); continue; }
                    }
                    stream_set_blocking($x, false); $k = (int) $x; $cli[$k] = $x; $buf[$k] = '';
                    continue;
                }
                $k = (int) $s; $d = fread($s, 65536);
                if ($d === '' || $d === false) { if (feof($s)) { fclose($s); unset($cli[$k], $buf[$k]); } continue; }
                $buf[$k] .= $d;
                if (strlen($buf[$k]) > 1048576) { fclose($s); unset($cli[$k], $buf[$k]); continue; }
                while (($i = strpos($buf[$k], SB)) !== false && ($j = strpos($buf[$k], EB . CR, $i)) !== false) {
                    $msg = substr($buf[$k], $i + 1, $j - $i - 1); $buf[$k] = substr($buf[$k], $j + 2);
                    fwrite($s, SB . procesar($msg, 'mllp') . EB . CR); $n++;
                }
            }
            $latido($n);
        }
    } finally { foreach ($cli as $s) @fclose($s); fclose($srv); }
    return $n;
}
