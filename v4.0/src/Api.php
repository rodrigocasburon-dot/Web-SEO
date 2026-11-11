<?php
/**
 * Utilidades comunes de los endpoints de api/: cabeceras, respuestas JSON, validación de la
 * petición (POST, JSON, mismo origen) y límite de uso por IP.
 */
class Api
{
    public static function headers(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
    }

    public static function respond(int $status, array $data): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function fail(int $status, string $code, string $message): void
    {
        self::respond($status, ['ok' => false, 'error' => ['code' => $code, 'message' => $message]]);
    }

    /**
     * Exige POST con JSON desde el mismo origen y devuelve el cuerpo decodificado.
     * Exigir JSON obliga a los navegadores a hacer una comprobación CORS previa desde otros orígenes.
     */
    public static function readJsonPost(int $maxBytes = 8192): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            self::fail(405, 'METHOD_NOT_ALLOWED', 'Método no permitido.');
        }
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
            self::fail(415, 'UNSUPPORTED_MEDIA_TYPE', 'La petición debe enviarse en formato JSON.');
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $host = strtolower((string) preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        if ($origin !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== $host) {
            self::fail(403, 'FORBIDDEN_ORIGIN', 'Origen no permitido.');
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (strlen($raw) > $maxBytes) {
            self::fail(413, 'REQUEST_TOO_LARGE', 'La petición es demasiado grande.');
        }
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            self::fail(400, 'INVALID_REQUEST', 'La petición no es válida.');
        }
        return $input;
    }

    /** Aplica el límite de uso por IP; $bucket separa contadores (análisis, PageSpeed). */
    public static function rateLimit(array $config, string $bucket, string $what): void
    {
        // El directorio de storage se puede cambiar con una variable de entorno (lo usan las pruebas).
        $storage = getenv('WEBSEO_STORAGE_DIR') ?: __DIR__ . '/../storage';
        $limiter = new RateLimiter(
            $storage . '/ratelimit',
            (int) ($config['rate_limit']['max_requests'] ?? 10),
            (int) ($config['rate_limit']['window_seconds'] ?? 600)
        );
        // Supuesto: se usa REMOTE_ADDR. Detrás de un proxy inverso habría que configurar sus IP de confianza.
        $hit = $limiter->hit($bucket . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'desconocida'));
        if (!$hit['allowed']) {
            header('Retry-After: ' . $hit['retry_after']);
            self::fail(429, 'RATE_LIMITED', 'Has alcanzado el límite de ' . $what . '. Vuelve a intentarlo dentro de '
                . (int) ceil($hit['retry_after'] / 60) . ' minuto(s).');
        }
    }
}
