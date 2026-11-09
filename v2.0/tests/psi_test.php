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
    // La API rechaza con HTTP 400 seleccionar auditorías sueltas: hay que pedir lighthouseResult/audits entero
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $fields = explode(',', (string) ($query['fields'] ?? ''));
    assertTrue(in_array('lighthouseResult/audits', $fields, true), 'Pide las auditorías (C08)');
    assertTrue(in_array('loadingExperience', $fields, true), 'Pide los datos de campo (C14)');
    foreach ($fields as $field) {
        assertTrue(strpos($field, 'lighthouseResult/audits/') !== 0 && strpos($field, '(') === false,
            'Selección de campos que la API rechaza: ' . $field);
    }
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

/**
 * Respuestas de error simuladas (sin red): [respuesta del transporte, clave, código esperado, texto esperado].
 * La clave es ficticia y aparece a propósito en algunos mensajes de Google para comprobar que nunca se devuelve.
 */
function psiErrorCases(string $key): array
{
    $googleError = fn(int $code, string $message) => json_encode(['error' => ['code' => $code, 'message' => $message]]);
    $quotaBody = $googleError(429, "Quota exceeded for quota metric 'Queries' key=$key");
    return [
        '429 sin clave' => [['status' => 429, 'body' => $quotaBody], '', 'PSI_QUOTA', 'Falta la clave'],
        '429 con clave' => [['status' => 429, 'body' => $quotaBody], $key, 'PSI_QUOTA', 'cuota'],
        '400 clave no válida' => [['status' => 400, 'body' => $googleError(400, "API key not valid. key=$key")], $key, 'PSI_KEY', 'no es válida'],
        '400 petición rechazada' => [['status' => 400, 'body' => $googleError(400, "Request contains an invalid argument. key=$key")], $key, 'PSI_ERROR', 'rechazó la consulta'],
        '403' => [['status' => 403, 'body' => '{}'], $key, 'PSI_KEY', 'no es válida'],
        '500 web caída' => [['status' => 500, 'body' => $googleError(500, "Lighthouse returned error: FAILED_DOCUMENT_REQUEST. key=$key")], $key, 'PSI_ERROR', 'FAILED_DOCUMENT_REQUEST'],
        '200 con JSON inválido' => [['status' => 200, 'body' => 'no es json'], $key, 'PSI_ERROR', 'no válida'],
        '200 vacío' => [['status' => 200, 'body' => ''], $key, 'PSI_ERROR', 'no válida'],
        'tiempo agotado' => [['errno' => 28, 'status' => 0], $key, 'PSI_TIMEOUT', 'a tiempo'],
        'red (DNS)' => [['errno' => 6, 'status' => 0], $key, 'PSI_NETWORK', 'conectar'],
        'red (conexión)' => [['errno' => 7, 'status' => 0], $key, 'PSI_NETWORK', 'conectar'],
        'más de 5 MB' => [['errno' => 23, 'status' => 0], $key, 'PSI_TOO_LARGE', 'tamaño máximo'],
    ];
}

test('PsiClient: errores clasificados, explicados en español y sin la clave en los mensajes', function () {
    $key = 'CLAVE-FICTICIA-123';
    foreach (psiErrorCases($key) as $name => [$resp, $k, $code, $text]) {
        $r = psiClientWith($resp, $k)->run('https://example.com/');
        $message = $r['error']['message'];
        assertSame(false, $r['ok'], $name);
        assertSame(null, $r['data'], $name);
        assertSame($code, $r['error']['code'], $name);
        assertTrue(stripos($message, $text) !== false, "$name: $message");
        assertTrue(strpos($message, $key) === false, "$name: la clave aparece en el mensaje");
        // La explicación es española; el mensaje original de Google, si lo hay, va al final y entre comillas
        $own = (string) preg_replace('/ Mensaje de Google: «.*»\.$/su', '', $message);
        assertTrue(strpos($own, 'PageSpeed Insights') !== false, "$name: $own");
        assertTrue(preg_match('/\b(the|request|invalid|failed|returned|error)\b/i', $own) === 0, "$name: texto en inglés: $own");
    }
});

