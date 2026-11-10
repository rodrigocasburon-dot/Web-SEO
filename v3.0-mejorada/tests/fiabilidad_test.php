<?php
// Indicador de fiabilidad: valores calculados, sin tocar notas, pesos ni criterios.

/** Todas las comprobaciones del catálogo en CUMPLE salvo las indicadas (id => estado, o id => false para «no aplica»). */
function reliabilityChecks(array $states = []): array
{
    $out = [];
    foreach (CheckRunner::catalog() as $id => $check) {
        $s = $states[$id] ?? BaseCheck::OK;
        $out[] = $s === false ? $check->describe(BaseCheck::OK, 0, false) : $check->describe($s, $s === BaseCheck::FAIL ? 1 : 0, true);
    }
    return $out;
}

/** Primera comprobación del catálogo con esa gravedad. */
function checkIdWithSeverity(string $severity): string
{
    foreach (CheckRunner::catalog() as $id => $check) {
        if ($check->severity() === $severity) {
            return $id;
        }
    }
    throw new RuntimeException("No hay comprobaciones de gravedad $severity");
}

test('Fiabilidad: todo evaluado → completo, 100 % y sin nivel si no hay umbrales configurados', function () {
    $n = count(CheckRunner::catalog());
    $f = Reliability::build(reliabilityChecks());
    assertSame('completo', $f['estado']);
    assertSame([], $f['motivos']);
    assertSame(['evaluados' => $n, 'posibles' => $n, 'sin_datos' => 0, 'porcentaje' => 100.0, 'peso_evaluado' => 100.0, 'nivel' => null], $f['global']);
    foreach (Scorer::AREAS as $a) {
        assertSame(0, $f['areas'][$a]['sin_datos']);
        assertSame(100.0, $f['areas'][$a]['peso_evaluado']);
    }
    // Los fallos y avisos también son comprobaciones evaluadas: la fiabilidad no depende de la nota
    $withFails = Reliability::build(reliabilityChecks(['C04' => BaseCheck::FAIL, 'C10' => BaseCheck::WARN]));
    assertSame($f['global'], $withFails['global']);
});

test('Fiabilidad: sin datos → parcial; cuenta evaluados sobre posibles y lo que no aplica no cuenta', function () {
    $n = count(CheckRunner::catalog());
    $f = Reliability::build(reliabilityChecks(['C08' => BaseCheck::NO_DATA, 'C14' => BaseCheck::NO_DATA, 'C06' => BaseCheck::NO_DATA, 'C11' => false]));
    assertSame('parcial', $f['estado']);
    assertSame($n - 4, $f['global']['evaluados']);
    assertSame($n - 1, $f['global']['posibles']);
    assertSame(3, $f['global']['sin_datos']);
    assertSame(1, $f['no_aplican']);
    assertSame(round(100 * ($n - 4) / ($n - 1), 1), $f['global']['porcentaje']);
    assertTrue($f['global']['peso_evaluado'] < 100.0 && $f['global']['peso_evaluado'] > 0.0);
    assertSame(2, count($f['motivos']), implode(' | ', $f['motivos']));
    assertTrue(strpos($f['motivos'][0], 'PageSpeed') !== false && strpos($f['motivos'][1], 'C06') !== false);
    // Por área: C08 solo afecta a accesibilidad (0/0/1) y C14 solo a SEO (1/0/0)
    assertSame(1, $f['areas']['ia']['sin_datos'], 'en IA solo falta C06');
    assertSame(2, $f['areas']['seo']['sin_datos']);
    assertSame(2, $f['areas']['accesibilidad']['sin_datos']);
});

test('Fiabilidad: mientras no llega PageSpeed, C08 y C14 constan como pendientes (análisis parcial)', function () {
    $checks = array_values(array_filter(reliabilityChecks(), fn($c) => !in_array($c['id'], History::PSI_IDS, true)));
    $n = count($checks);
    $sin = Reliability::build($checks);
    assertSame('completo', $sin['estado'], 'sin indicar pendientes solo se cuentan los registros recibidos');
    $f = Reliability::build($checks, false, ['pendientes' => History::PSI_IDS]);
    assertSame('parcial', $f['estado']);
    assertSame($n, $f['global']['evaluados']);
    assertSame($n + 2, $f['global']['posibles']);
    assertSame(History::PSI_IDS, $f['pendientes']);
    // Ya recibidas: no se cuentan dos veces
    $all = Reliability::build(reliabilityChecks(), false, ['pendientes' => History::PSI_IDS]);
    assertSame('completo', $all['estado']);
    assertSame([], $all['pendientes']);
});

