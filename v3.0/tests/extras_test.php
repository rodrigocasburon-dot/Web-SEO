<?php
// Pruebas de C16 (enlaces rotos), C18 (sitemap) y C20 (certificado y contenido mixto). Sin red:
// las descargas usan un Fetcher con transporte simulado (fetcherWith) o fakeSiteFetch.

/** Contexto a partir de HTML en línea. */
function htmlCtx(string $html, string $url = 'https://example.com/pagina', array $page = [], ?array $robots = null, array $options = [], array $config = []): CheckContext
{
    $config = array_merge(require __DIR__ . '/../config/config.example.php', $config);
    $page = array_merge(['final_url' => $url, 'status' => 200, 'headers' => ['content-type' => 'text/html; charset=utf-8'],
        'content_type' => 'text/html; charset=utf-8', 'body' => $html], $page);
    return CheckContext::fromFetch($page, $config, $robots ?? ['status' => 404, 'body' => '', 'error' => null], $options);
}

function urlset(array $locs, string $extra = ''): string
{
    $items = implode('', array_map(fn($l) => '<url><loc>' . htmlspecialchars($l, ENT_XML1) . "</loc>$extra</url>", $locs));
    return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $items . '</urlset>';
}

function sitemapIndex(array $locs): string
{
    $items = implode('', array_map(fn($l) => "<sitemap><loc>$l</loc></sitemap>", $locs));
    return '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $items . '</sitemapindex>';
}

// ---------- C16 Enlaces rotos ----------

test('C16: rotos (404 y dominio inexistente), sin confirmar (503) y no comprobables (403, IP privada)', function () {
    $routes = [
        'https://example.com/ok' => fakeResp(200),
        'https://example.com/roto' => fakeResp(404),
        'https://otro.example/caido' => fakeResp(503),
        'https://example.com/redir' => fakeResp(301, ['location' => '/ok']),
        'https://example.com/prohibido' => fakeResp(403),
    ];
    $opts = [];
    $ctx = fx('c16_links');
    $ctx->fetch = function (string $url, array $o = []) use ($routes, &$opts) {
        $opts[] = $o;
        return fetcherWith($routes, ['noexiste.example' => []], $o)->fetch($url);
    };
    $r = runCheck('C16BrokenLinks', $ctx);
    assertSame('NO_CUMPLE', $r['estado'], $r['detalle']);
    assertSame(2, $r['ocurrencias']);
    // mailto, tel, javascript, anclas, la propia página y el duplicado con ancla no cuentan
    assertSame(['enlaces_en_la_pagina' => 7, 'comprobados' => 5, 'rotos' => 2, 'sin_confirmar' => 1, 'no_comprobados' => 2], $r['valor_medido']);
    assertTrue(strpos($r['evidencia'][0]['extracto'], 'HTTP 404: https://example.com/roto') === 0, $r['evidencia'][0]['extracto']);
    assertTrue(strpos($r['evidencia'][1]['extracto'], 'Dominio inexistente: https://noexiste.example/') === 0);
    assertTrue(strpos($r['evidencia'][2]['extracto'], 'HTTP 503: https://otro.example/caido') === 0);
    assertTrue(is_int($r['evidencia'][0]['linea']));
    assertSame(65536, $opts[0]['max_bytes']);
    assertTrue($opts[0]['timeout'] >= 1 && $opts[0]['timeout'] <= 5);
});

test('C16: máximo 20 enlaces; sin errores → CUMPLE con los no comprobados indicados', function () {
    $links = implode('', array_map(fn($i) => "<a href=\"/p$i\">Enlace $i</a>", range(1, 25)));
    $log = [];
    $ctx = htmlCtx("<html><body>$links</body></html>");
    $ctx->fetch = fakeSiteFetch($ctx->url, [], $log);
    $r = runCheck('C16BrokenLinks', $ctx);
    assertSame('CUMPLE', $r['estado']);
    assertSame(20, count($log));
    assertSame(20, $r['valor_medido']['comprobados']);
    assertSame(5, $r['valor_medido']['no_comprobados']);
    assertTrue(strpos($r['detalle'], '5 sin comprobar') !== false, $r['detalle']);
});

