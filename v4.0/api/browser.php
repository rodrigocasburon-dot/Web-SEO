<?php
/**
 * v3.0 · POST api/browser.php  (JSON: {"url": "...", "expira": 123, "firma": "..."})
 * Pruebas con Chrome (BrowserChecks) sobre la portada de un análisis de sitio. Solo URL firmadas por
 * api/site.php y solo si está activado en la configuración ('chrome' => ['activo' => true]).
 * Informativas: no cambian la nota.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../src/bootstrap.php';

Api::headers();

try {
    $input = Api::readJsonPost();
    $config = Config::load();
    if (!BrowserChecks::available($config)) {
        Api::fail(404, 'BROWSER_DISABLED', 'Las pruebas con Chrome no están activadas en este servidor.');
    }
    $url = is_string($input['url'] ?? null) ? $input['url'] : '';
    $expires = (int) ($input['expira'] ?? 0);
    $signature = is_string($input['firma'] ?? null) ? $input['firma'] : '';
    if ($url === '' || !SiteToken::fromStorage()->verify('chrome|' . $url, $expires, $signature)) {
        Api::fail(403, 'INVALID_SIGNATURE', 'Esta página no forma parte de un análisis de sitio válido o ha caducado.');
    }
    Api::rateLimit($config, 'browser', 'pruebas con Chrome');
    set_time_limit(120);
    $started = microtime(true);
    $records = BrowserChecks::run($url, $config);
    $out = ['ok' => true, 'url' => $url, 'checks' => $records, 'ms' => (int) round((microtime(true) - $started) * 1000)];
    RunStore::put('chrome', $signature, $out);   // v3.0: para el informe del historial
    Api::respond(200, $out);
} catch (Throwable $e) {
    error_log('[browser] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    Api::fail(500, 'BROWSER_ERROR', 'No se han podido hacer las pruebas con Chrome.');
}
