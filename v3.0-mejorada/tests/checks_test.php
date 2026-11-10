<?php
// Pruebas de las comprobaciones con los HTML de tests/fixtures (sin red).

const FIXTURES = __DIR__ . '/fixtures/';

/**
 * Contexto a partir de un HTML de ejemplo y, opcionalmente, un JSON con datos de la respuesta
 * (url, status, headers, redirects, blocked, robots). Por defecto: https://example.com/pagina,
 * 200, text/html y robots.txt inexistente (404).
 */
function fx(?string $html, ?string $json = null, array $options = []): CheckContext
{
    $meta = $json ? json_decode((string) file_get_contents(FIXTURES . $json . '.json'), true) : [];
    $body = $html ? (string) file_get_contents(FIXTURES . $html . '.html') : '';
    $page = [
        'final_url' => $meta['url'] ?? 'https://example.com/pagina',
        'status' => $meta['status'] ?? 200,
        'headers' => $meta['headers'] ?? ['content-type' => 'text/html; charset=utf-8'],
        'body' => $body,
        'truncated' => false,
        'blocked' => $meta['blocked'] ?? null,
        'redirects' => $meta['redirects'] ?? [],
    ];
    $page['content_type'] = $page['headers']['content-type'] ?? null;
    $robots = $meta['robots'] ?? ['status' => 404, 'body' => '', 'error' => null];
    $config = require __DIR__ . '/../config/config.example.php';
    $ctx = CheckContext::fromFetch($page, $config, $robots, $options);
    $ctx->fetch = fakeSiteFetch($page['final_url']);
    return $ctx;
}

/**
 * Descarga simulada para C16 y C18 (forma de Fetcher::fetch). Por defecto todo responde 200 y
 * /sitemap.xml incluye la URL de la página. $routes: URL → [status, body, content_type] o código de error.
 */
function fakeSiteFetch(string $pageUrl, array $routes = [], array &$log = []): callable
{
    $p = parse_url($pageUrl);
    $sitemap = $p['scheme'] . '://' . $p['host'] . '/sitemap.xml';
    $routes += [$sitemap => [200, '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>'
        . htmlspecialchars($pageUrl, ENT_XML1) . '</loc></url></urlset>', 'application/xml']];
    return function (string $url, array $opt = []) use ($routes, &$log): array {
        $log[] = [$url, $opt];
        $route = $routes[$url] ?? [200, '<html><body>ok</body></html>', 'text/html'];
        if (is_string($route)) {
            return ['ok' => false, 'status' => null, 'final_url' => $url, 'body' => '', 'truncated' => false, 'blocked' => null,
                'content_type' => null, 'error' => ['code' => $route, 'message' => $route]];
        }
        $status = $route[0];
        return ['ok' => true, 'status' => $status, 'final_url' => $url, 'body' => $route[1] ?? '', 'truncated' => !empty($route[3]),
            'content_type' => $route[2] ?? 'text/html', 'error' => null,
            'blocked' => in_array($status, [401, 403, 429], true) ? ['code' => 'HTTP_' . $status, 'message' => 'bloqueo'] : null];
    };
}

function runCheck(string $class, CheckContext $ctx): array
{
    return (new $class())->run($ctx);
}

