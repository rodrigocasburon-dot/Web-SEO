<?php
// Pruebas de CurlTransport contra un servidor local de PHP (127.0.0.1, sin internet).
// El Fetcher nunca llega a 127.0.0.1 (UrlGuard lo impide); aquí se llama al transporte directamente.

(function () {
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $available = $sock !== false && function_exists('proc_open');
    $proc = null;
    $port = 0;
    if ($available) {
        $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $proc = @proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/server/router.php'],
            [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes
        );
        $ready = false;
        for ($i = 0; $proc && $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
            if ($c) {
                fclose($c);
                $ready = true;
                break;
            }
            usleep(100000);
        }
        $available = $ready;
    }

    $opt = ['user_agent' => 'ProconsiAnalyzer/test', 'max_bytes' => 2097152];
    // host ficticio fijado a 127.0.0.1: demuestra que CURLOPT_RESOLVE se respeta
    $target = fn(string $path) => [
        'url' => "http://pinned.test:$port$path", 'host' => 'pinned.test', 'port' => $port,
        'ip' => '127.0.0.1', 'is_ip_literal' => false,
    ];

    test('CurlTransport: IP fijada, User-Agent propio y cabeceras en minúsculas', function () use (&$available, $target, $opt) {
        if (!$available) {
            echo "        (omitida: no se pudo arrancar el servidor local)\n";
            return;
        }
        $r = (new CurlTransport())($target('/ok'), 5000, $opt);
        assertSame(0, $r['errno'], $r['error']);
        assertSame(200, $r['status']);
        assertTrue(strpos($r['body'], 'host=pinned.test') !== false);
        assertTrue(strpos($r['body'], 'ua=ProconsiAnalyzer/test') !== false);
        assertSame('uno, dos', $r['headers']['x-test']);
        assertTrue(strpos($r['headers']['content-type'], 'text/html') === 0);
    });

    test('CurlTransport: no sigue redirecciones', function () use (&$available, $target, $opt) {
        if (!$available) {
            return;
        }
        $r = (new CurlTransport())($target('/redir'), 5000, $opt);
        assertSame(302, $r['status']);
        assertSame('/ok', $r['headers']['location']);
    });

    test('CurlTransport: límite de 2 MB, respuesta truncada sin error', function () use (&$available, $target, $opt) {
        if (!$available) {
            return;
        }
        $r = (new CurlTransport())($target('/big'), 8000, $opt);
        assertSame(0, $r['errno'], $r['error']);
        assertTrue($r['truncated']);
        assertSame(2097152, strlen($r['body']));
    });

    test('CurlTransport: un 404 no es un error de red', function () use (&$available, $target, $opt) {
        if (!$available) {
            return;
        }
        $r = (new CurlTransport())($target('/nada'), 5000, $opt);
        assertSame(0, $r['errno']);
        assertSame(404, $r['status']);
    });

    test('CurlTransport: tiempo agotado (errno 28)', function () use (&$available, $target, $opt) {
        if (!$available) {
            return;
        }
        $t0 = microtime(true);
        $r = (new CurlTransport())($target('/slow'), 700, $opt);
        assertSame(28, $r['errno']);
        assertTrue(microtime(true) - $t0 < 2.5, 'Debe cortar antes de que responda el servidor');
    });

    test('CurlTransport: cierre del servidor de pruebas', function () use (&$proc) {
        if ($proc) {
            proc_terminate($proc);
            proc_close($proc);
        }
    });
})();