test('C16: solo errores del servidor → AVISO; sin enlaces → no aplica; sin red o sin tiempo → SIN_DATOS', function () {
    $ctx = htmlCtx('<a href="/a">a</a>');
    $ctx->fetch = fakeSiteFetch($ctx->url, ['https://example.com/a' => [500]]);
    assertSame('AVISO', runCheck('C16BrokenLinks', $ctx)['estado']);

    $ctx = htmlCtx('<a href="/a">a</a>');
    $ctx->fetch = fakeSiteFetch($ctx->url, ['https://example.com/a' => 'TIMEOUT']);
    assertSame('AVISO', runCheck('C16BrokenLinks', $ctx)['estado']);

    $ctx = htmlCtx('<p>Sin enlaces <a href="mailto:a@b.es">correo</a></p>');
    $ctx->fetch = fakeSiteFetch($ctx->url);
    $r = runCheck('C16BrokenLinks', $ctx);
    assertSame(false, $r['aplica']);

    assertSame('SIN_DATOS', runCheck('C16BrokenLinks', htmlCtx('<a href="/a">a</a>'))['estado']);

    $ctx = htmlCtx('<a href="/a">a</a>', 'https://example.com/pagina', [], null, [], ['links_budget' => 0]);
    $ctx->fetch = fakeSiteFetch($ctx->url);
    assertSame('SIN_DATOS', runCheck('C16BrokenLinks', $ctx)['estado']);
});

test('C16: respeta <base href> al resolver enlaces relativos', function () {
    $log = [];
    $ctx = htmlCtx('<head><base href="https://cdn.example.com/dir/"></head><body><a href="x">x</a></body>');
    $ctx->fetch = fakeSiteFetch($ctx->url, [], $log);
    runCheck('C16BrokenLinks', $ctx);
    assertSame('https://cdn.example.com/dir/x', $log[0][0]);
});

// ---------- C18 Sitemap ----------

test('C18: sitemap declarado en robots.txt que incluye la URL (con barra final) → CUMPLE', function () {
    $log = [];
    $robots = ['status' => 200, 'body' => "User-agent: *\nDisallow:\nSitemap: https://example.com/sm.xml\n", 'error' => null];
    $ctx = htmlCtx('<html></html>', 'https://example.com/pagina', [], $robots);
    $ctx->fetch = fakeSiteFetch($ctx->url, ['https://example.com/sm.xml' => [200, urlset(['https://example.com/', 'https://EXAMPLE.com/pagina/'])]], $log);
    $r = runCheck('C18Sitemap', $ctx);
    assertSame('CUMPLE', $r['estado'], $r['detalle']);
    assertSame(['https://example.com/sm.xml'], array_column($log, 0), 'No se pide /sitemap.xml si robots.txt declara sitemaps');
    assertSame(1, $r['valor_medido']['sitemaps_en_robots']);
});

test('C18: índice de sitemaps; <image:loc> no cuenta como URL de página', function () {
    $image = '<image:image><image:loc>https://example.com/pagina</image:loc></image:image>';
    $routes = [
        'https://example.com/sitemap.xml' => [200, sitemapIndex(['https://example.com/sm1.xml'])],
        'https://example.com/sm1.xml' => [200, urlset(['https://example.com/otra'], $image)],
    ];
    $ctx = htmlCtx('<html></html>');
    $ctx->fetch = fakeSiteFetch($ctx->url, $routes);
    $r = runCheck('C18Sitemap', $ctx);
    assertSame('NO_CUMPLE', $r['estado'], $r['detalle']);
    assertSame(1, $r['valor_medido']['urls_leidas']);

    if (function_exists('gzencode')) {
        $routes['https://example.com/sitemap.xml'] = [200, sitemapIndex(['https://example.com/sm1.xml', 'https://example.com/sm2.xml.gz'])];
        $routes['https://example.com/sm2.xml.gz'] = [200, gzencode(urlset(['https://example.com/pagina'])), 'application/gzip'];
        $ctx->fetch = fakeSiteFetch($ctx->url, $routes);
        $r = runCheck('C18Sitemap', $ctx);
        assertSame('CUMPLE', $r['estado'], $r['detalle']);
        assertSame(3, $r['valor_medido']['archivos_leidos']);
    }
});