// [clase, html, json, opciones, estado esperado, ocurrencias esperadas (null = no se comprueba)]
$cases = [
    ['C01HttpsStatus', 'c01_ok', 'c01_ok', [], 'CUMPLE', null],
    ['C01HttpsStatus', 'c01_bad', 'c01_bad', [], 'NO_CUMPLE', 1],
    ['C01HttpsStatus', 'c01_ok', 'c01_bad_500', [], 'NO_CUMPLE', 1],
    ['C01HttpsStatus', 'c01_ok', 'c01_blocked', [], 'SIN_DATOS', null],

    ['C02BotsIndexing', 'c02_ok', 'c02_ok', [], 'CUMPLE', null],
    ['C02BotsIndexing', 'c02_bad', 'c02_bad', [], 'NO_CUMPLE', 1],
    ['C02BotsIndexing', 'c02_ok', 'c02_bad_search', [], 'NO_CUMPLE', 1],
    ['C02BotsIndexing', 'c02_bad_noindex', null, [], 'NO_CUMPLE', 1],
    ['C02BotsIndexing', 'c02_bad_noindex', null, ['noindex_intentional' => true], 'AVISO', null],
    ['C02BotsIndexing', 'c02_ok', 'c02_bad_header', [], 'NO_CUMPLE', 1],
    ['C02BotsIndexing', 'c02_ok', 'c02_header_otherbot', [], 'CUMPLE', null],
    ['C02BotsIndexing', 'c02_ok', 'c02_aviso', [], 'AVISO', null],
    ['C02BotsIndexing', 'c02_ok', 'c02_nodata', [], 'SIN_DATOS', null],
    ['C02BotsIndexing', 'c02_ok', 'c02_robots_html', [], 'CUMPLE', null],

    ['C03RawContent', 'c03_ok', null, [], 'CUMPLE', null],
    ['C03RawContent', 'c03_bad', null, [], 'NO_CUMPLE', 1],
    ['C03RawContent', 'c03_aviso', null, [], 'AVISO', null],

    ['C04AccessibleNames', 'c04_ok', null, [], 'CUMPLE', null],
    ['C04AccessibleNames', 'c04_bad', null, [], 'NO_CUMPLE', 5],

    ['C05Headings', 'c05_ok', null, [], 'CUMPLE', null],
    ['C05Headings', 'c05_bad', null, [], 'AVISO', null],          // dos h1: permitido, se avisa
    ['C05Headings', 'c05_bad_sin_h1', null, [], 'NO_CUMPLE', 1],
    ['C05Headings', 'c05_aviso', null, [], 'AVISO', null],

    ['C06ImageAlt', 'c06_ok', null, [], 'CUMPLE', null],
    ['C06ImageAlt', 'c06_bad', null, [], 'NO_CUMPLE', 2],
    ['C06ImageAlt', 'c06_na', null, [], 'CUMPLE', null],

    ['C07Language', 'c07_ok', null, [], 'CUMPLE', null],
    ['C07Language', 'c07_bad', null, [], 'NO_CUMPLE', 1],
    ['C07Language', 'c07_bad_formato', null, [], 'NO_CUMPLE', 1],
    ['C07Language', 'c07_bad_guion', null, [], 'NO_CUMPLE', 1],

    ['C09Viewport', 'c09_ok', null, [], 'CUMPLE', null],
    ['C09Viewport', 'c09_bad', null, [], 'NO_CUMPLE', 1],
    ['C09Viewport', 'c09_bad_zoom', null, [], 'NO_CUMPLE', 1],
    ['C09Viewport', 'c09_aviso', null, [], 'AVISO', null],

    ['C10Title', 'c10_ok', null, [], 'CUMPLE', null],
    ['C10Title', 'c10_bad', null, [], 'NO_CUMPLE', 1],
    ['C10Title', 'c10_bad_dos', null, [], 'NO_CUMPLE', 2],
    ['C10Title', 'c10_bad_vacio', null, [], 'NO_CUMPLE', 1],
    ['C10Title', 'c10_aviso', null, [], 'AVISO', null],

    ['C11Semantics', 'c11_ok', null, [], 'CUMPLE', null],
    ['C11Semantics', 'c11_bad', null, [], 'NO_CUMPLE', 2],
    ['C11Semantics', 'c11_aviso', null, [], 'AVISO', null],
    ['C11Semantics', 'c11_aviso_dos_main', null, [], 'AVISO', null],
    ['C11Semantics', 'c11_aviso_solo_hidden', null, [], 'AVISO', null],
    ['C11Semantics', 'c11_main_hidden', null, [], 'CUMPLE', null],

    ['C12Canonical', 'c12_ok', null, [], 'CUMPLE', null],
    ['C12Canonical', 'c12_bad', null, [], 'AVISO', null],         // a otra página: puede ser intencionado
    ['C12Canonical', 'c12_bad_sin', null, [], 'AVISO', null],     // sin canonical: es opcional
    ['C12Canonical', 'c12_bad_varios', null, [], 'NO_CUMPLE', 1],
    ['C12Canonical', 'c12_aviso', null, [], 'AVISO', null],
    ['C12Canonical', 'c12_bad_sin', 'c12_header', [], 'CUMPLE', null],

    ['C13StructuredData', 'c13_ok', null, [], 'CUMPLE', null],
    ['C13StructuredData', 'c13_bad', null, [], 'NO_CUMPLE', 1],
    ['C13StructuredData', 'c13_bad_tipo', null, [], 'NO_CUMPLE', 1],
    ['C13StructuredData', 'c13_bad_sin', null, [], 'AVISO', null], // sin JSON-LD: es opcional

    ['C15MetaDescription', 'c15_ok', null, [], 'CUMPLE', null],
    ['C15MetaDescription', 'c15_bad', null, [], 'NO_CUMPLE', 1],
    ['C15MetaDescription', 'c15_bad_vacia', null, [], 'NO_CUMPLE', 1],
    ['C15MetaDescription', 'c15_aviso', null, [], 'AVISO', null],
];

