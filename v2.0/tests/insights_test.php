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

// ---------- 05 Palabra clave objetivo ----------

function kwPage(): string
{
    return '<html><head><title>Software de Gestión para empresas | Proconsi</title>'
        . '<meta name="description" content="Programas de GESTION y ERP para pymes."></head><body>'
        . '<nav>Menú con software de gestión</nav><main><h1>Soluciones ERP</h1><h1>Software de gestión, a medida</h1>'
        . '<p>Nuestro software de gestión ayuda a las pymes.</p></main></body></html>';
}

test('Palabra clave: presencia sin distinguir mayúsculas ni tildes en los cinco elementos', function () {
    $ctx = htmlCtx(kwPage(), 'https://example.com/software-de-gestion/', [], null, ['palabra_clave' => 'software de gestión']);
    $k = Insights::build($ctx)['palabra_clave'];
    assertSame('software de gestión', $k['palabra_clave']);
    $by = array_column($k['elementos'], 'presente', 'elemento');
    assertSame(['title' => true, 'h1' => true, 'meta_description' => false, 'url' => true, 'primeras_palabras' => true], $by);
    assertSame(4, $k['presente_en']);
    assertSame('Software de gestión, a medida', $k['elementos'][1]['texto'], 'Se muestra el H1 que la contiene');

    $k2 = Insights::keyword(htmlCtx(kwPage()), 'GESTIÓN');
    assertSame(true, $k2['elementos'][2]['presente'], '«GESTION» en la description coincide con «GESTIÓN»');
    assertSame(false, $k2['elementos'][3]['presente'], 'La URL de prueba no la contiene');
});

test('Palabra clave: frase completa, sin coincidencias parciales y con la ñ distinta de la n', function () {
    $ctx = htmlCtx('<title>Superpotencia ERP</title><main><p>El año pasado.</p></main>');
    assertSame(false, Insights::keyword($ctx, 'potencia')['elementos'][0]['presente']);
    assertSame(true, Insights::keyword($ctx, 'erp')['elementos'][0]['presente']);
    assertSame(false, Insights::keyword($ctx, 'ano')['elementos'][4]['presente']);
    assertSame(true, Insights::keyword($ctx, 'Año')['elementos'][4]['presente']);
    assertSame(false, Insights::keyword($ctx, 'pasado el')['elementos'][4]['presente'], 'El orden de la frase importa');
});

test('Palabra clave: solo cuentan las primeras 100 palabras del contenido principal', function () {
    $html = '<main><p>' . str_repeat('relleno ', 99) . 'objetivo ' . str_repeat('más ', 50) . 'tardía</p></main>';
    $ctx = htmlCtx($html);
    assertSame(true, Insights::keyword($ctx, 'objetivo')['elementos'][4]['presente'], 'Palabra n.º 100');
    assertSame(false, Insights::keyword($ctx, 'tardía')['elementos'][4]['presente'], 'Palabra n.º 151');
    $nav = htmlCtx(kwPage());
    assertSame(false, Insights::keyword($nav, 'menú')['elementos'][4]['presente'], 'El menú no es contenido principal');
});

test('Palabra clave: opcional, recortada a 100 caracteres y sin caracteres de control', function () {
    assertSame(null, Insights::build(htmlCtx(kwPage()))['palabra_clave']);
    assertSame(null, Insights::keyword(htmlCtx(kwPage()), '  ¿?  '), 'Solo signos: no hay nada que buscar');
    assertSame('', Insights::keywordInput(['array']));
    assertSame('erp para pymes', Insights::keywordInput("  erp\tpara\x00 pymes "));
    assertSame(100, mb_strlen(Insights::keywordInput(str_repeat('ñ', 300))));
    assertSame('a e i o u u ñ c', Insights::fold('Á É Í Ó Ú Ü Ñ Ç'), 'La ñ se conserva');
    assertSame('consultoria tecnologica', Insights::fold("consultorí\u{00AD}a tecno\u{200B}lógica"), 'El guion blando no parte la palabra');
});

