<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Pruebas unitarias sin base de datos ni red: php pruebas/unitarias.php  (sale con 1 si algo falla) */
declare(strict_types=1);

$N = dirname(__DIR__) . '/nodo';
require "$N/app/nucleo/lib/nucleo.php";
foreach (['bus', 'colmena', 'modulos'] as $l) require_once "$N/app/nucleo/lib/$l.php";
require "$N/modulos/modbus/lib.php";
require "$N/modulos/mqtt/lib.php";
require "$N/modulos/alarmas/lib.php";

$ok = 0; $mal = 0;
function prueba(string $n, bool $c): void { global $ok, $mal; $c ? $ok++ : $mal++; echo ($c ? '  ✔ ' : '  ✘ ') . $n . "\n"; }

echo "Bus (comodines MQTT)\n";
prueba('+ un nivel', Nucleo\tema_coincide('pim/+/create', 'pim/Task/create'));
prueba('+ no cruza niveles', !Nucleo\tema_coincide('pim/+', 'pim/Task/create'));
prueba('# el resto', Nucleo\tema_coincide('colmena/#', 'colmena/vn2/aviso/x'));
prueba('exacto', Nucleo\tema_coincide('telemetria/valor', 'telemetria/valor') && !Nucleo\tema_coincide('telemetria/valor', 'telemetria/medicion'));

echo "JSON canónico (lo que se firma)\n";
prueba('claves ordenadas a cualquier profundidad', Nucleo\canonico(['b' => 1, 'a' => ['d' => 2, 'c' => [3, 1]]]) === '{"a":{"c":[3,1],"d":2},"b":1}');
prueba('conserva 1.0 y unicode', Nucleo\canonico(['v' => 1.0, 't' => 'año']) === '{"t":"año","v":1.0}');

echo "Identificadores\n";
$a = Nucleo\id_nuevo(); usleep(2000); $b = Nucleo\id_nuevo();
prueba('26 caracteres Crockford', (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $a));
prueba('ordenables por tiempo', strcmp($a, $b) < 0);

echo "Modbus (decodificación)\n";
$f = array_values(unpack('n2', pack('G', 221.5)));
prueba('float32 ABCD', abs(Modulos\Modbus\decodificar($f, 'float32', 'ABCD') - 221.5) < 1e-6);
prueba('float32 CDAB', abs(Modulos\Modbus\decodificar([$f[1], $f[0]], 'float32', 'CDAB') - 221.5) < 1e-6);
prueba('int16 negativo', Modulos\Modbus\decodificar([0xFFFB], 'int16') === -5.0);
prueba('uint32', Modulos\Modbus\decodificar([0x0036, 0xEEFB], 'uint32') === 3600123.0);
prueba('int32 negativo', Modulos\Modbus\decodificar([0xFFFF, 0xFFFE], 'int32') === -2.0);
$bl = Modulos\Modbus\bloques([['funcion' => 4, 'direccion' => 0, 'tipo' => 'float32'], ['funcion' => 4, 'direccion' => 4, 'tipo' => 'uint16'], ['funcion' => 4, 'direccion' => 300, 'tipo' => 'uint16'], ['funcion' => 3, 'direccion' => 0, 'tipo' => 'uint32']]);
prueba('agrupa contiguos y separa lejanos y por función', count($bl) === 3 && $bl[0]['desde'] === 0 && $bl[0]['fin'] === 5);

echo "MQTT (temas y cargas)\n";
prueba('tema pim/v1', Modulos\Mqtt\interpretar_tema('pim/v1/HOSP1/CMP-01/PRES', 'pim/v1/{refes}/{activo}/{punto}') === ['refes' => 'HOSP1', 'activo' => 'CMP-01', 'punto' => 'PRES']);
prueba('tema que no corresponde', Modulos\Mqtt\interpretar_tema('otro/v1/a/b/c', 'pim/v1/{refes}/{activo}/{punto}') === null);
prueba('carga numérica', Modulos\Mqtt\interpretar_carga(' 71.5 ') === ['valor' => 71.5]);
prueba('carga JSON corta', Modulos\Mqtt\interpretar_carga('{"v":410.5,"u":"kPa"}') === ['valor' => 410.5, 'unidad' => 'kPa']);
prueba('carga inválida', Modulos\Mqtt\interpretar_carga('{"x":1}') === null);

echo "Alarmas (condición con banda muerta, ISA-18.2)\n";
$d = ['tipo' => 'alta', 'limite' => 800, 'banda' => 20];
prueba('activa al superar el límite', Modulos\Alarmas\condicion($d, 801, false));
prueba('sigue activa dentro de la banda', Modulos\Alarmas\condicion($d, 790, true));
prueba('se normaliza fuera de la banda', !Modulos\Alarmas\condicion($d, 779, true));
$d = ['tipo' => 'baja-baja', 'limite' => 10, 'banda' => 2];
prueba('baja: activa por debajo, histéresis hacia arriba', Modulos\Alarmas\condicion($d, 9.9, false) && Modulos\Alarmas\condicion($d, 11, true) && !Modulos\Alarmas\condicion($d, 12.5, true));

echo "Libsodium (firma y caja sellada, como la colmena)\n";
$kp = sodium_crypto_sign_keypair(); $m = Nucleo\canonico(['de' => 'AAA', 'para' => 'BBB', 'ts' => 1]);
$fi = sodium_crypto_sign_detached($m, sodium_crypto_sign_secretkey($kp));
prueba('firma verifica', sodium_crypto_sign_verify_detached($fi, $m, sodium_crypto_sign_publickey($kp)));
prueba('alterar 1 byte la invalida', !sodium_crypto_sign_verify_detached($fi, $m . ' ', sodium_crypto_sign_publickey($kp)));
$cb = sodium_crypto_box_keypair(); $c = sodium_crypto_box_seal('secreto', sodium_crypto_box_publickey($cb));
prueba('solo el destinatario abre la caja', sodium_crypto_box_seal_open($c, $cb) === 'secreto' && sodium_crypto_box_seal_open($c, sodium_crypto_box_keypair()) === false);

echo "Manifiestos de los módulos\n";
foreach (glob("$N/modulos/*/modulo.json") as $f) {
    $j = json_decode((string) file_get_contents($f), true);
    prueba(basename(dirname($f)) . ': manifiesto válido, ABI ' . Nucleo\ABI . ', licencia libre', is_array($j) && $j['id'] === basename(dirname($f)) && (int) $j['abi'] === Nucleo\ABI && in_array($j['licencia'], Nucleo\LICENCIAS_LIBRES, true));
}
echo "\n" . ($mal ? "✘ $mal fallas, $ok correctas\n" : "✔ $ok pruebas correctas\n");
exit($mal ? 1 : 0);
