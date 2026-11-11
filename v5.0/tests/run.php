<?php
// Ejecuta todas las pruebas (sin internet): php tests/run.php
// Solo por consola: desde el navegador lanzaría toda la batería (y un navegador sin ventana) en el servidor.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
error_reporting(E_ALL);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/lib.php';

foreach (glob(__DIR__ . '/*_test.php') as $file) {
    require $file;
}
exit(runTests());
