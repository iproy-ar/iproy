<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/** Pruebas unitarias sin base de datos ni red: php pruebas/unitarias.php  (sale con 1 si algo falla) */
declare(strict_types=1);

$N = dirname(__DIR__) . '/nodo';
require "$N/app/nucleo/lib/nucleo.php";
foreach (['bus', 'colmena', 'modulos', 'qr'] as $l) require_once "$N/app/nucleo/lib/$l.php";
require "$N/modulos/modbus/lib.php";
require "$N/modulos/mqtt/lib.php";
require "$N/modulos/alarmas/lib.php";
require "$N/modulos/mem/lib.php";
foreach (['nucleo', 'modelo'] as $l) require_once "$N/app/pim/lib/$l.php";
require "$N/modulos/stock/lib.php";      // + panol.php (reposición, Holt)
require "$N/modulos/reportes/lib.php";   // + tendencias.php (Holt-Winters, percentiles)
require "$N/modulos/preventivos/lib.php";

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

require_once "$N/app/pim/lib/importar.php";
prueba('puente del sistema anterior: Atencion → en-espera; En Proceso con inicio → en-ejecucion; Revision con fin → finalizada',
    IproyPim\estado_legado('Atencion', true, false) === 'en-espera' && IproyPim\estado_legado('En Proceso', false, false) === 'asignada' && IproyPim\estado_legado('En Proceso', true, false) === 'en-ejecucion'
    && IproyPim\estado_legado('Revision', false, true) === 'finalizada' && IproyPim\estado_legado('Revision', false, false) === 'en-revision' && IproyPim\estado_legado('DENEGADO', false, false) === 'rechazada' && IproyPim\estado_legado('otro', false, false) === 'solicitada');
prueba('puente: correctivo con prioridad A → correctivo-inmediato; predictivo reconocido; todos son tipos PIM',
    IproyPim\tipo_legado('correctivo', 'A') === 'correctivo-inmediato' && IproyPim\tipo_legado('correctivo', 'B') === 'correctivo-diferido' && IproyPim\tipo_legado('predictivo', '') === 'predictivo' && IproyPim\tipo_legado('', 'A') === null
    && !array_diff(['correctivo-inmediato', 'correctivo-diferido', 'predictivo', 'preventivo-sistematico', 'mejora'], IproyPim\TIPOS_MANT) && !array_diff(IproyPim\ESTADO_LEGADO, array_keys(IproyPim\ESTADOS_ORDEN)));

echo "Pañol y tendencias (ola 3)\n";
prueba('z del nivel de servicio (escalones del sistema anterior)', Modulos\Stock\z(99) === 2.33 && Modulos\Stock\z(97.5) === 1.96 && Modulos\Stock\z(95) === 1.65 && Modulos\Stock\z(90) === 1.28 && Modulos\Stock\z(80) === 0.84);
prueba('Holt a = 0,5 · b = 0,3 sobre 10, 20, 15', Modulos\Stock\holt([10, 20, 15], 3)['proy'] === [30.25, 38.0, 45.75]);
prueba('Holt con un solo dato: repite el dato', Modulos\Stock\holt([7], 2)['proy'] === [7.0, 7.0]);
prueba('Holt nunca proyecta negativo', min(Modulos\Stock\holt([30, 10, 0], 3)['proy']) >= 0);
prueba('pendiente de la recta', Modulos\Stock\regresion([10, 20, 15]) === 2.5 && Modulos\Stock\regresion([4]) === 0.0);
prueba('meses entre dos meses (cruza el año)', Modulos\Stock\meses_entre('2026-11', '2027-02') === ['2026-11', '2026-12', '2027-01', '2027-02']);
prueba('percentiles 50 y 90', Modulos\Reportes\percentil([4, 1, 3, 2], 50) === 2.5 && Modulos\Reportes\percentil([1, 2, 3, 4], 90) === 3.7 && Modulos\Reportes\percentil([], 50) === null);
$y = []; for ($i = 0; $i < 30; $i++) $y[] = 100 + 2 * $i + 20 * sin($i * M_PI / 6);
$hw = Modulos\Reportes\holt_winters($y, 12, 6);
prueba('Holt-Winters: estacional con 30 meses, 6 meses de pronóstico y banda ordenada', $hw && str_contains($hw['modelo'], 'estacional') && count($hw['p50']) === 6
    && !array_filter(array_keys($hw['p50']), fn($i) => !($hw['p10'][$i] <= $hw['p50'][$i] && $hw['p50'][$i] <= $hw['p90'][$i])));
