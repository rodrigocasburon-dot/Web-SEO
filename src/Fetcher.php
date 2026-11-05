<?php
/**
 * Descarga una URL con todas las defensas: valida cada destino con UrlGuard, sigue las
 * redirecciones a mano (máx. 5), presupuesto total de 10 s, 2 MB de respuesta, IP fijada.
 * No guarda nada. Devuelve HTML, cabeceras, cadena de redirecciones y tiempos.
 */
class Fetcher
{
    private const REDIRECT_CODES = [301, 302, 303, 307, 308];

    private UrlGuard $guard;
    private array $opt;
    /** @var callable */
    private $transport;

    /**
     * @param array         $options   user_agent, timeout (s, presupuesto total), max_redirects, max_bytes
     * @param callable|null $transport fn(array $target, int $timeoutMs, array $opt): array (por defecto cURL)
     */
    public function __construct(UrlGuard $guard, array $options = [], ?callable $transport = null)
    {
        $this->guard = $guard;
        $this->opt = array_merge([
            'user_agent' => 'ProconsiAnalyzer/0.1 (herramienta interna de analisis web)',
            'timeout' => 10,
            'max_redirects' => 5,
            'max_bytes' => 2097152,
        ], $options);
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * @return array ok, requested_url, final_url, status, headers, content_type, body, truncated,
     *               bytes, redirects, hops, timings, blocked, error
     */
    public function fetch(string $url): array
    {
        $startedAt = microtime(true);
        $deadline = $startedAt + $this->opt['timeout'];
        $r = [
            'ok' => false,
            'requested_url' => $url,
            'final_url' => null,
            'status' => null,
            'headers' => [],
            'content_type' => null,
            'body' => '',
            'truncated' => false,
            'bytes' => 0,
            'redirects' => [],
            'hops' => [],
            'timings' => ['total_ms' => 0, 'dns_ms' => 0],
            'blocked' => null,
            'error' => null,
        ];

        $current = $url;
        $visited = [];

        while (true) {
            try {
                $target = $this->guard->validate($current);
            } catch (UrlGuardException $e) {
                $r['error'] = $this->error($e->getErrorCode(), $e->getMessage(), $current);
                break;
            }
            $r['timings']['dns_ms'] += $target['dns_ms'];

            if (isset($visited[$target['url']])) {
                $r['error'] = $this->error('REDIRECT_LOOP', 'Las redirecciones forman un bucle.', $target['url']);
                break;
            }
            $visited[$target['url']] = true;

            $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);
            if ($remainingMs <= 0) {
                $r['error'] = $this->error('TIMEOUT', 'Se agotó el tiempo de espera (' . $this->opt['timeout'] . ' s).', $target['url']);
                break;
            }

            $resp = ($this->transport)($target, $remainingMs, $this->opt);
            $r['final_url'] = $target['url'];
            $r['hops'][] = [
                'url' => $target['url'],
                'ip' => $target['ip'],
                'status' => $resp['status'],
                'timings' => $resp['timings'],
            ];

            if ($resp['errno'] !== 0) {
                $r['error'] = $this->mapCurlError((int) $resp['errno'], (string) $resp['error'], $target['url']);
                break;
            }

            $r['status'] = $resp['status'];
            $location = $resp['headers']['location'] ?? '';

            if (in_array($resp['status'], self::REDIRECT_CODES, true)) {
                if ($location === '') {
                    $r['error'] = $this->error('BAD_REDIRECT', 'Redirección sin cabecera Location.', $target['url']);
                    break;
                }
                if (count($r['redirects']) >= $this->opt['max_redirects']) {
                    $r['error'] = $this->error('TOO_MANY_REDIRECTS', 'Demasiadas redirecciones (máximo ' . $this->opt['max_redirects'] . ').', $target['url']);
                    break;
                }
                $next = self::resolveUrl($target['url'], str_replace(' ', '%20', trim($location)));
                $r['redirects'][] = [
                    'from' => $target['url'],
                    'status' => $resp['status'],
                    'location' => $location,
                    'to' => $next,
                ];
                $current = $next;
                continue;
            }

            // Respuesta final
            $r['ok'] = true;
            $r['headers'] = $resp['headers'];
            $r['content_type'] = $resp['headers']['content-type'] ?? null;
            $r['body'] = $resp['body'];
            $r['truncated'] = $resp['truncated'];
            $r['bytes'] = strlen($resp['body']);
            $r['blocked'] = $this->detectBlock($r);
            break;
        }

        $r['timings']['total_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        return $r;
    }

    /** Resuelve una referencia (Location) respecto a la URL base ya normalizada. */
    public static function resolveUrl(string $base, string $ref): string
    {
        if ($ref === '') {
            return $base;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $ref)) {
            return $ref;                       // absoluta; UrlGuard rechazará los esquemas no permitidos
        }
        $b = parse_url($base);
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (strncmp($ref, '//', 2) === 0) {
            return $b['scheme'] . ':' . $ref;
        }
        if ($ref[0] === '#') {
            return $base;
        }
        $ref = explode('#', $ref, 2)[0];
        $basePath = $b['path'] ?? '/';
        if ($ref !== '' && $ref[0] === '?') {
            return $origin . $basePath . $ref;
        }
        $query = '';
        if (($q = strpos($ref, '?')) !== false) {
            $query = substr($ref, $q);
            $ref = substr($ref, 0, $q);
        }
        if ($ref === '' || $ref[0] !== '/') {
            $ref = substr($basePath, 0, strrpos($basePath, '/') + 1) . $ref;
        }
        return $origin . self::removeDotSegments($ref) . $query;
    }

