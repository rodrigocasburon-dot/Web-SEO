<?php
// Pruebas de los fragmentos de corrección (función 09, src/FixSnippets.php). Sin red.
// Los registros salen de las comprobaciones reales sobre HTML en línea (htmlCtx, en extras_test.php).

/** Ejecuta una comprobación y devuelve su corrección (o null). */
function fixFor(string $class, string $html, string $url = 'https://example.com/pagina'): ?array
{
    $r = (new $class())->run(htmlCtx($html, $url));
    return FixSnippets::forRecord($r, $url);
}

test('Corrección C06: añade alt a la etiqueta real; «antes» es el código de la evidencia', function () {
    $html = '<main><img src="/img/a.jpg" class="foto" title="sin alt aquí"><img src="b.png" alt="Logo"></main>';
    $r = (new C06ImageAlt())->run(htmlCtx($html));
    $c = FixSnippets::forRecord($r, 'https://example.com/');
    assertSame(1, $c['total']);
    assertSame('<img src="/img/a.jpg" class="foto" title="sin alt aquí">', $c['fragmentos'][0]['antes']);
    assertSame($r['evidencia'][0]['extracto'], $c['fragmentos'][0]['antes'], 'No se inventa el código de partida');
    assertSame('<img src="/img/a.jpg" class="foto" title="sin alt aquí" alt="DESCRIPCIÓN DE LA IMAGEN">', $c['fragmentos'][0]['despues'],
        'El «alt» dentro del title no se confunde con un atributo');
    assertTrue(strpos($c['borrador'], 'borrador') !== false && strpos($c['borrador'], 'revisar') !== false);
    assertTrue($c['nota'] !== '');
});

test('Corrección C06: etiqueta recortada en la evidencia → no se propone nada; máximo 3 fragmentos', function () {
    $long = '<img src="/' . str_repeat('x', 300) . '.jpg">';
    assertSame(null, fixFor('C06ImageAlt', '<main>' . $long . '</main>'), 'Extracto recortado: la plantilla no encaja');
    $many = fixFor('C06ImageAlt', '<main>' . str_repeat('<img src="a.jpg">', 5) . '</main>');
    assertSame(5, $many['total']);
    assertSame(FixSnippets::MAX, count($many['fragmentos']));
});

test('Corrección C04: aria-label según el tipo y solo en la etiqueta de apertura', function () {
    $html = '<main><a href="/carrito" class="ico"><svg viewBox="0 0 10 10"><path d="M0 0h10"/></svg></a>'
        . '<button aria-label=""></button><input type="text" name="q"></main>';
    $c = fixFor('C04AccessibleNames', $html);
    $f = $c['fragmentos'];
    assertSame(3, count($f));
    assertSame(['<a href="/carrito" class="ico">', '<a href="/carrito" class="ico" aria-label="NOMBRE DEL ENLACE">'], [$f[0]['antes'], $f[0]['despues']]);
    assertSame('<button aria-label="NOMBRE DEL BOTÓN">', $f[1]['despues'], 'Sustituye el aria-label vacío en lugar de duplicarlo');
    assertSame('<input type="text" name="q" aria-label="NOMBRE DEL CAMPO">', $f[2]['despues']);
});

test('Corrección C07: lang ausente o no válido → marcador; es_ES → es-ES', function () {
    $missing = fixFor('C07Language', '<html><body><p>Hola</p></body></html>');
    assertSame(['<html>', '<html lang="IDIOMA">'], [$missing['fragmentos'][0]['antes'], $missing['fragmentos'][0]['despues']]);
    $under = fixFor('C07Language', '<html lang="es_es" class="x"><body></body></html>');
    assertSame('<html lang="es-ES" class="x">', $under['fragmentos'][0]['despues']);
    assertTrue(strpos($under['nota'], 'guion') !== false);
    $bad = fixFor('C07Language', '<html lang="spanish"><body></body></html>');
    assertSame('<html lang="IDIOMA">', $bad['fragmentos'][0]['despues']);
    assertSame(null, fixFor('C07Language', '<html lang="es"><body></body></html>'), 'Sin fallo no hay corrección');
});

test('Corrección C09: viewport ausente, zoom bloqueado y sin width=device-width', function () {
    $none = fixFor('C09Viewport', '<head><title>T</title></head>');
    assertSame(null, $none['fragmentos'][0]['antes'], 'No existe: no hay código de partida');
    assertSame('<meta name="viewport" content="width=device-width, initial-scale=1">', $none['fragmentos'][0]['despues']);
    $zoom = fixFor('C09Viewport', '<head><meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no"></head>');
    assertSame('<meta name="viewport" content="width=device-width, initial-scale=1">', $zoom['fragmentos'][0]['despues']);
    $width = fixFor('C09Viewport', '<head><meta name="viewport" content="initial-scale=1"></head>');
    assertSame('<meta name="viewport" content="width=device-width, initial-scale=1">', $width['fragmentos'][0]['despues']);
    assertSame(null, fixFor('C09Viewport', '<head><meta name="viewport" content="width=device-width,initial-scale=1"></head>'));
});

