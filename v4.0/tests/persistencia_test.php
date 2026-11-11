<?php
// Persistencia fiable: escritura atómica y reservas de RunStore, transacciones del historial y
// api/site_record.php de extremo a extremo con SQLite (sin MySQL, sin internet y sin claves).

/** Ejecuta $fn con WEBSEO_STORAGE_DIR apuntando a un directorio temporal nuevo. */
function withStorage(callable $fn, ?string $dir = null)
{
    $dir = $dir ?? tempDir('persist');
    $before = getenv('WEBSEO_STORAGE_DIR');
    putenv('WEBSEO_STORAGE_DIR=' . $dir);
    try {
        return $fn($dir);
    } finally {
        putenv($before === false ? 'WEBSEO_STORAGE_DIR' : 'WEBSEO_STORAGE_DIR=' . $before);
    }
}

test('RunStore: put() devuelve true, no deja temporales y sobrescribe de forma atómica', function () {
    withStorage(function (string $dir) {
        assertSame(true, RunStore::put('page', 'k', ['v' => 1]));
        assertSame(true, RunStore::put('page', 'k', ['v' => 2, 'texto' => str_repeat('ñ', 5000)]));
        assertSame(2, RunStore::get('page', 'k')['v']);
        assertSame([], glob($dir . '/runs/*.tmp') ?: []);
    });
});

