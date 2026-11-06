<?php
// Pruebas del análisis complementario (src/Insights.php): vistas informativas que no puntúan. Sin red.
// htmlCtx() está definida en extras_test.php.

// ---------- 02 Lo que ve un bot ----------

test('Vista bot: texto del main, sin menú ni scripts, y mismo recuento que C03', function () {
    $html = '<html lang="es"><head><title>T</title><script>var oculto = "no";</script></head><body>'
        . '<nav>Inicio Contacto</nav><main><h1>Servicios de consultoría</h1><p>Texto del <b>contenido</b> principal.</p>'
        . '<script>noVisible()</script></main><footer>Pie legal</footer></body></html>';
    $ctx = htmlCtx($html);
    $v = Insights::build($ctx)['vista_bot'];
    assertSame('main', $v['fuente']);
    assertSame('Servicios de consultoría Texto del contenido principal.', $v['texto']);
    assertSame(7, $v['palabras']);
    $c03 = (new C03RawContent())->run(htmlCtx($html));
    assertSame($c03['valor_medido']['palabras'], $v['palabras'], 'Mismo recuento que C03');
    assertSame($c03['valor_medido']['fuente'], $v['fuente'], 'Misma fuente que C03');
    assertTrue($v['poco_texto'], '7 < 50 palabras');
    assertSame(50, $v['minimo_palabras']);
    assertSame(false, $v['texto_recortado']);
});

test('Vista bot: sin main ni article usa body sin nav, header, footer ni aside', function () {
    $words = str_repeat('palabra ', 60);
    $html = '<body><header>Cabecera</header><nav>Menú</nav><div>' . $words . '</div><aside>Lateral</aside><footer>Pie</footer></body>';
    $v = Insights::build(htmlCtx($html))['vista_bot'];
    assertSame('body (sin nav, header, footer ni aside)', $v['fuente']);
    assertSame(60, $v['palabras']);
    assertSame(false, $v['poco_texto']);
    assertTrue(strpos($v['texto'], 'Menú') === false && strpos($v['texto'], 'Pie') === false);
});

test('Vista bot: el texto se devuelve tal cual (se escapa al pintarlo) y se recorta al máximo', function () {
    $html = '<main><p>&lt;script&gt;alert(1)&lt;/script&gt; &amp; «comillas»</p></main>';
    $v = Insights::build(htmlCtx($html))['vista_bot'];
    assertSame('<script>alert(1)</script> & «comillas»', $v['texto'], 'Texto plano; app.js lo inserta con textContent');

    $long = '<main><p>' . str_repeat('ñandú ', 20000) . '</p></main>';   // 120.000 caracteres
    $v = Insights::build(htmlCtx($long))['vista_bot'];
    assertSame(true, $v['texto_recortado']);
    assertSame(Insights::BOT_TEXT_MAX, mb_strlen($v['texto'], 'UTF-8'));
    assertSame(20000, $v['palabras'], 'El recuento usa el texto completo');
});

test('Vista bot: bloqueo o recurso no HTML → sin vista y con motivo; HTML truncado se indica', function () {
    $blocked = htmlCtx('<main>Acceso denegado</main>', 'https://example.com/', ['status' => 403, 'blocked' => ['code' => 'FORBIDDEN', 'message' => 'x']]);
    $b = Insights::build($blocked);
    assertSame(null, $b['vista_bot']);
    assertTrue(strpos((string) $b['sin_contenido'], 'bloquea') !== false);

    $pdf = htmlCtx('%PDF-1.4', 'https://example.com/a.pdf', ['content_type' => 'application/pdf', 'headers' => ['content-type' => 'application/pdf']]);
    assertSame(null, Insights::build($pdf)['vista_bot']);
    assertSame('El recurso no es HTML.', Insights::build($pdf)['sin_contenido']);

    $cut = Insights::build(htmlCtx('<main>Hola</main>', 'https://example.com/', ['truncated' => true]));
    assertSame(true, $cut['vista_bot']['html_recortado']);
    assertSame(null, $cut['sin_contenido']);
});

test('Vista bot: C03 usa la extracción compartida (sin copia propia)', function () {
    $c03 = (string) file_get_contents(__DIR__ . '/../src/Checks/C03RawContent.php');
    assertTrue(strpos($c03, 'DomHelper::mainContent(') !== false);
    assertTrue(strpos($c03, 'function mainContent') === false);
});

// ---------- 03 Vista previa en Google y redes ----------

