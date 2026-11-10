<?php
// Pruebas numéricas del Scorer.

/** Registro sintético mínimo. */
function rec(string $id, string $estado, string $sev = 'MEDIO', int $k = 0, ?array $pil = null, bool $aplica = true): array
{
    return [
        'id' => $id, 'estado' => $estado, 'severidad' => $sev, 'ocurrencias' => $k, 'aplica' => $aplica,
        'pilares' => $pil ?? ['seo' => 0, 'ia' => 0, 'accesibilidad' => 1],
    ];
}

function assertNear(float $expected, float $actual, string $msg = '', float $eps = 1e-9): void
{
    if (abs($expected - $actual) > $eps) {
        throw new RuntimeException("Esperado $expected, obtenido $actual. $msg");
    }
}

test('Scorer: w = 1 + ln(k)', function () {
    assertNear(1.0, Scorer::repetitionWeight(1));
    assertNear(1.0, Scorer::repetitionWeight(0), 'k < 1 se trata como 1');
    assertNear(1 + log(5), Scorer::repetitionWeight(5));
    assertNear(2.6094379124341, Scorer::repetitionWeight(5), '1 + ln 5', 1e-12);
});

test('Scorer: sin fallos todo 100 y sin riesgo', function () {
    $s = (new Scorer())->score([rec('A', 'CUMPLE'), rec('B', 'AVISO', 'CRITICO'), rec('C', 'SIN_DATOS', 'CRITICO')]);
    foreach (Scorer::AREAS as $a) {
        assertNear(100.0, $s['areas'][$a]['puntuacion'], $a);
    }
    assertNear(100.0, $s['global']['puntuacion']);
    assertSame(false, $s['riesgo_alto']);
    assertSame('', $s['riesgo_mensaje']);
});

test('Scorer: ejemplo del enunciado — 2 críticos distintos + 1 alto ×5 = 49,1', function () {
    $s = (new Scorer())->score([
        rec('X1', 'NO_CUMPLE', 'CRITICO', 1),
        rec('X2', 'NO_CUMPLE', 'CRITICO', 1),
        rec('X3', 'NO_CUMPLE', 'ALTO', 5),
    ]);
    $acc = $s['areas']['accesibilidad'];
    assertNear(100 - (15 + 15 + 8 * (1 + log(5))), $acc['puntuacion']);   // 49,1245…
    assertSame(49.1, $acc['redondeada']);
    assertSame(3, count($acc['descuentos']));
    assertNear(100.0, $s['areas']['seo']['puntuacion']);
    assertNear(0.35 * 100 + 0.30 * 100 + 0.35 * $acc['puntuacion'], $s['global']['puntuacion']);
    assertSame(82.2, $s['global']['redondeada']);
    assertSame(true, $s['riesgo_alto']);
    assertSame('Riesgo alto: revisar de inmediato', $s['riesgo_mensaje']);
    assertSame(2, count($s['riesgo_motivos']), 'Área < 50 y dos críticos');
});

test('Scorer: el reparto de la matriz divide la penalización entre áreas', function () {
    // C01 crítico: 15 × (0,7 / 0,3 / 0)
    $s = (new Scorer())->score([rec('C01', 'NO_CUMPLE', 'CRITICO', 1, ['seo' => 0.7, 'ia' => 0.3, 'accesibilidad' => 0])]);
    assertNear(89.5, $s['areas']['seo']['puntuacion']);
    assertNear(95.5, $s['areas']['ia']['puntuacion']);
    assertNear(100.0, $s['areas']['accesibilidad']['puntuacion']);
    assertNear(0.35 * 89.5 + 0.30 * 95.5 + 0.35 * 100, $s['global']['puntuacion']);
    // Un solo crítico y áreas ≥ 50: sin riesgo
    assertSame(false, $s['riesgo_alto']);
});

test('Scorer: la penalización total de un fallo es w × P aunque se reparta', function () {
    $s = (new Scorer())->score([rec('C05', 'NO_CUMPLE', 'MEDIO', 3, ['seo' => 0.3, 'ia' => 0.3, 'accesibilidad' => 0.4])]);
    $total = 0.0;
    foreach (Scorer::AREAS as $a) {
        $total += $s['areas'][$a]['penalizacion'];
    }
    assertNear(4 * (1 + log(3)), $total);
});

