<?php
// Revisión GEO (Preparación para IA): bots por categoría (C02), elegibilidad de fragmento (C25), riesgo de
// depender de JavaScript (C03) y coherencia del marcado (C30). Sin red: robots.txt y HTML de ejemplo.
// Usa htmlCtx() de extras_test.php y runCheck() de checks_test.php.

function geoRobots(string $txt, int $status = 200): array
{
    return ['status' => $status, 'body' => $txt, 'error' => null];
}

function geoC02(?array $robots, string $html = '<html><body><main>Texto</main></body></html>'): array
{
    return runCheck('C02BotsIndexing', htmlCtx($html, 'https://example.com/', [], $robots));
}

// ---------- C02 (GEO-01): bots por categoría ----------

test('GEO caso 1: solo GPTBot y ClaudeBot bloqueados → C02 cumple, con nota de decisión de no entrenar', function () {
    $r = geoC02(geoRobots("User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /"));
    assertSame('CUMPLE', $r['estado']);
    assertTrue(strpos($r['detalle'], 'decisión de no entrenar, no afecta a la búsqueda') !== false, $r['detalle']);
    assertSame('bloqueado', $r['valor_medido']['entrenamiento']['GPTBot']);
    assertSame('oficial', $r['respaldo']);
});

test('GEO caso 2: OAI-SearchBot bloqueado → C02 no cumple y nombra el bot', function () {
    $r = geoC02(geoRobots("User-agent: OAI-SearchBot\nDisallow: /"));
    assertSame('NO_CUMPLE', $r['estado']);
    assertTrue(strpos($r['detalle'], 'OAI-SearchBot') !== false, $r['detalle']);
    assertSame(1, $r['ocurrencias']);
    foreach (['Claude-SearchBot', 'PerplexityBot', 'Bingbot'] as $bot) {
        assertSame('NO_CUMPLE', geoC02(geoRobots("User-agent: $bot\nDisallow: /"))['estado'], $bot);
    }
});

test('GEO caso 3: «User-agent: *» con «Disallow: /» → no cumple', function () {
    $r = geoC02(geoRobots("User-agent: *\nDisallow: /"));
    assertSame('NO_CUMPLE', $r['estado']);
    assertTrue(strpos($r['detalle'], 'Googlebot') !== false && strpos($r['detalle'], 'OAI-SearchBot') !== false, $r['detalle']);
});

test('GEO caso 4: robots.txt 404 → permitido; 5xx → sin datos; 403 → aviso', function () {
    $absent = geoC02(geoRobots('', 404));
    assertSame('CUMPLE', $absent['estado']);
    assertSame('permitido', $absent['valor_medido']['busqueda']['OAI-SearchBot']);
    $error = geoC02(geoRobots('', 503));
    assertSame('SIN_DATOS', $error['estado']);
    assertTrue(strpos($error['detalle'], 'RFC 9309') !== false, 'Explica que los bots pueden tratarlo como bloqueo');
    assertSame('AVISO', geoC02(geoRobots('', 403))['estado']);
    assertSame('prohibido', RobotsAnalyzer::table('https://example.com/', geoRobots('', 403))['robots_txt']);
});

test('GEO caso 5: Google-Extended bloqueado → sin penalización', function () {
    $r = geoC02(geoRobots("User-agent: Google-Extended\nDisallow: /"));
    assertSame('CUMPLE', $r['estado']);
    assertSame('permitido', $r['valor_medido']['busqueda']['Googlebot']);
});

test('GEO caso 6: Applebot sin reglas propias hereda el bloqueo de Googlebot; con reglas propias, no', function () {
    $t = RobotsAnalyzer::table('https://example.com/', geoRobots("User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nAllow: /"));
    $by = array_column($t['bots'], null, 'bot');
    assertSame('bloqueado', $by['Applebot']['acceso']);
    assertSame('Googlebot', $by['Applebot']['heredado_de']);
    assertSame('permitido', $by['Applebot-Extended']['acceso'], 'La herencia es solo de Applebot');

    $own = RobotsAnalyzer::table('https://example.com/', geoRobots("User-agent: Googlebot\nDisallow: /\n\nUser-agent: Applebot\nAllow: /"));
    $by = array_column($own['bots'], null, 'bot');
    assertSame(['permitido', null], [$by['Applebot']['acceso'], $by['Applebot']['heredado_de']]);

    // Solo Applebot bloqueado (no está entre los que penalizan): aviso
    assertSame('AVISO', geoC02(geoRobots("User-agent: Applebot\nDisallow: /"))['estado']);
});