prueba('Holt-Winters: con pocos meses usa Holt amortiguado; con menos de 3, nada', str_contains(Modulos\Reportes\holt_winters([5, 6, 7, 8, 9], 12, 3)['modelo'], 'amortiguada') && Modulos\Reportes\holt_winters([1, 2], 12, 3) === null);
prueba('Holt-Winters: serie constante → pronóstico constante', abs(Modulos\Reportes\holt_winters(array_fill(0, 30, 5), 12, 3)['p50'][2] - 5) < 0.01);
$bt = Modulos\Reportes\backtest($y, 12, 3);
prueba('control del modelo (MAE, WAPE, cobertura)', $bt && $bt['wape'] !== null && $bt['cobertura'] >= 0 && $bt['cobertura'] <= 1 && Modulos\Reportes\backtest([1, 2, 3], 12, 3) === null);

echo "Pedido de insumos según PIM\n";
prueba('letra del pedido = banda PIM (ConceptMap pim-banda-a-prioridad al revés)', Modulos\Stock\letra_prioridad('stat') === 'A' && Modulos\Stock\letra_prioridad('asap') === 'B' && Modulos\Stock\letra_prioridad('urgent') === 'C' && Modulos\Stock\letra_prioridad('routine') === 'D');
prueba('prioridad: acepta el código FHIR o el nombre del sistema anterior', Modulos\Stock\prioridad_codigo('Lo antes posible') === 'asap' && Modulos\Stock\prioridad_codigo('stat') === 'stat' && Modulos\Stock\prioridad_codigo('') === null);
$xl = Modulos\Stock\xlsx([['Código', 'Cantidad'], ['1-ELE-CAB', 2.5], ['<&>', 3]], 'Prueba'); $tmp = tempnam(sys_get_temp_dir(), 'x'); file_put_contents($tmp, $xl);
$z = new ZipArchive(); $okz = $z->open($tmp) === true; $hoja = $okz ? (string) $z->getFromName('xl/worksheets/sheet1.xml') : ''; if ($okz) $z->close(); @unlink($tmp);
prueba('planilla .xlsx: paquete válido, textos escapados y números como número', $okz && str_contains($hoja, '&lt;&amp;&gt;') && str_contains($hoja, '<v>2.5</v>') && str_contains($hoja, 'r="B3"'));

echo "QR (Model 2, byte, corrección M)\n";
$tam = fn(string $t) => count(Nucleo\qr_matriz($t));
prueba('versión según el largo: 1→21, 66→37, 140→49, 162→53 módulos', [$tam('a'), $tam(str_repeat('b', 66)), $tam(str_repeat('c', 140)), $tam(str_repeat('d', 162))] === [21, 37, 49, 53]);
$q = Nucleo\qr_matriz('PED-0001-A'); $fin = fn($y, $x) => $q[$y][$x] && $q[$y][$x + 6] && $q[$y + 6][$x] && $q[$y + 2][$x + 2] && $q[$y + 4][$x + 4] && !$q[$y + 1][$x + 1];
prueba('tres patrones de posición en sus esquinas', $fin(0, 0) && $fin(0, count($q) - 7) && $fin(count($q) - 7, 0));
prueba('módulo oscuro fijo (4V+9, 8)', (bool) $q[count($q) - 8][8]);
prueba('mismo texto → misma matriz', Nucleo\qr_matriz('PED-0001-A') === $q);
$sv = Nucleo\qr_svg('a<b&"c"', 'a<b&"c"'); prueba('SVG: título escapado y un solo trazado', str_starts_with($sv, '<svg') && str_contains($sv, 'a&lt;b&amp;') && substr_count($sv, '<path') === 1);
$demas = false; try { Nucleo\qr_matriz(str_repeat('x', 300)); } catch (\Throwable) { $demas = true; } prueba('texto que no entra en la versión 10 → error', $demas);
if (function_exists('imagecreate')) prueba('PNG con firma válida', str_starts_with(Nucleo\qr_png('hola', 4), "\x89PNG\r\n\x1a\n"));

