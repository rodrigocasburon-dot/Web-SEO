<?php
// Pruebas de PageSpeed Insights: PsiClient con transporte falso, C08, C14 y endpoints psi/score (sin red).

function psiFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $name . '.json'), true);
}

function psiCtx(?array $psi, ?array $error = null): CheckContext
{
    return CheckContext::forPsi('https://example.com/pagina', $psi, $error);
}

/** Respuesta con solo datos de campo (percentiles LCP ms, INP ms, CLS ×100; null = métrica ausente). */
function psiCrux(?int $lcp, ?int $inp, ?int $cls, array $extra = []): array
{
    $m = [];
    foreach (['LARGEST_CONTENTFUL_PAINT_MS' => $lcp, 'INTERACTION_TO_NEXT_PAINT' => $inp, 'CUMULATIVE_LAYOUT_SHIFT_SCORE' => $cls] as $k => $v) {
        if ($v !== null) {
            $m[$k] = ['percentile' => $v];
        }
    }
    return ['loadingExperience' => array_merge(['metrics' => $m], $extra)];
}

/** Respuesta con solo la auditoría de contraste. */
function psiAudit(array $audit, array $lh = []): array
{
    return ['lighthouseResult' => array_merge(['audits' => ['color-contrast' => $audit]], $lh)];
}

function psiClientWith(array $resp, string $key = '', ?string &$calledUrl = null): PsiClient
{
    return new PsiClient($key, 75, 'Test/1.0', function (string $url, int $timeout) use ($resp, &$calledUrl) {
        $calledUrl = $url;
        return array_merge(['status' => 200, 'body' => '', 'errno' => 0, 'error' => ''], $resp);
    });
}

// ---------- C08 Contraste ----------

test('C08: auditoría fallida → NO_CUMPLE con k = nº de elementos y evidencia resumida', function () {
    $r = (new C08Contrast())->run(psiCtx(psiFixture('psi_fail')));
    assertSame('NO_CUMPLE', $r['estado']);
    assertSame(3, $r['ocurrencias']);
    assertSame(3, count($r['evidencia']));
    assertSame('footer > p.legal', $r['evidencia'][0]['selector']);
    // Explicación en español
    assertTrue(strpos($r['evidencia'][0]['extracto'], 'Contraste 2,85:1; texto #999999 sobre #ffffff; se espera 4,5:1') !== false, $r['evidencia'][0]['extracto']);
    // Explicación en inglés
    assertTrue(strpos($r['evidencia'][1]['extracto'], 'Contraste 3,1:1; texto #888888 sobre #f5f5f5; se espera 4,5:1') !== false, $r['evidencia'][1]['extracto']);
    // Formato no reconocido: se conserva el texto original
    assertTrue(strpos($r['evidencia'][2]['extracto'], 'Formato desconocido sin cifras') !== false);
    assertSame(true, $r['requiere_revision_manual']);
    assertSame(['seo' => 0.0, 'ia' => 0.0, 'accesibilidad' => 1.0], array_map('floatval', $r['pilares']));
    assertTrue($r['recomendacion'] !== '');
});

test('C08: auditoría superada → CUMPLE; no aplicable → aplica=false', function () {
    $r = (new C08Contrast())->run(psiCtx(psiFixture('psi_pass')));
    assertSame('CUMPLE', $r['estado']);
    assertSame(true, $r['aplica']);
    $na = (new C08Contrast())->run(psiCtx(psiAudit(['score' => null, 'scoreDisplayMode' => 'notApplicable'])));
    assertSame('CUMPLE', $na['estado']);
    assertSame(false, $na['aplica']);
});

