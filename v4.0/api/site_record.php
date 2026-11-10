<?php
/**
 * v3.0 · POST api/site_record.php — guarda el análisis del sitio en el historial, con su informe completo.
 * JSON: {"url", "expira", "firma" (registro), "paginas": [firma de cada página],
 *        "enlace": {"id", "expira", "firma"} (opcional, v4: fila de la URL buscada a la que se añade el sitio)}
 *
 * El navegador solo dice QUÉ páginas se analizaron (sus firmas). Los resultados se leen de storage/runs/, donde
 * los dejaron api/site.php, api/site_page.php y api/browser.php, y solo valen los de ESTE análisis (mismo «run»):
 * así no se puede guardar un informe inventado. Las notas se recalculan aquí. Cada análisis se guarda una sola vez.
 *
 * v4: con «enlace» válido, el sitio se añade a la fila que creó api/analyze.php para esa URL (una búsqueda = una
 * fila). Sin él (historial de antes, o sin PageSpeed), se crea una fila propia de tipo «sitio», como en la v3.0.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../src/bootstrap.php';

Api::headers();

try {
    // Hasta 20.000 firmas de 64 caracteres
    $input = Api::readJsonPost(2 * 1024 * 1024);
    $config = Config::load();
    if (!History::enabled($config)) {
        Api::respond(200, ['ok' => true, 'activo' => false]);
    }
    set_time_limit(120);
    $token = SiteToken::fromStorage();
    $url = is_string($input['url'] ?? null) ? $input['url'] : '';
    $expires = (int) ($input['expira'] ?? 0);
    $sig = is_string($input['firma'] ?? null) ? $input['firma'] : '';
    $home = $sig !== '' ? RunStore::get('home', $sig) : null;
    if ($url === '' || !$token->verify('registro|' . $url, $expires, $sig) || !$home || ($home['url'] ?? '') !== $url) {
        Api::fail(403, 'INVALID_SIGNATURE', 'El análisis no es válido o ha caducado.');
    }
    $run = (string) ($home['sitio']['run'] ?? '');

    // Un solo uso por análisis
    $dir = (getenv('WEBSEO_STORAGE_DIR') ?: __DIR__ . '/../storage') . '/site_records';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    foreach (glob($dir . '/*.used') ?: [] as $old) {
        if (filemtime($old) < time() - RunStore::TTL) {
            @unlink($old);
        }
    }
    $fh = @fopen($dir . '/' . hash('sha256', $sig) . '.used', 'x');
    if (!$fh) {
        Api::fail(409, 'ALREADY_RECORDED', 'Este análisis ya está guardado en el historial.');
    }
    fclose($fh);

    // Solo páginas que el servidor analizó para ESTE análisis
    $max = SitePlanner::maxPages($config);
    $pages = [];
    foreach (array_slice(is_array($input['paginas'] ?? null) ? $input['paginas'] : [], 0, $max) as $f) {
        if (is_string($f) && $f !== '' && !isset($pages[$f])) {
            $r = RunStore::get('page', $f);
            if ($r && $run !== '' && ($r['run'] ?? '') === $run) {
                $pages[$f] = $r;
            }
        }
    }
    $pages = array_values($pages);

    // Pruebas con Chrome (firma conocida por el servidor)
    $chrome = !empty($home['sitio']['chrome']['firma']) ? RunStore::get('chrome', (string) $home['sitio']['chrome']['firma']) : null;

    // Notas: media de la portada y las páginas con nota; dos URL que acaban en la misma página cuentan una vez
    $homeBlocked = !empty($home['blocked']);
    $scored = [];
    if (!$homeBlocked) {
        $p = $home['puntuacion'];
        $scored[UrlNormalizer::comparisonKey($url)] = [
            'notas' => ['seo' => $p['areas']['seo']['puntuacion'], 'ia' => $p['areas']['ia']['puntuacion'],
                'accesibilidad' => $p['areas']['accesibilidad']['puntuacion'], 'global' => $p['global']['puntuacion']],
            'fallos' => SiteToken::failing($home['checks']),
        ];
    }
    foreach ($pages as $p) {
        if (empty($p['ok']) || !empty($p['blocked']) || !is_array($p['notas'] ?? null)) {
            continue;
        }
        try {
            $key = UrlNormalizer::comparisonKey((string) $p['url']);
        } catch (Throwable $e) {
            continue;
        }
        $scored[$key] ??= ['notas' => $p['notas'], 'fallos' => (array) ($p['fallos'] ?? [])];
    }
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
    $homeReport['sitio']['run'] = null;
    $homeReport['sitio']['paginas'] = [];
    $homeReport['sitio']['chrome'] = null;
    $siteReport = [
        'home' => $homeReport,
        'pages' => array_map(function (array $p) {
            unset($p['run'], $p['fallos']);
            return $p;
        }, $pages),
        'chromeResult' => $chrome,
        'medias' => $means,
        // Lo que tardó la portada + lo que pasó desde que terminó hasta guardar
        'seconds' => (int) round(((int) ($home['sitio']['tiempo_portada_ms'] ?? 0)) / 1000) + (int) RunStore::age('home', $sig),
    ];

    // v4: se añade a la fila de la URL buscada si el permiso es válido y es la misma dirección
    $link = is_array($input['enlace'] ?? null) ? $input['enlace'] : null;
    $linkId = $link && is_int($link['id'] ?? null) ? $link['id'] : 0;
    $attached = false;
    if ($linkId > 0 && is_string($link['firma'] ?? null)
        && $token->verify('enlace|' . $linkId, (int) ($link['expira'] ?? 0), $link['firma'])) {
        $row = $history->report($linkId);
        if ($row && History::urlKey($row['url']) === History::urlKey($url)) {
            $attached = $history->attachSite($linkId, count($scored), $siteReport);
        }
    }
    if ($attached) {
        $id = $linkId;
    } else {
        $id = $history->recordSite($url, $means, $failing, count($scored), $homeBlocked);
        $history->saveReport($id, ['planned' => count($pages)] + $siteReport);
    }

    Api::respond(200, [
        'ok' => true,
        'activo' => true,
        'id' => $id,
        'paginas' => count($scored),
        'meses' => History::months($config),
    ]);
} catch (Throwable $e) {
    error_log('[site_record] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    Api::fail(500, 'INTERNAL_ERROR', 'Error interno del analizador.');
}
