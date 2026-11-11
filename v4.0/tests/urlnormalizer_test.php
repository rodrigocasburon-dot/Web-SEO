<?php
// Pruebas de UrlNormalizer (clave para comparar URL). Sin red: UrlGuard::normalize no resuelve DNS.

test('UrlNormalizer: esquema y host en minúsculas, sin www, sin fragmento ni barra de la raíz', function () {
    $cases = [
        'HTTPS://WWW.Example.COM/' => 'https://example.com',
        'https://example.com' => 'https://example.com',
        'example.com' => 'https://example.com',
        'https://www.example.com/#contacto' => 'https://example.com',
        'http://example.com:80/a' => 'http://example.com/a',
        'https://example.com:443/' => 'https://example.com',
        'https://www.example.com/Ruta/Con/Mayusculas' => 'https://example.com/Ruta/Con/Mayusculas',
        'https://ñandú.es/' => 'https://xn--and-6ma2c.es',
    ];
    foreach ($cases as $in => $expected) {
        assertSame($expected, UrlNormalizer::comparisonKey($in), $in);
    }
});

test('UrlNormalizer: www solo se quita si queda un dominio; la barra final de otras rutas se conserva', function () {
    assertSame('https://www.com', UrlNormalizer::comparisonKey('https://www.com/'));
    assertSame('https://www2.example.com', UrlNormalizer::comparisonKey('https://www2.example.com/'));
    assertSame('https://sub.www.example.com', UrlNormalizer::comparisonKey('https://sub.www.example.com'));
    assertSame('https://example.com/blog/', UrlNormalizer::comparisonKey('https://www.example.com/blog/'));
    assertSame('https://example.com/blog', UrlNormalizer::comparisonKey('https://www.example.com/blog'));
});

test('UrlNormalizer: quita parámetros de seguimiento y conserva el resto en su orden', function () {
    $cases = [
        'https://example.com/a?b=1&utm_source=x&c=2&gclid=abc' => 'https://example.com/a?b=1&c=2',
        'https://example.com/?utm_source=x&utm_medium=y' => 'https://example.com',
        'https://example.com/?UTM_Campaign=1&id=5' => 'https://example.com/?id=5',
        'https://example.com/a?fbclid=1&gbraid=2&wbraid=3&msclkid=4&dclid=5&yclid=6&mc_cid=7&mc_eid=8&igshid=9' => 'https://example.com/a',
        'https://example.com/a?utm%5Fsource=x&q=uno%20dos' => 'https://example.com/a?q=uno%20dos',
        'https://example.com/a?solo&&x=' => 'https://example.com/a?solo&x=',
        'https://example.com/a?gclidx=1' => 'https://example.com/a?gclidx=1',
    ];
    foreach ($cases as $in => $expected) {
        assertSame($expected, UrlNormalizer::comparisonKey($in), $in);
    }
});

test('UrlNormalizer: variantes de la misma página dan la misma clave', function () {
    $variants = [
        'https://www.example.com/servicios?utm_source=newsletter#precios',
        'HTTPS://EXAMPLE.COM/servicios',
        'example.com/servicios?fbclid=xyz',
        'https://example.com:443/servicios',
    ];
    $keys = array_unique(array_map([UrlNormalizer::class, 'comparisonKey'], $variants));
    assertSame(['https://example.com/servicios'], array_values($keys));
    // Distintas de verdad: esquema, ruta o parámetros que no son de seguimiento
    assertTrue(UrlNormalizer::comparisonKey('http://example.com/') !== UrlNormalizer::comparisonKey('https://example.com/'));
    assertTrue(UrlNormalizer::comparisonKey('https://example.com/a?id=1') !== UrlNormalizer::comparisonKey('https://example.com/a?id=2'));
});

test('UrlNormalizer: entradas no válidas se rechazan con las reglas de UrlGuard', function () {
    assertGuardCode('SCHEME_NOT_ALLOWED', fn() => UrlNormalizer::comparisonKey('ftp://example.com/'));
    assertGuardCode('CREDENTIALS_IN_URL', fn() => UrlNormalizer::comparisonKey('https://user:pass@example.com/'));
    assertGuardCode('INVALID_URL', fn() => UrlNormalizer::comparisonKey(''));
    assertGuardCode('PORT_NOT_ALLOWED', fn() => UrlNormalizer::comparisonKey('https://example.com:8080/'));
});
