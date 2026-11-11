<?php
// Identidad detectada de la web (DESIGN.md §7): colores, tipografías, logotipo, favicon y theme-color.
// Informativo, sin puntuación. Sin red: HTML de ejemplo y un fetch simulado para las hojas enlazadas.
// Usa htmlCtx() de extras_test.php.

function identityHtml(string $head = '', string $body = ''): string
{
    return '<!doctype html><html lang="es"><head><title>Prueba</title>' . $head . '</head><body>' . $body . '</body></html>';
}

test('Identidad: normaliza colores hex, rgb, hsl y nombres; descarta los casi transparentes', function () {
    assertSame('#0072EE', IdentityDetector::parseColor('#0072ee'));
    assertSame('#FF5500', IdentityDetector::parseColor('#f50'));
    assertSame('#FF9E2D', IdentityDetector::parseColor('rgb(255, 158, 45)'));
    assertSame('#FF9E2D', IdentityDetector::parseColor('rgb(255 158 45 / 90%)'));
    assertSame('#FFFFFF', IdentityDetector::parseColor('white'));
    assertSame('#FF0000', IdentityDetector::parseColor('hsl(0, 100%, 50%)'));
    assertSame(null, IdentityDetector::parseColor('rgba(0,0,0,.1)'));
    assertSame(null, IdentityDetector::parseColor('#00000010'));
    assertSame(null, IdentityDetector::parseColor('var(--x)'));
});

test('Identidad: contraste sobre blanco con la fórmula de WCAG', function () {
    assertSame(21.0, IdentityDetector::contrastWithWhite('#000000'));
    assertSame(1.0, IdentityDetector::contrastWithWhite('#FFFFFF'));
    assertSame(4.52, IdentityDetector::contrastWithWhite('#0072EE'));
});

test('Identidad: theme-color y variables de marca con confianza alta; blanco descartado', function () {
    $html = identityHtml('<meta name="theme-color" content="#0072ee"><style>:root{--color-primary:#1fb5ff;--brand-accent:rgb(255,158,45)}'
        . ' a{color:#ff5500} .b{background:#FF5500} .c{border:1px solid #ff5501} body{color:#fff;background:white}</style>');
    $d = IdentityDetector::detect(htmlCtx($html));
    assertSame('#0072EE', $d['theme_color']['hex']);
    $hex = array_column($d['colores'], 'hex');
    assertSame(['#0072EE', '#1FB5FF', '#FF9E2D', '#FF5500'], $hex);
    assertSame('alta', $d['colores'][1]['confianza']);
    assertTrue(strpos($d['colores'][1]['origen'], '--color-primary') !== false, $d['colores'][1]['origen']);
    // #ff5500 y #ff5501 se agrupan: 3 usos → confianza media
    assertSame('media', $d['colores'][3]['confianza']);
    assertTrue(!in_array('#FFFFFF', $hex, true));
});

test('Identidad: tipografías de Google Fonts, @font-face y font-family; ignora genéricas, del sistema e iconos', function () {
    $html = identityHtml('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&amp;family=Roboto+Slab&amp;display=swap">'
        . '<style>@font-face{font-family:"Marca Sans";src:url(m.woff2)} @font-face{font-family:"Font Awesome 6 Free"}'
        . ' body{font:400 16px/1.5 "Inter",Arial,sans-serif} h1{font-family:Poppins,sans-serif} h2{font-family:Poppins} p{font-family:Arial,sans-serif}</style>');
    $d = IdentityDetector::detect(htmlCtx($html));
    $names = array_column($d['tipografias'], 'nombre');
    assertSame(['Inter', 'Roboto Slab', 'Marca Sans', 'Poppins'], $names);
    assertSame('Google Fonts', $d['tipografias'][0]['origen']);
    assertSame('media', $d['tipografias'][3]['confianza']);
});

