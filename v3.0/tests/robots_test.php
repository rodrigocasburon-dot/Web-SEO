<?php
// Pruebas del intérprete de robots.txt (RFC 9309).

test('RobotsTxt: sin grupo aplicable se permite todo', function () {
    $r = RobotsTxt::parse("User-agent: OtroBot\nDisallow: /");
    assertTrue($r->isAllowed('Googlebot', '/'));
});

test('RobotsTxt: grupo específico tiene prioridad sobre *', function () {
    $r = RobotsTxt::parse("User-agent: *\nDisallow: /\n\nUser-agent: googlebot\nAllow: /");
    assertTrue($r->isAllowed('Googlebot', '/pagina'));
    assertSame(false, $r->isAllowed('GPTBot', '/pagina'));
});

test('RobotsTxt: la regla más larga gana y en empate gana Allow', function () {
    $r = RobotsTxt::parse("User-agent: *\nDisallow: /blog/\nAllow: /blog/publico/\nDisallow: /x\nAllow: /x");
    assertSame(false, $r->isAllowed('Bot', '/blog/privado'));
    assertTrue($r->isAllowed('Bot', '/blog/publico/a'));
    assertTrue($r->isAllowed('Bot', '/x'));
});

test('RobotsTxt: comodines * y $', function () {
    $r = RobotsTxt::parse("User-agent: *\nDisallow: /*.pdf$\nDisallow: /*?sesion=");
    assertSame(false, $r->isAllowed('Bot', '/docs/a.pdf'));
    assertTrue($r->isAllowed('Bot', '/docs/a.pdf?v=1'));
    assertSame(false, $r->isAllowed('Bot', '/p?sesion=1'));
});

test('RobotsTxt: Disallow vacío no bloquea; comentarios y BOM se ignoran', function () {
    $r = RobotsTxt::parse("\xEF\xBB\xBFUser-agent: * # todos\nDisallow:\n");
    assertTrue($r->isAllowed('Bot', '/'));
});

test('RobotsTxt: varios user-agent en un grupo y grupos repetidos se combinan', function () {
    $r = RobotsTxt::parse("User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /a\n\nUser-agent: GPTBot\nDisallow: /b");
    assertSame(false, $r->isAllowed('ClaudeBot', '/a'));
    assertSame(false, $r->isAllowed('gptbot', '/b'));
    assertTrue($r->isAllowed('ClaudeBot', '/b'));
});

test('RobotsTxt: explain devuelve la regla aplicada', function () {
    $e = RobotsTxt::parse("User-agent: *\nDisallow: /privado")->explain('Bot', '/privado/x');
    assertSame('Disallow: /privado', $e['rule']);
    assertSame('*', $e['group']);
});

test('RobotsTxt: el user-agent se compara por su token (se ignoran versión y comodín final)', function () {
    $r = RobotsTxt::parse("User-agent: GPTBot/1.0\nDisallow: /\n\nUser-agent: googlebot*\nDisallow: /privado");
    assertSame(false, $r->isAllowed('GPTBot', '/'));
    assertSame('gptbot', $r->explain('GPTBot', '/')['group']);
    assertSame(false, $r->isAllowed('Googlebot', '/privado/a'));
    assertTrue($r->isAllowed('Googlebot-Image', '/privado/a'), 'Otro token: no hereda el grupo de googlebot');
    assertSame('*', RobotsTxt::token(' * '));
    assertSame('oai-searchbot', RobotsTxt::token('OAI-SearchBot'));
});

// ---------- Función 04: tabla de bots de IA (RobotsAnalyzer) ----------

function botsTable(?array $robots, string $url = 'https://example.com/blog/post?id=7', array $config = []): array
{
    $config = array_merge(require __DIR__ . '/../config/config.example.php', $config);
    $t = RobotsAnalyzer::table($url, $robots, $config);
    $by = [];
    foreach ($t['bots'] as $row) {
        $by[$row['bot']] = $row;
    }
    $t['by'] = $by;
    return $t;
}

test('Bots IA: bots desde la configuración, separados en búsqueda y entrenamiento', function () {
    $t = botsTable(['status' => 404, 'body' => '', 'error' => null]);
    $search = array_column(array_filter($t['bots'], fn($b) => $b['tipo'] === 'busqueda'), 'bot');
    $train = array_column(array_filter($t['bots'], fn($b) => $b['tipo'] === 'entrenamiento'), 'bot');
    assertSame(['Googlebot', 'OAI-SearchBot', 'PerplexityBot'], array_values($search));
    assertSame(['GPTBot', 'ClaudeBot', 'Google-Extended'], array_values($train));
    $custom = RobotsAnalyzer::bots(['search_engine_bots' => ['Googlebot', 'Bingbot'], 'ai_search_bots' => ['Bingbot', ' '], 'ai_training_bots' => ['CCBot']]);
    assertSame(['Googlebot', 'Bingbot'], $custom['busqueda'], 'Sin duplicados ni vacíos');
    assertSame(['CCBot'], $custom['entrenamiento']);
});

