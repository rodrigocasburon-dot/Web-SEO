<?php
// Pruebas de UrlGuard con DNS simulado (sin red).

function guardWith(array $dns = []): UrlGuard
{
    return new UrlGuard([], function (string $host) use ($dns) {
        return $dns[$host] ?? ['93.184.216.34'];
    });
}

test('UrlGuard: URL https normal', function () {
    $r = guardWith()->validate('https://Example.com/Ruta?a=1#frag');
    assertSame('https://example.com/Ruta?a=1', $r['url']);
    assertSame('93.184.216.34', $r['ip']);
    assertSame(443, $r['port']);
});

test('UrlGuard: sin esquema se supone https; punto final y puerto por defecto', function () {
    assertSame('https://example.com/', guardWith()->normalize('example.com.')['url']);
    assertSame('http://example.com/', guardWith()->normalize('http://example.com:80')['url']);
});

test('UrlGuard: esquemas no permitidos', function () {
    foreach (['javascript:alert(1)', 'file:///etc/passwd', 'ftp://example.com/', 'gopher://example.com/', 'data:text/html,x', 'mailto:a@b.com'] as $u) {
        assertGuardCode('SCHEME_NOT_ALLOWED', fn() => guardWith()->validate($u));
    }
});

test('UrlGuard: entradas inválidas', function () {
    assertGuardCode('INVALID_URL', fn() => guardWith()->validate(''));
    assertGuardCode('INVALID_URL', fn() => guardWith()->validate('http://exa mple.com'));
    assertGuardCode('INVALID_URL', fn() => guardWith()->validate('http://evil.com\\@good.com/'));
    assertGuardCode('INVALID_URL', fn() => guardWith()->validate('https://example.com/' . str_repeat('a', 3000)));
    assertGuardCode('INVALID_HOST', fn() => guardWith()->validate('http://intranet/'));
});

test('UrlGuard: credenciales y puertos', function () {
    assertGuardCode('CREDENTIALS_IN_URL', fn() => guardWith()->validate('http://user:pw@example.com/'));
    assertGuardCode('PORT_NOT_ALLOWED', fn() => guardWith()->validate('http://example.com:8080/'));
    assertGuardCode('PORT_NOT_ALLOWED', fn() => guardWith()->validate('http://example.com:22/'));
});

test('UrlGuard: IP privadas y reservadas literales', function () {
    $bad = [
        'http://127.0.0.1/', 'http://10.0.0.1/', 'http://192.168.1.1/', 'http://172.16.0.1/', 'http://172.31.255.255/',
        'http://169.254.169.254/', 'http://0.0.0.0/', 'http://100.64.0.1/', 'http://192.0.2.1/', 'http://224.0.0.1/',
        'http://255.255.255.255/', 'http://[::1]/', 'http://[::]/', 'http://[::ffff:127.0.0.1]/', 'http://[::ffff:10.0.0.1]/',
        'http://[fe80::1]/', 'http://[fc00::1]/', 'http://[fd12:3456::1]/', 'http://[64:ff9b::7f00:1]/',
        'http://[2002:7f00:1::]/', 'http://[2001:db8::1]/', 'http://[ff02::1]/',
    ];
    foreach ($bad as $u) {
        assertGuardCode('PRIVATE_ADDRESS', fn() => guardWith()->validate($u));
    }
});

test('UrlGuard: IP públicas literales aceptadas', function () {
    foreach (['http://8.8.8.8/', 'http://93.184.216.34/', 'http://[2606:4700:4700::1111]/'] as $u) {
        $r = guardWith()->validate($u);
        assertTrue($r['is_ip_literal']);
    }
});

test('UrlGuard: formas numéricas ambiguas rechazadas', function () {
    foreach (['http://2130706433/', 'http://0x7f.0.0.1/', 'http://0177.0.0.1/', 'http://127.1/'] as $u) {
        assertGuardCode('INVALID_HOST', fn() => guardWith()->validate($u));
    }
});

test('UrlGuard: dominios internos', function () {
    assertGuardCode('PRIVATE_ADDRESS', fn() => guardWith()->validate('http://app.localhost/'));
    assertGuardCode('PRIVATE_ADDRESS', fn() => guardWith()->validate('http://nas.local/'));
});

test('UrlGuard: dominio que resuelve a IP privada', function () {
    $g = guardWith(['interno.example.com' => ['10.0.0.5']]);
    assertGuardCode('PRIVATE_ADDRESS', fn() => $g->validate('https://interno.example.com/'));
});

test('UrlGuard: respuesta DNS mixta (pública + privada) se rechaza', function () {
    $g = guardWith(['mixto.example.com' => ['93.184.216.34', '127.0.0.1']]);
    assertGuardCode('PRIVATE_ADDRESS', fn() => $g->validate('https://mixto.example.com/'));
});

test('UrlGuard: DNS sin respuesta', function () {
    $g = guardWith(['nada.example.com' => []]);
    assertGuardCode('DNS_FAILED', fn() => $g->validate('https://nada.example.com/'));
});

test('UrlGuard: prefiere IPv4 cuando hay A y AAAA', function () {
    $g = guardWith(['dual.example.com' => ['2606:4700:4700::1111', '93.184.216.34']]);
    assertSame('93.184.216.34', $g->validate('https://dual.example.com/')['ip']);
});

test('UrlGuard: solo IPv6 pública', function () {
    $g = guardWith(['v6.example.com' => ['2606:4700:4700::1111']]);
    assertSame('2606:4700:4700::1111', $g->validate('https://v6.example.com/')['ip']);
});

test('UrlGuard: dominio IDN a punycode (si hay intl)', function () {
    if (!function_exists('idn_to_ascii')) {
        return;
    }
    assertSame('xn--bcher-kva.example', guardWith()->normalize('https://bücher.example/')['host']);
});

test('UrlGuard: ruta con bytes no ASCII se codifica', function () {
    assertSame('https://example.com/%C3%B1', guardWith()->normalize('https://example.com/ñ')['url']);
});
