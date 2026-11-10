<?php
// v3.0 · Pruebas de C21–C24 (comprobaciones traídas de la v1.0). Sin red. Usan htmlCtx() de extras_test.php.

test('C21: iframes sin title → NO_CUMPLE; con title, ocultos o sin iframes → bien', function () {
    $r = runCheck('C21IframeTitle', htmlCtx('<iframe src="https://maps.example/"></iframe><iframe title="Vídeo" src="x"></iframe><iframe src="y"></iframe>'));
    assertSame('NO_CUMPLE', $r['estado']);
    assertSame(2, $r['ocurrencias']);
    assertSame('CUMPLE', runCheck('C21IframeTitle', htmlCtx('<iframe title="Mapa" src="x"></iframe><iframe aria-hidden="true" src="t"></iframe>'))['estado']);
    assertSame(false, runCheck('C21IframeTitle', htmlCtx('<p>Sin iframes</p>'))['aplica']);
});

test('C22: id repetido usado por un label → NO_CUMPLE; repetido sin uso → AVISO', function () {
    $r = runCheck('C22DuplicateIds', htmlCtx('<label for="email">Correo</label><input id="email"><div id="email"></div>'));
    assertSame('NO_CUMPLE', $r['estado']);
    assertSame(1, $r['ocurrencias']);
    assertSame('NO_CUMPLE', runCheck('C22DuplicateIds', htmlCtx('<a href="#menu">Ir</a><nav id="menu"></nav><div id="menu"></div>'))['estado']);
    assertSame('AVISO', runCheck('C22DuplicateIds', htmlCtx('<div id="a"></div><div id="a"></div>'))['estado']);
    assertSame('CUMPLE', runCheck('C22DuplicateIds', htmlCtx('<div id="a"></div><div id="b"></div>'))['estado']);
});

test('C23: vídeo sin subtítulos → NO_CUMPLE; de fondo en silencio → AVISO; con track → CUMPLE', function () {
    assertSame('NO_CUMPLE', runCheck('C23VideoCaptions', htmlCtx('<video controls src="a.mp4"></video>'))['estado']);
    assertSame('AVISO', runCheck('C23VideoCaptions', htmlCtx('<video autoplay muted loop src="fondo.mp4"></video>'))['estado']);
    assertSame('CUMPLE', runCheck('C23VideoCaptions', htmlCtx('<video controls><track kind="captions" src="s.vtt" srclang="es"></video>'))['estado']);
    $none = runCheck('C23VideoCaptions', htmlCtx('<iframe title="v" src="https://www.youtube.com/embed/x"></iframe>'));
    assertSame(false, $none['aplica']);
    assertTrue(strpos($none['detalle'], 'no se pueden comprobar') !== false);
});

test('C24: autoplay con sonido o marquee → AVISO (nunca penaliza); en silencio → CUMPLE', function () {
    assertSame('AVISO', runCheck('C24Autoplay', htmlCtx('<audio autoplay src="a.mp3"></audio>'))['estado']);
    assertSame('AVISO', runCheck('C24Autoplay', htmlCtx('<marquee>Oferta</marquee>'))['estado']);
    assertSame('CUMPLE', runCheck('C24Autoplay', htmlCtx('<video autoplay muted src="v.mp4"></video>'))['estado']);
});

test('C21–C24: reparto de áreas definido y textos en lang/es.php', function () {
    $w = require __DIR__ . '/../config/weights.php';
    foreach (['C21', 'C22', 'C23', 'C24'] as $id) {
        assertTrue(isset($w[$id]) && abs(array_sum($w[$id]) - 1) < 1e-9, "$id con reparto que suma 1");
        assertTrue(Lang::check($id, 'nombre') !== '' && Lang::check($id, 'recomendacion') !== '', "$id con textos");
    }
});