    private static function removeDotSegments(string $path): string
    {
        $out = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $i => $s) {
            if ($s === '.' || $s === '..') {
                if ($s === '..' && count($out) > 1) {
                    array_pop($out);
                }
                if ($i === $last) {
                    $out[] = '';
                }
                continue;
            }
            $out[] = $s;
        }
        $res = implode('/', $out);
        return ($res === '' || $res[0] !== '/') ? '/' . $res : $res;
    }

    private function error(string $code, string $message, ?string $url = null): array
    {
        return ['code' => $code, 'message' => $message, 'url' => $url];
    }

    private function mapCurlError(int $errno, string $detail, string $url): array
    {
        switch ($errno) {
            case 28:
                return $this->error('TIMEOUT', 'Se agotó el tiempo de espera de conexión o respuesta (máximo ' . $this->opt['timeout'] . ' s en total).', $url);
            case 6:
                return $this->error('DNS_FAILED', 'No se ha podido resolver el dominio.', $url);
            case 7: case 52: case 55: case 56:
                return $this->error('CONNECTION_FAILED', 'No se ha podido conectar con el servidor.', $url);
            case 35: case 51: case 58: case 59: case 60: case 64: case 66: case 77: case 82: case 83: case 90: case 91:
                return $this->error('SSL_ERROR', 'Error de certificado o de conexión segura: ' . $detail, $url);
            default:
                return $this->error('UNKNOWN', 'Error de red (' . $errno . '): ' . $detail, $url);
        }
    }

    /**
     * Distingue bloqueo del sitio (403, captcha, login, límite de uso) de un fallo del sitio.
     * Heurística: puede dar falsos negativos/positivos; el mensaje no afirma más de lo observado.
     */
    private function detectBlock(array $r): ?array
    {
        $status = (int) $r['status'];
        $h = $r['headers'];
        $sample = substr($r['body'], 0, 200000);

        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $sample, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1])));
        }
        $markers = ['g-recaptcha', 'h-captcha', 'hcaptcha.com', 'cf-chl', 'cf-challenge',
            'challenges.cloudflare.com', '/cdn-cgi/challenge-platform', 'captcha-delivery.com', 'px-captcha'];
        $hasMarker = false;
        foreach ($markers as $mk) {
            if (stripos($sample, $mk) !== false) {
                $hasMarker = true;
                break;
            }
        }
        $challengeTitle = (bool) preg_match(
            '/^(just a moment|attention required|one more step|verify you are human|are you a robot|checking your browser|access denied)/i',
            $title
        );
        $challengeHeader = isset($h['cf-mitigated']) && stripos($h['cf-mitigated'], 'challenge') !== false;

        // Un captcha cuenta solo en respuestas de error o en páginas con título de desafío;
        // un formulario normal con reCAPTCHA en un 200 no es un bloqueo.
        if ($challengeHeader || (($status >= 400 || $challengeTitle) && ($hasMarker || $challengeTitle))) {
            return ['reason' => 'captcha', 'message' => 'El sitio muestra una verificación anti-bots (captcha o desafío). No se puede analizar de forma automática; no indica un fallo del sitio.'];
        }
        if ($status === 401) {
            return ['reason' => 'login', 'message' => 'El sitio exige autenticación (HTTP 401). No se puede analizar contenido protegido.'];
        }
        if ($status === 403) {
            return ['reason' => 'forbidden', 'message' => 'El sitio ha bloqueado el acceso automático (HTTP 403). Es un bloqueo, no un fallo del sitio.'];
        }
        if ($status === 429) {
            return ['reason' => 'rate_limit', 'message' => 'El sitio limita las peticiones automáticas (HTTP 429). Inténtalo más tarde.'];
        }
        // Redirección a una página de acceso (heurística por la ruta)
        if ($r['redirects'] && $r['hops']) {
            $re = '#/(log-?in|sign-?in|sso|auth(entication)?)(/|\.|$)#i';
            $firstPath = (string) parse_url($r['hops'][0]['url'], PHP_URL_PATH);
            $finalPath = (string) parse_url($r['final_url'], PHP_URL_PATH);
            if (preg_match($re, $finalPath) && !preg_match($re, $firstPath)) {
                return ['reason' => 'login', 'message' => 'La URL redirige a una página de acceso (posible contenido protegido). Heurística basada en la ruta.'];
            }
        }
        return null;
    }
}
