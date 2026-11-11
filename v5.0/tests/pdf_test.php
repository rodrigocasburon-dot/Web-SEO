<?php
// v4 · PDF corporativos que se descargan (assets/js/pdf.js). La lógica se prueba con Node (tests/js/pdf_test.js);
// sin Node, la prueba queda OMITIDA. Además se comprueba que las pantallas usan el PDF y no el diálogo de imprimir.

test('PDF (JS en Node): WinAnsi, cortes de línea, estructura del PDF y pie con número de página', function () {
    if (!function_exists('proc_open')) {
        skipTest('proc_open no está disponible.');
    }
    $null = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
    $proc = @proc_open(['node', __DIR__ . '/js/pdf_test.js'], [1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes);
    if (!is_resource($proc)) {
        skipTest('No se ha encontrado Node.js.');
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($proc);
    $res = json_decode(trim((string) $out), true);
    if ($code !== 0 && !is_array($res)) {
        skipTest('No se ha podido ejecutar Node.js.');
    }
    assertTrue(is_array($res), 'Salida: ' . substr((string) $out, 0, 200));
    assertSame([], $res['fallos']);
    assertSame(4, $res['ok']);
});

test('PDF: informe, hoja de ruta y requisitos se descargan con pdf.js (sin window.print) y sin recursos externos', function () {
    $app = (string) file_get_contents(__DIR__ . '/../assets/js/app.js');
    assertTrue(strpos($app, 'pdfReport(run)') !== false && strpos($app, 'pdfRoadmap(run)') !== false);
    assertTrue(strpos($app, 'printRoadmap') === false, 'La hoja de ruta ya no usa el diálogo de imprimir');
    $index = (string) file_get_contents(__DIR__ . '/../index.php');
    $posPdf = strpos($index, "\$asset('assets/js/pdf.js')");
    $posApp = strpos($index, "\$asset('assets/js/app.js')");
    assertTrue($posPdf !== false && $posApp !== false && $posPdf < $posApp, 'pdf.js se carga antes que app.js');
    $req = (string) file_get_contents(__DIR__ . '/../requisitos.php');
    assertTrue(strpos($req, 'id="requisitos-pdf"') !== false && strpos($req, "\$asset('assets/js/requisitos.js')") !== false);
    // Poppins: la única petición de los PDF es a los TTF del propio servidor (assets/js/pdf-fuentes.js)
    $fonts = (string) file_get_contents(__DIR__ . '/../assets/js/pdf-fuentes.js');
    preg_match_all("~'(assets/[^']+)'~", $fonts, $mm);
    assertSame(['assets/fonts/Poppins-Regular.ttf', 'assets/fonts/Poppins-SemiBold.ttf'], $mm[1]);
    assertTrue(strpos($fonts, 'http') === false && substr_count($fonts, 'fetch(') === 1, 'pdf-fuentes.js solo pide los TTF locales');
    foreach (['Poppins-Regular.ttf', 'Poppins-SemiBold.ttf'] as $ttf) {
        assertTrue(is_file(__DIR__ . '/../assets/fonts/' . $ttf), $ttf . ' existe');
    }
    assertTrue(strpos($index, "\$asset('assets/js/pdf-fuentes.js')") !== false && strpos($req, "\$asset('assets/js/pdf-fuentes.js')") !== false);
    foreach (['pdf.js', 'requisitos.js', 'informes-pdf.js'] as $f) {
        $js = (string) file_get_contents(__DIR__ . '/../assets/js/' . $f);
        assertTrue(strpos($js, 'fetch(') === false && strpos($js, 'XMLHttpRequest') === false, $f . ' no pide nada a la red');
        assertTrue(strpos($js, 'innerHTML') === false && strpos($js, 'document.write') === false, $f . ' sin innerHTML');
    }
});
