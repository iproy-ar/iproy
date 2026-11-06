<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
/**
 * Catálogos editables (1.6.0): las listas desplegables de toda la aplicación. El usuario elige; no escribe.
 *
 *  - El núcleo trae listas de norma (EN 13306, ISO 14224, unidades SI, rubros); cada módulo agrega las suyas en su
 *    manifiesto: "catalogos": {"clave": {"nombre", "descripcion", "norma", "padre", "permite_otro", "con_sigla",
 *    "valores": [["CODIGO", "Texto", "padre_codigo"|null, "SIGLA"|null], …]}}.
 *  - Las semillas se cargan en cada arranque: lo que el administrador desactivó no reaparece y lo que editó no se pisa;
 *    los valores de fábrica que nadie editó toman el texto de la versión nueva.
 *  - Una lista «dependiente» (padre) filtra sus valores por el valor elegido en la lista padre (categoría → subcategoría).
 *  - «Otro…»: si la lista lo permite, quien tenga «catalogos.agregar» suma un valor nuevo desde el mismo formulario.
 *  - Nada se borra: los valores se desactivan, así los registros viejos siguen mostrando su texto.
 */
declare(strict_types=1);

namespace Nucleo;

const CAPACIDADES_CATALOGOS = ['catalogos.ver' => ['GM', 'EJ', 'SO', 'FT', 'CI'], 'catalogos.agregar' => ['GM', 'EJ'], 'catalogos.admin' => []];