test('PsiClient: cualquier fallo deja C08 y C14 en «Sin datos», con el motivo, sin la clave y sin bajar la nota', function () {
    $key = 'CLAVE-FICTICIA-123';
    foreach (psiErrorCases($key) as $name => [$resp, $k]) {
        $r = psiClientWith($resp, $k)->run('https://example.com/');
        $out = (new CheckRunner())->runPsi(psiCtx(null, $r['error']));
        assertSame(['C08', 'C14'], array_column($out, 'id'), $name);
        foreach ($out as $c) {
            assertSame('SIN_DATOS', $c['estado'], "$name {$c['id']}");
            assertSame(0, $c['ocurrencias'], "$name {$c['id']}");
            assertTrue(strpos($c['detalle'], 'Sin datos de PageSpeed Insights: ' . $r['error']['message']) === 0, "$name {$c['id']}: {$c['detalle']}");
            assertTrue(strpos((string) json_encode($c, JSON_UNESCAPED_UNICODE), $key) === false, "$name {$c['id']}: la clave aparece");
        }
        $s = (new Scorer())->score($out);
        foreach (['seo', 'ia', 'accesibilidad'] as $area) {
            assertTrue(abs($s['areas'][$area]['puntuacion'] - 100) < 1e-9, "$name: «Sin datos» no resta en $area");
        }
    }
});

test('C08 con el fixture psi_pass: auditoría ausente o con score null → «Sin datos»; C14 se evalúa igual', function () {
    $cases = [
        'auditoría ausente' => [null, 'no incluye la auditoría de contraste'],
        'score null sin modo' => [['id' => 'color-contrast', 'score' => null], 'no se pudo completar'],
        'score null en modo binario' => [['id' => 'color-contrast', 'score' => null, 'scoreDisplayMode' => 'binary'], 'no se pudo completar'],
        'score null en modo manual' => [['id' => 'color-contrast', 'score' => null, 'scoreDisplayMode' => 'manual'], 'no se pudo completar'],
    ];
    foreach ($cases as $name => [$audit, $text]) {
        $psi = psiFixture('psi_pass');
        if ($audit === null) {
            unset($psi['lighthouseResult']['audits']['color-contrast']);
        } else {
            $psi['lighthouseResult']['audits']['color-contrast'] = $audit;
        }
        [$c08, $c14] = (new CheckRunner())->runPsi(psiCtx($psi));
        assertSame('SIN_DATOS', $c08['estado'], $name);
        assertSame(0, $c08['ocurrencias'], $name);
        assertTrue(strpos($c08['detalle'], $text) !== false, "$name: {$c08['detalle']}");
        assertSame('CUMPLE', $c14['estado'], "$name: C14 no depende de la auditoría de contraste");
    }
});

test('Pruebas: los endpoints cargan la configuración de WEBSEO_CONFIG_DIR, nunca el config.php local', function () {
    $dir = testConfigDir();
    assertTrue(is_file($dir . '/config.example.php'));
    assertTrue(!is_file($dir . '/config.php'), 'El directorio de pruebas no debe tener config.php');
    // Config::load respeta la variable: con un config.php de prueba (sin clave real) se lee ese
    file_put_contents($dir . '/config.php', "<?php return ['marca_de_prueba' => 'directorio de pruebas'];");
    putenv('WEBSEO_CONFIG_DIR=' . $dir);
    try {
        $config = Config::load();
    } finally {
        putenv('WEBSEO_CONFIG_DIR');
    }
    assertTrue(($config['marca_de_prueba'] ?? null) === 'directorio de pruebas', 'No se usó WEBSEO_CONFIG_DIR');
    // assertTrue y no assertSame: si fallara, el mensaje no debe mostrar ninguna clave
    assertTrue(($config['psi_api_key'] ?? null) === '', 'La clave no viene vacía de config.example.php');
});

// ---------- Endpoints (sin red) ----------

(function () {
    [$proc, $port] = startPhpServer(dirname(__DIR__), null, ['WEBSEO_STORAGE_DIR' => tempDir('webseo'), 'WEBSEO_CONFIG_DIR' => testConfigDir()]);
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
        // Informe reconstruido con los textos del servidor
        $inf = json_decode($r['body'], true)['informe'];
        assertSame(['C08'], array_column($inf['prioridades'], 'id'));
        assertSame(Lang::check('C08', 'recomendacion'), $inf['prioridades'][0]['recomendacion']);
        assertSame('planificar', $inf['prioridades'][0]['cuadrante']);
        assertTrue(strpos($inf['resumen'][0], 'Nota global') === 0);
        $blocked = json_decode($post('score.php', ['checks' => [['id' => 'C10', 'estado' => 'SIN_DATOS']], 'bloqueado' => true])['body'], true);
        assertTrue(strpos($blocked['informe']['resumen'][0], 'bloqueado el acceso') !== false);
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
