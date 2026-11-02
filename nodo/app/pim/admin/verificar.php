<?php
/**
 * iProy · Servidor PIM v1.0 · verificación de la cadena de huellas (PIM-FED) · 30-10-2026
 * Recalcula SHA-256(huella anterior | contenido) de cada versión de cada recurso y avisa cualquier alteración.
 * Solo desde el propio equipo con el token del worker. Lo usa «iProy-Control pim verificar».
 */
declare(strict_types=1);

namespace IproyPim;

require dirname(__DIR__) . '/lib/nucleo.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$n = is_file(raiz() . '/data/php/nodo.php') ? (require raiz() . '/data/php/nodo.php') : [];
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || empty($n['tick_token'])
    || !hash_equals((string) $n['tick_token'], (string) ($_SERVER['HTTP_X_IPROY_TICK'] ?? ''))) {
    http_response_code(403); echo '{"ok":false,"error":"solo desde el nodo"}'; exit;
}
$q = pdo()->query('SELECT recurso, id, version, contenido, huella FROM pim_version ORDER BY recurso, id, version');
$ant = []; $total = 0; $mal = [];
while ($v = $q->fetch()) {
    $k = $v['recurso'] . '/' . $v['id'];
    $esperada = hash('sha256', ($ant[$k] ?? str_repeat('0', 64)) . '|' . $v['contenido']);
    if (!hash_equals($esperada, $v['huella'])) $mal[] = "$k/_history/{$v['version']}";
    $ant[$k] = $v['huella'];
    $total++;
}
echo json_encode(['ok' => !$mal, 'versiones' => $total, 'recursos' => count($ant), 'alteradas' => array_slice($mal, 0, 100)], JSON_UNESCAPED_SLASHES);