/** Listas de norma que trae el núcleo. */
function catalogos_base(): array {
    return [
        'unidad' => ['nombre' => 'Unidades de medida', 'norma' => 'SI (BIPM) y unidades comerciales', 'descripcion' => 'Presentación de insumos y magnitudes de los puntos de medición.',
            'valores' => [['u', 'Unidad (u)'], ['par', 'Par'], ['juego', 'Juego'], ['caja', 'Caja'], ['rollo', 'Rollo'], ['bolsa', 'Bolsa'], ['m', 'Metro (m)'], ['cm', 'Centímetro (cm)'],
                ['mm', 'Milímetro (mm)'], ['m2', 'Metro cuadrado (m²)'], ['m3', 'Metro cúbico (m³)'], ['L', 'Litro (L)'], ['mL', 'Mililitro (mL)'], ['kg', 'Kilogramo (kg)'],
                ['g', 'Gramo (g)'], ['h', 'Hora (h)']]],
        'tipo_mantenimiento' => ['nombre' => 'Tipos de mantenimiento', 'norma' => 'EN 13306:2017 (PIM: pim-tipo-mantenimiento)', 'descripcion' => 'Clasificación de cada orden de trabajo.',
            'valores' => [['correctivo-inmediato', 'Correctivo inmediato'], ['correctivo-diferido', 'Correctivo diferido'], ['preventivo-sistematico', 'Preventivo sistemático'],
                ['preventivo-condicional', 'Preventivo condicional'], ['mejora', 'Mejora'], ['ensayo-reglamentario', 'Ensayo reglamentario'], ['verificacion-tecnica-pma', 'Verificación técnica de PMA']]],
        'modo_falla' => ['nombre' => 'Modos de falla', 'norma' => 'ISO 14224:2016, tabla B.6 (códigos de 3 letras; PIM: pim-modo-falla)', 'descripcion' => 'Se elige al finalizar un correctivo.',
            'valores' => [['AIR', 'Lectura anormal del instrumento'], ['BRD', 'Rotura'], ['ELP', 'Fuga externa de fluido de proceso'], ['ELU', 'Fuga externa de fluido auxiliar'],
                ['ERO', 'Salida errática'], ['FTC', 'No cierra'], ['FTF', 'No funciona cuando se lo necesita'], ['FTO', 'No abre'], ['FTS', 'No arranca cuando se lo requiere'],
                ['HIO', 'Salida alta'], ['INL', 'Pérdida interna'], ['LOO', 'Salida baja'], ['NOI', 'Ruido'], ['OHE', 'Sobrecalentamiento'], ['PDE', 'Desvío de parámetro'],
                ['PLU', 'Obstrucción o taponamiento'], ['SER', 'Problemas menores en servicio'], ['SPO', 'Operación espuria'], ['STD', 'Deficiencia estructural'],
                ['STP', 'No se detiene'], ['UST', 'Parada imprevista'], ['VIB', 'Vibración alta'], ['OTH', 'Otro'], ['UNK', 'Desconocido']]],
        'categoria_solicitud' => ['nombre' => 'Motivos de solicitud', 'descripcion' => 'Qué le pasa al equipo o a la instalación, según quien pide.',
            'valores' => [['FALLA', 'Falla o no funciona'], ['RUIDO', 'Ruido o vibración'], ['FUGA', 'Fuga o pérdida'], ['ELEC', 'Eléctrico'], ['ILUM', 'Iluminación'],
                ['HVAC', 'Climatización'], ['AGUA', 'Agua y sanitarios'], ['GASM', 'Gases medicinales'], ['OBRA', 'Obra o edilicio'], ['MOB', 'Mobiliario'], ['OTRO', 'Otro']]],
        'especialidad' => ['nombre' => 'Rubros o especialidades', 'descripcion' => 'Rubro de proveedores, empresas y técnicos.', 'con_sigla' => 1, 'permite_otro' => 1,
            'valores' => [['ELE', 'Electricidad', null, 'ELE'], ['PLO', 'Plomería y sanitarios', null, 'PLO'], ['CLI', 'Climatización', null, 'CLI'], ['GAS', 'Gases medicinales', null, 'GAS'],
                ['OBR', 'Obra civil', null, 'OBR'], ['PIN', 'Pintura', null, 'PIN'], ['CAR', 'Carpintería', null, 'CAR'], ['HER', 'Herrería', null, 'HER'], ['VID', 'Vidrios', null, 'VID'],
                ['ABE', 'Aberturas', null, 'ABE'], ['FER', 'Ferretería', null, 'FER'], ['MEC', 'Mecánica', null, 'MEC'], ['INF', 'Informática', null, 'INF'],
                ['EMD', 'Electromedicina', null, 'EMD'], ['ASC', 'Ascensores', null, 'ASC'], ['INC', 'Protección contra incendio', null, 'INC'], ['MUL', 'Multirubro', null, 'MUL'],
                ['OTR', 'Otro', null, 'OTR']]],
        'fabricante' => ['nombre' => 'Fabricantes y marcas', 'descripcion' => 'Se completa con el uso: cada marca se escribe una sola vez y después se elige.', 'permite_otro' => 1, 'valores' => []],
        'frecuencia' => ['nombre' => 'Frecuencias de mantenimiento', 'descripcion' => 'El código es la cantidad de días (se usa en los planes preventivos).',
            'valores' => [['1', 'Diaria'], ['7', 'Semanal'], ['15', 'Quincenal'], ['30', 'Mensual'], ['60', 'Bimestral'], ['90', 'Trimestral'], ['120', 'Cuatrimestral'], ['180', 'Semestral'], ['365', 'Anual'], ['730', 'Bienal']]],
        'tipo_local' => ['nombre' => 'Tipos de ubicación', 'norma' => 'HL7 FHIR location-physical-type (IHE mCSD)', 'descripcion' => 'Niveles del árbol de ubicaciones.',
            'valores' => [['bu', 'Edificio'], ['wi', 'Ala'], ['lvl', 'Nivel o piso'], ['ro', 'Local o sala'], ['area', 'Área']]],
    ];
}

/** Todas las definiciones: núcleo + módulos activos. */
function catalogos_def(): array {
    $d = [];
    foreach (catalogos_base() as $k => $c) $d[$k] = $c + ['origen' => 'nucleo'];
    foreach (activos() as $m) foreach ((array) ($m['catalogos'] ?? []) as $k => $c) $d[$k] = (array) $c + ['origen' => $m['id']];
    return $d;
}

