<?php
// Pruebas de la exportación a CSV y JSON (función 08). La lógica está en assets/js/export.js y se prueba en un
// navegador sin interfaz (Edge o Chrome, sin red): abre tests/js/export_test.html y lee el resultado del DOM.
// Si no hay navegador, la prueba se informa como OMITIDA (no como correcta).

/** Ruta del navegador para las pruebas de JavaScript (variable ANALYZER_TEST_BROWSER o rutas habituales). */
function testBrowser(): ?string
{
    $candidates = array_filter([
        getenv('ANALYZER_TEST_BROWSER') ?: null,
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/usr/bin/google-chrome',
        '/usr/bin/microsoft-edge',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ]);
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    return null;
}

/** Abre un HTML local en el navegador sin interfaz y devuelve el DOM final (o null si no termina). */
function dumpDom(string $browser, string $htmlFile, int $timeout = 45): ?string
{
    if (!function_exists('proc_open')) {
        return null;
    }
    $profile = tempDir('analyzer-browser-');
    $out = $profile . DIRECTORY_SEPARATOR . 'dom.html';
    $path = str_replace('\\', '/', (string) realpath($htmlFile));
    $url = 'file://' . ($path !== '' && $path[0] === '/' ? '' : '/') . str_replace(' ', '%20', $path);
    $cmd = [$browser, '--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--disable-extensions', '--user-data-dir=' . $profile . DIRECTORY_SEPARATOR . 'perfil', '--dump-dom', $url];
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $cmd[] = '--no-sandbox';
    }
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $proc = @proc_open($cmd, [1 => ['file', $out, 'w'], 2 => ['file', $null, 'w']], $pipes);
    if (!$proc) {
        return null;
    }
    $end = microtime(true) + $timeout;
    while (proc_get_status($proc)['running'] && microtime(true) < $end) {
        usleep(100000);
    }
    if (proc_get_status($proc)['running']) {
        proc_terminate($proc);
    }
    proc_close($proc);
    $dom = is_file($out) ? (string) file_get_contents($out) : null;
    removeTree($profile);
    return $dom;
}

/** Borra un directorio temporal creado por tempDir() (solo dentro del directorio temporal del sistema). */
function removeTree(string $dir): void
{
    $tmp = realpath(sys_get_temp_dir());
    $real = realpath($dir);
    if ($real === false || $tmp === false || strpos($real, $tmp . DIRECTORY_SEPARATOR) !== 0) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($real);
}

test('Exportar (JS en navegador): CSV «;» con BOM, inyección de fórmulas, columnas, JSON y nombre de archivo', function () {
    $browser = testBrowser();
    if ($browser === null) {
        skipTest('No se ha encontrado Edge ni Chrome (se puede indicar con ANALYZER_TEST_BROWSER).');
    }
    $dom = dumpDom($browser, __DIR__ . '/js/export_test.html');
    assertTrue($dom !== null && $dom !== '', 'El navegador no devolvió el DOM (' . $browser . ').');
    assertTrue(preg_match('#<pre id="resultado">(.*?)</pre>#s', $dom, $m) === 1, 'No aparece #resultado.');
    $res = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    assertTrue(is_array($res), 'Las pruebas de JavaScript no se ejecutaron: ' . substr($m[1], 0, 200));
    assertSame([], $res['fallos']);
    assertTrue($res['ok'] >= 28, 'Comprobaciones ejecutadas: ' . $res['ok']);
});

test('Exportar: se genera en el navegador (Blob) sin peticiones al servidor ni innerHTML', function () {
    $js = (string) file_get_contents(__DIR__ . '/../assets/js/export.js');
    assertTrue(strpos($js, 'new Blob(') !== false && strpos($js, 'URL.createObjectURL') !== false);
    foreach (['fetch(', 'XMLHttpRequest', 'innerHTML', 'insertAdjacentHTML', 'document.write'] as $bad) {
        assertTrue(strpos($js, $bad) === false, 'export.js no debe usar ' . $bad);
    }
    $index = (string) file_get_contents(__DIR__ . '/../index.php');
    $posExport = strpos($index, "\$asset('assets/js/export.js')");
    $posApp = strpos($index, "\$asset('assets/js/app.js')");
    assertTrue($posExport !== false && $posApp !== false && $posExport < $posApp, 'export.js se carga antes que app.js');
    $app = (string) file_get_contents(__DIR__ . '/../assets/js/app.js');
    assertTrue(strpos($app, 'window.AnalyzerExport') !== false && strpos($app, 'X.download(') !== false, 'app.js descarga con AnalyzerExport');
});
