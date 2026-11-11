<?php
// Pruebas de seguridad repetibles de Radar web: SSRF, XSS, CSRF, límites del rastreo y controles de despliegue.
// Sin internet ni dependencias: DNS y red simulados, y un servidor integrado de PHP en 127.0.0.1 para la API.
// Uso: php tests/seguridad.php   (código de salida 0 si todo pasa). Detalle de cada hallazgo en docs/SEGURIDAD.md.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
error_reporting(E_ALL);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/lib.php';

$root = dirname(__DIR__);

/** UrlGuard con DNS simulado: los dominios no listados resuelven a una IP pública de ejemplo. */
function secGuard(array $dns = []): UrlGuard
{
    return new UrlGuard([], fn(string $host) => $dns[$host] ?? ['93.184.216.34']);
}

/** Respuesta simulada con la forma de CurlTransport. */
function secResp(int $status, array $headers = [], string $body = ''): array
{
    return ['errno' => 0, 'error' => '', 'status' => $status, 'headers' => $headers, 'body' => $body, 'truncated' => false,
        'remote_ip' => '93.184.216.34', 'cert' => null,
        'timings' => ['namelookup_ms' => 1, 'connect_ms' => 1, 'tls_ms' => 1, 'ttfb_ms' => 1, 'total_ms' => 1]];
}

/** Fetcher con transporte simulado que registra a qué destino (URL e IP fijada) se conecta. */
function secFetcher(array $routes, array $dns, array &$log): Fetcher
{
    $transport = function (array $target) use ($routes, &$log) {
        $log[] = $target;
        return $routes[$target['url']] ?? secResp(404);
    };
    return new Fetcher(secGuard($dns), [], $transport);
}

// ---------------------------------------------------------------- SSRF

test('SSRF: esquemas, IP internas, codificaciones raras, credenciales y puertos se rechazan antes de conectar', function () {
    // Dominios del «atacante» que apuntan a la red interna (DNS simulado)
    $dns = ['interno.example.com' => ['10.0.0.5'], 'meta.example.com' => ['169.254.169.254'], 'v6.example.com' => ['::1'],
        'mixto.example.com' => ['93.184.216.34', '192.168.1.10']];
    $payloads = [
        'file:///C:/Windows/win.ini', 'gopher://127.0.0.1:6379/_INFO', 'dict://127.0.0.1:11211/stat', 'php://filter/resource=index.php',
        'ftp://example.com/', 'http://127.0.0.1/', 'http://localhost/', 'http://localhost./', 'http://2130706433/',
        'http://0177.0.0.1/', 'http://0x7f.1/', 'http://127.1/', 'http://user:pass@example.com/', 'http://example.com@127.0.0.1/',
        'http://[::1]/', 'http://[::ffff:127.0.0.1]/', 'http://[::ffff:7f00:1]/', 'http://[0:0:0:0:0:0:0:1]/',
        'http://169.254.169.254/latest/meta-data/', 'http://0.0.0.0/', 'http://10.1.2.3/', 'http://192.168.0.1/', 'http://[fe80::1]/',
        'http://[fc00::1]/', 'http://example.com:3306/', 'http://example.com:22/', 'http://nas.local/', 'http://app.internal/',
        'http://interno.example.com/', 'http://meta.example.com/', 'http://v6.example.com/', 'http://mixto.example.com/',
    ];
    $g = secGuard($dns);
    foreach ($payloads as $u) {
        try {
            $g->validate($u);
        } catch (UrlGuardException $e) {
            continue;
        }
        throw new RuntimeException("No se ha rechazado: $u");
    }
});

test('SSRF: una redirección a la red interna se revalida y no se sigue', function () {
    $dns = ['interno.example.com' => ['10.0.0.5']];
    foreach (['http://127.0.0.1:3306/', 'http://interno.example.com/', 'file:///C:/Windows/win.ini', 'http://[::1]/'] as $dest) {
        $log = [];
        $r = secFetcher(['https://example.com/' => secResp(302, ['location' => $dest])], $dns, $log)->fetch('https://example.com/');
        assertTrue(!empty($r['error']['code']), "Debe fallar la redirección a $dest");
        assertSame(1, count($log), "Solo se conecta a la URL pública, nunca a $dest");
    }
});

