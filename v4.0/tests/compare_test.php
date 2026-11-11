<?php
// Pruebas de la comparación de dos URLs (función 10). La lógica está en assets/js/compare.js y se prueba en un
// navegador sin interfaz (testBrowser y dumpDom, en export_test.php). Sin navegador, la prueba queda OMITIDA.

test('Comparar (JS en navegador): diferencias de notas, estados, casos y recuentos', function () {
    $browser = testBrowser();
    if ($browser === null) {
        skipTest('No se ha encontrado Edge ni Chrome (se puede indicar con ANALYZER_TEST_BROWSER).');
    }
    $dom = dumpDom($browser, __DIR__ . '/js/compare_test.html');
    assertTrue($dom !== null && $dom !== '', 'El navegador no devolvió el DOM (' . $browser . ').');
    assertTrue(preg_match('#<pre id="resultado">(.*?)</pre>#s', $dom, $m) === 1, 'No aparece #resultado.');
    $res = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    assertTrue(is_array($res), 'Las pruebas de JavaScript no se ejecutaron: ' . substr($m[1], 0, 200));
    assertSame([], $res['fallos']);
    assertTrue($res['ok'] >= 24, 'Comprobaciones ejecutadas: ' . $res['ok']);
});

test('Comparar: una petición por URL al mismo endpoint, sin endpoint nuevo ni innerHTML', function () {
    $js = (string) file_get_contents(__DIR__ . '/../assets/js/compare.js');
    foreach (['fetch(', 'XMLHttpRequest', 'innerHTML', 'insertAdjacentHTML', 'document.write'] as $bad) {
        assertTrue(strpos($js, $bad) === false, 'compare.js no debe usar ' . $bad);
    }
    $app = (string) file_get_contents(__DIR__ . '/../assets/js/app.js');
    assertTrue(strpos($app, 'window.AnalyzerCompare') !== false, 'app.js usa AnalyzerCompare');
    // Cada análisis lleva una sola URL: { url: ... }; no hay ningún campo con dos URL
    assertTrue(preg_match("#postJson\('api/analyze\.php', \{ url: #", $app) === 1);
    assertTrue(strpos($app, 'urls:') === false && strpos($app, 'url2:') === false, 'Ninguna petición lleva dos URL');
    // v3.0: el modo sitio añade site.php, site_page.php, site_record.php y browser.php; la comparación sigue sin endpoint propio
    assertSame(['analyze.php', 'browser.php', 'history_report.php', 'psi.php', 'score.php', 'site.php', 'site_page.php', 'site_record.php'], array_map('basename', glob(__DIR__ . '/../api/*.php')), 'Sin endpoints nuevos para comparar');
    $index = (string) file_get_contents(__DIR__ . '/../index.php');
    $posCompare = strpos($index, "\$asset('assets/js/compare.js')");
    $posApp = strpos($index, "\$asset('assets/js/app.js')");
    assertTrue($posCompare !== false && $posApp !== false && $posCompare < $posApp, 'compare.js se carga antes que app.js');
});

test('Buscador (v4): URL, tipo de análisis (rápido por defecto) y departamento, todo etiquetado', function () {
    $html = renderIndex();
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xp = new DOMXPath($doc);
    $form = $xp->query('//form[@id="analyze-form"]')->item(0);
    assertTrue($form !== null);
    assertSame(1, $xp->query('.//input[@id="url"]', $form)->length);
    assertSame(1, $xp->query('//label[@for="url"]')->length);
    assertSame(1, $xp->query('.//fieldset[legend]//input[@type="radio" and @value="rapido" and @checked]', $form)->length, 'rápido por defecto');
    assertSame(1, $xp->query('.//fieldset[legend]//input[@type="radio" and @value="profundo"]', $form)->length);
    foreach (['modo-rapido', 'modo-profundo', 'perfil'] as $id) {
        assertSame(1, $xp->query('//label[@for="' . $id . '"]')->length, 'Etiqueta de ' . $id);
    }
    $deps = array_map(fn($o) => $o->getAttribute('value'), iterator_to_array($xp->query('//select[@id="perfil"]/option')));
    assertSame(['general', 'marketing', 'web', 'diseno', 'direccion'], $deps);
    assertSame(0, $xp->query('//*[@id="mode-compare" or @id="keyword" or @id="noindex" or @id="local-business"]')->length);
    // Contenedores del sitio entero (assets/js/site.js)
    foreach (['site-section', 'site-status', 'site-progress', 'site-stop', 'site-results'] as $id) {
        assertSame(1, $xp->query('//*[@id="' . $id . '"]')->length, $id);
    }
    assertSame(1, $xp->query('//label[@for="site-progress"]')->length);
});