test('Scorer: sin pilares en el registro usa config/weights.php', function () {
    $r = rec('C12', 'NO_CUMPLE', 'MEDIO', 1);
    unset($r['pilares']);
    $s = (new Scorer())->score([$r]);
    assertNear(96.0, $s['areas']['seo']['puntuacion']);
    assertNear(100.0, $s['areas']['accesibilidad']['puntuacion']);
});

test('Scorer: la nota no baja de 0', function () {
    $recs = [];
    for ($i = 0; $i < 10; $i++) {
        $recs[] = rec("X$i", 'NO_CUMPLE', 'CRITICO', 10);
    }
    $s = (new Scorer())->score($recs);
    assertNear(0.0, $s['areas']['accesibilidad']['puntuacion']);
    assertSame(0.0, $s['areas']['accesibilidad']['redondeada']);
});

test('Scorer: dos críticos repartidos activan riesgo aunque las áreas superen 50', function () {
    $s = (new Scorer())->score([
        rec('A', 'NO_CUMPLE', 'CRITICO', 1, ['seo' => 1, 'ia' => 0, 'accesibilidad' => 0]),
        rec('B', 'NO_CUMPLE', 'CRITICO', 1, ['seo' => 0, 'ia' => 0, 'accesibilidad' => 1]),
    ]);
    assertNear(85.0, $s['areas']['seo']['puntuacion']);
    assertSame(true, $s['riesgo_alto']);
    assertSame(['A', 'B'], $s['fallos_criticos']);
});

test('Scorer: umbral de riesgo exacto en 50', function () {
    $seo = ['seo' => 1, 'ia' => 0, 'accesibilidad' => 0];
    // 6 altos (48) + 2 bajos (2) = 50 → área 50: sin riesgo
    $recs = [];
    for ($i = 0; $i < 6; $i++) {
        $recs[] = rec("A$i", 'NO_CUMPLE', 'ALTO', 1, $seo);
    }
    $recs[] = rec('B1', 'NO_CUMPLE', 'BAJO', 1, $seo);
    $recs[] = rec('B2', 'NO_CUMPLE', 'BAJO', 1, $seo);
    $s = (new Scorer())->score($recs);
    assertNear(50.0, $s['areas']['seo']['puntuacion']);
    assertSame(false, $s['riesgo_alto']);
    // Un bajo más → 49: riesgo
    $recs[] = rec('B3', 'NO_CUMPLE', 'BAJO', 1, $seo);
    $s = (new Scorer())->score($recs);
    assertNear(49.0, $s['areas']['seo']['puntuacion']);
    assertSame(true, $s['riesgo_alto']);
});

test('Scorer: SIN_DATOS y no aplica se listan aparte y no penalizan', function () {
    $s = (new Scorer())->score([
        rec('A', 'SIN_DATOS', 'CRITICO'),
        rec('B', 'CUMPLE', 'ALTO', 0, null, false),
        rec('C', 'CUMPLE'),
    ]);
    assertSame(['A'], $s['sin_datos']);
    assertSame(['B'], $s['no_aplica']);
    assertNear(100.0, $s['areas']['accesibilidad']['puntuacion']);
});

test('Scorer: área incompleta si más de la mitad de sus comprobaciones son SIN_DATOS', function () {
    $acc = ['seo' => 0, 'ia' => 0, 'accesibilidad' => 1];
    // 2 de 3 sin datos → incompleta
    $s = (new Scorer())->score([rec('A', 'SIN_DATOS', 'ALTO', 0, $acc), rec('B', 'SIN_DATOS', 'ALTO', 0, $acc), rec('C', 'CUMPLE', 'ALTO', 0, $acc)]);
    assertSame(true, $s['areas']['accesibilidad']['incompleta']);
    assertSame(false, $s['areas']['seo']['incompleta'], 'Un área sin comprobaciones no se marca');
    assertSame(true, $s['global']['incompleta']);
    // Exactamente la mitad → no incompleta
    $s = (new Scorer())->score([rec('A', 'SIN_DATOS', 'ALTO', 0, $acc), rec('C', 'CUMPLE', 'ALTO', 0, $acc)]);
    assertSame(false, $s['areas']['accesibilidad']['incompleta']);
    // Las que no aplican no cuentan en el denominador: 1 sin datos de 1 que aplica → incompleta
    $s = (new Scorer())->score([rec('A', 'SIN_DATOS', 'ALTO', 0, $acc), rec('N', 'CUMPLE', 'ALTO', 0, $acc, false)]);
    assertSame(true, $s['areas']['accesibilidad']['incompleta']);
});