test('Bots IA: bloquea solo el entrenamiento; grupo propio y regla aplicada', function () {
    $txt = "User-agent: *\nAllow: /\n\nUser-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /\n\nUser-agent: Google-Extended\nDisallow: /blog/";
    $t = botsTable(['status' => 200, 'body' => $txt, 'error' => null]);
    assertSame('presente', $t['robots_txt']);
    assertSame('/blog/post?id=7', $t['ruta']);
    assertSame(['acceso' => 'bloqueado', 'grupo' => 'gptbot', 'regla' => 'Disallow: /'], array_intersect_key($t['by']['GPTBot'], array_flip(['acceso', 'grupo', 'regla'])));
    assertSame('bloqueado', $t['by']['ClaudeBot']['acceso']);
    assertSame('Disallow: /blog/', $t['by']['Google-Extended']['regla']);
    assertSame(['acceso' => 'permitido', 'grupo' => '*', 'regla' => 'Allow: /'], array_intersect_key($t['by']['OAI-SearchBot'], array_flip(['acceso', 'grupo', 'regla'])));
    assertSame('permitido', $t['by']['Googlebot']['acceso']);
});

test('Bots IA: regla más larga y empate a favor de Allow', function () {
    $txt = "User-agent: *\nDisallow: /blog/\nAllow: /blog/post\n\nUser-agent: PerplexityBot\nDisallow: /blog/post\nAllow: /blog/post";
    $t = botsTable(['status' => 200, 'body' => $txt, 'error' => null]);
    assertSame('permitido', $t['by']['Googlebot']['acceso'], '/blog/post (10) gana a /blog/ (6)');
    assertSame('Allow: /blog/post', $t['by']['Googlebot']['regla']);
    assertSame('permitido', $t['by']['PerplexityBot']['acceso'], 'Empate de longitud: gana Allow');
    assertSame('perplexitybot', $t['by']['PerplexityBot']['grupo']);
});

test('Bots IA: todo bloqueado salvo Googlebot; sin grupo aplicable se permite', function () {
    $t = botsTable(['status' => 200, 'body' => "User-agent: *\nDisallow: /\n\nUser-agent: Googlebot\nDisallow:", 'error' => null]);
    assertSame('permitido', $t['by']['Googlebot']['acceso']);
    assertSame(null, $t['by']['Googlebot']['regla'], 'Disallow vacío no bloquea');
    assertSame('bloqueado', $t['by']['OAI-SearchBot']['acceso']);
    $none = botsTable(['status' => 200, 'body' => "User-agent: OtroBot\nDisallow: /", 'error' => null]);
    assertSame(['permitido', null], [$none['by']['GPTBot']['acceso'], $none['by']['GPTBot']['grupo']]);
});

test('Bots IA: robots.txt ausente, en HTML o con error se informa sin penalizar', function () {
    $absent = botsTable(['status' => 404, 'body' => '', 'error' => null]);
    assertSame('ausente', $absent['robots_txt']);
    assertSame('No existe robots.txt (HTTP 404): se permite todo.', $absent['nota']);
    assertSame(['permitido'], array_values(array_unique(array_column($absent['bots'], 'acceso'))));

    $html = botsTable(['status' => 200, 'body' => '<!DOCTYPE html><html></html>', 'error' => null]);
    assertSame('ausente', $html['robots_txt']);

    $err = botsTable(['status' => 503, 'body' => '', 'error' => null]);
    assertSame('sin_datos', $err['robots_txt']);
    assertSame(['sin_datos'], array_values(array_unique(array_column($err['bots'], 'acceso'))));
    $timeout = botsTable(['status' => null, 'body' => null, 'error' => 'TIMEOUT']);
    assertSame('robots.txt no disponible (TIMEOUT).', $timeout['nota']);

    // Es solo informativa: no añade comprobaciones ni cambia la puntuación
    $ctx = htmlCtx('<title>T</title>', 'https://example.com/', [], ['status' => 503, 'body' => '', 'error' => null]);
    assertSame('sin_datos', Insights::build($ctx)['bots_ia']['robots_txt']);
});

test('Bots IA: la tabla se calcula aunque la página esté bloqueada', function () {
    $ctx = htmlCtx('<p>Acceso denegado</p>', 'https://example.com/', ['status' => 403, 'blocked' => ['code' => 'FORBIDDEN', 'message' => 'x']],
        ['status' => 200, 'body' => "User-agent: GPTBot\nDisallow: /", 'error' => null]);
    $b = Insights::build($ctx);
    assertSame(null, $b['vista_bot']);
    assertSame('presente', $b['bots_ia']['robots_txt']);
});
