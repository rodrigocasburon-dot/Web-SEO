<?php
// Agregado del modo sitio (assets/js/site.js): nota media, fiabilidad y recomendaciones agrupadas.
// Se ejecuta tests/js/site_test.js en un navegador sin interfaz, sin red (como export_test.php).
// Si no hay navegador, la prueba se informa como OMITIDA (no como correcta).

test('Sitio (JS en navegador): media, fiabilidad y recomendaciones agrupadas con recuento y tope de páginas', function () {
    $browser = testBrowser();
    if ($browser === null) {
        skipTest('No se ha encontrado Edge ni Chrome (se puede indicar con ANALYZER_TEST_BROWSER).');
    }
    $dom = dumpDom($browser, __DIR__ . '/js/site_test.html');
    assertTrue($dom !== null && $dom !== '', 'El navegador no devolvió el DOM (' . $browser . ').');
    assertTrue(preg_match('#<pre id="resultado">(.*?)</pre>#s', $dom, $m) === 1, 'No aparece #resultado.');
    $res = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    assertTrue(is_array($res), 'Las pruebas de JavaScript no se ejecutaron: ' . substr($m[1], 0, 200));
    assertSame([], $res['fallos']);
    assertTrue($res['ok'] >= 35, 'Comprobaciones ejecutadas: ' . $res['ok']);
});

test('Sitio: el tope de páginas por recomendación sale de la configuración y site.js no usa innerHTML', function () {
    $config = Config::load(testConfigDir());
    assertSame(20, $config['site_group_max_pages']);
    $php = (string) file_get_contents(__DIR__ . '/../index.php');
    assertTrue(strpos($php, "'grupo_max_paginas'") !== false && strpos($php, "site_group_max_pages") !== false);
    $js = (string) file_get_contents(__DIR__ . '/../assets/js/site.js');
    assertTrue(strpos($js, 'maxPaginas: i18n.grupo_max_paginas') !== false);
    foreach (['innerHTML', 'insertAdjacentHTML', 'document.write'] as $bad) {
        assertTrue(strpos($js, $bad) === false, 'site.js no debe usar ' . $bad);
    }
    foreach (['cumple WCAG', 'cumple la ley'] as $bad) {
        assertTrue(stripos($js, $bad) === false, 'site.js no debe decir «' . $bad . '»');
    }
});