// ---------- 06 Esquema del documento ----------

/** Árbol reducido a «hN texto» con hijos, para comparar. */
function outlineShape(array $nodes): array
{
    return array_map(fn($n) => ['h' . $n['nivel'] . ($n['salto'] ? '!' : '') . ' ' . $n['texto'] => outlineShape($n['hijos'])], $nodes);
}

test('Esquema: árbol anidado de encabezados con saltos marcados (criterio de C05)', function () {
    $html = '<body><h1>Inicio</h1><h2>Servicios</h2><h4>ERP</h4><h3>CRM</h3><h2>Contacto</h2>'
        . '<h3 hidden>Oculto</h3><template><h2>Plantilla</h2></template><h6>Final</h6></body>';
    $o = Insights::build(htmlCtx($html))['esquema'];
    assertSame([['h1 Inicio' => [
        ['h2 Servicios' => [['h4! ERP' => []], ['h3 CRM' => []]]],
        ['h2 Contacto' => [['h6! Final' => []]]],
    ]]], outlineShape($o['encabezados']));
    assertSame(6, $o['total_encabezados'], 'Sin el oculto ni el de la plantilla');
    assertSame(2, $o['saltos']);
    $c05 = (new C05Headings())->run(htmlCtx($html));
    assertSame($c05['valor_medido']['saltos'], $o['saltos'], 'Mismos saltos que C05');
    $erp = $o['encabezados'][0]['hijos'][0]['hijos'][0];
    assertSame(2, $erp['desde']);
});

test('Esquema: sin h1 al principio, encabezado vacío y límite de encabezados', function () {
    $o = Insights::outline(htmlCtx('<h3>Tres</h3><h2></h2><h1>Uno</h1>'));
    assertSame([3, 2, 1], array_column($o['encabezados'], 'nivel'), 'Ninguno es hijo de otro: los siguientes no son de nivel mayor');
    assertSame(null, $o['encabezados'][1]['texto'], 'Encabezado vacío');
    assertSame(0, $o['saltos'], 'El primer encabezado no cuenta como salto');

    $many = Insights::outline(htmlCtx(str_repeat('<h2>x</h2>', Insights::OUTLINE_MAX + 5)));
    assertSame(Insights::OUTLINE_MAX + 5, $many['total_encabezados']);
    assertSame(Insights::OUTLINE_MAX, count($many['encabezados']));
    assertSame(true, $many['recortado']);
});

test('Esquema: regiones con recuento, equivalentes ARIA, nombres y ocultas', function () {
    $html = '<body><header>Cabecera</header><div role="banner">Otra</div>'
        . '<nav aria-label="Menú principal">a</nav><div role="navigation" aria-labelledby="t"><h2 id="t">Migas</h2></div>'
        . '<main><article><header>De artículo</header><footer>Pie de artículo</footer></article></main>'
        . '<main hidden>copia</main><aside>Lateral</aside><nav role="menubar">no es región</nav>'
        . '<footer>Pie</footer><div role="contentinfo complementary">x</div><template><nav>t</nav></template></body>';
    $r = array_column(Insights::outline(htmlCtx($html))['regiones'], null, 'rol');
    assertSame(2, $r['banner']['total'], 'header de página + role=banner; el de article no cuenta');
    assertSame(2, $r['navigation']['total'], 'nav con role=menubar no es región');
    assertSame(['Menú principal', 'Migas'], $r['navigation']['etiquetas']);
    assertSame([1, 1], [$r['main']['total'], $r['main']['ocultas']]);
    assertSame(1, $r['complementary']['total']);
    assertSame(2, $r['contentinfo']['total'], 'footer de página + role=contentinfo (primer token)');
    assertSame(['banner', 'navigation', 'main', 'complementary', 'contentinfo'], array_keys($r));
});