test('C08: sin PSI, error de la auditoría, error de Lighthouse o auditoría ausente → SIN_DATOS', function () {
    $cases = [
        'sin psi' => psiCtx(null, ['code' => 'PSI_QUOTA', 'message' => 'Falta la clave']),
        'error' => psiCtx(psiAudit(['score' => null, 'scoreDisplayMode' => 'error', 'errorMessage' => 'axe falló'])),
        'runtime' => psiCtx(psiAudit(['score' => 0], ['runtimeError' => ['code' => 'FAILED_DOCUMENT_REQUEST', 'message' => 'x']])),
        'ausente' => psiCtx(['lighthouseResult' => ['audits' => []]]),
        'sin lighthouse' => psiCtx(['loadingExperience' => []]),
    ];
    foreach ($cases as $name => $ctx) {
        $r = (new C08Contrast())->run($ctx);
        assertSame('SIN_DATOS', $r['estado'], $name);
        assertSame(0, $r['ocurrencias'], $name);
    }
    $r = (new C08Contrast())->run($cases['sin psi']);
    assertTrue(strpos($r['detalle'], 'Falta la clave') !== false);
});

// ---------- C14 Core Web Vitals ----------

test('C14: LCP deficiente → NO_CUMPLE (k = 1) y valores legibles', function () {
    $r = (new C14CoreWebVitals())->run(psiCtx(psiFixture('psi_fail')));
    assertSame('NO_CUMPLE', $r['estado']);
    assertSame(1, $r['ocurrencias']);
    assertSame('Deficiente', $r['valor_medido']['Evaluación']);
    assertSame('4,3 s (deficiente)', $r['valor_medido']['LCP (p75)']);
    assertSame('180 ms (bueno)', $r['valor_medido']['INP (p75)']);
    assertSame('0,12 (mejorable)', $r['valor_medido']['CLS (p75)']);
    assertSame(['seo' => 1.0, 'ia' => 0.0, 'accesibilidad' => 0.0], array_map('floatval', $r['pilares']));
});

test('C14: valores justo en el umbral «bueno» → CUMPLE', function () {
    $r = (new C14CoreWebVitals())->run(psiCtx(psiFixture('psi_pass')));
    assertSame('CUMPLE', $r['estado']);
    assertSame('', $r['recomendacion']);
});

test('C14: bordes de los umbrales (bueno ≤, deficiente >)', function () {
    $cases = [
        [2501, 200, 10, 'AVISO'], [4000, 200, 10, 'AVISO'], [4001, 200, 10, 'NO_CUMPLE'],
        [2500, 201, 10, 'AVISO'], [2500, 500, 10, 'AVISO'], [2500, 501, 10, 'NO_CUMPLE'],
        [2500, 200, 11, 'AVISO'], [2500, 200, 25, 'AVISO'], [2500, 200, 26, 'NO_CUMPLE'],
        [1000, 100, 0, 'CUMPLE'],
    ];
    foreach ($cases as [$lcp, $inp, $cls, $expected]) {
        $r = (new C14CoreWebVitals())->run(psiCtx(psiCrux($lcp, $inp, $cls)));
        assertSame($expected, $r['estado'], "LCP $lcp, INP $inp, CLS $cls");
    }
    assertSame('BUENO', C14CoreWebVitals::rate(0.1, 0.1, 0.25));
    assertSame('DEFICIENTE', C14CoreWebVitals::rate(0.26, 0.1, 0.25));
});

test('C14: solo datos del origen, sin métricas o sin PSI → SIN_DATOS', function () {
    $origin = (new C14CoreWebVitals())->run(psiCtx(psiCrux(5000, 600, 40, ['origin_fallback' => true])));
    assertSame('SIN_DATOS', $origin['estado']);
    assertTrue(strpos($origin['detalle'], 'dominio completo') !== false);
    assertSame('SIN_DATOS', (new C14CoreWebVitals())->run(psiCtx(psiCrux(null, null, null)))['estado']);
    assertSame('SIN_DATOS', (new C14CoreWebVitals())->run(psiCtx(['lighthouseResult' => []]))['estado']);
    assertSame('SIN_DATOS', (new C14CoreWebVitals())->run(psiCtx(null, ['code' => 'PSI_TIMEOUT', 'message' => 'x']))['estado']);
});

