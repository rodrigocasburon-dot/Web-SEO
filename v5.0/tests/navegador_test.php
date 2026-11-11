<?php
// Chrome (Navegador): resolución fijada, validación por esquema+host+puerto, descarte de respuestas de redes
// no públicas y limpieza de perfiles. Sin internet: la prueba con Chrome real usa un servidor en 127.0.0.1
// y se omite si no hay Chrome instalado.

class_exists('Navegador');   // carga también ruta_chrome() y borrar_carpeta()

/** Navegador sin arrancar Chrome, con un UrlGuard de pruebas (DNS simulado). */
function navegadorSinChrome(UrlGuard $guard): Navegador
{
    $n = (new ReflectionClass('Navegador'))->newInstanceWithoutConstructor();
    foreach (['guard' => $guard, 'cerrado' => true] as $prop => $value) {
        $p = new ReflectionProperty('Navegador', $prop);
        $p->setAccessible(true);
        $p->setValue($n, $value);
    }
    return $n;
}

function navegadorCall(Navegador $n, string $method, ...$args)
{
    $m = new ReflectionMethod('Navegador', $method);
    $m->setAccessible(true);
    return $m->invoke($n, ...$args);
}

test('Chrome: desactivado por defecto en la configuración de ejemplo', function () {
    $config = Config::load(testConfigDir());
    assertSame(false, $config['chrome']['activo']);
    assertSame(false, BrowserChecks::available($config));
});

test('Chrome: reglas de resolución que fijan el host a la IP validada (IPv4, IPv6 y entradas no válidas)', function () {
    assertSame('MAP example.com 93.184.216.34', Navegador::reglas_resolucion(['Example.com' => '93.184.216.34']));
    assertSame('MAP a.example 93.184.216.34, MAP b.example [2606:2800:220:1::1]',
        Navegador::reglas_resolucion(['a.example' => '93.184.216.34', 'b.example' => '2606:2800:220:1::1']));
    // Nada que pueda inyectar otra regla o no sea una IP
    assertSame('', Navegador::reglas_resolucion(['a.example, MAP * 127.0.0.1' => '93.184.216.34', 'b.example' => 'no-es-ip', 'c d' => '1.1.1.1']));
    assertSame('', Navegador::reglas_resolucion([]));
    // Chrome sin resolución propia: lo fijado primero y, al final, todo lo demás no se resuelve
    assertSame('MAP a.example 93.184.216.34, MAP * ~NOTFOUND', Navegador::reglas_resolucion(['a.example' => '93.184.216.34'], true));
    assertSame('MAP * ~NOTFOUND', Navegador::reglas_resolucion([], true));
});

test('Chrome: la respuesta solo se entrega si Chrome se conectó a una red pública', function () {
    assertSame(true, Navegador::respuesta_permitida('Public', false));
    foreach (['Private', 'Local', 'Loopback', 'Unknown', ''] as $space) {
        assertSame(false, Navegador::respuesta_permitida($space, false), $space);
        assertSame(false, Navegador::respuesta_permitida($space, true), "$space, aunque el host esté fijado");
    }
    // Sin dato de Chrome: solo si el host está fijado a la IP validada
    assertSame(true, Navegador::respuesta_permitida(null, true));
    assertSame(false, Navegador::respuesta_permitida(null, false));
});

test('Chrome: la validación del destino va por esquema, host y puerto (antes solo por host)', function () {
    $guard = new UrlGuard([], fn(string $host) => $host === 'interno.example' ? ['10.0.0.5'] : ['93.184.216.34']);
    $n = navegadorSinChrome($guard);
    assertSame(true, navegadorCall($n, 'destino_seguro', 'https://e.example/a'));
    // Mismo host, puerto no permitido: antes pasaba porque el host ya estaba «aprobado»
    assertSame(false, navegadorCall($n, 'destino_seguro', 'https://e.example:8443/a'));
    assertSame(false, navegadorCall($n, 'destino_seguro', 'http://e.example:22/'));
    assertSame(true, navegadorCall($n, 'destino_seguro', 'http://e.example/b'));
    assertSame(true, navegadorCall($n, 'destino_seguro', 'https://e.example/otra'), 'el permitido sigue permitido');
    assertSame(false, navegadorCall($n, 'destino_seguro', 'https://interno.example/'));
    assertSame(false, navegadorCall($n, 'destino_seguro', 'http://127.0.0.1/'));
    assertSame(false, navegadorCall($n, 'destino_seguro', 'file:///etc/passwd'));
    assertSame(false, navegadorCall($n, 'destino_seguro', 'chrome://settings'));
    assertSame(true, navegadorCall($n, 'destino_seguro', 'data:text/plain,hola'));
});

test('Chrome: limpiar_huerfanos borra los perfiles abandonados y respeta los recientes y lo que no es suyo', function () {
    $base = tempDir('chrome-huerfanos');
    $old = $base . DIRECTORY_SEPARATOR . 'web_seo_chrome_' . bin2hex(random_bytes(6));
    $new = $base . DIRECTORY_SEPARATOR . 'web_seo_chrome_' . bin2hex(random_bytes(6));
    $other = $base . DIRECTORY_SEPARATOR . 'web_seo_chrome_no-es-un-perfil';
    foreach ([$old, $new, $other] as $d) {
        mkdir($d . DIRECTORY_SEPARATOR . 'Default', 0700, true);
        file_put_contents($d . DIRECTORY_SEPARATOR . 'Default' . DIRECTORY_SEPARATOR . 'Cookies', 'x');
    }
    touch($old, time() - 3600);
    touch($other, time() - 3600);
    assertSame(1, Navegador::limpiar_huerfanos(900, $base));
    assertSame(false, is_dir($old));
    assertSame(true, is_dir($new));
    assertSame(true, is_dir($other), 'solo se borran carpetas con el nombre exacto de un perfil');
});

