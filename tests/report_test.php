<?php
// Pruebas del informe (src/Report.php) y de BaseCheck::describe(). Sin red.

/** Registros de ejemplo: [id, estado, ocurrencias, aplica] → registros completos desde el catálogo. */
function reportRecords(array $rows): array
{
    $catalog = CheckRunner::catalog();
    return array_map(fn($r) => $catalog[$r[0]]->describe($r[1], $r[2] ?? 0, $r[3] ?? true), $rows);
}

/** Todos los textos de una estructura (para buscar afirmaciones prohibidas). */
function reportStrings($v): array
{
    if (is_string($v)) {
        return [$v];
    }
    $out = [];
    if (is_array($v)) {
        foreach ($v as $k => $x) {
            if (is_string($k)) {
                $out[] = $k;
            }
            $out = array_merge($out, reportStrings($x));
        }
    }
    return $out;
}

function reportSample(): array
{
    return reportRecords([
        ['C10', 'NO_CUMPLE', 1],    // ALTO → 8
        ['C02', 'NO_CUMPLE', 1],    // CRITICO → 15
        ['C04', 'NO_CUMPLE', 3],    // CRITICO → 15 × (1 + ln 3) = 31,48
        ['C13', 'NO_CUMPLE', 1],    // MEDIO → 4
        ['C15', 'AVISO'],
        ['C05', 'AVISO'],
        ['C12', 'CUMPLE'],
        ['C06', 'SIN_DATOS'],
        ['C11', 'CUMPLE', 0, false],
    ]);
}

test('describe(): datos fijos de la clase y recomendación según el estado', function () {
    foreach (CheckRunner::catalog() as $id => $check) {
        $r = $check->describe('NO_CUMPLE', 2);
        assertSame($id, $r['id']);
        assertSame($check->severity(), $r['severidad']);
        assertSame(Lang::check($id, 'nombre'), $r['nombre']);
        assertSame(Lang::check($id, 'recomendacion'), $r['recomendacion'], $id);
        assertSame(2, $r['ocurrencias']);
        assertTrue(abs(array_sum($r['pilares']) - 1) < 1e-9, $id);
        assertSame('', $check->describe('CUMPLE')['recomendacion'], $id);
    }
    assertSame(Lang::check('C05', 'recomendacion_aviso'), CheckRunner::catalog()['C05']->describe('AVISO')['recomendacion']);
    assertSame(1, CheckRunner::catalog()['C05']->describe('NO_CUMPLE', 0)['ocurrencias']);   // un fallo cuenta al menos una vez
});

test('Report: prioridades — fallos por penalización (w × P) y después avisos por gravedad', function () {
    $p = Report::priorities(reportSample());
    assertSame(['C04', 'C02', 'C10', 'C13', 'C05', 'C15'], array_column($p, 'id'));
    assertSame([1, 2, 3, 4, 5, 6], array_column($p, 'orden'));
    assertSame(31.5, $p[0]['penalizacion']);
    assertSame(3, $p[0]['ocurrencias']);
    assertSame(0.0, $p[4]['penalizacion']);
    assertSame(Lang::check('C04', 'recomendacion'), $p[0]['recomendacion']);
    assertSame(Lang::check('C05', 'recomendacion_aviso'), $p[4]['recomendacion']);
});

test('Report: desempate con igual penalización — mayor impacto, menor esfuerzo, id', function () {
    // Todas de gravedad alta (8 puntos). C09: impacto alto; C06, C07 y C10: impacto medio y esfuerzo bajo
    $p = Report::priorities(reportRecords([['C10', 'NO_CUMPLE', 1], ['C07', 'NO_CUMPLE', 1], ['C09', 'NO_CUMPLE', 1], ['C06', 'NO_CUMPLE', 1]]));
    assertSame(['C09', 'C06', 'C07', 'C10'], array_column($p, 'id'));
    // Esfuerzo: C01 (crítico, esfuerzo medio) antes que C03 (crítico, esfuerzo alto)
    $p = Report::priorities(reportRecords([['C03', 'NO_CUMPLE', 1], ['C01', 'NO_CUMPLE', 1]]));
    assertSame(['C01', 'C03'], array_column($p, 'id'));
});

