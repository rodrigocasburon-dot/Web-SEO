<?php
// Pruebas del simulador de mejora (función 01). Sin internet.

/** Registro sintético con lo que necesitan Scorer y Report::priorities(). */
function simRec(string $id, string $estado, string $sev = 'MEDIO', int $k = 0, ?array $pil = null): array
{
    return [
        'id' => $id, 'nombre' => 'Comprobación ' . $id, 'estado' => $estado, 'severidad' => $sev,
        'ocurrencias' => $k, 'aplica' => true, 'impacto' => 'MEDIO', 'esfuerzo' => 'MEDIO',
        'pilares' => $pil ?? ['seo' => 0, 'ia' => 0, 'accesibilidad' => 1],
    ];
}

/** Ejemplo del enunciado: 2 críticos distintos + 1 alto ×5 → accesibilidad 49,1. */
function simExample(): array
{
    return [
        simRec('X1', 'NO_CUMPLE', 'CRITICO', 1),
        simRec('X2', 'NO_CUMPLE', 'CRITICO', 1),
        simRec('X3', 'NO_CUMPLE', 'ALTO', 5),
        simRec('Y1', 'AVISO', 'ALTO'),
        simRec('Y2', 'SIN_DATOS', 'MEDIO'),
    ];
}

test('Simulador: ejemplo de 49,1 — corregir los 3 fallos deja accesibilidad en 100', function () {
    $s = Simulator::simulate(simExample(), 3, null, Scorer::raw());
    assertSame(3, $s['n']);
    assertSame(3, $s['fallos_totales']);
    assertSame(['X3', 'X1', 'X2'], array_column($s['corregidos'], 'id'), 'Orden: primero lo que más resta (8 × (1 + ln 5) = 20,9)');
    assertSame(['antes' => 49.1, 'despues' => 100.0, 'mejora' => 50.9], $s['notas']['accesibilidad']);
    assertSame(['antes' => 100.0, 'despues' => 100.0, 'mejora' => 0.0], $s['notas']['seo']);
    assertSame(['antes' => 82.2, 'despues' => 100.0, 'mejora' => 17.8], $s['notas']['global']);
    assertSame(['antes' => true, 'despues' => false], $s['riesgo_alto']);
});

test('Simulador: N = 1 y N = 2 en el ejemplo de 49,1 (70,0 y 85,0)', function () {
    $one = Simulator::simulate(simExample(), 1, null, Scorer::raw());
    assertSame(['X3'], array_column($one['corregidos'], 'id'));
    assertSame(20.9, $one['corregidos'][0]['penalizacion']);
    assertSame(['antes' => 49.1, 'despues' => 70.0, 'mejora' => 20.9], $one['notas']['accesibilidad']);
    assertSame(true, $one['riesgo_alto']['despues'], 'Siguen dos fallos críticos');

    $two = Simulator::simulate(simExample(), 2, null, Scorer::raw());
    assertSame(['X3', 'X1'], array_column($two['corregidos'], 'id'));
    assertSame(85.0, $two['notas']['accesibilidad']['despues']);
    assertSame(false, $two['riesgo_alto']['despues']);
});

test('Simulador: reutiliza el Scorer — el «después» coincide con puntuar a mano los registros corregidos', function () {
    $records = [
        simRec('A', 'NO_CUMPLE', 'ALTO', 3, ['seo' => 0.4, 'ia' => 0.6, 'accesibilidad' => 0]),
        simRec('B', 'NO_CUMPLE', 'MEDIO', 1, ['seo' => 0.3, 'ia' => 0.3, 'accesibilidad' => 0.4]),
        simRec('C', 'NO_CUMPLE', 'BAJO', 2, ['seo' => 1, 'ia' => 0, 'accesibilidad' => 0]),
        simRec('D', 'NO_CUMPLE', 'BAJO', 1, ['seo' => 1, 'ia' => 0, 'accesibilidad' => 0]),
    ];
    $s = Simulator::simulate($records, 2);
    assertSame(['A', 'B'], array_column($s['corregidos'], 'id'));
    $manual = $records;
    $manual[0]['estado'] = 'CUMPLE';
    $manual[1]['estado'] = 'CUMPLE';
    $expected = (new Scorer())->score($manual);
    foreach (Scorer::AREAS as $a) {
        assertSame($expected['areas'][$a]['redondeada'], $s['notas'][$a]['despues'], $a);
    }
    assertSame($expected['global']['redondeada'], $s['notas']['global']['despues']);
});

test('Simulador: sin fallos no cambia nada y no modifica los registros de entrada', function () {
    $records = [simRec('A', 'CUMPLE'), simRec('B', 'AVISO', 'CRITICO')];
    $copy = $records;
    $s = Simulator::simulate($records);
    assertSame([], $s['corregidos']);
    assertSame(0, $s['fallos_totales']);
    assertSame(['antes' => 100.0, 'despues' => 100.0, 'mejora' => 0.0], $s['notas']['global']);
    assertSame($copy, $records);

    $fail = [simRec('A', 'NO_CUMPLE', 'ALTO', 1)];
    Simulator::simulate($fail);
    assertSame('NO_CUMPLE', $fail[0]['estado'], 'La simulación no altera el análisis');
});

test('Simulador: N fuera de rango se acota y Report::build lo incluye (salvo acceso bloqueado)', function () {
    assertSame(1, Simulator::simulate(simExample(), 0)['n']);
    assertSame(Simulator::MAX_TOP, Simulator::simulate(simExample(), 99)['n']);

    $records = simExample();
    $score = (new Scorer())->score($records);
    $r = Report::build($records, $score, false, 2);
    assertSame(2, $r['simulacion']['n']);
    // Report::build usa la escala normalizada: el mismo resultado que el simulador con el Scorer por defecto
    assertSame(Simulator::simulate($records, 2)['notas']['accesibilidad']['despues'], $r['simulacion']['notas']['accesibilidad']['despues']);
    assertSame(Simulator::DEFAULT_TOP, Report::build($records, $score)['simulacion']['n']);
    assertSame(null, Report::build($records, $score, true)['simulacion']);
});

test('Simulador: N por defecto en la configuración de ejemplo', function () {
    $cfg = (array) require __DIR__ . '/../config/config.example.php';   // sin config.php local
    assertSame(3, (int) ($cfg['simulator_top_n'] ?? 0));
});
