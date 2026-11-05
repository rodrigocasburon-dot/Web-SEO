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
