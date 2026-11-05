<?php
// La propia pantalla debe superar sus comprobaciones de accesibilidad y tener contraste suficiente.

/** Renderiza index.php como HTML. */
function renderIndex(): string
{
    ob_start();
    include __DIR__ . '/../index.php';
    return (string) ob_get_clean();
}

test('Autocomprobación: la pantalla no falla ninguna comprobación de HTML ni de accesibilidad', function () {
    $ctx = CheckContext::fromFetch([
        'final_url' => 'https://analizador.example/', 'status' => 200,
        'headers' => ['content-type' => 'text/html; charset=utf-8'], 'content_type' => 'text/html; charset=utf-8',
        'body' => renderIndex(),
    ], require __DIR__ . '/../config/config.example.php');
    // C02 (noindex intencionado: herramienta interna), C12 y C13 (SEO) no aplican a una herramienta interna
    foreach (['C04AccessibleNames', 'C05Headings', 'C06ImageAlt', 'C07Language', 'C09Viewport', 'C10Title', 'C11Semantics'] as $class) {
        $r = (new $class())->run($ctx);
        assertSame('CUMPLE', $r['estado'], $class . ': ' . $r['detalle']);
    }
});

/** Relación de contraste WCAG entre dos colores #rrggbb. */
function contrastRatio(string $a, string $b): float
{
    $lum = function (string $hex): float {
        $hex = ltrim($hex, '#');
        $c = [];
        foreach ([0, 2, 4] as $i) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    $l1 = $lum($a);
    $l2 = $lum($b);
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}

test('Contraste: la fórmula coincide con valores conocidos', function () {
    assertTrue(abs(contrastRatio('#000000', '#ffffff') - 21) < 0.01);
    assertTrue(abs(contrastRatio('#777777', '#ffffff') - 4.48) < 0.01);
});

test('Contraste: los pares de color de app.css alcanzan 4,5:1 (texto) y 3:1 (bordes y foco)', function () {
    $css = (string) file_get_contents(__DIR__ . '/../assets/css/app.css');
    preg_match_all('/--([a-z\-]+):\s*(#[0-9a-f]{6})\s*;/i', $css, $m, PREG_SET_ORDER);
    $v = [];
    foreach ($m as $x) {
        $v[$x[1]] = $x[2];
    }
    $text = [['text', 'bg'], ['text', 'surface'], ['muted', 'bg'], ['muted', 'surface'], ['on-primary', 'primary'],
        ['primary', 'bg'], ['fail', 'fail-bg'], ['fail', 'bg'], ['warn', 'warn-bg'], ['ok', 'ok-bg'],
        ['nodata', 'nodata-bg'], ['on-primary', 'fail']];
    foreach ($text as [$fg, $bg]) {
        $ratio = contrastRatio($v[$fg], $v[$bg]);
        assertTrue($ratio >= 4.5, sprintf('%s sobre %s: %.2f:1', $fg, $bg, $ratio));
    }
    foreach ([['border', 'bg'], ['border', 'surface'], ['focus', 'bg'], ['focus', 'surface']] as [$fg, $bg]) {
        $ratio = contrastRatio($v[$fg], $v[$bg]);
        assertTrue($ratio >= 3, sprintf('%s sobre %s: %.2f:1', $fg, $bg, $ratio));
    }
});

test('Seguridad: app.js no usa innerHTML ni insertAdjacentHTML', function () {
    $js = (string) file_get_contents(__DIR__ . '/../assets/js/app.js');
    // Se ignoran los comentarios que lo mencionan
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $js);
    assertTrue(strpos($code, 'innerHTML') === false && strpos($code, 'insertAdjacentHTML') === false && strpos($code, 'document.write') === false);
});

test('Seguridad: la pantalla no carga recursos externos (sin CDN)', function () {
    $html = renderIndex();
    assertTrue(!preg_match('#(src|href)="(https?:)?//#i', $html));
});
