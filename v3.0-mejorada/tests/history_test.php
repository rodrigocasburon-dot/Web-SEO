<?php
// Historial (Fase 4) con SQLite en memoria: sin MySQL ni internet. El SQL de History es común a ambos.

function historyDb(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE historial (id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT NOT NULL, url_clave TEXT NOT NULL,
        fecha TEXT NOT NULL, nota_seo REAL NULL, nota_ia REAL NULL, nota_accesibilidad REAL NULL, nota_global REAL NULL,
        incompleta INTEGER NOT NULL DEFAULT 0, bloqueado INTEGER NOT NULL DEFAULT 0, pagespeed INTEGER NOT NULL DEFAULT 0,
        criticos INTEGER NOT NULL DEFAULT 0, altos INTEGER NOT NULL DEFAULT 0, medios INTEGER NOT NULL DEFAULT 0,
        bajos INTEGER NOT NULL DEFAULT 0, tipo TEXT NOT NULL DEFAULT "url", paginas INTEGER NOT NULL DEFAULT 1, informe BLOB NULL)');
    return $pdo;
}

/** Registros como los de api/analyze.php: todos CUMPLE salvo los indicados; C08 y C14 sin datos. */
function historyChecks(array $states = []): array
{
    $out = [];
    foreach (CheckRunner::catalog() as $id => $check) {
        $estado = $states[$id] ?? (in_array($id, History::PSI_IDS, true) ? BaseCheck::NO_DATA : BaseCheck::OK);
        $out[] = $check->describe($estado, $estado === BaseCheck::FAIL ? 1 : 0, true);
    }
    return $out;
}

function historyUtc(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date, new DateTimeZone('UTC'));
}

test('Historial: desactivado sin DSN (configuración de ejemplo)', function () {
    $config = Config::load(testConfigDir());
    assertSame(false, History::enabled($config));
    assertSame(null, History::fromConfig($config));
    assertSame(12, History::months($config));
});

test('Historial: el resumen guarda solo notas y fallos por gravedad; sin notas si hay bloqueo', function () {
    $checks = historyChecks(['C03' => BaseCheck::FAIL, 'C12' => BaseCheck::FAIL, 'C10' => BaseCheck::WARN]);
    $score = (new Scorer())->score($checks);
    $row = History::summary($score, $checks, false);
    assertSame(['nota_seo', 'nota_ia', 'nota_accesibilidad', 'nota_global', 'incompleta', 'bloqueado', 'pagespeed',
        'criticos', 'altos', 'medios', 'bajos'], array_keys($row));
    assertSame($score['global']['redondeada'], $row['nota_global']);
    $fails = 0;
    foreach (['criticos', 'altos', 'medios', 'bajos'] as $k) { $fails += $row[$k]; }
    assertSame(2, $fails);   // el aviso no cuenta
    assertSame(0, $row['pagespeed']);
    $blocked = History::summary($score, $checks, true);
    assertSame(null, $blocked['nota_global']);
    assertSame(1, $blocked['bloqueado']);
});

test('Historial: guarda, lista los anteriores de la misma URL y borra los de más de 12 meses', function () {
    $h = new History(historyDb(), str_repeat('a', 64), 12);
    $checks = historyChecks();
    $score = (new Scorer())->score($checks);
    $old = $h->record('https://example.com/', $score, $checks, false, historyUtc('2025-01-10 10:00:00'));
    $a = $h->record('https://example.com/', $score, $checks, false, historyUtc('2026-03-01 10:00:00'));
    $h->record('https://otra.example/', $score, $checks, false, historyUtc('2026-03-02 10:00:00'));
    $b = $h->record('https://EXAMPLE.com', $score, $checks, false, historyUtc('2026-04-01 10:00:00'));
    assertTrue($old < $a && $a < $b);
    $prev = $h->previous('https://example.com/', $b);
    assertSame(1, count($prev));   // la de 2025 se ha borrado; la actual y la otra URL no se listan
    assertSame('2026-03-01T10:00:00Z', $prev[0]['fecha']);
    assertSame($score['global']['redondeada'], $prev[0]['nota_global']);
    assertSame(false, $prev[0]['pagespeed']);
});

test('Historial: score.php solo actualiza con firmas válidas de analyze.php y psi.php, y una sola vez', function () {
    $key = str_repeat('b', 64);
    $h = new History(historyDb(), $key, 12);
    $checks = historyChecks(['C03' => BaseCheck::FAIL]);
    $id = $h->record('https://example.com/', (new Scorer())->score($checks), $checks, false, historyUtc('2026-05-01 00:00:00'));
    $sigA = $h->signAnalysis($id, $checks);

    $catalog = CheckRunner::catalog();
    $psi = [$catalog['C08']->describe(BaseCheck::FAIL, 3, true), $catalog['C14']->describe(BaseCheck::OK, 0, true)];
    $sigP = History::sign($key, 'psi', $psi);
    [$rest] = History::split($checks);
    $final = array_merge($rest, $psi);
    $score = (new Scorer())->score($final);

    // Estado manipulado en el navegador: C03 pasa a CUMPLE
    $forged = array_map(function ($c) { if ($c['id'] === 'C03') { $c['estado'] = BaseCheck::OK; } return $c; }, $final);
    assertSame(false, $h->finalize($id, $forged, $sigA, $sigP, $score, false));
    assertSame(false, $h->finalize($id, $final, $sigA, str_repeat('0', 64), $score, false));
    assertSame(false, $h->finalize($id + 1, $final, $sigA, $sigP, $score, false));   // firma de otra fila

    assertSame(true, $h->finalize($id, $final, $sigA, $sigP, $score, false));
    assertSame(false, $h->finalize($id, $final, $sigA, $sigP, $score, false));       // ya incluye PageSpeed
    $other = $h->record('https://example.com/', $score, $final, false, historyUtc('2026-05-02 00:00:00'));
    $prev = $h->previous('https://example.com/', $other);
    assertSame(true, $prev[0]['pagespeed']);
    assertSame($score['global']['redondeada'], $prev[0]['nota_global']);
});

test('Historial: la clave de firma se genera una vez y se reutiliza', function () {
    $file = tempDir('webseo-hist') . DIRECTORY_SEPARATOR . 'historial.key';
    $k1 = History::secretKey($file);
    assertTrue((bool) preg_match('/^[0-9a-f]{64}$/', $k1));
    assertSame($k1, History::secretKey($file));
});