test('C18: variante http/www → AVISO; formato de texto → CUMPLE', function () {
    $ctx = htmlCtx('<html></html>');
    $ctx->fetch = fakeSiteFetch($ctx->url, ['https://example.com/sitemap.xml' => [200, urlset(['http://www.example.com/pagina'])]]);
    $r = runCheck('C18Sitemap', $ctx);
    assertSame('AVISO', $r['estado']);
    assertSame('con otra variante', $r['valor_medido']['incluye_la_url']);

    $ctx->fetch = fakeSiteFetch($ctx->url, ['https://example.com/sitemap.xml' => [200, "https://example.com/a\nhttps://example.com/pagina\n", 'text/plain']]);
    assertSame('CUMPLE', runCheck('C18Sitemap', $ctx)['estado']);
});

test('C18: sin sitemap (404 o HTML) → NO_CUMPLE; bloqueo o red → SIN_DATOS; noindex intencionado → no aplica', function () {
    $ctx = htmlCtx('<html></html>');
    $ctx->fetch = fakeSiteFetch($ctx->url, ['https://example.com/sitemap.xml' => [404]]);
    $r = runCheck('C18Sitemap', $ctx);
    assertSame('NO_CUMPLE', $r['estado']);
    assertTrue(strpos($r['detalle'], 'No hay sitemap') === 0, $r['detalle']);

    $ctx->fetch = fakeSiteFetch($ctx->url, ['https://example.com/sitemap.xml' => [200, '<html><body>Inicio</body></html>', 'text/html']]);
    assertSame('NO_CUMPLE', runCheck('C18Sitemap', $ctx)['estado']);

    $ctx->fetch = fakeSiteFetch($ctx->url, ['https://example.com/sitemap.xml' => [403]]);
    assertSame('SIN_DATOS', runCheck('C18Sitemap', $ctx)['estado']);

    $ctx->fetch = fakeSiteFetch($ctx->url, ['https://example.com/sitemap.xml' => 'TIMEOUT']);
    assertSame('SIN_DATOS', runCheck('C18Sitemap', $ctx)['estado']);

    $ctx = htmlCtx('<html></html>', 'https://example.com/pagina', [], null, ['noindex_intentional' => true]);
    $ctx->fetch = fakeSiteFetch($ctx->url);
    assertSame(false, runCheck('C18Sitemap', $ctx)['aplica']);
});

test('C18: lectura parcial (más archivos que el máximo o truncado) sin encontrar la URL → AVISO', function () {
    $children = array_map(fn($i) => "https://example.com/sm$i.xml", range(1, 6));
    $routes = ['https://example.com/sitemap.xml' => [200, sitemapIndex($children)]];
    foreach ($children as $c) {
        $routes[$c] = [200, urlset(['https://example.com/otra'])];
    }
    $log = [];
    $ctx = htmlCtx('<html></html>');
    $ctx->fetch = fakeSiteFetch($ctx->url, $routes, $log);
    $r = runCheck('C18Sitemap', $ctx);
    assertSame('AVISO', $r['estado'], $r['detalle']);
    assertSame(5, count($log), 'Máximo 5 archivos');

    $ctx->fetch = fakeSiteFetch($ctx->url, ['https://example.com/sitemap.xml' => [200, urlset(['https://example.com/otra']), 'application/xml', true]]);
    assertSame('AVISO', runCheck('C18Sitemap', $ctx)['estado']);
});

// ---------- C20 Certificado y contenido mixto ----------