test('C14: falta INP → se evalúa con LCP y CLS y se indica', function () {
    $r = (new C14CoreWebVitals())->run(psiCtx(psiCrux(2000, null, 5)));
    assertSame('CUMPLE', $r['estado']);
    assertTrue(strpos($r['detalle'], 'Sin datos de INP') !== false, $r['detalle']);
    assertTrue(!isset($r['valor_medido']['INP (p75)']));
});

test('CheckRunner::runPsi devuelve C08 y C14', function () {
    $out = (new CheckRunner())->runPsi(psiCtx(psiFixture('psi_fail')));
    assertSame(['C08', 'C14'], array_column($out, 'id'));
});

test('Puntuación con C08 (k = 3) y C14 fallidos', function () {
    $out = (new CheckRunner())->runPsi(psiCtx(psiFixture('psi_fail')));
    $s = (new Scorer())->score($out);
    // Accesibilidad: 100 − 8·(1 + ln 3); SEO: 100 − 4·1
    assertTrue(abs($s['areas']['accesibilidad']['puntuacion'] - (100 - 8 * (1 + log(3)))) < 1e-9);
    assertTrue(abs($s['areas']['seo']['puntuacion'] - 96) < 1e-9);
    assertTrue(abs($s['areas']['ia']['puntuacion'] - 100) < 1e-9);
});

// ---------- PsiClient ----------