test('RunStore: put() devuelve false si no se puede escribir (antes se ignoraba el error)', function () {
    // El «directorio» de storage es en realidad un archivo: no se puede crear runs/ dentro
    $file = tempDir('persist') . DIRECTORY_SEPARATOR . 'no-es-directorio';
    file_put_contents($file, 'x');
    $log = ini_set('error_log', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
    try {
        withStorage(function () {
            assertSame(false, RunStore::put('page', 'k', ['v' => 1]));
            assertSame(null, RunStore::get('page', 'k'));
        }, $file);
    } finally {
        ini_set('error_log', (string) $log);
    }
});

test('RunStore: purge() borra lo caducado y los temporales huérfanos, y conserva lo reciente', function () {
    withStorage(function (string $dir) {
        RunStore::put('page', 'vieja', ['v' => 1]);
        RunStore::put('page', 'nueva', ['v' => 2]);
        $files = glob($dir . '/runs/page_*.json');
        assertSame(2, count($files));
        $old = $dir . '/runs/page_' . hash('sha256', 'vieja') . '.json';
        touch($old, time() - RunStore::TTL - 10);
        $tmp = $dir . '/runs/page_x.json.abc.tmp';
        file_put_contents($tmp, 'a medias');
        touch($tmp, time() - 120);
        assertSame(2, RunStore::purge());
        assertSame(null, RunStore::get('page', 'vieja'));
        assertSame(2, RunStore::get('page', 'nueva')['v']);
        assertSame(false, is_file($tmp));
    });
});

test('RunStore: reserva de un solo uso — en curso, liberada, confirmada y abandonada', function () {
    withStorage(function () {
        $now = 1000000;
        assertSame('ok', RunStore::claim('a', $now));
        assertSame('en_curso', RunStore::claim('a', $now + 5));
        // Falló el guardado: se libera y se puede reintentar
        RunStore::release('a');
        assertSame('ok', RunStore::claim('a', $now + 6));
        // Guardado confirmado: ya no se puede repetir ni liberar
        assertSame(true, RunStore::confirm('a', ['id' => 7]));
        assertSame('hecho', RunStore::claim('a', $now + 7));
        RunStore::release('a');
        assertSame('hecho', RunStore::claim('a', $now + 8));
        assertSame(7, RunStore::claimInfo('a')['id']);
        // Petición interrumpida sin liberar: pasado CLAIM_STALE se puede retomar
        assertSame('ok', RunStore::claim('b', $now));
        assertSame('en_curso', RunStore::claim('b', $now + RunStore::CLAIM_STALE - 1));
        assertSame('ok', RunStore::claim('b', $now + RunStore::CLAIM_STALE + 1));
        assertSame(null, RunStore::claimInfo('b'));
    });
});

test('Historial: atomic() deshace la fila si el informe no se puede guardar', function () {
    $pdo = historyDb();
    $h = new History($pdo, str_repeat('a', 64));
    $checks = historyChecks();
    $score = (new Scorer())->score($checks);
    $thrown = false;
    try {
        $h->atomic(function (History $h) use ($score, $checks) {
            $id = $h->record('https://e.com/', $score, $checks, false);
            $h->saveReport($id + 100, ['x' => 1]);   // fila inexistente: saveReport debe fallar
        });
    } catch (RuntimeException $e) {
        $thrown = true;
    }
    assertTrue($thrown, 'saveReport sobre una fila que no existe lanza una excepción');
    assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM historial')->fetchColumn(), 'la fila se ha deshecho');
    assertSame(false, $pdo->inTransaction());

    // Caso correcto: quedan la fila y su informe
    $id = $h->atomic(function (History $h) use ($score, $checks) {
        $id = $h->record('https://e.com/', $score, $checks, false);
        $h->saveReport($id, ['x' => 1]);
        return $id;
    });
    assertSame(['x' => 1], $h->report($id)['informe']);
});

test('Historial: un informe que no se puede codificar no se guarda como vacío', function () {
    $h = new History(historyDb(), str_repeat('a', 64));
    $id = $h->record('https://e.com/', (new Scorer())->score(historyChecks()), historyChecks(), false);
    $thrown = false;
    try {
        $h->saveReport($id, ['n' => NAN]);   // json_encode no admite NAN
    } catch (RuntimeException $e) {
        $thrown = true;
    }
    assertTrue($thrown);
    assertSame(null, $h->report($id));
});

test('Historial: purgeExpired() borra solo las filas de más de N meses', function () {
    $h = new History(historyDb(), str_repeat('a', 64), 12);
    $checks = historyChecks();
    $score = (new Scorer())->score($checks);
    $h->record('https://e.com/', $score, $checks, false, historyUtc('2026-01-01 00:00:00'));
    $h->record('https://e.com/', $score, $checks, false, historyUtc('2026-06-01 00:00:00'));
    assertSame(0, $h->purgeExpired(historyUtc('2026-12-31 00:00:00')));
    assertSame(1, $h->purgeExpired(historyUtc('2027-02-01 00:00:00')));
    assertSame(1, count($h->latest()));
});

test('Historial: una clave de firma dañada se regenera y después es estable', function () {
    $file = tempDir('webseo-hist') . DIRECTORY_SEPARATOR . 'historial.key';
    file_put_contents($file, 'a medias');
    $k1 = History::secretKey($file);
    assertTrue((bool) preg_match('/^[0-9a-f]{64}$/', $k1));
    assertSame($k1, History::secretKey($file));
    assertSame($k1, trim((string) file_get_contents($file)));
});

test('site_record.php: si MySQL falla NO marca el análisis como usado; al reintentar se guarda, y solo una vez', function () {
    $storage = tempDir('persist-e2e');
    $configDir = testConfigDir();
    $db = $storage . DIRECTORY_SEPARATOR . 'historial.sqlite';
    // Configuración de prueba: historial sobre SQLite (sin claves). La tabla todavía NO existe: el guardado fallará.
    file_put_contents($configDir . '/config.php', '<?php return ' . var_export(['historial' => ['dsn' => 'sqlite:' . $db, 'usuario' => '', 'clave' => '', 'meses' => 12]], true) . ';');
    new PDO('sqlite:' . $db);

    $url = 'https://e.com/';
    $expires = time() + 600;
    // v4: las páginas van firmadas para un «run» (rastreo) y solo cuentan los resultados de ese mismo run
    $run = str_repeat('ab', 16);
    [$sig, $pageSig, $missingSig] = withStorage(function () use ($url, $expires, $run) {
        $token = SiteToken::fromStorage();
        $sig = $token->sign('registro|' . $url, $expires);
        $pageSig = $token->sign(SiteToken::pageScope($run, 'https://e.com/a'), $expires);
        $missingSig = $token->sign(SiteToken::pageScope($run, 'https://e.com/b'), $expires);
        $checks = historyChecks(['C04' => BaseCheck::FAIL]);
        $score = (new Scorer())->score($checks);
        assertTrue(RunStore::put('home', $sig, [
            'ok' => true, 'url' => $url, 'blocked' => null, 'checks' => $checks, 'puntuacion' => $score,
            'sitio' => ['run' => $run, 'paginas' => [['url' => 'https://e.com/a', 'firma' => $pageSig], ['url' => 'https://e.com/b', 'firma' => $missingSig]],
                'registro' => ['firma' => $sig], 'chrome' => null, 'tiempo_portada_ms' => 1200],
        ]));
        assertTrue(RunStore::put('page', $pageSig, ['ok' => true, 'run' => $run, 'url' => 'https://e.com/a', 'blocked' => null,
            'notas' => ['seo' => 80.0, 'ia' => 90.0, 'accesibilidad' => 70.0, 'global' => 79.5], 'fallos' => ['C06' => 'ALTO'], 'checks' => []]));
        return [$sig, $pageSig, $missingSig];
    }, $storage);

    [$proc, $port] = startPhpServer(dirname(__DIR__), null, ['WEBSEO_STORAGE_DIR' => $storage, 'WEBSEO_CONFIG_DIR' => $configDir]);
    if ($proc === null) {
        skipTest('No se pudo arrancar el servidor local de PHP.');
    }
    try {
        $post = fn() => httpRequest('POST', "http://127.0.0.1:$port/api/site_record.php",
            json_encode(['url' => $url, 'expira' => $expires, 'firma' => $sig, 'paginas' => [$pageSig, $missingSig, 'inventada']]),
            ['Content-Type: application/json']);

        // 1) La base de datos falla (no hay tabla): 503 y el análisis NO queda marcado como usado
        $r = $post();
        assertSame(503, $r['status'], $r['body']);
        assertSame('HISTORY_UNAVAILABLE', json_decode($r['body'], true)['error']['code']);

        // 2) Se arregla la base de datos y se reintenta: ahora sí se guarda (antes respondía 409 y se perdía)
        $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE historial (id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT NOT NULL, url_clave TEXT NOT NULL,
            fecha TEXT NOT NULL, nota_seo REAL NULL, nota_ia REAL NULL, nota_accesibilidad REAL NULL, nota_global REAL NULL,
            incompleta INTEGER NOT NULL DEFAULT 0, bloqueado INTEGER NOT NULL DEFAULT 0, pagespeed INTEGER NOT NULL DEFAULT 0,
            criticos INTEGER NOT NULL DEFAULT 0, altos INTEGER NOT NULL DEFAULT 0, medios INTEGER NOT NULL DEFAULT 0,
            bajos INTEGER NOT NULL DEFAULT 0, tipo TEXT NOT NULL DEFAULT "url", paginas INTEGER NOT NULL DEFAULT 1, informe BLOB NULL)');
        $r = $post();
        assertSame(200, $r['status'], $r['body']);
        $d = json_decode($r['body'], true);
        assertSame(true, $d['ok']);
        assertSame(2, $d['paginas'], 'portada y una página');
        assertSame(1, $d['sin_resultado'], 'la página pedida sin resultado en el servidor se cuenta, no se pierde en silencio');

        // 3) Fila e informe guardados juntos
        assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM historial WHERE informe IS NOT NULL')->fetchColumn());
        $saved = (new History($pdo, str_repeat('a', 64)))->report((int) $d['id']);
        assertSame('sitio', $saved['tipo']);
        assertSame(1, count($saved['informe']['pages']));
        assertSame(1, $saved['informe']['sin_resultado']);
        assertSame(null, $saved['informe']['home']['sitio']['registro'], 'el informe guardado no lleva firmas');

        // 4) Un solo uso: el mismo análisis no se guarda dos veces
        $r = $post();
        assertSame(409, $r['status']);
        assertSame('ALREADY_RECORDED', json_decode($r['body'], true)['error']['code']);
        assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM historial')->fetchColumn());
    } finally {
        stopPhpServer($proc);
    }
});

test('bin/limpiar.php: solo se ejecuta por consola y limpia los temporales caducados', function () {
    $storage = tempDir('persist-cli');
    withStorage(function (string $dir) {
        RunStore::put('page', 'vieja', ['v' => 1]);
        touch($dir . '/runs/page_' . hash('sha256', 'vieja') . '.json', time() - RunStore::TTL - 10);
    }, $storage);
    $env = array_merge(getenv(), ['WEBSEO_STORAGE_DIR' => $storage, 'WEBSEO_CONFIG_DIR' => testConfigDir()]);
    $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/limpiar.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    assertSame(0, proc_close($proc));
    assertTrue(strpos($out, 'Temporales de análisis borrados: 1') !== false, $out);
    assertSame([], glob($storage . '/runs/page_*.json') ?: []);
});
