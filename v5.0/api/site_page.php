<?php
/**
 * v3.0 · POST api/site_page.php  (JSON: {"url": "...", "run": "...", "expira": 123, "firma": "..."})
 * Analiza UNA página de un sitio. Solo acepta URL firmadas por api/site.php o por esta misma API
 * (no se puede usar para descargar cualquier dirección). El navegador hace varias a la vez.
 * Sin C16 (enlaces) ni C18 (sitemap), que son de todo el sitio y ya se hacen en la portada.
 * v4: devuelve también los enlaces de la página a otras del mismo sitio, firmados para el mismo análisis,
 * para que el navegador los añada a la cola y se recorra el sitio entero.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../src/bootstrap.php';

Api::headers();

/** Máximo de enlaces nuevos que se devuelven por página */
const SITE_PAGE_MAX_LINKS = 1000;

try {
    $input = Api::readJsonPost();
    $config = Config::load();

    $url = is_string($input['url'] ?? null) ? $input['url'] : '';
    $run = is_string($input['run'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $input['run']) ? $input['run'] : '';
    $expires = (int) ($input['expira'] ?? 0);
    $signature = is_string($input['firma'] ?? null) ? $input['firma'] : '';
    $token = SiteToken::fromStorage();
    if ($url === '' || $run === '' || !$token->verify(SiteToken::pageScope($run, $url), $expires, $signature)) {
        Api::fail(403, 'INVALID_SIGNATURE', 'Esta página no forma parte de un análisis de sitio válido o ha caducado.');
    }
    // Límite propio, generoso: cada análisis de sitio lanza muchas páginas
    $max = SitePlanner::maxPages($config);
    $config['rate_limit'] = ['max_requests' => $max * 2, 'window_seconds' => (int) ($config['rate_limit']['window_seconds'] ?? 600)];
    Api::rateLimit($config, 'site_page', 'páginas analizadas');

    // v4: turno en el análisis (tope de páginas, análisis detenido y ritmo de peticiones al sitio)
    $turn = SiteRun::reserve($run);
    if (!$turn['ok']) {
        $status = ['RUN_UNKNOWN' => 403, 'RETRY' => 429][$turn['codigo']] ?? 409;
        Api::respond($status, ['ok' => false, 'url' => $url, 'run' => $run,
            'error' => ['code' => $turn['codigo'], 'message' => $turn['mensaje']], 'reintentar' => $turn['reintentar']]);
    }
    set_time_limit(25 + (int) ceil($turn['espera']));
    if ($turn['espera'] > 0) {
        usleep((int) round($turn['espera'] * 1e6));
    }
    $state = SiteRun::get($run);
    $started = microtime(true);
    $result = (new Analyzer($config))->analyze($url, [
        'pagina_de_sitio' => true,
        'noindex_intentional' => !empty($input['noindex_intentional']),
        'sitio_host' => (string) parse_url($url, PHP_URL_HOST),
    ]);
    $ms = (int) round((microtime(true) - $started) * 1000);
    // El sitio pide frenar (429 Too Many Requests o 503 Service Unavailable): se detiene el análisis
    $httpStatus = (int) ($result['fetch']['status'] ?? 0);
    if ($httpStatus === 429 || $httpStatus === 503) {
        SiteRun::stop($run, 'El sitio analizado ha respondido HTTP ' . $httpStatus . ' (pide reducir las peticiones): se ha detenido el análisis para no sobrecargarlo.');
    }
    if (!$result['ok']) {
        $out = ['ok' => false, 'url' => $url, 'error' => $result['error'], 'ms' => $ms, 'run' => $run];
        $out['guardado_temporal'] = RunStore::put('page', $signature, $out);
        Api::respond(200, $out);
    }
    // Respuesta compacta: lo que cumple va sin textos; lo que falla o avisa, con su detalle y recomendación
    $checks = array_map(function (array $c) {
        $row = ['id' => $c['id'], 'estado' => $c['estado'], 'severidad' => $c['severidad'], 'aplica' => $c['aplica']];
        if (in_array($c['estado'], [BaseCheck::FAIL, BaseCheck::WARN], true) && $c['aplica'] !== false) {
            $row['ocurrencias'] = $c['ocurrencias'];
            $row['detalle'] = mb_strimwidth((string) $c['detalle'], 0, 300, '…');
            $row['recomendacion'] = $c['recomendacion'];
        }
        return $row;
    }, $result['checks']);
    $p = $result['puntuacion'];
    $notas = [
        'seo' => $p['areas']['seo']['puntuacion'],
        'ia' => $p['areas']['ia']['puntuacion'],
        'accesibilidad' => $p['areas']['accesibilidad']['puntuacion'],
        'global' => $p['global']['puntuacion'],
    ];
    $out = [
        'ok' => true,
        'run' => $run,
        'url' => $result['url'],
        'status' => $result['fetch']['status'] ?? null,
        'blocked' => $result['blocked'],
        'titulo' => mb_substr((string) ($result['titulo'] ?? ''), 0, 200),
        'ms' => $ms,
        'checks' => $checks,
        'notas' => $notas,
        'fallos' => SiteToken::failing($result['checks']),
        'pistas' => $result['pistas'] ?? null,
    ];
    // Resultado calculado aquí, guardado para el historial (site_record.php). Sin los enlaces: no hacen falta.
    $out['guardado_temporal'] = RunStore::put('page', $signature, $out);
    // Enlaces nuevos, firmados para este mismo análisis (el navegador descarta los que ya conoce). Solo en el profundo.
    // El servidor decide: solo en el modo profundo y sin pasar del tope (SiteRun); el navegador descarta las que ya conoce.
    $links = ($state['modo'] ?? 'rapido') === 'profundo' ? array_slice((array) ($result['enlaces'] ?? []), 0, SITE_PAGE_MAX_LINKS) : [];
    $links = $links ? SiteRun::allowLinks($run, $links) : [];
    $out['nuevas'] = array_map(fn(string $u) => ['url' => $u, 'firma' => $token->sign(SiteToken::pageScope($run, $u), $expires)], $links);
    $after = SiteRun::get($run);
    if ($after !== null && $after['parado'] !== null) {
        $out['parado'] = $after['parado'];
    }
    Api::respond(200, $out);
} catch (Throwable $e) {
    Api::internalError('site_page', $e);
}