test('PsiClient: URL de la petición (categorías, estrategia, idioma, campos y clave)', function () {
    $url = (new PsiClient(''))->buildUrl('https://example.com/a b?x=1&y=2');
    assertTrue(strpos($url, 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?') === 0);
    assertTrue(strpos($url, 'url=https%3A%2F%2Fexample.com%2Fa%20b%3Fx%3D1%26y%3D2') !== false, $url);
    assertTrue(strpos($url, '&strategy=mobile&category=ACCESSIBILITY&category=PERFORMANCE&locale=es') !== false);
    assertTrue(strpos($url, 'color-contrast') !== false);
    assertTrue(strpos($url, 'key=') === false);
    assertTrue(strpos((new PsiClient('ABC123'))->buildUrl('https://example.com/'), '&key=ABC123') !== false);
});

test('PsiClient: respuesta correcta', function () {
    $called = null;
    $r = psiClientWith(['body' => json_encode(psiFixture('psi_pass'))], 'K', $called)->run('https://example.com/pagina');
    assertSame(true, $r['ok']);
    assertSame('12.6.0', $r['data']['lighthouseResult']['lighthouseVersion']);
    assertTrue(strpos((string) $called, 'key=K') !== false);
});

test('PsiClient: errores clasificados y sin la clave en los mensajes', function () {
    $key = 'SECRETKEY123';
    $quotaBody = json_encode(['error' => ['code' => 429, 'message' => "Quota exceeded for quota metric 'Queries' key=$key"]]);
    $cases = [
        // [respuesta, clave, código esperado, texto esperado en el mensaje]
        [['status' => 429, 'body' => $quotaBody], '', 'PSI_QUOTA', 'Falta la clave'],
        [['status' => 429, 'body' => $quotaBody], $key, 'PSI_QUOTA', 'cuota'],
        [['status' => 400, 'body' => json_encode(['error' => ['message' => "API key not valid. key=$key"]])], $key, 'PSI_KEY', 'no es válida'],
        [['status' => 403, 'body' => '{}'], $key, 'PSI_KEY', 'no es válida'],
        [['status' => 500, 'body' => json_encode(['error' => ['message' => 'Lighthouse returned error: FAILED_DOCUMENT_REQUEST']])], $key, 'PSI_ERROR', 'FAILED_DOCUMENT_REQUEST'],
        [['status' => 200, 'body' => 'no es json'], $key, 'PSI_ERROR', 'no válida'],
        [['errno' => 28, 'status' => 0], $key, 'PSI_TIMEOUT', 'a tiempo'],
        [['errno' => 6, 'status' => 0], $key, 'PSI_NETWORK', 'conectar'],
    ];
    foreach ($cases as $i => [$resp, $k, $code, $text]) {
        $r = psiClientWith($resp, $k)->run('https://example.com/');
        assertSame(false, $r['ok'], "caso $i");
        assertSame($code, $r['error']['code'], "caso $i");
        assertTrue(stripos($r['error']['message'], $text) !== false, "caso $i: " . $r['error']['message']);
        assertTrue(strpos($r['error']['message'], $key) === false, "caso $i: la clave aparece en el mensaje");
    }
});

// ---------- Endpoints (sin red) ----------

(function () {
    [$proc, $port] = startPhpServer(dirname(__DIR__), null, ['WEBSEO_STORAGE_DIR' => tempDir('webseo')]);
    $base = "http://127.0.0.1:$port";
    $post = fn(string $ep, $data, array $headers = []) => httpRequest('POST', "$base/api/$ep", is_string($data) ? $data : json_encode($data),
        array_merge(['Content-Type: application/json'], $headers));
    $skip = function () use ($proc) {
        if ($proc === null) {
            echo "        (omitida: no se pudo arrancar el servidor local)\n";
            return true;
        }
        return false;
    };

    test('API psi: GET → 405; IP privada y esquema no permitido → 400 sin consultar a Google', function () use ($skip, $base, $post) {
        if ($skip()) {
            return;
        }
        assertSame(405, httpRequest('GET', "$base/api/psi.php")['status']);
        foreach (['http://10.0.0.1/' => 'PRIVATE_ADDRESS', 'file:///etc/passwd' => 'SCHEME_NOT_ALLOWED', '' => 'INVALID_URL'] as $url => $code) {
            $r = $post('psi.php', ['url' => $url]);
            assertSame(400, $r['status'], $url);
            assertSame($code, json_decode($r['body'], true)['error']['code'], $url);
        }
        assertSame(403, $post('psi.php', ['url' => 'https://example.com'], ['Origin: https://evil.example'])['status']);
    });

    test('API score: recalcula con gravedad y reparto del servidor', function () use ($skip, $post) {
        if ($skip()) {
            return;
        }
        // La gravedad enviada (BAJO) se ignora: C08 es ALTO en el servidor
        $r = $post('score.php', ['checks' => [
            ['id' => 'C08', 'estado' => 'NO_CUMPLE', 'ocurrencias' => 3, 'severidad' => 'BAJO', 'pilares' => ['seo' => 1, 'ia' => 0, 'accesibilidad' => 0]],
            ['id' => 'C14', 'estado' => 'SIN_DATOS', 'ocurrencias' => 0],
            ['id' => 'C10', 'estado' => 'CUMPLE'],
        ]]);
        assertSame(200, $r['status'], $r['body']);
        $p = json_decode($r['body'], true)['puntuacion'];
        assertTrue(abs($p['areas']['accesibilidad']['puntuacion'] - (100 - 8 * (1 + log(3)))) < 1e-6);
        assertTrue(abs($p['areas']['seo']['puntuacion'] - 100) < 1e-9);
        assertSame(['C14'], $p['sin_datos']);
    });

    test('API score: id desconocido, estado no válido, duplicado o JSON roto → 400', function () use ($skip, $post) {
        if ($skip()) {
            return;
        }
        $bad = [
            ['checks' => [['id' => 'C99', 'estado' => 'CUMPLE']]],
            ['checks' => [['id' => 'C08', 'estado' => 'FALLO']]],
            ['checks' => [['id' => 'C08', 'estado' => 'CUMPLE'], ['id' => 'C08', 'estado' => 'NO_CUMPLE']]],
            ['checks' => 'x'],
            '{roto',
        ];
        foreach ($bad as $i => $data) {
            assertSame(400, $post('score.php', $data)['status'], "caso $i");
        }
        assertSame(413, $post('score.php', str_repeat(' ', 17000))['status']);
    });

    test('API psi/score: cierre del servidor de pruebas', function () use ($proc) {
        stopPhpServer($proc);
    });
})();
