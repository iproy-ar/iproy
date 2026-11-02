<?php
/**
 * iProy · SDK PIM · genera el par de claves de un sistema cliente y su JWKS público · 30-10-2026
 *
 *   php generar-claves.php <carpeta> <kid> [rsa|ec]       crea clave_privada.pem (queda en TU servidor) y jwks_publico.json
 *   php generar-claves.php --jwks <clave_privada.pem> <kid>   solo imprime el JWKS público de una clave existente
 *
 * Al Gestor del nodo se le entrega SOLO jwks_publico.json. La clave privada nunca se envía.
 */
declare(strict_types=1);

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function jwks(OpenSSLAsymmetricKey $k, string $kid): string {
    $d = openssl_pkey_get_details($k);
    $jwk = $d['type'] === OPENSSL_KEYTYPE_EC
        ? ['kty' => 'EC', 'crv' => 'P-384', 'kid' => $kid, 'alg' => 'ES384', 'use' => 'sig',
           'x' => b64u(str_pad($d['ec']['x'], 48, "\0", STR_PAD_LEFT)), 'y' => b64u(str_pad($d['ec']['y'], 48, "\0", STR_PAD_LEFT))]
        : ['kty' => 'RSA', 'kid' => $kid, 'alg' => 'RS384', 'use' => 'sig', 'e' => b64u($d['rsa']['e']), 'n' => b64u($d['rsa']['n'])];
    return json_encode(['keys' => [$jwk]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
$a = $argv;
if (($a[1] ?? '') === '--jwks') {
    $k = openssl_pkey_get_private((string) @file_get_contents($a[2] ?? '')) ?: exit("clave ilegible\n");
    echo jwks($k, $a[3] ?? 'clave-1'); exit;
}
[$dir, $kid, $tipo] = [$a[1] ?? '', $a[2] ?? '', $a[3] ?? 'ec'];
if ($dir === '' || !preg_match('/^[A-Za-z0-9._\-]{1,40}$/', $kid)) exit("uso: php generar-claves.php <carpeta> <kid> [rsa|ec]\n");
if (is_file("$dir/clave_privada.pem")) exit("ya existe $dir/clave_privada.pem: no se reemplaza\n");
@mkdir($dir, 0700, true);
$k = $tipo === 'rsa' ? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072])
                     : openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
openssl_pkey_export($k, $pem);
file_put_contents("$dir/clave_privada.pem", $pem); @chmod("$dir/clave_privada.pem", 0600);
file_put_contents("$dir/jwks_publico.json", jwks($k, $kid));
echo "✔ $dir/clave_privada.pem (guardala; no se comparte)\n✔ $dir/jwks_publico.json (entregá este archivo al Gestor del nodo)\n";
