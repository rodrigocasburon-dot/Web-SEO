<?php
/**
 * POST api/analyze.php  (JSON: {"url": "...", "noindex_intentional": false, "palabra_clave": "opcional"})
 * Descarga y analiza el HTML de una URL y devuelve comprobaciones y puntuación en JSON.
 * C08 y C14 (PageSpeed Insights) se obtienen aparte con api/psi.php.
 * Cuenta peticiones por IP para el límite de uso y, si el historial está activado (Fase 4), guarda la
 * nota (sin PageSpeed) y los fallos por gravedad; nunca contenido de la web analizada.
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
    // Google Maps, acortadores y direcciones locales: mensaje claro antes de descargar nada (UrlGuard valida después)
    try {
        InputUrlPolicy::check($url);
    } catch (UrlGuardException $e) {
        Api::fail(400, $e->getErrorCode(), $e->getMessage());
    }
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

    $payload = [
        'ok' => true,
        'historial' => ['activo' => false],
        'url' => $result['url'],
        'fecha' => gmdate('c'),
        'user_agent' => (string) ($config['user_agent'] ?? 'ProconsiAnalyzer/0.1'),
        'blocked' => $result['blocked'],
        'robots' => $result['robots'],
        'fetch' => $fetch,
        'checks' => $result['checks'],
        'puntuacion' => $result['puntuacion'],
        // C08 y C14 llegan después (api/psi.php): hasta entonces constan como pendientes en la fiabilidad
        'informe' => Report::build($result['checks'], $result['puntuacion'], !empty($result['blocked']),
            (int) ($config['simulator_top_n'] ?? Simulator::DEFAULT_TOP),
            ['truncado' => !empty($fetch['truncated']), 'pendientes' => History::PSI_IDS, 'umbrales' => (array) ($config['fiabilidad'] ?? [])]),
        'complementario' => $result['complementario'] ?? null,
    ];
    // Historial (Fase 4 y v3.0): notas, recuentos e informe completo con los datos del servidor. La fila y su
    // informe se guardan en UNA transacción: o quedan los dos o ninguno (antes podía quedar la fila sin informe).
    // Un fallo de la base de datos no impide devolver el informe. Si luego llega PageSpeed, api/score.php lo actualiza.
    if (History::enabled($config)) {
        $payload['historial'] = ['activo' => false, 'error' => 'No se ha podido guardar este análisis en el historial.'];
        $history = History::fromConfig($config);
        if ($history) {
            try {
                $id = $history->atomic(function (History $h) use ($result, $payload): int {
                    $id = $h->record((string) $result['url'], $result['puntuacion'], $result['checks'], !empty($result['blocked']));
                    $h->saveReport($id, [
                        'data' => ['historial' => null] + $payload,
                        'psi' => null,
                        'psiMerged' => false,
                        'psiFailure' => 'PageSpeed Insights no se incorporó a este informe (no respondió a tiempo o se cerró la página antes).',
                    ]);
                    $h->saveReliability($id, $payload['informe']['fiabilidad']);
                    return $id;
                });
                $payload['historial'] = [
                    'activo' => true,
                    'id' => $id,
                    'firma' => $history->signAnalysis($id, $result['checks']),
                    // v4: permiso para añadir a esta fila el análisis del sitio entero (api/site_record.php)
                    'enlace' => (function () use ($id) {
                        $expires = time() + RunStore::TTL;
                        return ['expira' => $expires, 'firma' => SiteToken::fromStorage()->sign('enlace|' . $id, $expires)];
                    })(),
                    'meses' => History::months($config),
                ];
                // La lista de anteriores es accesoria: si falla, el análisis ya está guardado
                try {
                    $payload['historial']['anteriores'] = $history->previous((string) $result['url'], $id);
                } catch (Throwable $e) {
                    $payload['historial']['anteriores'] = [];
                    ErrorLog::record('historial:analyze', $e);
                }
            } catch (Throwable $e) {
                ErrorLog::record('historial:analyze', $e);
            }
        }
    }
    Api::respond(200, $payload);
} catch (Throwable $e) {
    Api::internalError('analyze', $e);
}
