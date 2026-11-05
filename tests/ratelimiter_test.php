<?php
// Pruebas del límite de uso por IP (directorio temporal y reloj simulado).

test('RateLimiter: permite hasta el máximo y luego bloquea con Retry-After', function () {
    $now = 1000;
    $rl = new RateLimiter(tempDir('rl'), 3, 60, function () use (&$now) { return $now; });
    assertSame(true, $rl->hit('1.2.3.4')['allowed']);
    $now = 1010;
    assertSame(true, $rl->hit('1.2.3.4')['allowed']);
    $r = $rl->hit('1.2.3.4');
    assertSame(true, $r['allowed']);
    assertSame(0, $r['remaining']);
    $r = $rl->hit('1.2.3.4');
    assertSame(false, $r['allowed']);
    assertSame(50, $r['retry_after'], 'El primer intento (t=1000) caduca en t=1060');
});

test('RateLimiter: la ventana es deslizante', function () {
    $now = 1000;
    $rl = new RateLimiter(tempDir('rl'), 2, 60, function () use (&$now) { return $now; });
    $rl->hit('ip');
    $now = 1030;
    $rl->hit('ip');
    assertSame(false, $rl->hit('ip')['allowed']);
    $now = 1061;                                     // caduca el primero
    assertSame(true, $rl->hit('ip')['allowed']);
    assertSame(false, $rl->hit('ip')['allowed']);
});

test('RateLimiter: cada IP tiene su contador y el fichero no contiene la IP', function () {
    $dir = tempDir('rl');
    $rl = new RateLimiter($dir, 1, 60, fn() => 1000);
    assertSame(true, $rl->hit('10.0.0.1')['allowed']);
    assertSame(true, $rl->hit('10.0.0.2')['allowed']);
    assertSame(false, $rl->hit('10.0.0.1')['allowed']);
    foreach (glob($dir . '/*') as $f) {
        assertTrue(strpos((string) file_get_contents($f), '10.0.0') === false);
        assertTrue(strpos($f, '10.0.0') === false);
    }
});

test('RateLimiter: cleanup borra registros caducados', function () {
    $dir = tempDir('rl');
    $rl = new RateLimiter($dir, 5, 60, fn() => time());
    $rl->hit('a');
    $files = glob($dir . '/rl_*.json');
    touch($files[0], time() - 3600);
    $rl->cleanup();
    assertSame([], glob($dir . '/rl_*.json'));
});