test('C20: contenido mixto activo → NO_CUMPLE (k = 3); pasivo contado aparte', function () {
    $r = runCheck('C20HttpsSecurity', fx('c20_bad'));
    assertSame('NO_CUMPLE', $r['estado'], $r['detalle']);
    assertSame(3, $r['ocurrencias']);   // script, formulario y hoja de estilos por http
    assertSame(3, $r['valor_medido']['contenido_mixto_activo']);
    assertSame(3, $r['valor_medido']['contenido_mixto_pasivo']);   // img src, candidato de srcset e icono
    assertSame('certificado TLS', $r['evidencia'][0]['selector']);
    assertTrue(strpos($r['evidencia'][1]['extracto'], 'http://cdn.example.com/app.js') !== false);
});

test('C20: upgrade-insecure-requests convierte el contenido mixto activo en AVISO', function () {
    $html = (string) file_get_contents(FIXTURES . 'c20_bad.html');
    $ctx = htmlCtx($html, 'https://example.com/pagina', ['headers' => ['content-type' => 'text/html', 'content-security-policy' => "default-src 'self'; upgrade-insecure-requests"]]);
    assertSame('AVISO', runCheck('C20HttpsSecurity', $ctx)['estado']);
    $meta = str_replace('<head>', '<head><meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">', $html);
    assertSame('AVISO', runCheck('C20HttpsSecurity', htmlCtx($meta))['estado']);
});

test('C20: certificado (emisor y caducidad) y página por http', function () {
    $clean = '<html><head><script src="https://cdn.example.com/a.js"></script></head><body><img src="/a.jpg" alt=""></body></html>';
    $r = runCheck('C20HttpsSecurity', htmlCtx($clean, 'https://example.com/', ['cert' => [
        'subject' => 'CN = example.com', 'issuer' => "C = US, O = Let's Encrypt, CN = R11", 'valid_to' => time() + 90 * 86400 + 600]]));
    assertSame('CUMPLE', $r['estado'], $r['detalle']);
    assertSame(90, $r['valor_medido']['días_para_caducar']);
    assertTrue(strpos($r['evidencia'][0]['extracto'], "emisor: Let's Encrypt") !== false, $r['evidencia'][0]['extracto']);

    $r = runCheck('C20HttpsSecurity', htmlCtx($clean, 'https://example.com/', ['cert' => ['issuer' => '', 'subject' => '', 'valid_to' => time() + 5 * 86400 + 600]]));
    assertSame('AVISO', $r['estado']);
    assertTrue(strpos($r['detalle'], 'caduca en 5 días') !== false, $r['detalle']);

    $r = runCheck('C20HttpsSecurity', htmlCtx($clean));
    assertSame('CUMPLE', $r['estado']);
    assertTrue(strpos($r['evidencia'][0]['extracto'], 'sin datos de caducidad') !== false);

    $r = runCheck('C20HttpsSecurity', htmlCtx('<img src="http://x.example/a.jpg" alt="">', 'http://example.com/'));
    assertSame(false, $r['aplica']);
});

test('CurlTransport::leafCert y Fetcher: datos del certificado de la respuesta final', function () {
    assertSame(null, CurlTransport::leafCert(false));
    assertSame(null, CurlTransport::leafCert([]));
    $c = CurlTransport::leafCert([['Subject' => 'CN = example.com', 'Issuer' => 'C = US, O = Test CA', 'Expire date' => 'Jan 15 23:59:59 2030 GMT']]);
    assertSame('C = US, O = Test CA', $c['issuer']);
    assertSame(gmmktime(23, 59, 59, 1, 15, 2030), $c['valid_to']);

    $cert = ['subject' => 'CN = example.com', 'issuer' => 'O = X', 'valid_to' => 123];
    $r = fetcherWith([
        'https://example.com/' => fakeResp(301, ['location' => '/final']),
        'https://example.com/final' => fakeResp(200, [], '<html></html>', ['cert' => $cert]),
    ])->fetch('https://example.com/');
    assertSame($cert, $r['cert']);
    assertSame($cert, CheckContext::fromFetch($r)->cert);
});