test('Chrome real: no resuelve hosts sin fijar, y una respuesta desde 127.0.0.1 se descarta aunque la validación viera una IP pública (DNS rebinding simulado)', function () {
    if (ruta_chrome(['chrome_ruta' => '']) === null) {
        skipTest('No hay Chrome ni Edge instalados en este equipo.');
    }
    // Servidor «interno» en 127.0.0.1 que anota cada visita y devuelve un secreto
    $docroot = tempDir('chrome-rebind');
    $hits = $docroot . DIRECTORY_SEPARATOR . 'visitas.log';
    file_put_contents($docroot . '/index.php', '<?php file_put_contents(' . var_export($hits, true) . ', "x", FILE_APPEND); ?>'
        . '<!doctype html><html lang="es"><head><title>Interno</title></head><body><p>SECRETO-INTERNO-12345</p></body></html>');
    [$proc, $port] = startPhpServer($docroot);
    if ($proc === null) {
        skipTest('No se pudo arrancar el servidor local de PHP.');
    }
    $profiles = fn() => glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'web_seo_chrome_*', GLOB_ONLYDIR) ?: [];
    $visits = fn() => is_file($hits) ? strlen((string) file_get_contents($hits)) : 0;
    $before = $profiles();
    // PHP «ve» una IP pública para cualquier dominio (DNS simulado); el puerto del servidor de pruebas está permitido
    $guard = new UrlGuard(['allowed_ports' => [80, 443, $port]], fn(string $host) => ['93.184.216.34']);
    $base = ['chrome_ruta' => '', 'chrome_sin_sandbox' => false, 'max_segundos' => 40];
    $n = null;
    try {
        // 1) Solo hosts fijados: un dominio validado pero no fijado no se puede resolver; Chrome no sale a ningún sitio
        try {
            $n = new Navegador($base + ['solo_fijados' => true, 'fijar' => ['fijado.example' => '127.0.0.1']], $guard);
        } catch (Throwable $e) {
            skipTest('Chrome no ha arrancado en este equipo: ' . $e->getMessage());
        }
        assertSame(1, count($profiles()) - count($before), 'un perfil temporal mientras Chrome está abierto');
        $n->ir("http://otro.example:$port/", 8);
        assertSame(0, $visits(), 'el host sin fijar no se ha resuelto: ninguna petición ha salido');
        assertSame(['otro.example' => '93.184.216.34'], $n->sin_fijar(), 'queda anotado para fijarlo en la siguiente pasada');
        $text = (string) $n->evaluar('document.documentElement ? document.documentElement.innerText : ""');
        assertTrue(strpos($text, 'SECRETO-INTERNO') === false);
        $n->cerrar();

        // 2) La IP «validada» resulta ser interna (lo que pasaría con un DNS que cambia de respuesta si Chrome
        //    resolviera por su cuenta): la petición sale, pero la respuesta se descarta antes de llegar a la página
        $n = new Navegador($base + ['solo_fijados' => true, 'fijar' => ['rebind.example' => '127.0.0.1']], $guard);
        $p = new ReflectionProperty('Navegador', 'fijados');
        $p->setAccessible(true);
        $p->setValue($n, []);   // el host no consta como fijado a una IP validada
        $n->ir("http://rebind.example:$port/", 8);
        $text = (string) $n->evaluar('document.documentElement ? document.documentElement.innerText : ""');
        assertTrue(strpos($text, 'SECRETO-INTERNO') === false, 'la página no ha recibido el contenido interno: ' . substr($text, 0, 120));
        assertTrue($n->seguridad['respuestas_descartadas'] >= 1, 'respuesta descartada: ' . json_encode($n->seguridad));
        assertTrue($visits() >= 1, 'la petición sí llegó al servidor: es el riesgo residual documentado en docs/SEGURIDAD.md');
        // Ese destino queda vetado para el resto de la sesión
        assertSame(false, navegadorCall($n, 'destino_seguro', "http://rebind.example:$port/otra"));
    } finally {
        if ($n) {
            $n->cerrar();
            $n->cerrar();   // se puede llamar varias veces
        }
        stopPhpServer($proc);
    }
    assertSame(count($before), count($profiles()), 'los perfiles temporales se han borrado al cerrar');
});

test('Chrome real: si la petición se interrumpe (error fatal sin cerrar), Chrome y su perfil se limpian igualmente', function () {
    if (ruta_chrome(['chrome_ruta' => '']) === null) {
        skipTest('No hay Chrome ni Edge instalados en este equipo.');
    }
    $profiles = fn() => glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'web_seo_chrome_*', GLOB_ONLYDIR) ?: [];
    $before = $profiles();
    // Proceso aparte que abre Chrome y muere con un error fatal, sin finally ni cerrar()
    $script = tempDir('chrome-fatal') . DIRECTORY_SEPARATOR . 'fatal.php';
    file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/src/bootstrap.php', true) . ';'
        . ' $n = new Navegador(["chrome_ruta" => "", "chrome_sin_sandbox" => false, "solo_fijados" => true]);'
        . ' echo "ABIERTO:", count(glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . "web_seo_chrome_*", GLOB_ONLYDIR) ?: []), "\n";'
        . ' funcion_que_no_existe();');
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $code = proc_close($proc);
    if (strpos($out, 'ABIERTO:') === false) {
        skipTest('Chrome no ha arrancado en este equipo.');
    }
    assertTrue($code !== 0, 'el proceso ha terminado con error');
    assertTrue((int) substr($out, strpos($out, 'ABIERTO:') + 8) > count($before), 'había un perfil abierto antes del error');
    assertSame(count($before), count($profiles()), 'tras el error fatal no queda ningún perfil temporal');
});