test('SSRF: DNS rebinding — la conexión usa la IP validada, no una nueva resolución', function () use ($root) {
    $calls = 0;
    $guard = new UrlGuard([], function () use (&$calls) {
        return ++$calls === 1 ? ['93.184.216.34'] : ['127.0.0.1'];   // 1.ª respuesta pública, después interna
    });
    $seen = [];
    $f = new Fetcher($guard, [], function (array $t) use (&$seen) {
        $seen[] = $t['ip'];
        return secResp(200, ['content-type' => 'text/html'], '<html></html>');
    });
    $f->fetch('https://rebind.example.com/');
    assertSame(['93.184.216.34'], $seen, 'El transporte recibe la IP fijada');
    $src = (string) file_get_contents($root . '/src/CurlTransport.php');
    assertTrue(strpos($src, 'CURLOPT_RESOLVE') !== false, 'cURL fija la IP con CURLOPT_RESOLVE');
    assertTrue(strpos($src, "CURLOPT_FOLLOWLOCATION => false") !== false, 'cURL no sigue redirecciones por su cuenta');
    assertTrue(strpos($src, "CURLOPT_PROXY => ''") !== false, 'cURL ignora proxies del entorno');
});

test('SSRF: el mensaje de rechazo no revela la IP interna (SEG-05)', function () {
    try {
        secGuard(['interno.example.com' => ['10.20.30.40']])->validate('https://interno.example.com/');
    } catch (UrlGuardException $e) {
        assertTrue(strpos($e->getMessage(), '10.20.30.40') === false, $e->getMessage());
        return;
    }
    throw new RuntimeException('Debía rechazarse');
});

// ---------------------------------------------------------------- XSS

test('XSS: el JavaScript del informe no usa sumideros de HTML (innerHTML, document.write, eval…)', function () use ($root) {
    $sinks = '/\.(innerHTML|outerHTML)\s*=|insertAdjacentHTML\s*\(|document\.write\s*\(|\beval\s*\(|new\s+Function\s*\(|set(Timeout|Interval)\s*\(\s*[\'"]/';
    foreach (glob($root . '/assets/js/*.js') as $file) {
        $code = (string) file_get_contents($file);
        $code = preg_replace('#/\*.*?\*/#s', '', $code);                 // sin comentarios de bloque
        $code = preg_replace('#^\s*//.*$#m', '', $code);                  // ni de línea
        assertTrue(!preg_match($sinks, $code, $m), basename($file) . ' usa ' . ($m[0] ?? ''));
    }
});

test('XSS: el contenido hostil de la página analizada llega al informe como texto, sin alterarlo ni ejecutarlo', function () {
    $hostile = '<!doctype html><html lang="es"><head><title><script>window.__xss=1</script>Hostil</title>'
        . '<meta name="description" content="&quot;&gt;&lt;img src=x onerror=alert(1)&gt;">'
        . '<script type="application/ld+json">{"@type":"Organization","name":"</script><script>alert(1)</script>","logo":"javascript:alert(1)"}</script>'
        . '</head><body><main><h1>&lt;svg onload=alert(1)&gt;</h1><a href="javascript:alert(1)">x</a></main></body></html>';
    $log = [];
    $page = secFetcher(['https://example.com/' => secResp(200, ['content-type' => 'text/html; charset=utf-8'], $hostile)], [], $log);
    $none = secFetcher([], [], $log);
    $config = require __DIR__ . '/../config/config.example.php';
    $r = (new Analyzer($config, $page, $none, fn(string $url, array $o = []) => $none->fetch($url)))->analyze('https://example.com/');
    $json = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    assertTrue(is_string($json) && json_decode($json, true) !== null, 'El informe es JSON válido');
    // Las URL que el informe ofrece como enlace o imagen solo pueden ser http/https
    $bad = [];
    array_walk_recursive($r, function ($v, $k) use (&$bad) {
        if (is_string($v) && in_array($k, ['url', 'logo', 'favicon', 'imagen', 'href'], true) && preg_match('/^\s*javascript:/i', $v)) {
            $bad[] = "$k=$v";
        }
    });
    assertSame([], $bad, 'Ninguna URL javascript: en campos de enlace o imagen');
});