/** Carga las semillas (en cada arranque; idempotente). */
function sembrar_catalogos(): array {
    $n = 0;
    foreach (catalogos_def() as $k => $c) {
        if (!preg_match('/^[a-z][a-z0-9_]{1,58}$/', $k)) continue;
        q('INSERT INTO nucleo_catalogo (clave, nombre, descripcion, origen, norma, padre, permite_otro, con_sigla) VALUES (?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion), origen = VALUES(origen), norma = VALUES(norma), padre = VALUES(padre)',
          [$k, $c['nombre'] ?? $k, $c['descripcion'] ?? null, $c['origen'], $c['norma'] ?? null, $c['padre'] ?? null, (int) !empty($c['permite_otro']), (int) !empty($c['con_sigla'])]);
        foreach ((array) ($c['valores'] ?? []) as $i => $v) {
            $v = array_values((array) $v);
            // nuevo: se agrega · de fábrica y nunca editado: se actualiza el texto · editado por el administrador: no se toca
            $n += q('INSERT INTO nucleo_catalogo_valor (catalogo, codigo, texto, padre_codigo, sigla, orden, semilla) VALUES (?,?,?,?,?,?,1)
                     ON DUPLICATE KEY UPDATE texto = IF(semilla = 1 AND editado = 0, VALUES(texto), texto), sigla = IF(semilla = 1 AND editado = 0, VALUES(sigla), sigla)',
                    [$k, (string) $v[0], (string) ($v[1] ?? $v[0]), $v[2] ?? null, $v[3] ?? null, ($i + 1) * 10])->rowCount() === 1 ? 1 : 0;
        }
    }
    return ['valores_nuevos' => $n];
}

