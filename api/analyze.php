<?php
/**
 * POST api/analyze.php  (JSON: {"url": "...", "noindex_intentional": false})
 * Descarga y analiza el HTML de una URL y devuelve comprobaciones y puntuación en JSON.
 * No guarda nada de los análisis; solo cuenta peticiones por IP para el límite de uso.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(int $status, string $code, string $message): void
{
    respond($status, ['ok' => false, 'error' => ['code' => $code, 'message' => $message]]);
}

// Código de error → estado HTTP
const ERROR_STATUS = [
    'INVALID_URL' => 400, 'SCHEME_NOT_ALLOWED' => 400, 'CREDENTIALS_IN_URL' => 400, 'PORT_NOT_ALLOWED' => 400,
    'INVALID_HOST' => 400, 'PRIVATE_ADDRESS' => 400,
    'DNS_FAILED' => 502, 'CONNECTION_FAILED' => 502, 'SSL_ERROR' => 502, 'REDIRECT_LOOP' => 502,
    'TOO_MANY_REDIRECTS' => 502, 'BAD_REDIRECT' => 502, 'UNKNOWN' => 502, 'TIMEOUT' => 504,
];

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        fail(405, 'METHOD_NOT_ALLOWED', 'Método no permitido.');
    }
    // Exigir JSON obliga a los navegadores a hacer una comprobación CORS previa desde otros orígenes.
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        fail(415, 'UNSUPPORTED_MEDIA_TYPE', 'La petición debe enviarse en formato JSON.');
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = strtolower((string) preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if ($origin !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== $host) {
        fail(403, 'FORBIDDEN_ORIGIN', 'Origen no permitido.');
    }

    $input = json_decode((string) file_get_contents('php://input', false, null, 0, 8192), true);
    if (!is_array($input)) {
        fail(400, 'INVALID_REQUEST', 'La petición no es válida.');
    }

    $config = Config::load();
    // El directorio de storage se puede cambiar con una variable de entorno (lo usan las pruebas).
    $storage = getenv('WEBSEO_STORAGE_DIR') ?: __DIR__ . '/../storage';
    $limiter = new RateLimiter(
        $storage . '/ratelimit',
        (int) ($config['rate_limit']['max_requests'] ?? 10),
        (int) ($config['rate_limit']['window_seconds'] ?? 600)
    );
    // Supuesto: se usa REMOTE_ADDR. Detrás de un proxy inverso habría que configurar sus IP de confianza.
    $hit = $limiter->hit((string) ($_SERVER['REMOTE_ADDR'] ?? 'desconocida'));
    if (!$hit['allowed']) {
        header('Retry-After: ' . $hit['retry_after']);
        fail(429, 'RATE_LIMITED', 'Has alcanzado el límite de análisis. Vuelve a intentarlo dentro de '
            . (int) ceil($hit['retry_after'] / 60) . ' minuto(s).');
    }

    $url = is_string($input['url'] ?? null) ? $input['url'] : '';
    set_time_limit(45);
    $result = (new Analyzer($config))->analyze($url, ['noindex_intentional' => !empty($input['noindex_intentional'])]);

    // Datos de la descarga que se devuelven (sin cuerpo ni cabeceras de la web analizada)
    $fetch = array_intersect_key($result['fetch'] ?? [], array_flip(
        ['requested_url', 'final_url', 'status', 'content_type', 'bytes', 'truncated', 'redirects', 'hops', 'timings']
    ));

    if (!$result['ok']) {
        $code = (string) ($result['error']['code'] ?? 'UNKNOWN');
        respond(ERROR_STATUS[$code] ?? 502, ['ok' => false, 'error' => $result['error'], 'fetch' => $fetch]);
    }

    respond(200, [
        'ok' => true,
        'url' => $result['url'],
        'blocked' => $result['blocked'],
        'robots' => $result['robots'],
        'fetch' => $fetch,
        'checks' => $result['checks'],
        'puntuacion' => $result['puntuacion'],
    ]);
} catch (Throwable $e) {
    error_log('[analyze] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    fail(500, 'INTERNAL_ERROR', 'Error interno del analizador.');
}
