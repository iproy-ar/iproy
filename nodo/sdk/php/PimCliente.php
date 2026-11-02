<?php
/**
 * iProy · SDK PHP del Servidor PIM v1.0 (PIM v0.9 / HL7 FHIR R4) · 30-10-2026 · licencia: uso libre dentro del organismo
 *
 * Requisitos: PHP ≥ 8.1 con openssl y curl. Un solo archivo, sin dependencias.
 *
 *   require 'PimCliente.php';
 *   $pim = new PimCliente('https://nodo:8443/pim/r4', 'mi-sistema', file_get_contents('clave_privada.pem'), 'mi-clave-1');
 *   $ordenes = $pim->buscar('Task', ['business-status' => 'solicitada', '_count' => 20]);
 *   [$sr, $taskRef] = $pim->solicitar('Device/01J…', 'ELE-UPS', 'UPS de quirófano 2 en alarma', 'degradado');
 *   $pim->transicion($taskId, 'asignada', ['responsable' => 'Organization/01J…']);
 *
 * La clave privada nunca sale de tu servidor: el nodo solo conoce tu clave pública (JWKS) y tu rol PIM.
 */
declare(strict_types=1);

final class PimError extends RuntimeException {
    public function __construct(public int $http, string $msg, public ?array $outcome = null) { parent::__construct($msg, $http); }
}

final class PimCliente {
    private ?string $tok = null;
    private int $exp = 0;
    private string $alg;
    private \OpenSSLAsymmetricKey $clave;

    /** @param string $base URL de la API, p. ej. https://nodo:8443/pim/r4 · $alcance: el pedido al token (por defecto, todo lo autorizado) */
    public function __construct(private string $base, private string $clientId, string $clavePrivadaPem, private string $kid = '',
                                private string $alcance = 'system/*.cruds', private bool $verificarTls = true) {
        $this->base = rtrim($base, '/');
        $k = openssl_pkey_get_private($clavePrivadaPem);
        if (!$k) throw new InvalidArgumentException('Clave privada ilegible (PEM)');
        $this->clave = $k;
        $t = openssl_pkey_get_details($k)['type'];
        $this->alg = $t === OPENSSL_KEYTYPE_EC ? 'ES384' : 'RS384';
    }

    // ------------------------------------------------------------ operaciones REST
    public function metadata(): array { return $this->http('GET', 'metadata', null, [], false)[0]; }
    public function leer(string $tipo, string $id): array { return $this->http('GET', "$tipo/$id")[0]; }
    /** Búsqueda: devuelve el Bundle. Con $todas = true recorre los enlaces «next» y devuelve solo la lista de recursos. */
    public function buscar(string $tipo, array $params = [], bool $todas = false): array {
        $b = $this->http('GET', $tipo . ($params ? '?' . http_build_query($params) : ''))[0];
        if (!$todas) return $b;
        $l = array_map(fn($e) => $e['resource'], $b['entry'] ?? []);
        while ($n = $this->siguiente($b)) { $b = $this->http('GET', $n)[0]; foreach ($b['entry'] ?? [] as $e) $l[] = $e['resource']; }
        return $l;
    }
    public function crear(array $recurso): array { return $this->http('POST', $recurso['resourceType'], $recurso)[0]; }
    /** PUT con If-Match: usa meta.versionId del recurso que leíste (control de concurrencia; 412 si otro lo cambió antes). */
    public function actualizar(array $recurso): array {
        $v = $recurso['meta']['versionId'] ?? throw new InvalidArgumentException('Falta meta.versionId: leé el recurso antes de modificarlo');
        return $this->http('PUT', $recurso['resourceType'] . '/' . $recurso['id'], $recurso, ['If-Match: W/"' . $v . '"'])[0];
    }
    /** Cambio de estado de una orden. $extra: motivo, responsable (Organization/…), prioridad, tipoMantenimiento, modoFalla, nota. */
    public function transicion(string $taskId, string $estado, array $extra = [], ?string $version = null): array {
        $p = [['name' => 'estado', 'valueCode' => $estado]];
        foreach ($extra as $k => $v) {
            if ($v === null || $v === '') continue;
            $p[] = $k === 'responsable' ? ['name' => $k, 'valueReference' => ['reference' => $v]]
                 : (in_array($k, ['prioridad', 'tipoMantenimiento', 'modoFalla'], true) ? ['name' => $k, 'valueCode' => $v] : ['name' => $k, 'valueString' => $v]);
        }
        return $this->http('POST', "Task/$taskId/\$transicion", ['resourceType' => 'Parameters', 'parameter' => $p], $version ? ['If-Match: W/"' . $version . '"'] : [])[0];
    }
    /** PIM-SOL atajo: crea la solicitud (y el servidor crea la orden). Devuelve [ServiceRequest, 'Task/<id>']. */
    public function solicitar(string $sujeto, string $categoria, string $descripcion, ?string $estadoDeclarado = null, ?string $prioridad = null, ?string $solicitante = null): array {
        $sr = ['resourceType' => 'ServiceRequest', 'status' => 'active', 'intent' => 'order', 'subject' => ['reference' => $sujeto],
               'code' => ['coding' => [['system' => 'https://iproy.com.ar/fhir/CodeSystem/pim-categoria', 'code' => $categoria]], 'text' => $categoria],
               'note' => [['text' => $descripcion]]];
        if ($prioridad) $sr['priority'] = $prioridad;
        if ($solicitante) $sr['requester'] = ['display' => $solicitante];
        if ($estadoDeclarado) $sr['extension'] = [['url' => 'https://iproy.com.ar/fhir/StructureDefinition/pim-estado-declarado', 'valueCode' => $estadoDeclarado]];
        [$r, $cab] = $this->http('POST', 'ServiceRequest', $sr);
        return [$r, $cab['x-pim-task'] ?? null];
    }
    /** PIM-TEL atajo: envía una medición. */
    public function medir(string $deviceId, string $punto, float $valor, string $unidadUcum, ?string $instante = null): array {
        return $this->crear(['resourceType' => 'Observation', 'status' => 'final', 'device' => ['reference' => "Device/$deviceId"],
            'code' => ['coding' => [['system' => 'https://iproy.com.ar/fhir/CodeSystem/pim-punto', 'code' => $punto]]],
            'effectiveDateTime' => $instante ?? date('c'), 'valueQuantity' => ['value' => $valor, 'unit' => $unidadUcum, 'system' => 'http://unitsofmeasure.org', 'code' => $unidadUcum]]);
    }
    /** Lote (Bundle batch) de recursos Observation/ServiceRequest. */
    public function lote(array $recursos): array {
        return $this->http('POST', '', ['resourceType' => 'Bundle', 'type' => 'batch',
            'entry' => array_map(fn($r) => ['resource' => $r, 'request' => ['method' => 'POST', 'url' => $r['resourceType']]], $recursos)])[0];
    }
    public function historial(string $tipo, ?string $id = null, ?string $desde = null): array {
        return $this->http('GET', $tipo . ($id ? "/$id" : '') . '/_history' . ($desde ? '?_since=' . rawurlencode($desde) : ''))[0];
    }
    public function procedencia(string $ref): array { return $this->buscar('Provenance', ['target' => $ref], true); }

