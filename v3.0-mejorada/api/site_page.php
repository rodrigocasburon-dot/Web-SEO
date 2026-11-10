<?php
/**
 * v3.0 · POST api/site_page.php  (JSON: {"url": "...", "expira": 123, "firma": "..."})
 * Analiza UNA página de la muestra de un sitio. Solo acepta URL firmadas por api/site.php
 * (no se puede usar para descargar cualquier dirección). El navegador hace varias a la vez.
 * Sin C16 (enlaces) ni C18 (sitemap), que son de todo el sitio y ya se hacen en la portada.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../src/bootstrap.php';

Api::headers();

try {
    $input = Api::readJsonPost();
    $config = Config::load();

    $url = is_string($input['url'] ?? null) ? $input['url'] : '';
    $expires = (int) ($input['expira'] ?? 0);
    $signature = is_string($input['firma'] ?? null) ? $input['firma'] : '';
    if ($url === '' || !SiteToken::fromStorage()->verify($url, $expires, $signature)) {
        Api::fail(403, 'INVALID_SIGNATURE', 'Esta página no forma parte de un análisis de sitio válido o ha caducado.');
    }
    // Límite propio, generoso: cada análisis de sitio lanza muchas páginas
    $max = max(1, (int) ($config['site_max_pages'] ?? 50));
    $config['rate_limit'] = ['max_requests' => $max * 4, 'window_seconds' => (int) ($config['rate_limit']['window_seconds'] ?? 600)];
    Api::rateLimit($config, 'site_page', 'páginas analizadas');

    set_time_limit(25);
    $started = microtime(true);
    $result = (new Analyzer($config))->analyze($url, [
        'pagina_de_sitio' => true,
        'noindex_intentional' => !empty($input['noindex_intentional']),
    ]);
    $ms = (int) round((microtime(true) - $started) * 1000);
    if (!$result['ok']) {
        $out = ['ok' => false, 'url' => $url, 'error' => $result['error'], 'ms' => $ms];
        $out['guardado_temporal'] = RunStore::put('page', $signature, $out);
        Api::respond(200, $out);
    }
    // Respuesta compacta: lo necesario para agregar (sin evidencias largas)
    $checks = array_map(fn(array $c) => [
        'id' => $c['id'],
        'nombre' => $c['nombre'],
        'estado' => $c['estado'],
        'severidad' => $c['severidad'],
        'ocurrencias' => $c['ocurrencias'],
        'aplica' => $c['aplica'],
        'detalle' => mb_strimwidth((string) $c['detalle'], 0, 300, '…'),
        'recomendacion' => $c['recomendacion'],
    ], $result['checks']);
    $p = $result['puntuacion'];
    $notas = [
        'seo' => $p['areas']['seo']['puntuacion'],
        'ia' => $p['areas']['ia']['puntuacion'],
        'accesibilidad' => $p['areas']['accesibilidad']['puntuacion'],
        'global' => $p['global']['puntuacion'],
    ];
    $failing = SiteToken::failing($result['checks']);
    $out = [
        'ok' => true,
        'url' => $result['url'],
        'status' => $result['fetch']['status'] ?? null,
        'blocked' => $result['blocked'],
        'titulo' => mb_substr((string) ($result['titulo'] ?? ''), 0, 200),
        'ms' => $ms,
        'checks' => $checks,
        'notas' => $notas,
        // Historial (v3.0): resultado firmado para que api/site_record.php no acepte notas inventadas
        'fallos' => $failing,
        'firma_pagina' => $signature,
        'firma_resultado' => SiteToken::fromStorage()->signResult($signature, (string) $result['url'], $notas, $failing, !empty($result['blocked'])),
    ];
    // v3.0: resultado calculado aquí, guardado 1 h para el historial (site_record.php)
    $out['guardado_temporal'] = RunStore::put('page', $signature, $out);
    Api::respond(200, $out);
} catch (Throwable $e) {
    Api::internalError('site_page', $e);
}
