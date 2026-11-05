<?php
// Ejecuta todas las pruebas (sin internet): php tests/run.php
error_reporting(E_ALL);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/lib.php';

foreach (glob(__DIR__ . '/*_test.php') as $file) {
    require $file;
}
exit(runTests());