function catalogo(string $k): array {
    return fila('SELECT * FROM nucleo_catalogo WHERE clave = ?', [$k]) ?? throw new Fallo(404, "No existe la lista «{$k}»");
}
/** Lista de catálogos con cantidad de valores (para Configuración › Listas). */
function catalogos_lista(): array {
    return filas('SELECT c.clave, c.nombre, c.descripcion, c.origen, c.norma, c.padre, c.permite_otro, c.con_sigla,
                  SUM(v.activo = 1) AS activos, COUNT(v.id) AS total
                  FROM nucleo_catalogo c LEFT JOIN nucleo_catalogo_valor v ON v.catalogo = c.clave GROUP BY c.clave ORDER BY c.origen = "nucleo" DESC, c.nombre');
}
/** Valores de una lista. $todos: incluye los desactivados (administración). */
function catalogo_valores(string $k, bool $todos = false, ?string $padre = null): array {
    catalogo($k);
    $w = 'catalogo = ?'; $a = [$k];
    if (!$todos) $w .= ' AND activo = 1';
    if ($padre !== null && $padre !== '') { $w .= ' AND padre_codigo = ?'; $a[] = $padre; }
    return array_map(fn($r) => ['codigo' => $r['codigo'], 'texto' => $r['texto'], 'padre' => $r['padre_codigo'], 'sigla' => $r['sigla'], 'orden' => (int) $r['orden'],
        'activo' => (int) $r['activo'], 'semilla' => (int) $r['semilla']], filas("SELECT * FROM nucleo_catalogo_valor WHERE $w ORDER BY orden, texto", $a));
}
/** Texto de un código (para mostrar registros). */
function catalogo_texto(string $k, ?string $codigo): string {
    if ($codigo === null || $codigo === '') return '';
    return (string) (valor('SELECT texto FROM nucleo_catalogo_valor WHERE catalogo = ? AND codigo = ?', [$k, $codigo]) ?? $codigo);
}
function catalogo_existe_valor(string $k, string $codigo, bool $solo_activos = true): bool {
    return (bool) valor('SELECT 1 FROM nucleo_catalogo_valor WHERE catalogo = ? AND codigo = ?' . ($solo_activos ? ' AND activo = 1' : ''), [$k, $codigo]);
}
/** Quita tildes y diéresis (no depende de iconv ni de intl, que varían según la compilación de PHP). */
function sin_tildes(string $s): string {
    return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'Ü' => 'U', 'Ñ' => 'N', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'â' => 'a', 'ê' => 'e', 'ô' => 'o', 'ç' => 'c', 'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O', 'Ç' => 'C', '²' => '2', '³' => '3', 'º' => 'o', 'ª' => 'a']);
}
/** Código estable a partir del texto: MAYÚSCULAS sin tildes, guiones; único dentro de la lista. */
function codigo_de_texto(string $k, string $texto, int $max = 40): string {
    $base = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '-', sin_tildes($texto)), '-'));
    $base = substr($base !== '' ? $base : 'VALOR', 0, $max);
    $c = $base; $i = 2;
    while (valor('SELECT 1 FROM nucleo_catalogo_valor WHERE catalogo = ? AND codigo = ?', [$k, $c])) $c = substr($base, 0, $max - 4) . '-' . $i++;
    return $c;
}
/** Sigla de 3 letras a partir del texto (para códigos de insumo {n}-{CAT}-{SUB}); única entre los hermanos. */
function sigla_de_texto(string $k, string $texto, ?string $padre): string {
    $t = strtoupper(preg_replace('/[^A-Za-z]/', '', sin_tildes($texto)));
    $cand = array_unique(array_filter([substr($t, 0, 3), substr($t, 0, 2) . substr($t, -1), substr($t, 0, 1) . substr($t, 2, 2)], fn($s) => strlen($s) === 3));
    foreach ($cand as $s) if (!valor('SELECT 1 FROM nucleo_catalogo_valor WHERE catalogo = ? AND sigla = ? AND (padre_codigo <=> ?)', [$k, $s, $padre])) return $s;
    for ($i = 2; $i < 100; $i++) { $s = substr($t ?: 'VAL', 0, 1) . str_pad((string) $i, 2, '0', STR_PAD_LEFT); if (!valor('SELECT 1 FROM nucleo_catalogo_valor WHERE catalogo = ? AND sigla = ? AND (padre_codigo <=> ?)', [$k, $s, $padre])) return $s; }
    return substr($t, 0, 3);
}