test('Vista previa: title, URL y description recortados a unos 60/60/155 caracteres sin partir palabras', function () {
    $title = 'Programas de gestión y ERP para empresas, pymes y autónomos en toda España';   // 74
    $desc = str_repeat('Descripción larga del servicio de consultoría tecnológica. ', 4);
    $html = '<html><head><title>  ' . $title . ' </title><meta name="description" content="' . $desc . '"></head><body></body></html>';
    $g = Insights::build(htmlCtx($html, 'https://example.com/servicios/erp-para-empresas-y-autonomos/gestion-integral?ref=1'))['vista_previa']['google'];
    assertSame($title, $g['titulo']);
    assertSame(74, $g['titulo_longitud']);
    assertSame(true, $g['titulo_recortado']);
    assertSame('Programas de gestión y ERP para empresas, pymes y autónomos…', $g['titulo_mostrado']);
    assertTrue(mb_strlen($g['descripcion_mostrado']) <= 156 && mb_substr($g['descripcion_mostrado'], -1) === '…');
    assertSame(true, $g['url_recortado']);
    assertTrue(mb_strlen($g['url_mostrado']) <= 61);

    $short = Insights::build(htmlCtx('<title>Inicio</title>'))['vista_previa']['google'];
    assertSame('Inicio', $short['titulo_mostrado']);
    assertSame(false, $short['titulo_recortado']);
    assertSame(null, $short['descripcion']);
    assertSame(null, $short['descripcion_mostrado']);
});

test('Vista previa: recorte sin espacios cercanos y sin romper caracteres multibyte', function () {
    assertSame(str_repeat('ñ', 10) . '…', Insights::truncate(str_repeat('ñ', 30), 10));
    assertSame('Hola mundo', Insights::truncate('Hola mundo', 10));
    assertSame('Uno dos tres…', Insights::truncate('Uno dos tres, cuatro', 14));
});

test('Vista previa: tarjeta social con Open Graph completo e imagen relativa resuelta', function () {
    $html = '<head><title>T</title>'
        . '<meta property="og:title" content="Título OG"><meta property="og:description" content="Desc OG">'
        . '<meta property="og:image" content="/img/portada.jpg"><meta name="twitter:card" content="summary_large_image"></head>';
    $s = Insights::build(htmlCtx($html, 'https://www.example.com/a/b'))['vista_previa']['social'];
    assertSame([], $s['ausentes']);
    assertSame('Título OG', $s['titulo']);
    assertSame('og:title', $s['titulo_origen']);
    assertSame('https://www.example.com/img/portada.jpg', $s['imagen']);
    assertSame(true, $s['imagen_https']);
    assertSame('summary_large_image', $s['tarjeta']);
    assertSame('www.example.com', $s['dominio']);
});

test('Vista previa: sin etiquetas sociales lista las ausentes y usa title/description como aproximación', function () {
    $html = '<head><title>Inicio</title><meta name="description" content="Texto"><meta property="og:title" content="  "></head>';
    $s = Insights::build(htmlCtx($html))['vista_previa']['social'];
    assertSame(['og:title', 'og:description', 'og:image', 'twitter:card'], $s['ausentes'], 'og:title vacío cuenta como ausente');
    assertSame('Inicio', $s['titulo']);
    assertSame('title', $s['titulo_origen']);
    assertSame('meta description', $s['descripcion_origen']);
    assertSame(null, $s['imagen']);
    assertSame(false, $s['imagen_https']);
});

test('Vista previa: imagen http o con esquema no web no se marca para cargar', function () {
    $http = Insights::build(htmlCtx('<meta property="og:image" content="http://cdn.example.com/a.jpg">'))['vista_previa']['social'];
    assertSame('http://cdn.example.com/a.jpg', $http['imagen']);
    assertSame(false, $http['imagen_https']);
    $js = Insights::build(htmlCtx('<meta property="og:image" content="javascript:alert(1)">'))['vista_previa']['social'];
    assertSame(false, $js['imagen_https']);
    $tw = Insights::build(htmlCtx('<meta name="twitter:image" content="https://x.example/t.png">'))['vista_previa']['social'];
    assertSame('twitter:image', $tw['imagen_origen']);
    assertSame(true, $tw['imagen_https']);
    assertSame(['og:title', 'og:description', 'og:image', 'twitter:card'], $tw['ausentes']);
});

test('Vista previa: la CSP solo abre img-src a https y la imagen se pide sin referer', function () {
    $index = (string) file_get_contents(__DIR__ . '/../index.php');
    assertTrue(preg_match("/img-src 'self' data: https:;/", $index) === 1, 'img-src admite https:');
    assertTrue(preg_match("/script-src 'self';/", $index) === 1 && strpos($index, 'http:') === false, 'Ni scripts externos ni http');
    $js = (string) file_get_contents(__DIR__ . '/../assets/js/app.js');
    assertTrue(strpos($js, "referrerpolicy: 'no-referrer'") !== false);
    assertTrue(strpos($js, "/^https:\/\//i.test(s.imagen)") !== false, 'La pantalla vuelve a comprobar que es https');
});
