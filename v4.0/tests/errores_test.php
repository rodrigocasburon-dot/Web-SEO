<?php
// Errores internos controlados: SIN_DATOS con identificador, registro técnico saneado y respuesta fija de la API.

/** Ejecuta CheckRunner::runAll (privado) con las comprobaciones indicadas. */
function runChecksForTest(array $checks, CheckContext $ctx): array
{
    $m = new ReflectionMethod(CheckRunner::class, 'runAll');
    $m->setAccessible(true);
    return $m->invoke(new CheckRunner(), $checks, $ctx);
}

function errorTestContext(): CheckContext
{
    return CheckContext::forPsi('https://example.com/', null, null, []);
}

test('Error interno en una comprobación: SIN_DATOS con referencia, sin textos de PHP y sin penalizar', function () {
    $dir = tempDir('errores');
    putenv('WEBSEO_STORAGE_DIR=' . $dir);
    try {
        $broken = new class extends BaseCheck {
            protected string $id = 'C04';
            protected string $severity = 'CRITICO';
            protected bool $needsHtml = false;
            protected function evaluate(CheckContext $ctx): array
            {
                throw new RuntimeException("Fallo al leer https://cliente-secreto.example/privado?token=abc en C:\\wamp64\\www\\app\\src\\X.php\nlinea 2");
            }
        };
        $records = runChecksForTest([$broken], errorTestContext());
        $r = $records[0];
        assertSame('C04', $r['id']);
        assertSame(BaseCheck::NO_DATA, $r['estado']);
        assertTrue((bool) preg_match('/^E-[0-9a-f]{8}$/', $r['error_interno']));
        assertTrue(strpos($r['detalle'], $r['error_interno']) !== false, 'el detalle lleva la referencia');
        assertTrue(strpos($r['detalle'], 'cliente-secreto') === false && strpos($r['detalle'], 'RuntimeException') === false
            && strpos($r['detalle'], '.php') === false, 'el detalle no lleva textos de PHP: ' . $r['detalle']);
        // Registro completo, como cualquier otra comprobación
        foreach (['nombre', 'pilares', 'severidad', 'recomendacion', 'impacto', 'esfuerzo', 'evidencia', 'ocurrencias', 'aplica'] as $k) {
            assertTrue(array_key_exists($k, $r), "falta $k");
        }
        // No penaliza: el área queda en 100 y la comprobación consta como sin datos
        $score = (new Scorer())->score($records);
        assertSame(100.0, $score['areas']['accesibilidad']['puntuacion']);
        assertSame(['C04'], $score['sin_datos']);

        // Registro técnico: identificador, contexto, clase y archivo; sin la URL ni rutas del servidor
        $log = (string) file_get_contents(ErrorLog::file());
        assertTrue(strpos($log, $r['error_interno']) !== false);
        assertTrue(strpos($log, "check:C04\tRuntimeException\terrores_test.php:") !== false, $log);
        assertTrue(strpos($log, 'cliente-secreto') === false && strpos($log, 'token=abc') === false, 'sin URL: ' . $log);
        assertTrue(strpos($log, 'wamp64') === false, 'sin rutas del servidor: ' . $log);
        assertSame(1, substr_count($log, "\n"), 'una línea por error');
    } finally {
        putenv('WEBSEO_STORAGE_DIR');
    }
});

test('Error interno: las demás comprobaciones se siguen evaluando', function () {
    $dir = tempDir('errores');
    putenv('WEBSEO_STORAGE_DIR=' . $dir);
    try {
        $broken = new class extends BaseCheck {
            protected string $id = 'C05';
            protected bool $needsHtml = false;
            protected function evaluate(CheckContext $ctx): array
            {
                throw new TypeError('x');
            }
        };
        $fine = new class extends BaseCheck {
            protected string $id = 'C10';
            protected bool $needsHtml = false;
            protected function evaluate(CheckContext $ctx): array
            {
                return $this->result(self::OK, null, null, [], 'bien');
            }
        };
        $records = runChecksForTest([$broken, $fine], errorTestContext());
        assertSame([BaseCheck::NO_DATA, BaseCheck::OK], array_column($records, 'estado'));
        assertTrue(!isset($records[1]['error_interno']));
    } finally {
        putenv('WEBSEO_STORAGE_DIR');
    }
});

test('ErrorLog: los errores de base de datos se reducen al SQLSTATE (sin usuario ni servidor)', function () {
    $dir = tempDir('errores');
    putenv('WEBSEO_STORAGE_DIR=' . $dir);
    try {
        $e = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'radar'@'10.0.0.5' (using password: YES)");
        $id = ErrorLog::record('historial:conexion', $e);
        $log = (string) file_get_contents(ErrorLog::file());
        assertTrue(strpos($log, $id) !== false);
        assertTrue(strpos($log, 'radar') === false && strpos($log, '10.0.0.5') === false && strpos($log, 'password') === false, $log);
        assertTrue(strpos(ErrorLog::userMessage($id), $id) !== false);
    } finally {
        putenv('WEBSEO_STORAGE_DIR');
    }
});

test('API: un error interno responde 500 con un mensaje fijo y una referencia que está en el registro', function () {
    $storage = tempDir('errores-api');
    $configDir = testConfigDir();
    // Historial sobre un SQLite sin tabla: la consulta falla dentro del endpoint
    $db = $storage . DIRECTORY_SEPARATOR . 'vacio.sqlite';
    new PDO('sqlite:' . $db);
    file_put_contents($configDir . '/config.php', '<?php return ' . var_export(['historial' => ['dsn' => 'sqlite:' . $db, 'usuario' => '', 'clave' => '', 'meses' => 12]], true) . ';');
    [$proc, $port] = startPhpServer(dirname(__DIR__), null, ['WEBSEO_STORAGE_DIR' => $storage, 'WEBSEO_CONFIG_DIR' => $configDir]);
    if ($proc === null) {
        skipTest('No se pudo arrancar el servidor local de PHP.');
    }
    try {
        $r = httpRequest('POST', "http://127.0.0.1:$port/api/history_report.php", json_encode(['id' => 1]), ['Content-Type: application/json']);
        assertSame(500, $r['status'], $r['body']);
        $d = json_decode($r['body'], true);
        assertSame(false, $d['ok']);
        assertSame('INTERNAL_ERROR', $d['error']['code']);
        assertTrue((bool) preg_match('/^E-[0-9a-f]{8}$/', $d['error']['ref']));
        assertTrue(strpos($d['error']['message'], $d['error']['ref']) !== false);
        assertTrue(stripos($r['body'], 'SQLSTATE') === false && stripos($r['body'], 'historial.php') === false
            && stripos($r['body'], 'no such table') === false, 'sin detalles de PHP ni de SQL: ' . $r['body']);
        $log = (string) @file_get_contents($storage . '/logs/errores.log');
        assertTrue(strpos($log, $d['error']['ref'] . "\tapi:history_report\tPDOException") !== false, $log);
    } finally {
        stopPhpServer($proc);
    }
});