test('GEO: los agentes a petición del usuario son informativos y no puntúan', function () {
    $r = geoC02(geoRobots("User-agent: ChatGPT-User\nUser-agent: Claude-User\nDisallow: /"));
    assertSame('CUMPLE', $r['estado']);
    assertTrue(strpos($r['detalle'], 'solo un bloqueo en cortafuegos o por IP lo garantiza') !== false, $r['detalle']);
});

test('GEO: noindex sigue siendo fallo en C02 (indexabilidad de GEO-26)', function () {
    assertSame('NO_CUMPLE', geoC02(geoRobots('', 404), '<html><head><meta name="robots" content="noindex"></head><body>x</body></html>')['estado']);
    $hdr = runCheck('C02BotsIndexing', htmlCtx('<html><body>x</body></html>', 'https://example.com/', ['headers' => ['content-type' => 'text/html', 'x-robots-tag' => 'max-snippet: 20, noindex']], geoRobots('', 404)));
    assertSame('NO_CUMPLE', $hdr['estado'], '«max-snippet:» no se confunde con un prefijo de bot');
});

// ---------- C25 (GEO-25): elegibilidad de fragmento ----------

function geoC25(string $head, string $body = '<main><p>Contenido principal de la página con bastante texto para comparar.</p></main>', array $headers = []): array
{
    $page = $headers ? ['headers' => array_merge(['content-type' => 'text/html; charset=utf-8'], $headers)] : [];
    return runCheck('C25SnippetEligibility', htmlCtx('<html><head>' . $head . '</head><body>' . $body . '</body></html>', 'https://example.com/', $page));
}

test('GEO caso 7: nosnippet → C25 no cumple; max-snippet:20 → aviso; data-nosnippet en el menú → cumple', function () {
    assertSame('NO_CUMPLE', geoC25('<meta name="robots" content="nosnippet">')['estado']);
    $short = geoC25('<meta name="robots" content="max-snippet:20">');
    assertSame('AVISO', $short['estado']);
    assertTrue(strpos($short['detalle'], 'orientativo') !== false, $short['detalle']);
    $menu = geoC25('', '<nav data-nosnippet><a href="/">Inicio</a> <a href="/a">Servicios y productos</a></nav><main><p>Contenido principal de la página con bastante texto para comparar.</p></main>');
    assertSame('CUMPLE', $menu['estado']);
    assertSame('oficial', $menu['respaldo']);
});

test('GEO: C25 con cabecera X-Robots-Tag, max-snippet:0 y -1, otro bot y data-nosnippet en el texto principal', function () {
    assertSame('NO_CUMPLE', geoC25('', '<main>Texto</main>', ['x-robots-tag' => 'nosnippet'])['estado']);
    assertSame('NO_CUMPLE', geoC25('<meta name="googlebot" content="max-snippet:0">')['estado'], 'max-snippet:0 equivale a nosnippet');
    assertSame('CUMPLE', geoC25('<meta name="robots" content="max-snippet:-1">')['estado']);
    assertSame('CUMPLE', geoC25('', '<main>Texto</main>', ['x-robots-tag' => 'otrobot: nosnippet'])['estado']);
    assertSame('AVISO', geoC25('', '<main><div data-nosnippet><p>Casi todo el texto principal de la página queda excluido.</p></div><p>Poco.</p></main>')['estado']);
});

// ---------- C03 (GEO-02): HTML inicial ----------

test('GEO caso 8: HTML con solo <div id="root"></div> → C03 aviso con riesgo alto (indicio, no certeza)', function () {
    $r = runCheck('C03RawContent', htmlCtx('<!doctype html><html><head><title>App</title></head><body><div id="root"></div><script src="/app.js"></script></body></html>'));
    assertSame('AVISO', $r['estado']);
    assertSame('alto', $r['valor_medido']['riesgo']);
    assertTrue(strpos($r['detalle'], 'Riesgo alto') !== false && strpos($r['detalle'], 'indicio') !== false, $r['detalle']);
    assertSame('experimento', $r['respaldo']);
    $scripts = str_repeat('<script src="/a.js"></script>', 6);
    $heavy = runCheck('C03RawContent', htmlCtx('<html><body><p>Cargando</p>' . $scripts . '</body></html>'));
    assertSame(['AVISO', 'probable'], [$heavy['estado'], $heavy['valor_medido']['riesgo']]);
});

// ---------- C30 (GEO-30): coherencia del marcado ----------

