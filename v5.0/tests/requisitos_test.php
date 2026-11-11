<?php
// v4 · Página de requisitos (requisitos.php): lista todas las comprobaciones del catálogo, sale de las propias
// clases (no puede quedar desactualizada), está en el menú de las tres pantallas y pasa la autocomprobación.

function renderRequisitos(): string
{
    ob_start();
    include __DIR__ . '/../requisitos.php';
    return (string) ob_get_clean();
}

test('Requisitos: aparece cada comprobación del catálogo, una vez, con su nombre y agrupada por área', function () {
    $html = renderRequisitos();
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xp = new DOMXPath($doc);
    $lang = Lang::all();
    $catalog = CheckRunner::catalog();
    assertSame(count($catalog), $xp->query('//section[contains(@class,"requisitos-area")]/details[@class="fold"]')->length);
    foreach ($catalog as $id => $check) {
        $node = $xp->query('//details[@id="' . $id . '"]')->item(0);
        assertTrue($node !== null, 'Falta ' . $id);
        assertTrue(strpos($node->textContent, $lang['checks'][$id]['nombre']) !== false, 'Nombre de ' . $id);
        $area = Report::primaryArea($check->describe(BaseCheck::NO_DATA)['pilares']);
        assertSame('area-' . $area, $node->parentNode->getAttribute('id'), $id . ' en su área');
    }
    foreach (['cumple WCAG', 'cumple la ley'] as $bad) {
        assertTrue(stripos($html, $bad) === false, 'No debe decir «' . $bad . '»');
    }
});

test('Requisitos: está en el menú de las tres pantallas y pasa la autocomprobación de HTML y accesibilidad', function () {
    foreach (['index.php', 'historial.php', 'requisitos.php'] as $page) {
        $php = (string) file_get_contents(__DIR__ . '/../' . $page);
        assertTrue(strpos($php, '<a href="requisitos.php"') !== false, $page . ' enlaza a requisitos.php');
    }
    $ctx = CheckContext::fromFetch([
        'final_url' => 'https://analizador.example/requisitos.php', 'status' => 200,
        'headers' => ['content-type' => 'text/html; charset=utf-8'], 'content_type' => 'text/html; charset=utf-8',
        'body' => renderRequisitos(),
    ], require __DIR__ . '/../config/config.example.php');
    foreach (['C04AccessibleNames', 'C05Headings', 'C06ImageAlt', 'C07Language', 'C09Viewport', 'C10Title', 'C11Semantics'] as $class) {
        $r = (new $class())->run($ctx);
        assertSame('CUMPLE', $r['estado'], $class . ': ' . $r['detalle']);
    }
});