foreach ($cases as [$class, $html, $json, $opt, $estado, $k]) {
    $label = $class . ': ' . ($html ?? '-') . ($json ? " + $json.json" : '') . ($opt ? ' ' . json_encode($opt) : '') . " → $estado";
    test($label, function () use ($class, $html, $json, $opt, $estado, $k) {
        $r = runCheck($class, fx($html, $json, $opt));
        assertSame($estado, $r['estado'], $r['detalle']);
        if ($k !== null) {
            assertSame($k, $r['ocurrencias'], 'ocurrencias');
        }
        if ($estado === 'NO_CUMPLE' || $estado === 'AVISO') {
            assertTrue($r['evidencia'] !== [], 'Un fallo o aviso debe llevar evidencia');
            assertTrue($r['recomendacion'] !== '', 'Un fallo o aviso debe llevar recomendación');
        } else {
            assertSame('', $r['recomendacion'], 'Sin fallo no hay recomendación');
        }
    });
}

test('Comprobaciones: C06 sin imágenes se marca como "no aplica"', function () {
    $r = runCheck('C06ImageAlt', fx('c06_na'));
    assertSame(false, $r['aplica']);
});

test('Comprobaciones: el registro tiene todos los campos obligatorios', function () {
    $required = ['id', 'pilares', 'estado', 'severidad', 'valor_medido', 'umbral', 'criterio', 'evidencia',
        'recomendacion', 'impacto', 'esfuerzo', 'requiere_revision_manual'];
    foreach (CheckRunner::checks() as $check) {
        $r = $check->run(fx('full_ok', 'full_ok'));
        foreach ($required as $key) {
            assertTrue(array_key_exists($key, $r), $r['id'] . " sin campo $key");
        }
        assertTrue(in_array($r['estado'], ['CUMPLE', 'NO_CUMPLE', 'AVISO', 'SIN_DATOS'], true));
        assertTrue(in_array($r['severidad'], ['CRITICO', 'ALTO', 'MEDIO', 'BAJO'], true));
        assertTrue(abs(array_sum($r['pilares']) - 1.0) < 1e-9, $r['id'] . ': el reparto debe sumar 1');
        assertTrue($r['nombre'] !== '', $r['id'] . ' sin nombre en lang/es.php');
        assertTrue(Lang::check($r['id'], 'recomendacion') !== '', $r['id'] . ' sin recomendación en lang/es.php');
    }
});

test('Comprobaciones: la evidencia incluye selector CSS y extracto', function () {
    $r = runCheck('C06ImageAlt', fx('c06_bad'));
    assertSame('html > body > main > img:nth-of-type(2)', $r['evidencia'][0]['selector']);
    assertSame('<img src="b.jpg">', $r['evidencia'][0]['extracto']);
    assertTrue(is_int($r['evidencia'][0]['linea']));
});

test('Página completa correcta: ninguna comprobación falla ni avisa', function () {
    foreach ((new CheckRunner())->run(fx('full_ok', 'full_ok')) as $r) {
        assertSame('CUMPLE', $r['estado'], $r['id'] . ': ' . $r['detalle']);
    }
});