test('Scorer: una comprobación cuenta en un área solo si su reparto es mayor que 0', function () {
    $s = (new Scorer())->score([rec('C12', 'SIN_DATOS', 'MEDIO', 0, ['seo' => 1, 'ia' => 0, 'accesibilidad' => 0])]);
    assertSame(1, $s['areas']['seo']['comprobaciones']);
    assertSame(0, $s['areas']['ia']['comprobaciones']);
});

test('Scorer: reparto inválido o severidad desconocida lanzan excepción', function () {
    foreach ([
        rec('Z', 'NO_CUMPLE', 'ALTO', 1, ['seo' => 0.5, 'ia' => 0.4, 'accesibilidad' => 0]),
        rec('Z', 'NO_CUMPLE', 'ENORME', 1),
    ] as $bad) {
        $thrown = false;
        try {
            (new Scorer())->score([$bad]);
        } catch (InvalidArgumentException $e) {
            $thrown = true;
        }
        assertTrue($thrown);
    }
});

test('Matriz config/weights.php: una fila por comprobación, valores en [0,1] y filas que suman 1', function () {
    $m = require __DIR__ . '/../config/weights.php';
    $ids = array_keys(CheckRunner::catalog());
    sort($ids);
    $rows = array_keys($m);
    sort($rows);
    assertSame($ids, $rows);
    foreach ($m as $id => $row) {
        assertSame(3, count($row), $id);
        foreach ($row as $v) {
            assertTrue($v >= 0 && $v <= 1, $id);
        }
        assertNear(1.0, array_sum($row), $id, 1e-9);
    }
    assertNear(1.0, array_sum(Scorer::GLOBAL_WEIGHTS));
});

test('Scorer + comprobaciones: página correcta 100 en todo; defectuosa con riesgo alto', function () {
    $ok = (new Scorer())->score((new CheckRunner())->run(fx('full_ok', 'full_ok')));
    assertSame(100.0, $ok['global']['redondeada']);
    assertSame(false, $ok['riesgo_alto']);

    $bad = (new Scorer())->score((new CheckRunner())->run(fx('full_bad')));
    // Accesibilidad: C04 crítico k=2 → 15·(1+ln2) = 25,40; C05 4·0,4; C06 8·0,6; C07 8·0,6; C09 8·0,5; C10 8·0,2; C11 4·0,4
    $acc = 100 - (15 * (1 + log(2)) + 1.6 + 4.8 + 4.8 + 4.0 + 1.6 + 1.6);
    assertNear($acc, $bad['areas']['accesibilidad']['puntuacion'], '', 1e-9);
    // SEO: C02 6 + C05 1,2 + C06 1,6 + C07 3,2 + C09 4 + C10 6,4 + C11 1,2 + C15 1 = 24,6 (C12 y C13 sin canonical ni JSON-LD: AVISO, no restan)
    assertNear(75.4, $bad['areas']['seo']['puntuacion'], '', 1e-9);
    // IA: C02 9 + C05 1,2 + C06 1,6 + C11 1,2 = 13
    assertNear(87.0, $bad['areas']['ia']['puntuacion'], '', 1e-9);
    assertSame(72.2, $bad['global']['redondeada']);
    // Riesgo por dos críticos (C02 y C04), no por áreas < 50
    assertSame(true, $bad['riesgo_alto']);
    assertSame(['C02', 'C04'], $bad['fallos_criticos']);
    assertSame(1, count($bad['riesgo_motivos']));
});

test('Scorer + comprobaciones: sitio bloqueado deja notas incompletas, no fallos inventados', function () {
    $s = (new Scorer())->score((new CheckRunner())->run(fx('full_bad', 'c01_blocked')));
    assertSame(true, $s['areas']['accesibilidad']['incompleta']);
    assertNear(100.0, $s['areas']['accesibilidad']['puntuacion']);
});
