<?php
// Pruebas del Fetcher con transporte simulado (sin red).

/** Respuesta simulada con la misma forma que devuelve CurlTransport. */
function fakeResp(int $status, array $headers = [], string $body = '', array $extra = []): array
{
    return array_merge([
        'errno' => 0, 'error' => '', 'status' => $status, 'headers' => $headers, 'body' => $body,
        'truncated' => false, 'remote_ip' => '93.184.216.34',
        'timings' => ['namelookup_ms' => 1, 'connect_ms' => 2, 'tls_ms' => 3, 'ttfb_ms' => 4, 'total_ms' => 5],
    ], $extra);
}

/** Crea un Fetcher cuyo transporte responde según la URL ($routes) y registra las peticiones. */
function fetcherWith(array $routes, array $dns = [], array $opt = [], array &$log = []): Fetcher
{
    $guard = new UrlGuard([], fn(string $host) => $dns[$host] ?? ['93.184.216.34']);
    $transport = function (array $target, int $timeoutMs, array $o) use ($routes, &$log) {
        $log[] = $target;
        if (!isset($routes[$target['url']])) {
            return fakeResp(404);
        }
        $r = $routes[$target['url']];
        return is_callable($r) ? $r($target, $timeoutMs) : $r;
    };
    return new Fetcher($guard, $opt, $transport);
}

test('Fetcher: 200 simple con IP fijada y tiempos', function () {
    $log = [];
    $f = fetcherWith(['https://example.com/' => fakeResp(200, ['content-type' => 'text/html'], '<html>hola</html>')], [], [], $log);
    $r = $f->fetch('example.com');
    assertTrue($r['ok']);
    assertSame(200, $r['status']);
    assertSame('<html>hola</html>', $r['body']);
    assertSame('text/html', $r['content_type']);
    assertSame('93.184.216.34', $log[0]['ip']);
    assertSame([], $r['redirects']);
    assertSame(1, count($r['hops']));
    assertTrue(isset($r['timings']['total_ms']));
    assertSame(null, $r['blocked']);
});

test('Fetcher: cadena de redirecciones revalidando cada destino', function () {
    $log = [];
    $f = fetcherWith([
        'http://example.com/' => fakeResp(301, ['location' => 'https://example.com/']),
        'https://example.com/' => fakeResp(302, ['location' => '/home']),
        'https://example.com/home' => fakeResp(200, [], 'fin'),
    ], [], [], $log);
    $r = $f->fetch('http://example.com');
    assertTrue($r['ok']);
    assertSame('https://example.com/home', $r['final_url']);
    assertSame(2, count($r['redirects']));
    assertSame(301, $r['redirects'][0]['status']);
    assertSame(3, count($log));
});

