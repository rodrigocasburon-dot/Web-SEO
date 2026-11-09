<?php
/**
 * POST api/psi.php  (JSON: {"url": "..."})
 * Consulta PageSpeed Insights (estrategia móvil) y devuelve los registros de C08 (contraste) y
 * C14 (Core Web Vitals). Se llama en paralelo con api/analyze.php porque PageSpeed tarda bastante más.
 * Si PageSpeed falla, responde igualmente 200 con C08 y C14 en SIN_DATOS y el motivo en "psi".
 */
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../src/bootstrap.php';

Api::headers();

try {
    $input = Api::readJsonPost();
    $config = Config::load();
    Api::rateLimit($config, 'psi', 'consultas a PageSpeed Insights');

    // Misma política de URL que el análisis (aunque la descarga la haga Google)
    try {
        $rawUrl = is_string($input['url'] ?? null) ? $input['url'] : '';
        InputUrlPolicy::check($rawUrl);   // Google Maps, acortadores y direcciones locales, sin consultar a Google
        $target = (new UrlGuard())->validate($rawUrl);
    } catch (UrlGuardException $e) {
        Api::fail(400, $e->getErrorCode(), $e->getMessage());
    }

    $psiTimeout = (int) ($config['psi_timeout'] ?? 75);
    set_time_limit($psiTimeout + 15);
    $client = new PsiClient((string) ($config['psi_api_key'] ?? ''), $psiTimeout, (string) ($config['user_agent'] ?? 'ProconsiAnalyzer/0.1'));
    $r = $client->run($target['url']);

    $ctx = CheckContext::forPsi($target['url'], $r['ok'] ? $r['data'] : null, $r['error'], $config);
    $checks = (new CheckRunner())->runPsi($ctx);

    $lh = $r['data']['lighthouseResult'] ?? [];
    Api::respond(200, [
        'ok' => true,
        'url' => $target['url'],
        'checks' => $checks,
        'psi' => [
            'ok' => $r['ok'],
            'error' => $r['error'],
            'estrategia' => 'móvil',
            'fecha' => $r['data']['analysisUTCTimestamp'] ?? null,
            'lighthouse' => $lh['lighthouseVersion'] ?? null,
            'url_final' => $lh['finalDisplayedUrl'] ?? null,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[psi] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    Api::fail(500, 'INTERNAL_ERROR', 'Error interno del analizador.');
}
