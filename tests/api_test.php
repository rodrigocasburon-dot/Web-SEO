<?php
// Pruebas del endpoint api/analyze.php y de index.php con el servidor integrado de PHP.
// Solo casos que no salen a internet (UrlGuard rechaza antes de cualquier petición).

(function () {
    [$proc, $port] = startPhpServer(dirname(__DIR__), null, ['WEBSEO_STORAGE_DIR' => tempDir('webseo')]);
    $base = "http://127.0.0.1:$port";
    $skip = function () use ($proc) {
        if ($proc === null) {
            echo "        (omitida: no se pudo arrancar el servidor local)\n";
            return true;
        }
        return false;
    };
    $post = fn(array $data, array $headers = []) => httpRequest('POST', "$base/api/analyze.php", json_encode($data),
        array_merge(['Content-Type: application/json'], $headers));

    test('API: GET devuelve 405 con Allow: POST', function () use ($skip, $base) {
        if ($skip()) {
            return;
        }
        $r = httpRequest('GET', "$base/api/analyze.php");
        assertSame(405, $r['status']);
        assertTrue(stripos($r['headers'], 'Allow: POST') !== false);
        assertSame('METHOD_NOT_ALLOWED', json_decode($r['body'], true)['error']['code']);
    });

    test('API: exige JSON (415)', function () use ($skip, $base) {
        if ($skip()) {
            return;
        }
        $r = httpRequest('POST', "$base/api/analyze.php", 'url=https://example.com', ['Content-Type: application/x-www-form-urlencoded']);
        assertSame(415, $r['status']);
    });

    test('API: origen distinto rechazado (403)', function () use ($skip, $post) {
        if ($skip()) {
            return;
        }
        $r = $post(['url' => 'https://example.com'], ['Origin: https://evil.example']);
        assertSame(403, $r['status']);
        assertSame('FORBIDDEN_ORIGIN', json_decode($r['body'], true)['error']['code']);
    });

    test('API: URL vacía, esquema no permitido e IP privada → 400 con código', function () use ($skip, $post) {
        if ($skip()) {
            return;
        }
        foreach (['' => 'INVALID_URL', 'ftp://example.com/' => 'SCHEME_NOT_ALLOWED', 'http://127.0.0.1/' => 'PRIVATE_ADDRESS',
            'http://169.254.169.254/latest/meta-data/' => 'PRIVATE_ADDRESS'] as $url => $code) {
            $r = $post(['url' => $url]);
            assertSame(400, $r['status'], $url);
            $d = json_decode($r['body'], true);
            assertSame(false, $d['ok']);
            assertSame($code, $d['error']['code'], $url);
        }
    });

    test('API: cabeceras de seguridad y JSON', function () use ($skip, $post) {
        if ($skip()) {
            return;
        }
        $r = $post(['url' => 'ftp://x.com']);
        assertTrue(stripos($r['headers'], 'application/json') !== false);
        assertTrue(stripos($r['headers'], 'X-Content-Type-Options: nosniff') !== false);
        assertTrue(stripos($r['headers'], 'Cache-Control: no-store') !== false);
    });

    test('Pantalla: index.php responde con formulario, CSP y textos fijos', function () use ($skip, $base) {
        if ($skip()) {
            return;
        }
        $r = httpRequest('GET', "$base/index.php");
        assertSame(200, $r['status']);
        assertTrue(stripos($r['headers'], 'Content-Security-Policy') !== false);
        assertTrue(strpos($r['body'], '<label for="url">') !== false);
        assertTrue(strpos($r['body'], 'No sustituye una auditoría manual') !== false);
    });

    test('Pantalla: storage/ y config/ no se sirven por web (comprobado con .htaccess solo en Apache)', function () {
        // El servidor integrado de PHP ignora .htaccess; aquí solo se verifica que la regla existe.
        assertTrue(strpos((string) file_get_contents(dirname(__DIR__) . '/storage/.htaccess'), 'Require all denied') !== false);
    });

    test('API: cierre del servidor de pruebas', function () use ($proc) {
        stopPhpServer($proc);
    });
})();
