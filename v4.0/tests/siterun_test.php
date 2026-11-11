<?php
// v4 · Control en el servidor del análisis del sitio entero (src/SiteRun.php) y Crawl-delay de robots.txt.
// Sin red: estado en un directorio temporal (WEBSEO_STORAGE_DIR).

function withRunStorage(callable $fn): void
{
    $old = getenv('WEBSEO_STORAGE_DIR');
    putenv('WEBSEO_STORAGE_DIR=' . tempDir('siterun'));
    try {
        $fn();
    } finally {
        putenv($old === false ? 'WEBSEO_STORAGE_DIR' : 'WEBSEO_STORAGE_DIR=' . $old);
    }
}

function newRun(string $mode, int $max, array $urls = ['https://a.example/'], ?float $delay = null, int $intervalMs = 0): string
{
    $run = bin2hex(random_bytes(16));
    assertTrue(SiteRun::create($run, $mode, $max, $urls, $delay, $intervalMs, time() + 600));
    return $run;
}

test('RobotsTxt: Crawl-delay del grupo propio o, si no lo hay, de «*»', function () {
    $r = RobotsTxt::parse("User-agent: *\nCrawl-delay: 2\nDisallow: /x\n\nUser-agent: ProconsiAnalyzer\nCrawl-delay: 5\n");
    assertSame(5.0, $r->crawlDelay('ProconsiAnalyzer'));
    assertSame(2.0, $r->crawlDelay('OtroBot'));
    assertSame(null, RobotsTxt::parse("User-agent: *\nDisallow: /x\n")->crawlDelay('ProconsiAnalyzer'));
    // El grupo propio sin Crawl-delay manda sobre el de «*»
    assertSame(null, RobotsTxt::parse("User-agent: *\nCrawl-delay: 9\n\nUser-agent: ProconsiAnalyzer\nDisallow:\n")->crawlDelay('ProconsiAnalyzer'));
});

test('SiteRun: el modo rápido no firma enlaces nuevos aunque el navegador los pida', function () {
    withRunStorage(function () {
        $run = newRun('rapido', 49, ['https://a.example/', 'https://a.example/b']);
        assertSame([], SiteRun::allowLinks($run, ['https://a.example/c', 'https://a.example/d']));
    });
});

test('SiteRun: el profundo firma solo páginas nuevas y no pasa del tope', function () {
    withRunStorage(function () {
        $run = newRun('profundo', 4, ['https://a.example/', 'https://a.example/b', 'https://a.example/c']);
        // b ya estaba firmada; caben 2 más (4 − 2 de la lista inicial, sin contar la portada)
        $ok = SiteRun::allowLinks($run, ['https://a.example/b', 'https://a.example/d', 'https://a.example/d#x', 'https://a.example/e', 'https://a.example/f']);
        assertSame(['https://a.example/d', 'https://a.example/e'], $ok);
        assertSame([], SiteRun::allowLinks($run, ['https://a.example/g']));
    });
});

test('SiteRun: no analiza más páginas que el tope y rechaza análisis inexistentes', function () {
    withRunStorage(function () {
        $run = newRun('profundo', 2);
        assertTrue(SiteRun::reserve($run, 1000.0)['ok']);
        assertTrue(SiteRun::reserve($run, 1000.0)['ok']);
        $r = SiteRun::reserve($run, 1000.0);
        assertSame('LIMIT_REACHED', $r['codigo']);
        assertSame('RUN_UNKNOWN', SiteRun::reserve(str_repeat('0', 32))['codigo']);
    });
});

test('SiteRun: reparte el ritmo con el Crawl-delay (máx. 10 s) y pide reintentar si la espera es larga', function () {
    withRunStorage(function () {
        $run = newRun('profundo', 100, ['https://a.example/'], 3.0, 250);
        assertSame(0.0, SiteRun::reserve($run, 1000.0)['espera']);
        assertSame(3.0, SiteRun::reserve($run, 1000.0)['espera']);
        assertSame(6.0, SiteRun::reserve($run, 1000.0)['espera']);
        $r = SiteRun::reserve($run, 1000.0);           // haría falta esperar 9 s (> MAX_WAIT)
        assertSame('RETRY', $r['codigo']);
        assertTrue($r['reintentar'] > 0);
        assertSame(10.0, (float) SiteRun::get(newRun('profundo', 5, ['https://a.example/'], 60.0))['intervalo']);
        // Sin Crawl-delay manda el intervalo mínimo
        assertSame(0.25, (float) SiteRun::get(newRun('profundo', 5, ['https://a.example/'], null, 250))['intervalo']);
    });
});

test('SiteRun: si el sitio pide frenar (429/503) se detiene y no se analizan ni firman más páginas', function () {
    withRunStorage(function () {
        $run = newRun('profundo', 100);
        SiteRun::stop($run, 'El sitio analizado ha respondido HTTP 429.');
        $r = SiteRun::reserve($run);
        assertSame('SITE_STOPPED', $r['codigo']);
        assertSame('El sitio analizado ha respondido HTTP 429.', $r['mensaje']);
        assertSame([], SiteRun::allowLinks($run, ['https://a.example/x']));
    });
});