    // ------------------------------------------------------------ autorización (SMART Backend Services)
    private function token(): string {
        if ($this->tok && $this->exp > time() + 20) return $this->tok;
        $aud = substr($this->base, 0, -3) . '/auth/token';                        // …/pim/r4 → …/pim/auth/token
        $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $h = $b64(json_encode(array_filter(['alg' => $this->alg, 'typ' => 'JWT', 'kid' => $this->kid])));
        $p = $b64(json_encode(['iss' => $this->clientId, 'sub' => $this->clientId, 'aud' => $aud, 'exp' => time() + 240, 'jti' => bin2hex(random_bytes(16))], JSON_UNESCAPED_SLASHES));
        openssl_sign("$h.$p", $firma, $this->clave, OPENSSL_ALGO_SHA384);
        if ($this->alg === 'ES384') $firma = self::der_a_jose($firma);
        $ch = curl_init($aud);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => $this->verificarTls,
            CURLOPT_SSL_VERIFYHOST => $this->verificarTls ? 2 : 0, CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => "$h.$p." . $b64($firma), 'scope' => $this->alcance])]);
        $j = json_decode((string) curl_exec($ch), true);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (empty($j['access_token'])) throw new PimError($code ?: 0, 'Token rechazado: ' . ($j['error_description'] ?? curl_error($ch) ?: 'sin respuesta'));
        $this->tok = $j['access_token']; $this->exp = time() + (int) $j['expires_in'];
        return $this->tok;
    }
    private static function der_a_jose(string $der): string {
        $o = 2; if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
        $lr = ord($der[$o + 1]); $r = substr($der, $o + 2, $lr); $o += 2 + $lr;
        $ls = ord($der[$o + 1]); $s = substr($der, $o + 2, $ls);
        return str_pad(ltrim($r, "\0"), 48, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 48, "\0", STR_PAD_LEFT);
    }
    private function siguiente(array $b): ?string {
        foreach ($b['link'] ?? [] as $l) if ($l['relation'] === 'next') return substr($l['url'], strlen($this->base) + 1);
        return null;
    }

    /** @return array{0: array, 1: array} cuerpo y cabeceras (en minúsculas) */
    private function http(string $metodo, string $ruta, ?array $cuerpo = null, array $cab = [], bool $auth = true): array {
        $cab[] = 'Accept: application/fhir+json';
        if ($auth) $cab[] = 'Authorization: Bearer ' . $this->token();
        if ($cuerpo !== null) $cab[] = 'Content-Type: application/fhir+json';
        $resp = [];
        $ch = curl_init($this->base . ($ruta === '' ? '' : '/' . $ruta));
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $cab,
            CURLOPT_SSL_VERIFYPEER => $this->verificarTls, CURLOPT_SSL_VERIFYHOST => $this->verificarTls ? 2 : 0,
            CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$resp) { $p = strpos($l, ':'); if ($p) $resp[strtolower(trim(substr($l, 0, $p)))] = trim(substr($l, $p + 1)); return strlen($l); }]);
        if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $b = curl_exec($ch);
        $h = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($b === false) throw new PimError(0, 'Sin conexión con el servidor PIM: ' . curl_error($ch));
        $j = json_decode((string) $b, true);
        if ($h >= 400) throw new PimError($h, (string) ($j['issue'][0]['diagnostics'] ?? $j['error_description'] ?? "HTTP $h"), is_array($j) ? $j : null);
        return [is_array($j) ? $j : [], $resp];
    }
}
