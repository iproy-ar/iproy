<?php
/**
 * iProy Portable · auto_prepend_file del nodo · v0.4.0 · 29-10-2026
 * Inyecta iproy-offline.js en las páginas HTML que genera PHP (GestionMant, iProy…) SIN modificar
 * sus archivos, y apunta las librerías de CDN a la copia local del nodo (/_iproy/cdn/…).
 * No toca JSON, descargas ni las rutas propias del nodo (/_iproy/, /fhir/).
 */
(static function (): void {
    $ruta = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (PHP_SAPI === 'cli' || str_starts_with($ruta, '/_iproy/') || str_starts_with($ruta, '/fhir')
        || preg_match('~/api/~', $ruta)) return;
    ob_start(static function (string $html, int $fase): string {
        if (!($fase & PHP_OUTPUT_HANDLER_FINAL) && !($fase & PHP_OUTPUT_HANDLER_END)) return $html;  // un solo paso al final
        foreach (headers_list() as $h)
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return $html;
        if (stripos($html, '<head') === false || stripos($html, 'iproy-offline.js') !== false) return $html;
        $js = dirname(__FILE__) . '/iproy-offline.js';
        $v = @filemtime($js) ?: 0;
        $tag = "\n  <script src=\"/_iproy/offline/iproy-offline.js?v={$v}\"></script>";
        $html = preg_replace('/<head(\s[^>]*)?>/i', '$0' . $tag, $html, 1) ?? $html;
        // Librerías externas → copia local (se descarga sola la primera vez que hay red)
        $cfg = @json_decode((string) @file_get_contents(dirname(__DIR__, 3) . '/data/red.json'), true) ?: [];
        if (($cfg['cdn_local'] ?? true) !== false) {
            $html = preg_replace('~(\s(?:src|href)\s*=\s*["\'])https://(cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|unpkg\.com|code\.jquery\.com|ajax\.googleapis\.com|fonts\.googleapis\.com|fonts\.gstatic\.com|use\.fontawesome\.com|cdn\.tailwindcss\.com)/~i',
                '$1/_iproy/cdn/$2/', $html) ?? $html;
        }
        return $html;
    }, 0);
})();
