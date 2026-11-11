<?php
// Pruebas del tono del contenido (src/Sentiment.php y Insights::tone). Sin red.
// htmlCtx() está definida en extras_test.php.

test('Tono: texto claramente positivo', function () {
    $r = Sentiment::analyze('Un servicio excelente y muy rápido. Estamos encantados, lo recomiendo.', 'es');
    assertSame(true, $r['disponible']);
    assertSame('positivo', $r['etiqueta']);
    assertTrue($r['puntuacion'] > 0.5, 'Puntuación ' . $r['puntuacion']);
    assertSame(0, $r['negativas']);
    assertSame(true, $r['concluyente']);
});

test('Tono: texto claramente negativo, con plurales y femeninos', function () {
    $r = Sentiment::analyze('Una experiencia horrible. Muchos problemas y errores; atención pésima.', 'es-ES');
    assertSame('negativo', $r['etiqueta']);
    assertTrue($r['puntuacion'] < -0.5, 'Puntuación ' . $r['puntuacion']);
    $terms = array_column($r['terminos_negativos'], 'termino');
    assertTrue(in_array('problema', $terms, true) && in_array('error', $terms, true) && in_array('pesimo', $terms, true), implode(',', $terms));
});

test('Tono: la negación invierte («sin problemas», «no es bueno»)', function () {
    $r = Sentiment::analyze('Funciona sin problemas.', 'es');
    assertSame('positivo', $r['etiqueta']);
    $r = Sentiment::analyze('El producto no es bueno.', 'es');
    assertSame('negativo', $r['etiqueta']);
});

test('Tono: «muy» intensifica', function () {
    $a = Sentiment::analyze('Es bueno pero algo lento.', 'es')['puntuacion'];
    $b = Sentiment::analyze('Es muy bueno pero algo lento.', 'es')['puntuacion'];
    assertTrue($b > $a, "$b > $a");
});

test('Tono: «sin embargo» no es una negación', function () {
    $r = Sentiment::analyze('Sin embargo, el resultado es excelente.', 'es');
    assertSame('positivo', $r['etiqueta']);
    assertSame(0, $r['negativas']);
});

test('Tono: la puntuación es el balance y no se satura en textos largos', function () {
    $r = Sentiment::analyze(str_repeat('Un trabajo excelente. ', 50) . str_repeat('Un error grave. ', 50), 'es');
    // Excelente (+3) frente a error (−2) + grave (−2): (150 − 200) / 350
    assertSame(-0.143, $r['puntuacion']);
    assertSame('negativo', $r['etiqueta']);
});

test('Tono: texto sin carga es neutral y poco concluyente', function () {
    $r = Sentiment::analyze('La oficina abre de lunes a viernes en la calle Mayor.', 'es');
    assertSame('neutral', $r['etiqueta']);
    assertSame(0.0, (float) $r['puntuacion']);
    assertSame(false, $r['concluyente']);
});

test('Tono: otro idioma no se analiza; sin lang se supone español', function () {
    $r = Sentiment::analyze('This is excellent.', 'en');
    assertSame(false, $r['disponible']);
    assertTrue(strpos($r['motivo'], '«en»') !== false);
    $r = Sentiment::analyze('Excelente trabajo.', null);
    assertSame(true, $r['disponible']);
    assertSame(true, $r['idioma_supuesto']);
});

test('Tono: frases más positivas y negativas', function () {
    $r = Sentiment::analyze('Todo fue genial y perfecto. El envío llegó con retraso. La caja es azul.', 'es');
    assertSame(1, count($r['frases_positivas']));
    assertSame('Todo fue genial y perfecto.', $r['frases_positivas'][0]['texto']);
    assertSame('El envío llegó con retraso.', $r['frases_negativas'][0]['texto']);
});

test('Tono: Insights lo calcula sobre el contenido principal y lee el lang de <html>', function () {
    $html = '<html lang="es"><body><nav>Error fatal horrible</nav><main><p>Un equipo fantástico y un resultado excelente.</p></main></body></html>';
    $t = Insights::build(htmlCtx($html))['tono'];
    assertSame('positivo', $t['etiqueta'], 'El menú no cuenta');
    assertSame(0, $t['negativas']);
    assertSame('es', $t['idioma']);
    $t = Insights::build(htmlCtx('<html lang="fr"><body><main><p>Excellent.</p></main></body></html>'))['tono'];
    assertSame(false, $t['disponible']);
});
