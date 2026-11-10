<?php
/**
 * v3.0 · POST api/site_record.php — guarda un análisis de sitio en el historial, con su informe completo.
 * JSON: {"url", "expira", "firma" (registro), "paginas": [firma de cada página], "psi_firma": "…" (opcional)}
 *
 * El navegador solo dice QUÉ páginas se analizaron (sus firmas). Los resultados se leen de storage/runs/, donde
 * los dejaron api/site.php, api/site_page.php, api/browser.php y api/psi.php: así no se puede guardar un informe
 * inventado. La nota se recalcula aquí (media de las páginas con nota). Cada análisis se guarda una sola vez.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../src/bootstrap.php';

Api::headers();

try {
    $input = Api::readJsonPost(65536);
    $config = Config::load();
    if (!History::enabled($config)) {
        Api::respond(200, ['ok' => true, 'activo' => false]);
    }
    $token = SiteToken::fromStorage();
    $url = is_string($input['url'] ?? null) ? $input['url'] : '';
    $expires = (int) ($input['expira'] ?? 0);
    $sig = is_string($input['firma'] ?? null) ? $input['firma'] : '';
    $home = $sig !== '' ? RunStore::get('home', $sig) : null;
    if ($url === '' || !$token->verify('registro|' . $url, $expires, $sig) || !$home || ($home['url'] ?? '') !== $url) {
        Api::fail(403, 'INVALID_SIGNATURE', 'El análisis no es válido o ha caducado.');
    }

    // Un solo uso por análisis. La reserva impide que dos peticiones simultáneas lo guarden dos veces, pero el
    // análisis solo se da por «usado» cuando la base de datos confirma el guardado (confirm). Si algo falla
    // antes —MySQL caído, error, tiempo agotado—, la reserva se libera al terminar la petición y se puede reintentar.
    $claimKey = 'registro|' . $sig;
    $claim = RunStore::claim($claimKey);
    if ($claim === 'hecho') {
        Api::fail(409, 'ALREADY_RECORDED', 'Este análisis ya está guardado en el historial.');
    }
    if ($claim !== 'ok') {
        Api::fail(409, 'RECORD_IN_PROGRESS', 'Este análisis se está guardando en este momento.');
    }
    $recorded = false;
    register_shutdown_function(function () use (&$recorded, $claimKey) {
        if (!$recorded) {
            RunStore::release($claimKey);
        }
    });

    // Solo las páginas de la muestra de ESTE análisis (las firmó site.php) y que el servidor analizó
    $planned = [];
    foreach ((array) ($home['sitio']['paginas'] ?? []) as $p) {
        $planned[(string) $p['firma']] = (string) $p['url'];
    }
    $pages = [];
    $missing = [];   // páginas que el navegador dice haber analizado y cuyo resultado no está en el servidor
    foreach (array_slice(is_array($input['paginas'] ?? null) ? $input['paginas'] : [], 0, 500) as $f) {
        if (is_string($f) && isset($planned[$f]) && !isset($pages[$f]) && !isset($missing[$f])) {
            $r = RunStore::get('page', $f);
            if ($r) {
                $pages[$f] = $r;
            } else {
                $missing[$f] = $planned[$f];
            }
        }
    }
    $pages = array_values($pages);
    $missing = array_values($missing);

    // Pruebas con Chrome (firma conocida por el servidor) y PageSpeed de la portada (debe ser de esta URL)
    $chrome = !empty($home['sitio']['chrome']['firma']) ? RunStore::get('chrome', (string) $home['sitio']['chrome']['firma']) : null;
    $psiSig = is_string($input['psi_firma'] ?? null) ? $input['psi_firma'] : '';
    $psi = $psiSig !== '' ? RunStore::get('psi', $psiSig) : null;
    if ($psi && UrlNormalizer::comparisonKey((string) ($psi['url'] ?? '')) !== UrlNormalizer::comparisonKey($url)) {
        $psi = null;
    }

    // Nota: media de la portada y las páginas con nota; dos URL que acaban en la misma página cuentan una vez
    $homeBlocked = !empty($home['blocked']);
    $scored = [];
    // Para la fiabilidad: páginas con nota (con sus comprobaciones) y recuento de las que no la tienen
    $withScore = [];
    $counts = ['previstas' => count($planned) + 1, 'bloqueadas' => 0, 'errores' => 0, 'sin_resultado' => count($missing), 'duplicadas' => 0];
    if (!$homeBlocked) {
        $withScore[] = ['checks' => $home['checks']];
        $p = $home['puntuacion'];
        $scored[UrlNormalizer::comparisonKey($url)] = [
            'notas' => ['seo' => $p['areas']['seo']['puntuacion'], 'ia' => $p['areas']['ia']['puntuacion'],
                'accesibilidad' => $p['areas']['accesibilidad']['puntuacion'], 'global' => $p['global']['puntuacion']],
            'fallos' => SiteToken::failing($home['checks']),
        ];
    }
    foreach ($pages as $p) {
        if (empty($p['ok']) || !empty($p['blocked']) || !is_array($p['notas'] ?? null)) {
            $counts[empty($p['ok']) ? 'errores' : 'bloqueadas']++;
            continue;
        }
        try {
            $key = UrlNormalizer::comparisonKey((string) $p['url']);
        } catch (Throwable $e) {
            $counts['errores']++;
            continue;
        }
        if (isset($scored[$key])) {
            $counts['duplicadas']++;
            continue;
        }
        $scored[$key] = ['notas' => $p['notas'], 'fallos' => (array) ($p['fallos'] ?? [])];
        $withScore[] = ['checks' => (array) ($p['checks'] ?? [])];
    }
    $reliability = Reliability::site($withScore, $homeBlocked, $counts, (array) ($config['fiabilidad'] ?? []));
    $means = null;
    $failing = [];
    if ($scored) {
        $means = [];
        foreach (['seo', 'ia', 'accesibilidad', 'global'] as $k) {
            $vals = array_column(array_column($scored, 'notas'), $k);
            $means[$k] = $vals ? array_sum($vals) / count($vals) : null;
        }
        foreach ($scored as $v) {
            $failing += $v['fallos'];
        }
    }

    $history = History::fromConfig($config);
    if (!$history) {
        Api::fail(503, 'HISTORY_UNAVAILABLE', 'No se ha podido guardar este análisis en el historial.');
    }
    // Informe completo (lo que necesita assets/js/site.js para volver a pintarlo), sin firmas
    $homeReport = $home;
    $homeReport['sitio']['registro'] = null;
    $homeReport['sitio']['paginas'] = array_map(fn($p) => ['url' => $p['url']], (array) $homeReport['sitio']['paginas']);
    $homeReport['sitio']['chrome'] = null;
    $report = [
        'home' => $homeReport,
        'pages' => array_map(function (array $p) {
            unset($p['firma_pagina'], $p['firma_resultado']);
            return $p;
        }, $pages),
        'chromeResult' => $chrome,
        'psiResult' => $psi,
        'planned' => count($planned),
        'sin_resultado' => $missing,
        'fiabilidad' => $reliability,
        // Lo que tardó la portada + lo que pasó desde que terminó hasta guardar
        'seconds' => (int) round(((int) ($home['sitio']['tiempo_portada_ms'] ?? 0)) / 1000) + (int) RunStore::age('home', $sig),
    ];

    // Fila e informe en UNA transacción: si el informe no se puede guardar, tampoco queda la fila
    try {
        $id = $history->atomic(function (History $h) use ($url, $means, $failing, $scored, $homeBlocked, $report, $reliability): int {
            $id = $h->recordSite($url, $means, $failing, count($scored), $homeBlocked);
            $h->saveReport($id, $report);
            $h->saveReliability($id, $reliability);
            return $id;
        });
    } catch (Throwable $e) {
        ErrorLog::record('historial:site_record', $e);
        // La reserva se libera al terminar (shutdown): el navegador puede reintentar
        Api::fail(503, 'HISTORY_UNAVAILABLE', 'No se ha podido guardar este análisis en el historial. Puedes volver a intentarlo.');
    }
    // Solo ahora el análisis queda marcado como usado
    $recorded = true;
    RunStore::confirm($claimKey, ['id' => $id]);

    try {
        $previous = $history->previous($url, $id, 10, 'sitio');
    } catch (Throwable $e) {
        $previous = [];
        ErrorLog::record('historial:site_record', $e);
    }
    Api::respond(200, [
        'ok' => true,
        'activo' => true,
        'id' => $id,
        'paginas' => count($scored),
        'sin_resultado' => count($missing),
        'fiabilidad' => $reliability,
        'meses' => History::months($config),
        'anteriores' => $previous,
    ]);
} catch (Throwable $e) {
    Api::internalError('site_record', $e);
}
