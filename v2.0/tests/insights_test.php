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