// ---------------------------------------------------------------- Servidor local (CSRF, cabeceras, despliegue)

[$proc, $port] = startPhpServer($root, null, ['WEBSEO_STORAGE_DIR' => tempDir('webseo-seg'), 'WEBSEO_CONFIG_DIR' => testConfigDir()]);
$base = "http://127.0.0.1:$port";
$needServer = function () use ($proc) {
    if ($proc === null) {
        skipTest('No se ha podido arrancar el servidor integrado de PHP.');
    }
};
$apis = array_map('basename', glob($root . '/api/*.php'));

test('CSRF: todos los endpoints de la API rechazan GET, formularios, text/plain y otros orígenes', function () use ($needServer, $base, $apis) {
    $needServer();
    foreach ($apis as $api) {
        $u = "$base/api/$api";
        assertSame(405, httpRequest('GET', $u)['status'], "$api GET");
        assertSame(415, httpRequest('POST', $u, 'url=https://example.com', ['Content-Type: application/x-www-form-urlencoded'])['status'], "$api formulario");
        assertSame(415, httpRequest('POST', $u, '{"url":"https://example.com"}', ['Content-Type: text/plain'])['status'], "$api text/plain");
        foreach (['https://evil.example', 'null'] as $origin) {
            $r = httpRequest('POST', $u, '{}', ['Content-Type: application/json', "Origin: $origin"]);
            assertSame(403, $r['status'], "$api Origin: $origin");
        }
    }
});

test('XSS/CSRF: la API responde JSON con nosniff y sin caché', function () use ($needServer, $base) {
    $needServer();
    $r = httpRequest('POST', "$base/api/analyze.php", '{"url":"http://127.0.0.1/"}', ['Content-Type: application/json']);
    assertSame(400, $r['status']);
    assertTrue(stripos($r['headers'], 'Content-Type: application/json') !== false);
    assertTrue(stripos($r['headers'], 'X-Content-Type-Options: nosniff') !== false);
    assertTrue(stripos($r['headers'], 'Cache-Control: no-store') !== false);
});

test('XSS: las páginas llevan CSP sin scripts en línea y cabeceras de protección (SEG-08/09)', function () use ($needServer, $base) {
    $needServer();
    foreach (['index.php', 'historial.php', 'requisitos.php'] as $p) {
        $h = httpRequest('GET', "$base/$p")['headers'];
        assertTrue((bool) preg_match('/^Content-Security-Policy:.*script-src \'self\'/mi', $h), "$p: CSP con script-src 'self'");
        assertTrue(!preg_match('/^Content-Security-Policy:.*script-src[^;]*unsafe-inline/mi', $h), "$p: sin unsafe-inline en scripts");
        assertTrue(stripos($h, "frame-ancestors 'none'") !== false && stripos($h, 'X-Frame-Options: DENY') !== false, "$p: no incrustable");
        assertTrue(stripos($h, 'Permissions-Policy:') !== false, "$p: Permissions-Policy");
        assertTrue(stripos($h, 'X-Powered-By') === false, "$p: sin X-Powered-By");
    }
    assertTrue(stripos(httpRequest('GET', "$base/historial.php")['headers'], 'Cache-Control: no-store') !== false, 'historial sin caché');
});

test('XSS y fugas: historial.php no refleja HTML ni muestra errores con rutas (SEG-04)', function () use ($needServer, $base, $root) {
    $needServer();
    $r = httpRequest('GET', "$base/historial.php?q=" . rawurlencode('<script>alert(1)</script>'));
    assertTrue(strpos($r['body'], '<script>alert(1)') === false, 'q se escapa');
    foreach (['q[]=x', 'pagina[]=2', 'q[a][b]=1'] as $qs) {
        $b = httpRequest('GET', "$base/historial.php?$qs")['body'];
        assertTrue(stripos($b, 'Warning') === false && stripos($b, 'Fatal') === false, "$qs: sin avisos de PHP");
        assertTrue(strpos($b, $root) === false && strpos($b, str_replace('/', '\\', $root)) === false, "$qs: sin rutas del servidor");
    }
});