test('Corrección C10, C12 y C15: elemento ausente o vacío; otros fallos no encajan', function () {
    $t = fixFor('C10Title', '<head></head><body></body>');
    assertSame([null, '<title>TÍTULO DE LA PÁGINA (35-60 CARACTERES)</title>'], [$t['fragmentos'][0]['antes'], $t['fragmentos'][0]['despues']]);
    $empty = fixFor('C10Title', '<head><title> </title></head>');
    assertSame('<title> </title>', $empty['fragmentos'][0]['antes']);
    assertSame(null, fixFor('C10Title', '<head><title>Uno</title><title>Dos</title></head>'), 'Dos title: no es una corrección sencilla');
    assertSame(null, fixFor('C10Title', '<head><title>Corto</title></head>'), 'Aviso de longitud: requiere redactar');

    $c = fixFor('C12Canonical', '<head><title>T</title></head>', 'https://example.com/a?b=1&c=2');
    assertSame('<link rel="canonical" href="https://example.com/a?b=1&amp;c=2">', $c['fragmentos'][0]['despues'], 'La URL final, escapada');
    assertSame(null, fixFor('C12Canonical', '<head><link rel="canonical" href="https://example.com/otra"></head>'),
        'Canonical a otra página: puede ser intencionado');

    $d = fixFor('C15MetaDescription', '<head><title>T</title></head>');
    assertSame('<meta name="description" content="DESCRIPCIÓN DE LA PÁGINA (120-155 CARACTERES)">', $d['fragmentos'][0]['despues']);
    $de = fixFor('C15MetaDescription', '<head><meta name="description" content=""></head>');
    assertSame('<meta name="description" content="DESCRIPCIÓN DE LA PÁGINA (120-155 CARACTERES)">', $de['fragmentos'][0]['despues']);
});

test('Corrección C20: http → https solo en los atributos con URL', function () {
    $html = '<head><script src="http://cdn.example.com/a.js" data-info="http://no-tocar"></script></head>'
        . '<body><img src="/a.jpg" srcset="http://cdn.example.com/a.jpg 1x, http://cdn.example.com/b.jpg 2x" alt="x"></body>';
    $c = fixFor('C20HttpsSecurity', $html);
    $after = array_column($c['fragmentos'], 'despues');
    assertTrue(in_array('<script src="https://cdn.example.com/a.js" data-info="http://no-tocar">', $after, true), json_encode($after));
    assertTrue(in_array('<img src="/a.jpg" srcset="https://cdn.example.com/a.jpg 1x, https://cdn.example.com/b.jpg 2x" alt="x">', $after, true), json_encode($after));
});

test('Corrección: comprobaciones sin plantilla o sin fallo no llevan fragmento; el análisis no cambia la puntuación', function () {
    assertSame(null, FixSnippets::forRecord(['id' => 'C13', 'estado' => 'NO_CUMPLE', 'evidencia' => []], 'https://e.com/'));
    assertSame(null, FixSnippets::forRecord(['id' => 'C06', 'estado' => 'CUMPLE', 'evidencia' => []], 'https://e.com/'));
    $records = [(new C06ImageAlt())->run(htmlCtx('<main><img src="a.jpg"></main>'))];
    $with = FixSnippets::attach($records, 'https://example.com/');
    assertTrue(isset($with[0]['correccion']['fragmentos']));
    assertSame((new Scorer())->score($records), (new Scorer())->score($with), 'La corrección no afecta a la puntuación');
    $lang = Lang::all()['correcciones'];
    foreach (['C04', 'C06', 'C07', 'C09', 'C10', 'C12', 'C15', 'C20'] as $id) {
        assertTrue(isset($lang[$id]['nota']), 'Plantilla en lang/es.php: ' . $id);
    }
});

test('Corrección: el análisis completo la adjunta con la URL final y no cambia estados ni nota', function () {
    $config = require __DIR__ . '/../config/config.example.php';
    $page = fetcherWith([
        'https://example.com/' => fakeResp(301, ['location' => '/inicio?a=1&b=2']),
        'https://example.com/inicio?a=1&b=2' => fakeResp(200, ['content-type' => 'text/html; charset=utf-8'], '<html lang="es"><title>T</title><main><h1>Hola</h1></main></html>'),
    ]);
    $none = fetcherWith([]);
    $r = (new Analyzer($config, $page, $none, fn(string $url, array $o = []) => $none->fetch($url)))->analyze('https://example.com/');
    $byId = array_column($r['checks'], null, 'id');
    assertSame('<link rel="canonical" href="https://example.com/inicio?a=1&amp;b=2">', $byId['C12']['correccion']['fragmentos'][0]['despues']);
    assertTrue(!isset($byId['C07']['correccion']), 'C07 cumple: sin corrección');
    assertSame((new Scorer())->score($r['checks']), $r['puntuacion']);
});

test('Corrección: lectura de etiquetas y atributos', function () {
    assertSame('<a href="x>y" class=b>', FixSnippets::openTag('<a href="x>y" class=b>texto</a>'), '«>» entre comillas');
    assertSame(null, FixSnippets::openTag('<img src="muy-larg…'), 'Recortada');
    assertSame(null, FixSnippets::openTag('(no hay meta viewport)'));
    assertSame('b', FixSnippets::attrValue('<a href="x" class=b>', 'class'));
    assertSame(null, FixSnippets::attrValue('<a title="class=b">', 'class'));
    assertSame('<img src="a" alt="x">', FixSnippets::setAttr('<img src="a" />', 'alt', 'x'));
    assertSame('<a title="say &quot;hi&quot;">', FixSnippets::setAttr('<a title=old>', 'title', 'say "hi"'));
});
