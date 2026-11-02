<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Identidad criptográfica del nodo (se crea sola la primera vez; nunca sale del equipo):
 *  - firma ed25519: firma el diario y cada sobre de la colmena;
 *  - caja X25519: recibe sobres sellados (solo este nodo puede abrirlos, ni siquiera el hub);
 *  - clave ES384 «colmena»: aserciones SMART para usar la API PIM de los nodos vinculados.
 */
declare(strict_types=1);

namespace Nucleo;

function identidad(): array {
    static $i = null;
    if ($i !== null) return $i;
    $f = dir_datos() . '/identidad.json';
    $i = leer_json($f);
    if (empty($i['firma_sec'])) {
        $kp = sodium_crypto_sign_keypair(); $cb = sodium_crypto_box_keypair();
        $i = ['node_id' => nodo()['id'], 'creada' => date('c'),
              'firma_pub' => base64_encode(sodium_crypto_sign_publickey($kp)), 'firma_sec' => base64_encode(sodium_crypto_sign_secretkey($kp)),
              'caja_pub' => base64_encode(sodium_crypto_box_publickey($cb)), 'caja_sec' => base64_encode(sodium_crypto_box_secretkey($cb))];
        escribir_json($f, $i, true);
    }
    return $i;
}
/** Huella legible para comparar por teléfono o en persona antes de aceptar un vínculo (como los «números de seguridad»). */
function huella(string $firma_pub_b64): string {
    return implode('-', str_split(strtoupper(substr(hash('sha256', (string) base64_decode($firma_pub_b64)), 0, 20)), 4));
}
function firmar(string $msg): string { return base64_encode(sodium_crypto_sign_detached($msg, (string) base64_decode(identidad()['firma_sec']))); }
function verificar_firma(string $msg, string $firma_b64, string $pub_b64): bool {
    $f = base64_decode($firma_b64, true); $p = base64_decode($pub_b64, true);
    if ($f === false || $p === false || strlen($f) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($p) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return false;
    return sodium_crypto_sign_verify_detached($f, $msg, $p);
}
function sellar(string $msg, string $caja_pub_b64): string { return base64_encode(sodium_crypto_box_seal($msg, (string) base64_decode($caja_pub_b64))); }
function abrir(string $caja_b64): ?string {
    $i = identidad();
    $kp = sodium_crypto_box_keypair_from_secretkey_and_publickey((string) base64_decode($i['caja_sec']), (string) base64_decode($i['caja_pub']));
    $r = sodium_crypto_box_seal_open((string) base64_decode($caja_b64), $kp);
    return $r === false ? null : $r;
}

// ---------------------------------------------------------------- claves ES384 para aserciones SMART (PIM)
function clave_es384(string $nombre): \OpenSSLAsymmetricKey {
    $f = dir_datos('claves') . "/$nombre.pem";
    if (!is_file($f)) {
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']) ?: throw new Fallo(500, 'No se pudo crear la clave ES384 (openssl)');
        openssl_pkey_export($k, $pem); file_put_contents($f, $pem, LOCK_EX); @chmod($f, 0600);
    }
    return openssl_pkey_get_private((string) file_get_contents($f)) ?: throw new Fallo(500, "Clave $nombre ilegible");
}
function jwk_publico(string $nombre, string $kid): array {
    $d = openssl_pkey_get_details(clave_es384($nombre));
    return ['kty' => 'EC', 'crv' => 'P-384', 'kid' => $kid, 'alg' => 'ES384', 'use' => 'sig',
            'x' => b64u(str_pad($d['ec']['x'], 48, "\0", STR_PAD_LEFT)), 'y' => b64u(str_pad($d['ec']['y'], 48, "\0", STR_PAD_LEFT))];
}
/** Aserción client_assertion (RFC 7523) firmada ES384, como pide SMART Backend Services. */
function asercion(string $clave, string $kid, string $cliente, string $aud): string {
    $h = b64u(json_encode(['alg' => 'ES384', 'typ' => 'JWT', 'kid' => $kid]));
    $p = b64u(json_encode(['iss' => $cliente, 'sub' => $cliente, 'aud' => $aud, 'exp' => time() + 240, 'jti' => bin2hex(random_bytes(16))], JSON_UNESCAPED_SLASHES));
    openssl_sign("$h.$p", $der, clave_es384($clave), OPENSSL_ALGO_SHA384);
    // DER (SEQUENCE{INTEGER r, INTEGER s}) → JOSE r‖s (48 + 48 bytes)
    $o = 2; if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
    $lr = ord($der[$o + 1]); $r = substr($der, $o + 2, $lr); $o += 2 + $lr;
    $ls = ord($der[$o + 1]); $s = substr($der, $o + 2, $ls);
    return "$h.$p." . b64u(str_pad(ltrim($r, "\0"), 48, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 48, "\0", STR_PAD_LEFT));
}
/** Lo que el nodo publica de sí mismo (sin secretos). */
function identidad_publica(): array {
    $i = identidad(); $n = nodo(); $p = producto();
    return ['protocolo' => PROTOCOLO, 'node_id' => $n['id'], 'nombre' => $n['nombre'], 'producto' => $p['nombre'], 'version' => $p['version'],
            'nucleo' => VERSION, 'firma_pub' => $i['firma_pub'], 'caja_pub' => $i['caja_pub'], 'huella' => huella($i['firma_pub']),
            'pim_jwk' => jwk_publico('colmena', 'colmena-' . strtolower($n['id'])), 'url' => url_propia()];
}