test('Fetcher: redirección a IP privada se bloquea sin hacer la petición', function () {
    $log = [];
    foreach (['http://127.0.0.1/admin', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/'] as $dest) {
        $log = [];
        $f = fetcherWith(['https://example.com/' => fakeResp(302, ['location' => $dest])], [], [], $log);
        $r = $f->fetch('https://example.com/');
        assertSame('PRIVATE_ADDRESS', $r['error']['code'], $dest);
        assertSame(1, count($log), 'No debe pedirse el destino privado');
    }
});

test('Fetcher: redirección a dominio que resuelve a IP privada', function () {
    $log = [];
    $f = fetcherWith(
        ['https://example.com/' => fakeResp(302, ['location' => 'https://trampa.example.org/'])],
        ['trampa.example.org' => ['192.168.0.10']], [], $log
    );
    $r = $f->fetch('https://example.com/');
    assertSame('PRIVATE_ADDRESS', $r['error']['code']);
    assertSame(1, count($log));
});

test('Fetcher: redirección a esquemas no permitidos', function () {
    foreach (['file:///etc/passwd', 'gopher://example.com/', 'ftp://example.com/', 'javascript:alert(1)'] as $dest) {
        $f = fetcherWith(['https://example.com/' => fakeResp(302, ['location' => $dest])]);
        $r = $f->fetch('https://example.com/');
        assertSame('SCHEME_NOT_ALLOWED', $r['error']['code'], $dest);
    }
});

test('Fetcher: bucle de redirecciones', function () {
    $f = fetcherWith([
        'https://example.com/a' => fakeResp(302, ['location' => '/b']),
        'https://example.com/b' => fakeResp(302, ['location' => '/a']),
    ]);
    $r = $f->fetch('https://example.com/a');
    assertSame('REDIRECT_LOOP', $r['error']['code']);
    assertSame(false, $r['ok']);
});

test('Fetcher: límite de 5 redirecciones', function () {
    $routes = [];
    for ($i = 0; $i < 10; $i++) {
        $routes["https://example.com/p$i"] = fakeResp(302, ['location' => '/p' . ($i + 1)]);
    }
    $routes['https://example.com/p5'] = fakeResp(200, [], 'ok');        // 5 saltos: permitido
    $r = fetcherWith($routes)->fetch('https://example.com/p0');
    assertTrue($r['ok']);
    assertSame(5, count($r['redirects']));

    $routes['https://example.com/p5'] = fakeResp(302, ['location' => '/p6']);   // 6 saltos: error
    $routes['https://example.com/p6'] = fakeResp(200, [], 'ok');
    $r = fetcherWith($routes)->fetch('https://example.com/p0');
    assertSame('TOO_MANY_REDIRECTS', $r['error']['code']);
});

test('Fetcher: redirección sin Location', function () {
    $r = fetcherWith(['https://example.com/' => fakeResp(302)])->fetch('https://example.com/');
    assertSame('BAD_REDIRECT', $r['error']['code']);
});

test('Fetcher: resolución de Location relativos', function () {
    $base = 'https://example.com/a/b/c.html?x=1';
    assertSame('https://example.com/d', Fetcher::resolveUrl($base, '/d'));
    assertSame('https://example.com/a/b/d.html', Fetcher::resolveUrl($base, 'd.html'));
    assertSame('https://example.com/a/d', Fetcher::resolveUrl($base, '../d'));
    assertSame('https://example.com/d', Fetcher::resolveUrl($base, '../../../d'));
    assertSame('https://otro.com/x', Fetcher::resolveUrl($base, '//otro.com/x'));
    assertSame('https://example.com/a/b/c.html?q=2', Fetcher::resolveUrl($base, '?q=2'));
    assertSame('https://example.com/a/b/e?z=1', Fetcher::resolveUrl($base, 'e?z=1#f'));
    assertSame('http://otro.com/', Fetcher::resolveUrl($base, 'http://otro.com/'));
    assertSame('https://example.com:8443/n', Fetcher::resolveUrl('https://example.com:8443/m/', '/n'));
});

test('Fetcher: 403 se informa como bloqueo, no como fallo', function () {
    $r = fetcherWith(['https://example.com/' => fakeResp(403, [], 'Forbidden')])->fetch('https://example.com/');
    assertTrue($r['ok']);
    assertSame(null, $r['error']);
    assertSame('forbidden', $r['blocked']['reason']);
});

test('Fetcher: 401, 429 y captcha de Cloudflare', function () {
    $r = fetcherWith(['https://example.com/' => fakeResp(401)])->fetch('https://example.com/');
    assertSame('login', $r['blocked']['reason']);

    $r = fetcherWith(['https://example.com/' => fakeResp(429)])->fetch('https://example.com/');
    assertSame('rate_limit', $r['blocked']['reason']);

    $cf = '<html><head><title>Just a moment...</title></head><body><div id="cf-chl-widget"></div></body></html>';
    $r = fetcherWith(['https://example.com/' => fakeResp(403, ['server' => 'cloudflare'], $cf)])->fetch('https://example.com/');
    assertSame('captcha', $r['blocked']['reason']);

    $r = fetcherWith(['https://example.com/' => fakeResp(200, ['cf-mitigated' => 'challenge'], 'x')])->fetch('https://example.com/');
    assertSame('captcha', $r['blocked']['reason']);
});

test('Fetcher: un formulario con reCAPTCHA en un 200 no es bloqueo; un 500 tampoco', function () {
    $page = '<html><head><title>Contacto</title></head><body><div class="g-recaptcha"></div></body></html>';
    $r = fetcherWith(['https://example.com/' => fakeResp(200, [], $page)])->fetch('https://example.com/');
    assertSame(null, $r['blocked']);

    $r = fetcherWith(['https://example.com/' => fakeResp(500, [], 'error')])->fetch('https://example.com/');
    assertTrue($r['ok']);
    assertSame(500, $r['status']);
    assertSame(null, $r['blocked']);
});

test('Fetcher: redirección a página de login se marca (heurística)', function () {
    $r = fetcherWith([
        'https://example.com/privado' => fakeResp(302, ['location' => '/login?next=/privado']),
        'https://example.com/login?next=/privado' => fakeResp(200, [], '<html>login</html>'),
    ])->fetch('https://example.com/privado');
    assertSame('login', $r['blocked']['reason']);

    // Si la URL pedida ya es /login no se marca
    $r = fetcherWith(['https://example.com/login' => fakeResp(200, [], 'x')])->fetch('https://example.com/login');
    assertSame(null, $r['blocked']);
});

test('Fetcher: errores de red de cURL se traducen a códigos', function () {
    $casos = [28 => 'TIMEOUT', 7 => 'CONNECTION_FAILED', 60 => 'SSL_ERROR', 35 => 'SSL_ERROR', 6 => 'DNS_FAILED', 99 => 'UNKNOWN'];
    foreach ($casos as $errno => $code) {
        $r = fetcherWith(['https://example.com/' => fakeResp(0, [], '', ['errno' => $errno, 'error' => 'detalle'])])->fetch('https://example.com/');
        assertSame($code, $r['error']['code'], "errno $errno");
        assertSame(false, $r['ok']);
    }
});

test('Fetcher: presupuesto de tiempo agotado antes de pedir', function () {
    $log = [];
    $r = fetcherWith(['https://example.com/' => fakeResp(200)], [], ['timeout' => 0], $log)->fetch('https://example.com/');
    assertSame('TIMEOUT', $r['error']['code']);
    assertSame(0, count($log));
});

test('Fetcher: el transporte recibe el tiempo restante, no más de lo permitido', function () {
    $seen = 0;
    $f = fetcherWith(['https://example.com/' => function ($t, $ms) use (&$seen) {
        $seen = $ms;
        return fakeResp(200);
    }], [], ['timeout' => 10]);
    $f->fetch('https://example.com/');
    assertTrue($seen > 0 && $seen <= 10000, "ms=$seen");
});

test('Fetcher: respuesta truncada se señala', function () {
    $r = fetcherWith(['https://example.com/' => fakeResp(200, [], 'abc', ['truncated' => true])])->fetch('https://example.com/');
    assertTrue($r['truncated']);
});

test('Fetcher: URL inválida devuelve error, no excepción', function () {
    $r = fetcherWith([])->fetch('ftp://example.com/');
    assertSame('SCHEME_NOT_ALLOWED', $r['error']['code']);
    assertSame(false, $r['ok']);
});