test('Report: matriz impacto × esfuerzo y problemas críticos', function () {
    assertSame('inmediata', Report::quadrant('ALTO', 'BAJO'));
    assertSame('planificar', Report::quadrant('ALTO', 'MEDIO'));
    assertSame('rapidas', Report::quadrant('MEDIO', 'BAJO'));
    assertSame('valorar', Report::quadrant('BAJO', 'ALTO'));

    $records = reportSample();
    $r = Report::build($records, (new Scorer())->score($records));
    assertSame(['C04', 'C02'], $r['criticos']);
    assertSame(['inmediata', 'planificar', 'rapidas', 'valorar'], array_keys($r['matriz']));
    assertSame(['C02'], $r['matriz']['inmediata']['ids']);
    assertSame(['C04'], $r['matriz']['planificar']['ids']);
    assertSame(['C10', 'C05', 'C15'], $r['matriz']['rapidas']['ids']);   // en orden de prioridad
    assertSame(['C13'], $r['matriz']['valorar']['ids']);
});

test('Report: resumen ejecutivo con cifras', function () {
    $records = reportSample();
    $score = (new Scorer())->score($records);
    $s = Report::build($records, $score)['resumen'];
    $global = number_format($score['global']['redondeada'], 1, ',', '.');
    assertTrue(strpos($s[0], "Nota global: $global sobre 100 (SEO ") === 0, $s[0]);
    assertTrue(strpos($s[0], 'Preparación para IA ') !== false, $s[0]);
    assertSame('Se han detectado 4 fallos (2 de gravedad crítica, 1 de gravedad alta y 1 de gravedad media) y 2 avisos en 7 comprobaciones evaluadas.', $s[1]);
    assertSame('1 comprobación no tiene datos y 1 no aplica a esta página; no restan puntos.', $s[2]);
    assertSame('Por dónde empezar: Nombres accesibles, Acceso de bots e indexación y Etiqueta title.', $s[3]);
});

test('Report: resumen sin fallos y con acceso bloqueado', function () {
    $records = reportRecords([['C10', 'CUMPLE'], ['C15', 'AVISO'], ['C12', 'CUMPLE']]);
    $s = Report::build($records, (new Scorer())->score($records))['resumen'];
    assertSame('Sin errores detectables automáticamente en 3 comprobaciones evaluadas; hay 1 aviso que conviene revisar.', $s[1]);

    $records = reportRecords([['C01', 'CUMPLE'], ['C04', 'SIN_DATOS'], ['C05', 'SIN_DATOS']]);
    $s = Report::build($records, (new Scorer())->score($records), true)['resumen'];
    assertTrue(strpos($s[0], 'bloqueado el acceso') !== false, $s[0]);
    foreach ($s as $line) {
        assertTrue(strpos($line, 'Nota global') === false, $line);
    }
    assertSame('2 comprobaciones no tienen datos; no restan puntos.', $s[2]);

    $s = Report::build([], (new Scorer())->score([]))['resumen'];
    assertSame('No se ha podido evaluar ninguna comprobación.', $s[1]);
});

test('Report: lista en español con «y» / «e»', function () {
    assertSame('', Report::joinList([]));
    assertSame('a', Report::joinList(['a']));
    assertSame('a y b', Report::joinList(['a', 'b']));
    assertSame('a, b y c', Report::joinList(['a', 'b', 'c']));
    assertSame('Etiqueta title e Idioma de la página', Report::joinList(['Etiqueta title', 'Idioma de la página']));
    assertSame('a y HTML semántico', Report::joinList(['a', 'HTML semántico']));
    assertSame('a y hierro', Report::joinList(['a', 'hierro']));
});

test('Report y glosario: ningún texto afirma cumplimiento', function () {
    $all = reportRecords(array_map(fn($id) => [$id, 'NO_CUMPLE', 2], array_keys(CheckRunner::catalog())));
    $texts = array_merge(
        reportStrings(Report::build($all, (new Scorer())->score($all))),
        reportStrings(Lang::all()['glosario'])
    );
    assertTrue(count(Lang::all()['glosario']) >= 20);
    foreach ($texts as $t) {
        assertTrue(!preg_match('/\bcumplen?\b|\bconforme\b/iu', $t), $t);
    }
});