echo "Preventivos: importación de planes\n";
prueba('fecha DD/MM/AAAA y AAAA-MM-DD; inválida → null', Modulos\Preventivos\fecha_iso('9/11/2026') === '2026-11-09' && Modulos\Preventivos\fecha_iso('2026-12-21') === '2026-12-21' && Modulos\Preventivos\fecha_iso('31/02/2026') === null && Modulos\Preventivos\fecha_iso('-----') === null);
prueba('frecuencia en días: «30», «cada 15 días», vacía → null', Modulos\Preventivos\dias_frecuencia('30') === 30 && Modulos\Preventivos\dias_frecuencia('cada 15 días') === 15 && Modulos\Preventivos\dias_frecuencia('') === null);
use function Modulos\Preventivos\{avanzar, siguiente_no_vencida};
prueba('mensual por mes calendario: 31/01 → 28/02, 09/11 → 09/12; bisiesto anual 29/02 → 28/02', avanzar('2026-01-31', 30) === '2026-02-28' && avanzar('2026-11-09', 30) === '2026-12-09' && avanzar('2024-02-29', 365) === '2025-02-28');
prueba('quincenal y semanal por días; bimestral 2 meses', avanzar('2026-11-09', 15) === '2026-11-24' && avanzar('2026-11-09', 7) === '2026-11-16' && avanzar('2026-11-30', 60) === '2027-01-30');
prueba('vencido: primera fecha del ciclo que no está vencida, sin perder el día', ($sv = siguiente_no_vencida('2020-01-09', 30)) >= date('Y-m-d') && substr($sv, 8) === '09' && siguiente_no_vencida('2999-01-01', 30) === '2999-01-01');

echo "Plan preventivo por semanas (2.0.0)\n";
use function Modulos\Preventivos\{semana_de, semanas_anio, rango_semana, frec_de_texto, semanas_de_frecuencia, dias_semana};
prueba('semana 1 = la del 1 de enero; 2024 empieza lunes', semana_de('2024-01-01') === [2024, 1] && semana_de('2024-01-07') === [2024, 1] && semana_de('2024-01-08') === [2024, 2]);
prueba('53 o 54 semanas: 2024 → 53, 2012 (bisiesto que empieza domingo) → 54, 2026 → 53', semanas_anio(2024) === 53 && semanas_anio(2012) === 54 && semanas_anio(2026) === 53);
prueba('diarias (2.1.0): la semana 1 de 2026 tiene lunes 29/12/2025 a miércoles fuera del año y jueves 01/01 dentro', (function () { $l = dias_semana(2026, 1, '0001111'); return $l[0]['fecha'] === '2025-12-29' && !$l[2]['en_rango'] && $l[3]['en_rango'] && $l[3]['fecha'] === '2026-01-01' && $l[3]['hecho'] && !$l[0]['hecho'] && $l[6]['fecha'] === '2026-01-04'; })());
prueba('diarias: la semana 53 de 2026 tiene 4 días en el año (28 al 31/12) y un día fuera nunca cuenta como hecho', (function () { $l = dias_semana(2026, 53, '1111111'); return array_sum(array_column($l, 'en_rango')) === 4 && $l[4]['fecha'] === '2027-01-01' && !$l[4]['hecho'] && $l[3]['hecho']; })());
prueba('semanas de los extremos parciales: 2026 sem 1 = 01/01 (jueves) al 04/01; sem 53 = 28/12 al 31/12', rango_semana(2026, 1) === ['2026-01-01', '2026-01-04'] && rango_semana(2026, 2) === ['2026-01-05', '2026-01-11'] && rango_semana(2026, 53) === ['2026-12-28', '2026-12-31']);
prueba('frecuencias de la planilla: «Semestral », «mensual», «2 Años», «Diaria», «En temporada» → S, M, 2A, D, null', frec_de_texto('Semestral ') === 'S' && frec_de_texto('mensual') === 'M' && frec_de_texto('2 Años') === '2A' && frec_de_texto('Diaria') === 'D' && frec_de_texto('En temporada') === null);
prueba('mensual desde la semana 3 = cada 4 semanas (3, 7, 11…): 13 veces en 2024', ($m = semanas_de_frecuencia('M', 3, 2024))[0] === 3 && $m[1] === 7 && $m[2] === 11 && count($m) === 13);
prueba('trimestral desde la 2 = 2, 15, 28, 41; diaria = todas las semanas', semanas_de_frecuencia('T', 2, 2026) === [2, 15, 28, 41] && count(semanas_de_frecuencia('D', 1, 2026)) === 53);

echo "\n" . ($mal ? "✘ $mal fallas, $ok correctas" : "✔ $ok pruebas correctas") . "\n";
exit($mal ? 1 : 0);
