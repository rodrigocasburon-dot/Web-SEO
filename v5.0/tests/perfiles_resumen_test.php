<?php
// Perfiles de lectura, resumen ejecutivo, pantalla de resultados e índice de los PDF (casos 1–9 del encargo).
// La parte de JavaScript se ejecuta en un navegador sin interfaz con datos de prueba fijos y sin red; la de PHP,
// con el servidor integrado en 127.0.0.1. También se ejecuta sola: php tests/perfiles_resumen.php

/** I18N con la misma forma que index.php (sin leer config/config.php). */
function perfilesI18n(): array
{
    $lang = Lang::all();
    $i18n = [
        'areas' => $lang['areas'],
        'estados' => $lang['estados'],
        'severidades' => $lang['severidades'],
        'niveles' => $lang['niveles'],
        'nota_incompleta' => $lang['nota_incompleta'],
        'glosario' => $lang['glosario'],
        'revisiones_manuales' => $lang['revisiones_manuales'],
        'nombres_checks' => array_map(fn($c) => $c['nombre'], $lang['checks']),
        'psi_checks' => ['C08' => $lang['checks']['C08']['nombre'], 'C14' => $lang['checks']['C14']['nombre']],
        'perfiles' => Perfil::forScreen(),
        'perfil_inicial' => Perfil::normalize(null),
        'penalizaciones' => Scorer::PENALTIES,
        'descripciones' => array_map(fn($c) => (string) ($c['descripcion'] ?? ''), $lang['checks']),
        'recomendaciones' => array_map(fn($c) => (string) ($c['recomendacion'] ?? ''), $lang['checks']),
        'enfoque' => $lang['enfoque'],
    ];
    foreach (CheckRunner::catalog() as $checkId => $check) {
        $i18n['bloque_checks'][$checkId] = Report::primaryArea($check->describe(BaseCheck::NO_DATA)['pilares']);
    }
    return $i18n;
}

test('Perfiles (PHP), casos 2 y 7: lista blanca y textos de los tres perfiles para todos los puntos', function () {
    foreach (['marketing', 'direccion', 'desarrollo'] as $p) {
        assertSame($p, Perfil::normalize($p));
    }
    foreach (['<script>alert(1)</script>', '', 'admin', 'MARKETING', ' marketing', null, 7, ['marketing']] as $bad) {
        assertSame('desarrollo', Perfil::normalize($bad), 'perfil no válido: ' . json_encode($bad));
    }
    $ids = array_merge(array_keys(CheckRunner::catalog()), ['WEB-TITULOS', 'WEB-404']);
    assertSame([], Perfil::missing($ids), 'textos pendientes');
});

test('Perfiles (HTTP), caso 2: ?perfil= no válido cae al de por defecto y no se refleja en la página', function () {
    [$proc, $port] = startPhpServer(dirname(__DIR__), null, ['WEBSEO_STORAGE_DIR' => tempDir('webseo'), 'WEBSEO_CONFIG_DIR' => testConfigDir()]);
    if ($proc === null) {
        skipTest('No se pudo arrancar el servidor local.');
    }
    try {
        foreach (['%3Cscript%3Ealert(1)%3C%2Fscript%3E' => '<script>alert(1)', 'admin' => '"perfil_inicial":"admin"', '' => null] as $q => $needle) {
            $r = httpRequest('GET', "http://127.0.0.1:$port/index.php?perfil=$q");
            assertSame(200, $r['status'], "perfil=$q");
            assertTrue(strpos($r['body'], '"perfil_inicial":"desarrollo"') !== false, "perfil=$q cae a desarrollo");
            if ($needle !== null) {
                assertTrue(strpos($r['body'], $needle) === false, "perfil=$q no se refleja");
            }
        }
        $r = httpRequest('GET', "http://127.0.0.1:$port/index.php?perfil=marketing");
        assertTrue(strpos($r['body'], '"perfil_inicial":"marketing"') !== false, 'un perfil válido se respeta');
    } finally {
        stopPhpServer($proc);
    }
});

test('Perfiles, resumen, pantalla y PDF (JS en navegador): casos 1 a 9', function () {
    $browser = testBrowser();
    if ($browser === null) {
        skipTest('No se ha encontrado Edge ni Chrome (se puede indicar con ANALYZER_TEST_BROWSER).');
    }
    $root = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    $src = fn(string $rel) => htmlspecialchars('file://' . ($root[0] === '/' ? '' : '/') . str_replace(' ', '%20', $root . '/' . $rel), ENT_QUOTES, 'UTF-8');
    $json = json_encode(perfilesI18n(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $scripts = '';
    foreach (['rw-componentes', 'perfiles', 'pdf', 'informes-pdf', 'dashboard'] as $f) {
        $scripts .= '<script src="' . $src("assets/js/$f.js") . '"></script>' . "\n";
    }
    $html = "<!doctype html>\n<html lang=\"es\"><head><meta charset=\"utf-8\"><title>Pruebas de perfiles</title></head><body>\n"
        . "<pre id=\"resultado\">sin ejecutar</pre><div id=\"zona\"></div><div id=\"zona2\"></div><div id=\"zona3\"></div>\n"
        . "<script>window.onerror = function (msg, f, l) { document.getElementById('resultado').textContent = JSON.stringify({ ok: 0, fallos: ['Error: ' + msg + ' (' + f + ':' + l + ')'] }); };</script>\n"
        . "<script type=\"application/json\" id=\"i18n\">$json</script>\n"
        . "<script>window.I18N = JSON.parse(document.getElementById('i18n').textContent);</script>\n"
        . $scripts
        . '<script src="' . $src('tests/js/perfiles_test.js') . "\"></script>\n</body></html>\n";
    $dir = tempDir('perfiles-js-');
    $file = $dir . DIRECTORY_SEPARATOR . 'perfiles_test.html';
    file_put_contents($file, $html);
    $dom = dumpDom($browser, $file, 90);
    @unlink($file);
    @rmdir($dir);
    assertTrue($dom !== null && $dom !== '', 'El navegador no devolvió el DOM (' . $browser . ').');
    assertTrue(preg_match('#<pre id="resultado">(.*?)</pre>#s', $dom, $m) === 1, 'No aparece #resultado.');
    $res = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    assertTrue(is_array($res), 'Las pruebas de JavaScript no se ejecutaron: ' . substr($m[1], 0, 300));
    assertSame([], $res['fallos']);
    foreach (range(1, 9) as $caso) {
        assertTrue(($res['casos'][$caso] ?? 0) > 0, "caso $caso sin comprobaciones");
    }
    $GLOBALS['__perfiles_js'] = $res;
});
