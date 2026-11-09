<?php
// Pruebas de InputUrlPolicy (entradas que no son la web del negocio). Sin red: solo normalize, sin DNS.

test('Entrada: Google Maps y acortadores → NOT_BUSINESS_SITE con mensaje claro', function () {
    $urls = [
        'https://www.google.com/maps/place/Proconsi', 'https://google.es/maps?q=le%C3%B3n', 'maps.google.com/?cid=1',
        'https://maps.app.goo.gl/abc123', 'https://goo.gl/maps/abc', 'https://g.page/proconsi', 'bit.ly/3xyz',
        'https://tinyurl.com/abc', 'https://t.co/abc', 'https://BIT.LY/abc', 'https://sub.bit.ly/abc',
    ];
    foreach ($urls as $url) {
        try {
            InputUrlPolicy::check($url);
            throw new RuntimeException("No se rechazó $url");
        } catch (UrlGuardException $e) {
            assertSame('NOT_BUSINESS_SITE', $e->getErrorCode(), $url);
            assertTrue(strpos($e->getMessage(), 'Introduce la dirección de la web del negocio.') !== false, $url);
        }
    }
});

test('Entrada: direcciones locales y con contraseña → mismo código que UrlGuard y la indicación', function () {
    $cases = [
        'https://usuario:clave@example.com/' => 'CREDENTIALS_IN_URL',
        'http://localhost/' => 'PRIVATE_ADDRESS',
        'localhost:80' => 'PRIVATE_ADDRESS',
        'http://127.0.0.1/' => 'PRIVATE_ADDRESS',
        'http://192.168.1.10/' => 'PRIVATE_ADDRESS',
        'http://[::1]/' => 'PRIVATE_ADDRESS',
        'https://intranet.local/' => 'PRIVATE_ADDRESS',
        'https://servidor.internal/' => 'PRIVATE_ADDRESS',
    ];
    foreach ($cases as $url => $code) {
        try {
            InputUrlPolicy::check($url);
            throw new RuntimeException("No se rechazó $url");
        } catch (UrlGuardException $e) {
            assertSame($code, $e->getErrorCode(), $url);
            assertTrue(strpos($e->getMessage(), InputUrlPolicy::HINT) !== false, "$url: " . $e->getMessage());
        }
    }
    // Otros errores de formato conservan el código y el mensaje de UrlGuard
    assertGuardCode('SCHEME_NOT_ALLOWED', fn() => InputUrlPolicy::check('ftp://example.com/'));
    assertGuardCode('INVALID_URL', fn() => InputUrlPolicy::check(''));
});

test('Entrada: webs normales pasan (incluido google.com sin /maps y rutas que contienen «maps»)', function () {
    foreach (['https://www.proconsi.com/', 'proconsi.com/contacto', 'https://www.proconsi.com/maps', 'https://www.google.com/',
        'https://bit.ly.example.com/', 'https://notbit.ly.es/', 'http://8.8.8.8/'] as $url) {
        InputUrlPolicy::check($url);
    }
    assertTrue(true);
});

// ---------- Endpoints: el rechazo llega antes de descargar o consultar a Google ----------

(function () {
    [$proc, $port] = startPhpServer(dirname(__DIR__), null, ['WEBSEO_STORAGE_DIR' => tempDir('webseo'), 'WEBSEO_CONFIG_DIR' => testConfigDir()]);
    $base = "http://127.0.0.1:$port";

    test('API analyze y psi: Google Maps, acortador y localhost → 400 con mensaje en español', function () use ($proc, $base) {
        if ($proc === null) {
            skipTest('No se pudo arrancar el servidor local.');
        }
        $cases = ['https://www.google.com/maps/place/x' => 'NOT_BUSINESS_SITE', 'https://bit.ly/abc' => 'NOT_BUSINESS_SITE',
            'http://localhost/' => 'PRIVATE_ADDRESS'];
        foreach (['analyze.php', 'psi.php'] as $ep) {
            foreach ($cases as $url => $code) {
                $r = httpRequest('POST', "$base/api/$ep", json_encode(['url' => $url]), ['Content-Type: application/json']);
                assertSame(400, $r['status'], "$ep $url");
                $err = json_decode($r['body'], true)['error'];
                assertSame($code, $err['code'], "$ep $url");
                assertTrue(strpos($err['message'], 'Introduce la dirección de la web del negocio.') !== false, "$ep $url");
            }
        }
    });

    test('API analyze y psi: cierre del servidor de pruebas de entradas', function () use ($proc) {
        stopPhpServer($proc);
    });
})();