/** Alta de un valor («Otro…» desde un formulario, o el administrador). */
function catalogo_agregar(string $k, array $d, bool $admin): array {
    $c = catalogo($k);
    if (!$admin && !(int) $c['permite_otro']) throw new Fallo(403, 'Esta lista la mantiene el administrador: pedile que agregue el valor en Configuración › Listas');
    $texto = trim(preg_replace('/\s+/', ' ', (string) ($d['texto'] ?? '')));
    if (mb_strlen($texto) < 2 || mb_strlen($texto) > 160) throw new Fallo(422, 'El texto debe tener entre 2 y 160 caracteres');
    $padre = trim((string) ($d['padre'] ?? '')) ?: null;
    if ($c['padre'] && !$padre) throw new Fallo(422, 'Elegí primero el valor de «' . catalogo($c['padre'])['nombre'] . '»');
    if ($padre && $c['padre'] && !catalogo_existe_valor($c['padre'], $padre)) throw new Fallo(422, 'El valor padre no existe');
    $dup = fila('SELECT codigo, activo FROM nucleo_catalogo_valor WHERE catalogo = ? AND LOWER(texto) = LOWER(?) AND (padre_codigo <=> ?)', [$k, $texto, $padre]);
    if ($dup) {
        if (!(int) $dup['activo'] && $admin) q('UPDATE nucleo_catalogo_valor SET activo = 1 WHERE catalogo = ? AND codigo = ?', [$k, $dup['codigo']]);
        elseif (!(int) $dup['activo']) throw new Fallo(409, '«' . $texto . '» existe pero está desactivado: pedile al administrador que lo reactive');
        return ['codigo' => $dup['codigo'], 'texto' => $texto, 'existia' => true];
    }
    $cod = trim((string) ($d['codigo'] ?? ''));
    if ($cod === '') $cod = codigo_de_texto($k, $texto);
    elseif (!preg_match('/^[A-Za-z0-9._-]{1,60}$/', $cod)) throw new Fallo(422, 'Código: letras, números, punto, guion o guion bajo (hasta 60)');
    elseif (catalogo_existe_valor($k, $cod, false)) throw new Fallo(409, "Ya existe el código {$cod} en la lista");
    $sigla = null;
    if ((int) $c['con_sigla']) {
        $sigla = strtoupper(trim((string) ($d['sigla'] ?? '')));
        if ($sigla === '') $sigla = sigla_de_texto($k, $texto, $padre);
        elseif (!preg_match('/^[A-Z0-9]{2,6}$/', $sigla)) throw new Fallo(422, 'Sigla: 2 a 6 letras o números');
    }
    $orden = (int) valor('SELECT COALESCE(MAX(orden), 0) + 10 FROM nucleo_catalogo_valor WHERE catalogo = ?', [$k]);
    q('INSERT INTO nucleo_catalogo_valor (catalogo, codigo, texto, padre_codigo, sigla, orden, creado_por) VALUES (?,?,?,?,?,?,?)',
      [$k, $cod, $texto, $padre, $sigla, $orden, $GLOBALS['nucleo_usuario']['usuario'] ?? null]);
    asentar('catalogo.alta', ['catalogo' => $k, 'codigo' => $cod, 'texto' => $texto, 'padre' => $padre], $k);
    return ['codigo' => $cod, 'texto' => $texto, 'sigla' => $sigla, 'existia' => false];
}
/** Cambio de un valor (solo administración): texto, sigla, orden, activo. El código no cambia nunca. */
function catalogo_cambiar(string $k, string $codigo, array $d): array {
    catalogo($k);
    $v = fila('SELECT * FROM nucleo_catalogo_valor WHERE catalogo = ? AND codigo = ?', [$k, $codigo]) ?? throw new Fallo(404, 'No existe ese valor');
    $set = []; $a = [];
    if (array_key_exists('texto', $d)) { $t = trim((string) $d['texto']); if (mb_strlen($t) < 1 || mb_strlen($t) > 160) throw new Fallo(422, 'Texto: 1 a 160 caracteres'); $set[] = 'texto = ?'; $a[] = $t; }
    if (array_key_exists('sigla', $d)) { $s = strtoupper(trim((string) $d['sigla'])); if ($s !== '' && !preg_match('/^[A-Z0-9]{2,6}$/', $s)) throw new Fallo(422, 'Sigla: 2 a 6 letras o números'); $set[] = 'sigla = ?'; $a[] = $s ?: null; }
    if (array_key_exists('orden', $d)) { $set[] = 'orden = ?'; $a[] = (int) $d['orden']; }
    if (array_key_exists('activo', $d)) { $set[] = 'activo = ?'; $a[] = $d['activo'] ? 1 : 0; }
    if (!$set) throw new Fallo(422, 'Nada para cambiar');
    $set[] = 'editado = 1'; $a[] = $k; $a[] = $codigo;
    q('UPDATE nucleo_catalogo_valor SET ' . implode(', ', $set) . ' WHERE catalogo = ? AND codigo = ?', $a);
    asentar('catalogo.cambio', ['catalogo' => $k, 'codigo' => $codigo] + array_intersect_key($d, array_flip(['texto', 'sigla', 'orden', 'activo'])), $k);
    foreach (catalogo_valores($k, true) as $x) if ($x['codigo'] === $codigo) return $x;
    return [];
}