test('Fiabilidad: el peso evaluado pondera por gravedad (perder una crítica pesa más que perder una baja)', function () {
    // Dos comprobaciones con el mismo reparto (0/0/1) y distinta gravedad
    $base = ['pilares' => ['seo' => 0, 'ia' => 0, 'accesibilidad' => 1], 'aplica' => true];
    $crit = ['id' => 'X1', 'severidad' => 'CRITICO'] + $base;
    $low = ['id' => 'X2', 'severidad' => 'BAJO'] + $base;
    $sinCritica = Reliability::build([$crit + ['estado' => BaseCheck::NO_DATA], $low + ['estado' => BaseCheck::OK]]);
    $sinBaja = Reliability::build([$crit + ['estado' => BaseCheck::OK], $low + ['estado' => BaseCheck::NO_DATA]]);
    assertSame(50.0, $sinCritica['global']['porcentaje']);
    assertSame(50.0, $sinBaja['global']['porcentaje']);
    // P: crítico 15, bajo 1 → 1/16 y 15/16
    assertSame(round(100 / 16, 1), $sinCritica['areas']['accesibilidad']['peso_evaluado']);
    assertSame(round(1500 / 16, 1), $sinBaja['areas']['accesibilidad']['peso_evaluado']);
    assertSame(null, $sinBaja['areas']['seo']['peso_evaluado'], 'área sin comprobaciones: sin porcentaje, no 0 ni 100');
});

test('Fiabilidad: bloqueo → bloqueado; HTML truncado → parcial aunque todo esté evaluado; errores internos contados', function () {
    $blocked = Reliability::build(reliabilityChecks(['C04' => BaseCheck::NO_DATA]), true, ['umbrales' => ['alta' => 90, 'media' => 50]]);
    assertSame('bloqueado', $blocked['estado']);
    assertSame(null, $blocked['global']['nivel'], 'bloqueado: sin nivel');
    $trunc = Reliability::build(reliabilityChecks(), false, ['truncado' => true]);
    assertSame('parcial', $trunc['estado']);
    assertSame(0, $trunc['global']['sin_datos']);
    assertSame(1, count($trunc['motivos']));
    $checks = reliabilityChecks(['C05' => BaseCheck::NO_DATA]);
    foreach ($checks as &$c) {
        if ($c['id'] === 'C05') {
            $c['error_interno'] = 'E-00000000';
        }
    }
    unset($c);
    assertSame(1, Reliability::build($checks)['errores_internos']);
});

test('Fiabilidad: el nivel solo existe con umbrales en la configuración (PROPUESTA comentada en el ejemplo)', function () {
    assertSame(null, Reliability::level(95.0, []));
    assertSame(null, Reliability::level(95.0, ['alta' => 50, 'media' => 90]), 'umbrales incoherentes: sin nivel');
    $t = ['alta' => 90, 'media' => 50];
    assertSame('alta', Reliability::level(90.0, $t));
    assertSame('media', Reliability::level(89.9, $t));
    assertSame('media', Reliability::level(50.0, $t));
    assertSame('baja', Reliability::level(49.9, $t));
    assertSame(null, Reliability::level(null, $t));
    $config = Config::load(testConfigDir());
    assertTrue(!isset($config['fiabilidad']), 'en config.example.php los umbrales están comentados');
    assertTrue(strpos((string) file_get_contents(__DIR__ . '/../config/config.example.php'), "// 'fiabilidad' => ['alta' => 90, 'media' => 50]") !== false);
});

test('Fiabilidad: no cambia notas ni el resto del informe', function () {
    $checks = reliabilityChecks(['C04' => BaseCheck::FAIL, 'C10' => BaseCheck::WARN, 'C08' => BaseCheck::NO_DATA]);
    $score = (new Scorer())->score($checks);
    $plain = Report::build($checks, $score);
    $withContext = Report::build($checks, $score, false, Simulator::DEFAULT_TOP,
        ['truncado' => true, 'pendientes' => History::PSI_IDS, 'umbrales' => ['alta' => 90, 'media' => 50]]);
    assertSame($score, (new Scorer())->score($checks));
    assertTrue(isset($plain['fiabilidad']['estado']));
    $a = $plain;
    $b = $withContext;
    unset($a['fiabilidad'], $b['fiabilidad']);
    assertSame($a, $b, 'el contexto de fiabilidad no altera prioridades, resumen, matriz ni simulación');
    assertSame('parcial', $withContext['fiabilidad']['estado']);
    assertTrue(in_array($withContext['fiabilidad']['global']['nivel'], ['alta', 'media', 'baja'], true));
});

