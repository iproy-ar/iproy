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
require "$N/modulos/mem/lib.php";
foreach (['nucleo', 'modelo'] as $l) require_once "$N/app/pim/lib/$l.php";

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
echo "IHE PCD-15 (HL7 v2, módulo mem)\n";
$m = "MSH|^~\\&|Fab^001A010000000001^EUI-64||Cli||20150119221713-0000||ORU^R44^ORU_R44|C1|P|2.6\rOBR|1|||69135^MDC_OBS_MEM^MDC|||20150119221713-0000\r"
   . "OBX|1|CWE|67925^MDC_ATTR_POWER_STAT^MDC|1.0.0.3|^BATT||20150119221713-0000||||F|||||||2000101^Bomba^001A010000000002^EUI-64";
$sg = Modulos\Mem\partir($m);
prueba('MSH-1 es el separador y MSH-9 el tipo', Modulos\Mem\campo($sg[0], 1) === '|' && Modulos\Mem\campo($sg[0], 9) === 'ORU^R44^ORU_R44');
prueba('OBX-18: EUI-64 del componente anterior al tipo', Modulos\Mem\eui(Modulos\Mem\campo($sg[2], 18)) === '001A010000000002');
prueba('MSH-3: EUI-64', Modulos\Mem\eui(Modulos\Mem\campo($sg[0], 3)) === '001A010000000001');
prueba('TS de HL7 v2 → dateTime FHIR', Modulos\Mem\ts('20150119221713-0000') === '2015-01-19T22:17:13-00:00' && Modulos\Mem\ts('20261102') === '2026-11-02T00:00:00-03:00');
$ack = Modulos\Mem\ack($sg, 'AA');
prueba('ACK con MSA|AA|<control> y ACK^R44', str_contains($ack, "\rMSA|AA|C1") && str_contains($ack, '|ACK^R44^ACK|'));
prueba('IP permitida por CIDR', Modulos\Mem\ip_permitida('192.168.1.20', ['192.168.1.0/24']) && !Modulos\Mem\ip_permitida('10.0.0.1', ['192.168.1.0/24']) && Modulos\Mem\ip_permitida('1.2.3.4', []));

echo "Contrato servidor ↔ especificación (L-329: por máquina)\n";
$G = "$N/conf/pim-guia";
$cods = function (string $id) use ($G): array {
    $j = json_decode((string) @file_get_contents("$G/CodeSystem-$id.json"), true) ?? []; $l = [];
    $r = function ($c) use (&$r, &$l) { foreach ($c as $x) { $l[] = (string) $x['code']; if (!empty($x['concept'])) $r($x['concept']); } }; $r((array) ($j['concept'] ?? [])); return $l;
};
prueba('estados de la orden = pim-estado-orden', array_keys(IproyPim\ESTADOS_ORDEN) == $cods('pim-estado-orden'));
prueba('tipos de mantenimiento = pim-tipo-mantenimiento', IproyPim\TIPOS_MANT == $cods('pim-tipo-mantenimiento'));
prueba('sistemas = pim-sistema', !array_diff(array_keys(IproyPim\SISTEMAS), $cods('pim-sistema')) && !array_diff($cods('pim-sistema'), array_keys(IproyPim\SISTEMAS)));
prueba('categoría de alcance de cada sistema (A–D)', !array_diff($cods('pim-sistema'), array_keys(IproyPim\SISTEMA_ALCANCE)) && !array_diff(IproyPim\SISTEMA_ALCANCE, ['A', 'B', 'C', 'D']));
prueba('la orden tiene 11 estados, cada uno con sus transiciones, y 3 terminales', count(IproyPim\ESTADOS_ORDEN) === 11 && array_keys(IproyPim\TRANSICIONES) === array_keys(IproyPim\ESTADOS_ORDEN) && count(array_filter(IproyPim\TRANSICIONES, fn($t) => !$t)) === 3);
$cm = json_decode((string) @file_get_contents("$G/ConceptMap-pim-banda-a-prioridad.json"), true); $mapa = [];
foreach ((array) ($cm['group'][0]['element'] ?? []) as $e) $mapa[substr($e['code'], 6)] = $e['target'][0]['code'];
prueba('banda del IRU → prioridad = ConceptMap pim-banda-a-prioridad (A a D)', count($mapa) === 4 && IproyPim\banda_prioridad() === $mapa);
$i = IproyPim\iru(['iru_f' => 8, 'iru_r' => 5, 'iru_m' => 4, 'iru_a' => 1, 'categoria_riesgo' => 1, 'redundancia' => 'N+1']);
prueba('IRU 8 + 5 + 4 + 1 = 18: banda base A; con redundancia N+1 baja a B y sugiere la prioridad de B', $i['total'] === 18 && $i['banda_base'] === 'A' && $i['banda'] === 'B' && $i['prioridad'] === $mapa['B'] && $mapa['A'] === 'stat' && $mapa['B'] === 'asap');
prueba('grupos de local = pim-grupo-local', IproyPim\GRUPOS_LOCAL == $cods('pim-grupo-local'));
prueba('estado declarado, presión y pertenencia ⊂ pim-valores', !array_diff(array_merge(IproyPim\ESTADO_DECLARADO, array_values(IproyPim\PRESION), IproyPim\PERTENENCIA), $cods('pim-valores')));
$fuente = implode("\n", array_map('file_get_contents', array_merge(glob("$N/app/pim/lib/*.php"), ["$N/modulos/mem/lib.php"])));
preg_match_all("/sd\\('([a-z0-9\\-]+)'\\)/", $fuente, $x1); preg_match_all("/(?:SD \\. |StructureDefinition\\/)'?(pim-[a-z0-9\\-]+)/", $fuente, $x2);
$sds = array_unique(array_merge($x1[1], $x2[1], array_values(IproyPim\ALIAS_SD)));
$faltan = array_values(array_filter($sds, fn($n) => $n !== 'pim-punto-medicion' && !is_file("$G/StructureDefinition-$n.json")));
prueba('toda extensión y perfil que usa el servidor existe en la guía (' . count($sds) . ')' . ($faltan ? ' · faltan: ' . implode(', ', $faltan) : ''), !$faltan);
preg_match_all("/cs\\('([a-z0-9\\-]+)'\\)/", $fuente, $x3);
$faltan = array_values(array_filter(array_unique($x3[1]), fn($n) => !is_file("$G/CodeSystem-$n.json")));
prueba('todo CodeSystem que usa el servidor existe en la guía' . ($faltan ? ' · faltan: ' . implode(', ', $faltan) : ''), !$faltan);

echo "\n" . ($mal ? "✘ $mal fallas, $ok correctas" : "✔ $ok pruebas correctas") . "\n";
exit($mal ? 1 : 0);