test('Página completa defectuosa: fallan las comprobaciones esperadas', function () {
    $states = [];
    foreach ((new CheckRunner())->run(fx('full_bad')) as $r) {
        $states[$r['id']] = $r['estado'];
    }
    $expected = ['C02' => 'NO_CUMPLE', 'C03' => 'AVISO', 'C04' => 'NO_CUMPLE', 'C05' => 'NO_CUMPLE', 'C06' => 'NO_CUMPLE',
        'C07' => 'NO_CUMPLE', 'C09' => 'NO_CUMPLE', 'C10' => 'NO_CUMPLE', 'C11' => 'NO_CUMPLE', 'C12' => 'AVISO',
        'C13' => 'AVISO', 'C15' => 'NO_CUMPLE'];
    foreach ($expected as $id => $estado) {
        assertSame($estado, $states[$id], $id);
    }
});

test('Sitio bloqueado: las comprobaciones de HTML devuelven SIN_DATOS', function () {
    foreach ((new CheckRunner())->run(fx('full_bad', 'c01_blocked')) as $r) {
        // C01, C02 y C18 no dependen del HTML de la página
        if (!in_array($r['id'], ['C01', 'C02', 'C18'], true)) {
            assertSame('SIN_DATOS', $r['estado'], $r['id']);
        }
    }
});

test('Recurso no HTML: las comprobaciones de HTML devuelven SIN_DATOS', function () {
    $ctx = CheckContext::fromFetch(['final_url' => 'https://example.com/a.pdf', 'status' => 200,
        'headers' => ['content-type' => 'application/pdf'], 'content_type' => 'application/pdf', 'body' => '%PDF-1.4'], []);
    assertSame('SIN_DATOS', runCheck('C10Title', $ctx)['estado']);
});

test('Codificación: HTML en ISO-8859-1 se convierte a UTF-8', function () {
    $html = mb_convert_encoding('<html lang="es"><head><title>Información de pruebas para años y niños</title></head></html>', 'ISO-8859-1', 'UTF-8');
    $ctx = CheckContext::fromFetch(['final_url' => 'https://example.com/', 'status' => 200,
        'headers' => ['content-type' => 'text/html; charset=ISO-8859-1'], 'content_type' => 'text/html; charset=ISO-8859-1', 'body' => $html], []);
    $r = runCheck('C10Title', $ctx);
    assertSame('Información de pruebas para años y niños', $r['valor_medido']['texto']);
});

test('C11: cuenta los main sin hidden y separa los ocultos (atributo propio o heredado, template)', function () {
    $r = runCheck('C11Semantics', fx('c11_main_hidden'));
    assertSame(1, $r['valor_medido']['main']);
    assertSame(2, $r['valor_medido']['main_con_hidden']);   // main hidden y role="main" dentro de un div hidden

    $r = runCheck('C11Semantics', fx('c11_aviso_dos_main'));
    assertSame(2, $r['valor_medido']['main']);
    assertSame(2, count($r['evidencia']), 'Evidencia de cada main');
    assertTrue(strpos($r['detalle'], '2 elementos main') !== false, $r['detalle']);
    assertTrue(strpos($r['detalle'], 'compruébalo a mano') !== false, 'Debe pedir revisión manual');
});

test('HTML truncado: se añade la advertencia de análisis parcial', function () {
    $ctx = fx('c10_ok');
    $ctx->truncated = true;
    assertTrue(strpos(runCheck('C10Title', $ctx)['detalle'], 'truncó') !== false);
});

test('Ningún texto afirma cumplimiento de WCAG ni de la ley', function () {
    $all = json_encode([Lang::all(), (new CheckRunner())->run(fx('full_ok', 'full_ok')), (new CheckRunner())->run(fx('full_bad'))], JSON_UNESCAPED_UNICODE);
    $lower = mb_strtolower($all);
    $patterns = ['/cumple (con )?(las? )?wcag/u', '/cumple (con )?la ley/u', '/conforme (con|a) (las? )?wcag/u',
        '/(?<!no )\bcertifica(mos)?\b/u', '/accesibilidad garantizada/u'];
    foreach ($patterns as $re) {
        assertTrue(!preg_match($re, $lower), "Texto prohibido: {$re}");
    }
});