test('Identidad: logotipo de JSON-LD (alta) o imagen con «logo» (media); favicon declarado o supuesto', function () {
    $ld = '<script type="application/ld+json">{"@type":"Organization","name":"Acme","logo":{"@type":"ImageObject","url":"/img/l.png"}}</script>';
    $d = IdentityDetector::detect(htmlCtx(identityHtml('<link rel="icon" href="/fav.png">', $ld), 'https://acme.example/a/'));
    assertSame('https://acme.example/img/l.png', $d['logotipo']['url']);
    assertSame('alta', $d['logotipo']['confianza']);
    assertSame('https://acme.example/fav.png', $d['favicon']['url']);
    assertSame('alta', $d['favicon']['confianza']);

    $d = IdentityDetector::detect(htmlCtx(identityHtml('', '<header><a href="/"><img src="/i/logo-acme.svg" alt="Acme"></a></header>'), 'https://acme.example/'));
    assertSame('https://acme.example/i/logo-acme.svg', $d['logotipo']['url']);
    assertSame('media', $d['logotipo']['confianza']);
    assertSame('Acme', $d['logotipo']['alt']);
    assertSame('baja', $d['favicon']['confianza']);
    assertSame('https://acme.example/favicon.ico', $d['favicon']['url']);

    $d = IdentityDetector::detect(htmlCtx(identityHtml('', '<img src="/foto.jpg" alt="Equipo">')));
    assertSame(null, $d['logotipo']);
});

test('Identidad: hojas enlazadas por fetch (primero las del dominio; máx. 3) y sin fetch se listan sin descargar', function () {
    $links = '<link rel="stylesheet" href="https://cdn.otro.example/x.css"><link rel="stylesheet" href="/a.css">'
        . '<link rel="stylesheet" href="/b.css"><link rel="stylesheet" href="/c.css"><link rel="stylesheet" href="/print.css" media="print">';
    $ctx = htmlCtx(identityHtml($links), 'https://acme.example/');
    $d = IdentityDetector::detect($ctx);
    assertSame(4, count($d['hojas_analizadas']));
    assertSame('no_descargada', $d['hojas_analizadas'][0]['estado']);
    assertSame('omitida', $d['hojas_analizadas'][3]['estado']);
    assertSame('https://cdn.otro.example/x.css', $d['hojas_analizadas'][3]['url']);

    $asked = [];
    $ctx->fetch = function (string $url, array $o = []) use (&$asked): array {
        $asked[] = $url;
        $css = ['https://acme.example/a.css' => '.btn{background:#e30613;color:#fff} a{color:#e30613} .x{border-color:#e30613}',
            'https://acme.example/b.css' => 'body{font-family:"Marca Sans",sans-serif}'];
        return isset($css[$url]) ? ['status' => 200, 'body' => $css[$url], 'error' => null] : ['status' => 404, 'body' => '', 'error' => null];
    };
    $d = IdentityDetector::detect($ctx);
    assertSame(['https://acme.example/a.css', 'https://acme.example/b.css', 'https://acme.example/c.css'], $asked);
    assertSame('#E30613', $d['colores'][0]['hex']);
    assertTrue(strpos($d['colores'][0]['origen'], 'a.css') !== false, $d['colores'][0]['origen']);
    assertSame('Marca Sans', $d['tipografias'][0]['nombre']);
    assertSame(['analizada', 'analizada', 'no_descargada', 'omitida'], array_column($d['hojas_analizadas'], 'estado'));
});

test('Identidad: forma parte del análisis complementario y no se calcula si el contenido no está disponible', function () {
    $c = Insights::build(htmlCtx(identityHtml('<meta name="theme-color" content="#e30613">')));
    assertSame('#E30613', $c['identidad']['theme_color']['hex']);
    assertTrue(strpos($c['identidad']['limitacion'], 'JavaScript') !== false);
    $c = Insights::build(htmlCtx('{}', 'https://example.com/', ['content_type' => 'application/json', 'headers' => ['content-type' => 'application/json']]));
    assertSame(null, $c['identidad']);
});

test('Identidad: ignora nombres de color dentro de var() y url() y las variables por defecto de librerías', function () {
    $html = identityHtml('<style>:root{--swiper-theme-color:#007aff;--e-global-color-primary:#d02422}'
        . ' .a{background:var(--wp--preset--color--vivid-green)} .b{color:var(--x-blue)} .c{background:url(red.png)}</style>');
    $hex = array_column(IdentityDetector::detect(htmlCtx($html))['colores'], 'hex');
    assertSame(['#D02422'], $hex);
});
