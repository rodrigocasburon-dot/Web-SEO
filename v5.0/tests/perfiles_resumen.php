<?php
// Pruebas de perfiles de lectura, resumen ejecutivo, pantalla de resultados e índice de los PDF (casos 1–9).
// Sin internet. Uso: php tests/perfiles_resumen.php   (código de salida 0 si todo pasa).
// Las mismas pruebas forman parte de tests/run.php (tests/perfiles_resumen_test.php).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
error_reporting(E_ALL);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/lib.php';
require_once __DIR__ . '/export_test.php';   // testBrowser() y dumpDom()
$GLOBALS['__tests'] = [];                     // solo las pruebas de este archivo
require __DIR__ . '/perfiles_resumen_test.php';
$code = runTests();
if (isset($GLOBALS['__perfiles_js'])) {
    $r = $GLOBALS['__perfiles_js'];
    ksort($r['casos']);
    echo 'Comprobaciones de JavaScript: ' . $r['ok'] . ' (por caso: '
        . implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($r['casos']), $r['casos'])) . ")\n";
}
exit($code);