test('Despliegue: las pruebas no se ejecutan desde la web (SEG-02)', function () use ($needServer, $base, $root) {
    $needServer();
    foreach (['run.php', 'live_fetch.php', 'analyze_cli.php', 'seguridad.php'] as $t) {
        assertSame(404, httpRequest('GET', "$base/tests/$t")['status'], "tests/$t");
    }
    assertSame(404, httpRequest('GET', "$base/bin/limpiar.php")['status'], 'bin/limpiar.php');
});

test('Despliegue: .htaccess niega las carpetas internas y desactiva los listados (SEG-03)', function () use ($root) {
    foreach (['config', 'src', 'lang', 'bin', 'docs', 'certs', 'tests', 'storage'] as $d) {
        $f = "$root/$d/.htaccess";
        assertTrue(is_file($f) && stripos((string) file_get_contents($f), 'Require all denied') !== false, "$d/.htaccess");
    }
    $top = (string) @file_get_contents("$root/.htaccess");
    assertTrue(stripos($top, 'Options -Indexes') !== false, 'Options -Indexes en la raíz');
    assertTrue(stripos($top, 'md|sql|pem') !== false, 'Niega .md, .sql y .pem');
    $boot = (string) file_get_contents("$root/src/bootstrap.php");
    assertTrue(strpos($boot, "ini_set('display_errors', '0')") !== false, 'display_errors desactivado fuera de la CLI');
    $gi = (string) @file_get_contents("$root/.gitignore");
    assertTrue(strpos($gi, 'config.php') !== false, 'config/config.php fuera del repositorio');
});

test('Abuso: falsear X-Forwarded-For no salta el límite de peticiones por IP', function () use ($needServer, $base) {
    $needServer();
    $limited = false;
    for ($i = 0; $i < 15 && !$limited; $i++) {
        $r = httpRequest('POST', "$base/api/analyze.php", '{"url":"http://127.0.0.1/"}', ['Content-Type: application/json',
            'X-Forwarded-For: 203.0.113.' . $i, 'Client-IP: 198.51.100.' . $i, 'X-Real-IP: 192.0.2.' . $i]);
        $limited = $r['status'] === 429;
    }
    assertTrue($limited, 'Debe llegar al 429 aunque cambie la IP declarada en cabeceras');
});

test('Cierre del servidor local', function () use ($proc) {
    stopPhpServer($proc);
});

// ---------------------------------------------------------------- Rastreo del sitio (SEG-01)

test('Rastreo: el servidor impone modo y tope; un análisis inventado o detenido no avanza (SEG-01)', function () {
    $old = getenv('WEBSEO_STORAGE_DIR');
    putenv('WEBSEO_STORAGE_DIR=' . tempDir('webseo-seg-run'));
    try {
        $run = bin2hex(random_bytes(16));
        assertTrue(SiteRun::create($run, 'rapido', 2, ['https://a.example/', 'https://a.example/b'], null, 0, time() + 600));
        assertSame([], SiteRun::allowLinks($run, ['https://a.example/c', 'https://a.example/d']), 'En el modo rápido no se firman enlaces nuevos');
        assertTrue(SiteRun::reserve($run, 1000.0)['ok']);
        assertTrue(SiteRun::reserve($run, 1000.0)['ok']);
        assertSame('LIMIT_REACHED', SiteRun::reserve($run, 1000.0)['codigo'], 'Tercera página por encima del tope');
        assertSame('RUN_UNKNOWN', SiteRun::reserve(str_repeat('0', 32))['codigo'], 'Análisis inventado');
        $run2 = bin2hex(random_bytes(16));
        SiteRun::create($run2, 'profundo', 50, ['https://a.example/'], null, 0, time() + 600);
        SiteRun::stop($run2, 'El sitio ha respondido 429');
        assertSame('SITE_STOPPED', SiteRun::reserve($run2, 1000.0)['codigo'], 'Análisis detenido');
    } finally {
        putenv($old === false ? 'WEBSEO_STORAGE_DIR' : 'WEBSEO_STORAGE_DIR=' . $old);
    }
});

exit(runTests());
