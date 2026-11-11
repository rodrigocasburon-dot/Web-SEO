<?php
/**
 * v4 · «Analizar sitio» ya no es una pantalla aparte: el buscador (index.php) analiza siempre la web entera.
 * Se mantiene esta dirección para que los enlaces antiguos (sitio.php?informe=ID) sigan funcionando.
 */
declare(strict_types=1);

$id = (int) ($_GET['informe'] ?? 0);
header('Location: ' . ($id > 0 ? 'index.php?informe=' . $id : './'), true, 301);
exit;
