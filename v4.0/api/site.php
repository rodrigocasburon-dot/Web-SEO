<?php
/**
 * v3.0 · POST api/site.php  (JSON: {"url": "..."})
 * Sitio entero: analiza la portada (como api/analyze.php) y devuelve las páginas del mismo sitio que ha
 * encontrado (enlaces y sitemaps), cada una firmada, para que el navegador las analice EN PARALELO con
 * api/site_page.php. Cada página devuelve a su vez sus enlaces nuevos, ya firmados (v4: todas las páginas,
 * hasta site_max_pages). Cuenta como un análisis en el límite de uso.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../src/bootstrap.php';

Api::headers();

const SITE_ERROR_STATUS = [
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
    $quick = ($input['modo'] ?? 'rapido') !== 'profundo';
    $noindexIntentional = !empty($input['noindex_intentional']);
    try {
        InputUrlPolicy::check($url);
    } catch (UrlGuardException $e) {
        Api::fail(400, $e->getErrorCode(), $e->getMessage());
    }
    // Portada (como analyze.php, hasta 35 s) + sitemaps (hasta 30 s)
    set_time_limit(90);
    $started = microtime(true);
    $options = ['noindex_intentional' => $noindexIntentional, 'con_html' => true];
    $result = (new Analyzer($config))->analyze($url, $options);

    $fetch = array_intersect_key($result['fetch'] ?? [], array_flip(
        ['requested_url', 'final_url', 'status', 'content_type', 'bytes', 'truncated', 'redirects', 'hops', 'timings']
    ));
    if (!$result['ok']) {
        $code = (string) ($result['error']['code'] ?? 'UNKNOWN');
        Api::respond(SITE_ERROR_STATUS[$code] ?? 502, ['ok' => false, 'error' => $result['error'], 'fetch' => $fetch]);
    }

    $plan = ['paginas' => [], 'candidatas' => 0, 'desde_enlaces' => 0, 'desde_sitemap' => 0, 'sitemaps_leidos' => 0, 'notas' => []];
    if (empty($result['blocked'])) {
        $guard = new UrlGuard();
        $opt = [
            'user_agent' => $config['user_agent'] ?? 'ProconsiAnalyzer/0.1',
            'timeout' => 6,
            'max_redirects' => $config['max_redirects'] ?? 5,
            'max_bytes' => 2097152,
        ];
        $fetchFn = fn(string $u, array $o = []) => (new Fetcher($guard, array_merge($opt, $o)))->fetch($u);
        // v4: rápido = portada + 49 páginas principales repartidas por secciones; profundo = todas
        $planConfig = $quick ? ['site_max_pages' => SitePlanner::QUICK_PAGES, 'site_sitemap_files' => 4, 'site_sitemap_budget' => 10] + $config : $config;
        $plan = SitePlanner::plan((string) $result['url'], (string) $result['html'], $result['robots_body'], $fetchFn, $planConfig);
    } else {
        $plan['notas'][] = 'La portada ha bloqueado el acceso: no se analizan más páginas.';
    }

    // Página de error (v3.0, WEB-01 de la v1.0): una URL inventada debe responder 404 o 410, no 200
    $notFound = null;
    if (empty($result['blocked'])) {
        $p = parse_url((string) $result['url']);
        $probe = $p['scheme'] . '://' . $p['host'] . '/radar-web-pagina-que-no-existe-' . bin2hex(random_bytes(4));
        $r404 = (new Fetcher(new UrlGuard(), ['user_agent' => $config['user_agent'] ?? 'ProconsiAnalyzer/0.1', 'timeout' => 6, 'max_bytes' => 262144]))->fetch($probe);
        $code = $r404['ok'] ? (int) $r404['status'] : null;
        $notFound = [
            'id' => 'S01',
            'nombre' => 'Página de error 404',
            'estado' => $code === null ? BaseCheck::NO_DATA : (in_array($code, [404, 410], true) ? BaseCheck::OK : ($code === 200 ? BaseCheck::FAIL : BaseCheck::WARN)),
            'detalle' => $code === null
                ? 'No se ha podido comprobar (' . ($r404['error']['message'] ?? 'error') . ').'
                : ($code === 200
                    ? 'Una dirección inventada responde 200: los buscadores pueden indexar páginas que no existen («soft 404»).'
                    : "Una dirección inventada responde $code" . (in_array($code, [404, 410], true) ? ', como debe.' : '; lo correcto es 404 o 410.')),
        ];
    }

    // v4: se analiza el sitio entero; las firmas valen lo mismo que lo que se guardan los resultados (4 h)
    $expires = time() + RunStore::TTL;
    $token = SiteToken::fromStorage();
    // Identificador del análisis: cada página firmada va ligada a él (también las que se descubren después)
    $run = bin2hex(random_bytes(16));
    // Historial (v3.0): token de un solo uso para guardar el análisis al terminar
    $registro = History::enabled($config) ? ['firma' => $token->sign('registro|' . (string) $result['url'], $expires)] : null;
    $maxPages = $quick ? SitePlanner::QUICK_PAGES : SitePlanner::maxPages($config);
    $plan['paginas'] = array_slice($plan['paginas'], 0, $maxPages);
    $paginas = array_map(fn(string $u) => ['url' => $u, 'firma' => $token->sign(SiteToken::pageScope($run, $u), $expires)], $plan['paginas']);
    // v4: el modo, el tope y el ritmo quedan fijados en el servidor (SiteRun); el navegador no los puede cambiar
    $crawlDelay = $result['robots_body'] !== null
        ? RobotsTxt::parse((string) $result['robots_body'])->crawlDelay(RobotsTxt::token((string) ($config['user_agent'] ?? 'ProconsiAnalyzer')))
        : null;
    if ($paginas && !SiteRun::create($run, $quick ? 'rapido' : 'profundo', $maxPages, array_merge([(string) $result['url']], $plan['paginas']), $crawlDelay,
        (int) ($config['site_min_interval_ms'] ?? SiteRun::DEFAULT_INTERVAL_MS), $expires)) {
        $plan['notas'][] = 'No se ha podido preparar el análisis del resto del sitio (almacén temporal no disponible).';
        $paginas = [];
    }
    if ($crawlDelay !== null && $crawlDelay > 0) {
        $plan['notas'][] = 'robots.txt pide un Crawl-delay de ' . rtrim(rtrim(number_format($crawlDelay, 1, ',', ''), '0'), ',') . ' s: se respeta'
            . ($crawlDelay > SiteRun::MAX_DELAY ? ' hasta un máximo de ' . (int) SiteRun::MAX_DELAY . ' s entre páginas' : '') . ', así que el análisis tardará más.';
    }

    $payload = [
        'ok' => true,
        'url' => $result['url'],
        'titulo' => preg_match('#<title[^>]*>(.*?)</title>#is', (string) $result['html'], $tm)
            ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 200) : '',
        'fecha' => gmdate('c'),
        'user_agent' => (string) ($config['user_agent'] ?? 'ProconsiAnalyzer/0.1'),
        'blocked' => $result['blocked'],
        'robots' => $result['robots'],
        'fetch' => $fetch,
        'checks' => $result['checks'],
        'puntuacion' => $result['puntuacion'],
        // v4 · Pistas para la revisión manual de la portada, en el mismo formato que las demás páginas
        'pistas' => (function (?array $p) {
            if (!$p) {
                return null;
            }
            return [
                'alt_sospechosos' => $p['alt']['sospechosos'],
                'alt_ejemplos' => array_slice(array_map(fn($i) => ['alt' => $i['alt'], 'motivo' => $i['motivo']], $p['alt']['ejemplos']), 0, 3),
                'legibilidad' => !empty($p['legibilidad']['disponible']) && !empty($p['legibilidad']['concluyente']) ? $p['legibilidad']['indice'] : null,
            ];
        })($result['complementario']['pistas_manuales'] ?? null),
        'informe' => Report::build($result['checks'], $result['puntuacion'], !empty($result['blocked']),
            (int) ($config['simulator_top_n'] ?? Simulator::DEFAULT_TOP),
            ['truncado' => !empty($fetch['truncated']), 'umbrales' => (array) ($config['fiabilidad'] ?? [])]),
        'sitio' => [
            'paginas' => $paginas,
            'expira' => $expires,
            'run' => $run,
            'modo' => $quick ? 'rapido' : 'profundo',
            'max_paginas' => $maxPages,
            'noindex_intentional' => $noindexIntentional,
            'candidatas' => $plan['candidatas'],
            'desde_enlaces' => $plan['desde_enlaces'],
            'desde_sitemap' => $plan['desde_sitemap'],
            'sitemaps_leidos' => $plan['sitemaps_leidos'],
            'notas' => $plan['notas'],
            'pagina_404' => $notFound,
            'registro' => $registro,
            'paralelas' => max(1, min(10, (int) ($config['site_parallel'] ?? 6))),
            // Pruebas con Chrome en la portada (si están activadas); firma propia, distinta de la de las páginas
            'chrome' => !empty($result['blocked']) || !BrowserChecks::available($config) ? null
                : ['url' => (string) $result['url'], 'firma' => $token->sign('chrome|' . (string) $result['url'], $expires)],
            'tiempo_portada_ms' => (int) round((microtime(true) - $started) * 1000),
        ],
    ];
    // v3.0: la portada queda 1 h en storage/ para que site_record.php guarde el informe con datos del servidor
    if ($registro && !RunStore::put('home', $registro['firma'], $payload)) {
        // Sin la portada en el servidor no se podría guardar el análisis: no se ofrece el registro
        $payload['sitio']['registro'] = null;
        $payload['sitio']['notas'][] = 'Este análisis no se podrá guardar en el historial (el servidor no ha podido escribir sus datos temporales).';
    }
    Api::respond(200, $payload);
} catch (Throwable $e) {
    Api::internalError('site', $e);
}