test('Fiabilidad de sitio: páginas con nota sobre previstas y comprobaciones evaluadas', function () {
    $page = fn(array $states) => ['checks' => array_map(fn($s) => ['estado' => $s, 'aplica' => true], $states)];
    $ok = Reliability::site([$page(['CUMPLE', 'NO_CUMPLE']), $page(['CUMPLE', 'AVISO'])], false, ['previstas' => 2]);
    assertSame('completo', $ok['estado']);
    assertSame(['evaluados' => 4, 'posibles' => 4, 'sin_datos' => 0, 'porcentaje' => 100.0, 'peso_evaluado' => null, 'nivel' => null], $ok['global']);

    $partial = Reliability::site([$page(['CUMPLE', 'SIN_DATOS'])], false, ['previstas' => 4, 'bloqueadas' => 1, 'errores' => 1, 'sin_resultado' => 0, 'duplicadas' => 1]);
    assertSame('parcial', $partial['estado']);
    assertSame(['con_nota' => 1, 'previstas' => 3, 'bloqueadas' => 1, 'errores' => 1, 'sin_resultado' => 0], $partial['paginas']);
    assertSame(50.0, $partial['global']['porcentaje']);
    assertSame(2, count($partial['motivos']));

    // Dos URL que acaban en la misma página no cuentan como página perdida
    $dup = Reliability::site([$page(['CUMPLE'])], false, ['previstas' => 2, 'duplicadas' => 1]);
    assertSame('completo', $dup['estado']);
    assertSame('bloqueado', Reliability::site([], true, ['previstas' => 5])['estado']);
});

test('Historial: guarda la fiabilidad si existen las columnas; sin ellas sigue funcionando igual', function () {
    $checks = historyChecks();   // C08 y C14 sin datos
    $score = (new Scorer())->score($checks);
    $rel = Reliability::build($checks);

    // Base sin migrar (esquema anterior)
    $old = new History(historyDb(), str_repeat('a', 64));
    assertSame(false, $old->hasReliability());
    $id = $old->record('https://e.com/', $score, $checks, false, historyUtc('2026-11-09 10:00:00'));
    $old->saveReliability($id, $rel);   // no falla
    assertSame(null, $old->latest()[0]['fiabilidad']);

    // Base con docs/migracion-fiabilidad.sql aplicada
    $pdo = historyDb();
    $pdo->exec('ALTER TABLE historial ADD COLUMN fiab_estado TEXT NULL');
    $pdo->exec('ALTER TABLE historial ADD COLUMN fiab_evaluados INTEGER NULL');
    $pdo->exec('ALTER TABLE historial ADD COLUMN fiab_posibles INTEGER NULL');
    $h = new History($pdo, str_repeat('a', 64));
    assertSame(true, $h->hasReliability());
    $first = $h->record('https://e.com/', $score, $checks, false, historyUtc('2026-11-09 10:00:00'));
    $h->saveReliability($first, $rel);
    $second = $h->record('https://e.com/', $score, $checks, false, historyUtc('2026-11-09 11:00:00'));
    $n = count($checks);
    assertSame(['estado' => 'parcial', 'evaluados' => $n - 2, 'posibles' => $n], $h->previous('https://e.com/', $second)[0]['fiabilidad']);
    $latest = $h->latest();
    assertSame(null, $latest[0]['fiabilidad'], 'fila sin fiabilidad guardada');
    assertSame('parcial', $latest[1]['fiabilidad']['estado']);

    // El SQL de migración existe y declara las tres columnas
    $sql = (string) file_get_contents(__DIR__ . '/../docs/migracion-fiabilidad.sql');
    foreach (['fiab_estado', 'fiab_evaluados', 'fiab_posibles'] as $col) {
        assertTrue(strpos($sql, 'ADD COLUMN ' . $col) !== false && strpos((string) file_get_contents(__DIR__ . '/../install.sql'), $col) !== false, $col);
    }
});
