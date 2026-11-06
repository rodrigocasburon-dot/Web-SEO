<?php
/**
 * POST api/analyze.php  (JSON: {"url": "...", "noindex_intentional": false, "palabra_clave": "opcional"})
 * Descarga y analiza el HTML de una URL y devuelve comprobaciones y puntuación en JSON.
 * C08 y C14 (PageSpeed Insights) se obtienen aparte con api/psi.php.
 * No guarda nada de los análisis; solo cuenta peticiones por IP para el límite de uso.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../src/bootstrap.php';

Api::headers();

// Código de error → estado HTTP
const ERROR_STATUS = [
    'INVALID_URL' => 400, 'SCHEME_NOT_ALLOWED' => 400, 'CREDENTIALS_IN_URL' => 400, 'PORT_NOT_ALLOWED' => 400,
    'INVALID_HOST' => 400, 'PRIVATE_ADDRESS' => 400,
    'DNS_FAILED' => 502, 'CONNECTION_FAILED' => 502, 'SSL_ERROR' => 502, 'REDIRECT_LOOP' => 502,
    'TOO_MANY_REDIRECTS' => 502, 'BAD_REDIRECT' => 502, 'UNKNOWN' => 502, 'TIMEOUT' => 504,
];

try {
    $input = Api::readJsonPost();
    $config = Config::load();
    Api::rateLimit($config, 'analyze', 'análisis');

    $url = is_string($input['url'] ?? null) ? $input['url'] : '';
    // Página 10 s + robots.txt 5 s + enlaces 12 s + sitemap 8 s en el peor caso
    set_time_limit(50);
    $result = (new Analyzer($config))->analyze($url, [
        'noindex_intentional' => !empty($input['noindex_intentional']),
        // Palabra clave objetivo (opcional, solo informativa)
        'palabra_clave' => Insights::keywordInput($input['palabra_clave'] ?? ''),
    ]);

    // Datos de la descarga que se devuelven (sin cuerpo ni cabeceras de la web analizada)
    $fetch = array_intersect_key($result['fetch'] ?? [], array_flip(
        ['requested_url', 'final_url', 'status', 'content_type', 'bytes', 'truncated', 'redirects', 'hops', 'timings']
    ));

    if (!$result['ok']) {
        $code = (string) ($result['error']['code'] ?? 'UNKNOWN');
        Api::respond(ERROR_STATUS[$code] ?? 502, ['ok' => false, 'error' => $result['error'], 'fetch' => $fetch]);
    }

    Api::respond(200, [
        'ok' => true,
        'url' => $result['url'],
        'fecha' => gmdate('c'),
        'user_agent' => (string) ($config['user_agent'] ?? 'ProconsiAnalyzer/0.1'),
        'blocked' => $result['blocked'],
        'robots' => $result['robots'],
        'fetch' => $fetch,
        'checks' => $result['checks'],
        'puntuacion' => $result['puntuacion'],
        'informe' => Report::build($result['checks'], $result['puntuacion'], !empty($result['blocked']),
            (int) ($config['simulator_top_n'] ?? Simulator::DEFAULT_TOP)),
        'complementario' => $result['complementario'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('[analyze] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    Api::fail(500, 'INTERNAL_ERROR', 'Error interno del analizador.');
}
