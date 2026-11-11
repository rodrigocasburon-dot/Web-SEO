<?php
// Componentes visuales del informe (docs/DESIGN.md, docs/COMPONENTES.md): JS en navegador sin interfaz y
// reglas estáticas (paleta, sin innerHTML, sin recursos externos). Sin red. Sin navegador → OMITIDA.

test('Componentes (JS en navegador): estados, escapado, medidor, barras, bala, figura, matriz, diferencia y minigráfico', function () {
    $browser = testBrowser();
    if ($browser === null) {
        skipTest('No se ha encontrado Edge ni Chrome (se puede indicar con ANALYZER_TEST_BROWSER).');
    }
    $dom = dumpDom($browser, __DIR__ . '/js/componentes_test.html');
    assertTrue($dom !== null && $dom !== '', 'El navegador no devolvió el DOM (' . $browser . ').');
    assertTrue(preg_match('#<pre id="resultado">(.*?)</pre>#s', $dom, $m) === 1, 'No aparece #resultado.');
    $res = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    assertTrue(is_array($res), 'Las pruebas de JavaScript no se ejecutaron: ' . substr($m[1], 0, 200));
    assertSame([], $res['fallos']);
    assertTrue($res['ok'] >= 50, 'Comprobaciones ejecutadas: ' . $res['ok']);
});

test('Componentes: los CSS nuevos solo usan los colores de DESIGN.md (sección 2) y no cargan nada externo', function () {
    $allowed = ['#000000', '#e30613', '#6c6c6c', '#ffffff', '#8f8f8f', '#d9d9d9', '#f4f3f2',
        '#b4b4b4', '#2e2e2e', '#f3a0a6', '#ec5a64', '#8f0410'];
    foreach (['tokens', 'componentes', 'graficos'] as $name) {
        $css = (string) file_get_contents(__DIR__ . '/../assets/css/' . $name . '.css');
        assertTrue($css !== '', $name . '.css vacío');
        preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $css, $m);
        foreach ($m[0] as $hex) {
            assertTrue(in_array(strtolower($hex), $allowed, true), $name . '.css usa un color fuera de la paleta: ' . $hex);
        }
        assertTrue(preg_match('/\b(rgb|rgba|hsl|hsla)\(/i', $css) === 0, $name . '.css: colores solo en hexadecimal de la paleta');
        assertTrue(preg_match('#url\(\s*["\']?(https?:)?//#i', $css) === 0 && stripos($css, '@import') === false, $name . '.css carga recursos externos');
        assertTrue(preg_match('/\bgreen\b|#0[0-9a-f]{1}[89a-f]/i', $css) === 0, $name . '.css: sin verde');
    }
    $tokens = (string) file_get_contents(__DIR__ . '/../assets/css/tokens.css');
    foreach (['Regular', 'Medium', 'SemiBold', 'Bold'] as $w) {
        assertTrue(strpos($tokens, '../fonts/Poppins-' . $w . '.ttf') !== false, 'Poppins ' . $w . ' local');
        assertTrue(is_file(__DIR__ . '/../assets/fonts/Poppins-' . $w . '.ttf'), 'Falta assets/fonts/Poppins-' . $w . '.ttf');
    }
});

test('Componentes: el JS no usa innerHTML ni recursos externos y no afirma cumplimiento', function () {
    $js = (string) file_get_contents(__DIR__ . '/../assets/js/rw-componentes.js');
    foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'http://', 'https://'] as $bad) {
        // El espacio de nombres de SVG es la única URL permitida (no es una petición)
        $clean = str_replace('http://www.w3.org/2000/svg', '', $js);
        assertTrue(strpos($clean, $bad) === false, 'rw-componentes.js no debe contener ' . $bad);
    }
    foreach (['cumple WCAG', 'cumple la ley'] as $bad) {
        assertTrue(stripos($js, $bad) === false, 'rw-componentes.js no debe decir «' . $bad . '»');
    }
});

// v4: solo hay buscador (index.php), que carga app.js y site.js; sitio.php solo redirige
test('Componentes: index.php carga los estilos y rw-componentes.js antes del código que los usa', function () {
    foreach ([['index.php', 'app.js'], ['index.php', 'site.js']] as [$page, $script]) {
        $php = (string) file_get_contents(__DIR__ . '/../' . $page);
        $posRw = strpos($php, "\$asset('assets/js/rw-componentes.js')");
        $posApp = strpos($php, "\$asset('assets/js/" . $script . "')");
        assertTrue($posRw !== false && $posApp !== false && $posRw < $posApp, $page . ': rw-componentes.js antes de ' . $script);
        foreach (['tokens', 'componentes', 'graficos'] as $css) {
            assertTrue(strpos($php, "\$asset('assets/css/" . $css . ".css')") !== false, $page . ' carga ' . $css . '.css');
        }
    }
    $app = (string) file_get_contents(__DIR__ . '/../assets/js/app.js');
    assertTrue(strpos($app, 'window.RadarComponents') !== false, 'app.js usa los componentes');
    // «Qué arreglar primero» usa los puntos que calcula el servidor, no los recalcula
    assertTrue(strpos($app, 'r.penalizacion') !== false && strpos($app, 'repetitionWeight') === false && strpos($app, 'Math.log') === false,
        'app.js no recalcula penalizaciones');
});