function geoBusiness(array $ld, string $body): CheckContext
{
    $json = json_encode(array_merge(['@context' => 'https://schema.org'], $ld), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return htmlCtx('<html><head><script type="application/ld+json">' . $json . '</script></head><body>' . $body . '</body></html>');
}

test('GEO caso 9: teléfono «+34 987 123 456» en JSON-LD y «987123456» en el texto → C30 cumple', function () {
    $ld = ['@type' => 'Dentist', 'name' => 'Clínica Ejemplo S.L.', 'telephone' => '+34 987 123 456',
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Calle Mayor 1', 'postalCode' => '24001']];
    $r = runCheck('C30MarkupCoherence', geoBusiness($ld, '<h1>Clínica Ejemplo</h1><p>Llámanos al 987123456. Calle Mayor 1, 24001 León.</p>'));
    assertSame('CUMPLE', $r['estado'], $r['detalle']);
    assertSame(['nombre', 'teléfono', 'dirección'], $r['valor_medido']['datos_comparados']);
    assertSame('oficial', $r['respaldo']);
    // Separadores distintos en el texto
    assertSame('CUMPLE', runCheck('C30MarkupCoherence', geoBusiness(['@type' => 'LocalBusiness', 'telephone' => '987123456'], '<p>Tel. 987 12 34 56</p>'))['estado']);
});

test('GEO: C30 no cumple si el teléfono o la dirección no coinciden; el nombre distinto solo avisa', function () {
    $bad = runCheck('C30MarkupCoherence', geoBusiness(['@type' => 'LocalBusiness', 'name' => 'Ejemplo', 'telephone' => '+34 987 000 000',
        'address' => ['postalCode' => '24001']], '<p>Ejemplo. Teléfono 987 123 456. 28001 Madrid.</p>'));
    assertSame('NO_CUMPLE', $bad['estado']);
    assertSame(2, $bad['ocurrencias']);
    $name = runCheck('C30MarkupCoherence', geoBusiness(['@type' => 'Organization', 'name' => 'Nombre Legal Distinto S.A.', 'telephone' => '987123456'], '<p>Marca. 987123456</p>'));
    assertSame('AVISO', $name['estado']);
});

test('GEO caso 11: web corporativa sin sede → C30 no aplica; con dirección visible y sin JSON-LD → aviso', function () {
    $corp = runCheck('C30MarkupCoherence', htmlCtx('<html><body><main><h1>Software para empresas</h1><p>Contacto: 987 123 456</p></main></body></html>'));
    assertSame(['CUMPLE', false], [$corp['estado'], $corp['aplica']]);
    $local = runCheck('C30MarkupCoherence', htmlCtx('<html><body><p>Estamos en Calle Mayor 1, 24001 León.</p></body></html>'));
    assertSame('AVISO', $local['estado']);
    $map = runCheck('C30MarkupCoherence', htmlCtx('<html><body><iframe title="Mapa" src="https://www.google.com/maps/embed?pb=1"></iframe></body></html>'));
    assertSame('AVISO', $map['estado']);
});

test('GEO: C25 y C30 tienen reparto de áreas, textos en lang/es.php y se ejecutan en el análisis', function () {
    $weights = require __DIR__ . '/../config/weights.php';
    $ids = array_keys(CheckRunner::catalog());
    foreach (['C25', 'C30'] as $id) {
        assertSame(1.0, round(array_sum($weights[$id]), 6), $id . ' suma 1');
        assertTrue(Lang::check($id, 'nombre') !== '' && Lang::check($id, 'recomendacion') !== '', $id . ' con textos');
        assertTrue(in_array($id, $ids, true), $id . ' en CheckRunner');
        assertSame('ia', Report::primaryArea(array_combine(Scorer::AREAS, $weights[$id])), $id . ' se muestra en Preparación para IA');
    }
});

test('GEO: ningún texto nuevo habla de «visibilidad en IA» ni de «posición en IA»', function () {
    $files = ['/../src/Checks/C02BotsIndexing.php', '/../src/Checks/C25SnippetEligibility.php', '/../src/Checks/C30MarkupCoherence.php',
        '/../lang/es.php', '/../assets/js/app.js', '/../config/ai_bots.php'];
    foreach ($files as $f) {
        $txt = mb_strtolower((string) file_get_contents(__DIR__ . $f));
        foreach (['visibilidad en ia', 'posición en ia', 'posicion en ia'] as $bad) {
            assertTrue(strpos($txt, $bad) === false, $f . ' contiene «' . $bad . '»');
        }
    }
});
