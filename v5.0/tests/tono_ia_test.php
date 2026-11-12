<?php
// Pruebas de src/ToneForAi.php (tono y citabilidad para IA) y de la descarga de tono-ia.php. Sin red.
require_once __DIR__ . '/../tono-ia.php';

test('Tono para IA: indicadores, ejemplos, idioma, extracción del HTML y descarga segura', function () {
    $t = function (string $name, bool $cond) { assertTrue($cond, $name); };
    $ind = fn(array $r, string $k) => array_values(array_filter($r['indicadores'], fn($i) => $i['clave'] === $k))[0];
    $hype = ToneForAi::analyze("Somos líderes del sector. Ofrecemos soluciones integrales a medida. Nuestro equipo de profesionales es el mejor. Somos una empresa innovadora y única.", 'es');
    $t('Texto promocional: afirmaciones sin respaldo detectadas', $ind($hype, 'sin_respaldo')['estado'] === 'mejorable' && count($hype['ejemplos']['sin_respaldo']) === 3);
    $t('Texto promocional: recomienda cambiar superlativos por pruebas', (bool) preg_grep('/superlativos/', $hype['recomendaciones']));
    $news = ToneForAi::analyze("El secretario general pide a los líderes mundiales acabar con los combustibles fósiles. La cumbre reúne a 190 países. El acuerdo final se votará el viernes. Los expertos piden medidas concretas.", 'es');
    $sup = ToneForAi::analyze("El mejor seguro para tu viaje ahora con 10% de descuento. La póliza cubre 30 días de viaje en Europa. El precio parte de 25 euros al año. La cobertura incluye 3.000 euros de gastos médicos.", 'es');
    $t('Un superlativo con dato no es frase citable', !in_array('El mejor seguro para tu viaje ahora con 10% de descuento.', $sup['ejemplos']['citables'], true));
    $t('Noticia: «líderes mundiales» no es autopromoción', count($news['ejemplos']['sin_respaldo']) === 0);
    [$txt2] = ToneForAi::textFromHtml('<main><ul><li><h3>Rectificar una declaración</h3><p>Si has cometido un error te indicamos qué hacer.</p></li></ul></main>');
    $t('HTML: bloques anidados sin repetir y separados', substr_count($txt2, 'error') === 1 && strpos($txt2, 'declaraciónSi') === false);
    $data = ToneForAi::analyze("La empresa trabaja desde 1985 con más de 4.000 clientes en España. La compañía tiene la certificación ISO 27001 desde 2019. El soporte técnico responde en menos de 4 horas laborables. El 92 % de los clientes renueva su contrato cada año.", 'es-ES');
    $t('Texto con datos: frases con datos bien', $ind($data, 'concretas')['estado'] === 'bien');
    $t('Texto con datos: al menos 3 frases citables', count($data['ejemplos']['citables']) >= 3);
    $t('Texto con datos: resumen sin mejoras', strpos($data['resumen'], 'no se detectan') !== false);
    $mix = ToneForAi::analyze("Puedes pedir tu demo hoy. Si tienes dudas, contacta con nosotros. Te respondemos rápido. Usted puede solicitar presupuesto. Consulte las condiciones. Contacte con el equipo comercial.", 'es');
    $t('Mezcla de tú y usted detectada', $ind($mix, 'trato')['estado'] === 'mejorable');
    $neg = ToneForAi::analyze("El servicio tuvo un fallo grave el lunes. Hubo retrasos y muchas quejas de clientes. La incidencia provocó pérdidas importantes. El problema sigue sin resolverse.", 'es');
    $t('Tono negativo detectado', $ind($neg, 'tono')['valor'] === 'negativo');
    $nn = ToneForAi::analyze("Nuestro sistema evita errores en la facturación. La herramienta no tiene problemas de rendimiento. Sin retrasos en los envíos. No hay riesgo para tus datos.", 'es');
    $t('Negaciones: «evita errores» no cuenta como negativo', $ind($nn, 'tono')['valor'] !== 'negativo');
    $t('Otro idioma: no disponible', ToneForAi::analyze('Hello world. This is a test. Another sentence here.', 'en')['disponible'] === false);
    $t('Poco texto: no disponible', ToneForAi::analyze('Hola.', 'es')['disponible'] === false);
    [$txt, $lang] = ToneForAi::textFromHtml('<html lang="es"><body><nav><p>Menú con 25 enlaces de navegación aquí.</p></nav><main><h1>Título</h1><p>Texto principal con 3 datos útiles.</p></main><footer><p>Pie con teléfono 900 000 000 de la empresa.</p></footer></body></html>');
    $t('HTML: solo el contenido principal, sin menú ni pie', strpos($txt, 'principal') !== false && strpos($txt, 'Menú') === false && strpos($txt, 'Pie') === false && $lang === 'es');
    [, $e1] = toneFetch('ftp://example.com/');
    [, $e2] = toneFetch('http://127.0.0.1/');
    [, $e3] = toneFetch('http://192.168.1.10/');
    [, $e4] = toneFetch('http://example.com:8080/');
    $t('Descarga: rechaza ftp, IP local, red privada y puertos especiales', $e1 && $e2 && $e3 && $e4);
});

test('Tono para IA: texto en Windows-1252 se convierte y se analiza', function () {
    $r = ToneForAi::analyze(mb_convert_encoding('Somos líderes del sector. Ofrecemos soluciones integrales a medida. Nuestro equipo es el mejor. Atendemos a 300 clientes desde 2001.', 'Windows-1252', 'UTF-8'), 'es');
    assertSame(true, $r['disponible']);
    assertSame(4, $r['frases']);
});

test('Tono para IA: Insights lo incluye y no altera el documento', function () {
    $html = '<html lang="es"><body><nav><p>Menú</p></nav><main><h1>Servicios</h1><p>Somos líderes en soluciones integrales.</p><p>Atendemos a 300 clientes desde 2001.</p><p>El soporte responde en 4 horas laborables.</p></main></body></html>';
    $ctx = htmlCtx($html);
    $c = Insights::build($ctx);
    assertTrue(isset($c['tono_ia']['disponible']) && $c['tono_ia']['disponible'] === true, 'tono_ia disponible');
    assertSame(1, $ctx->xpath()->query('//nav')->length, 'El documento sigue intacto');
});
