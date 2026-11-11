<?php
// Servidor local solo para pruebas de CurlTransport (php -S 127.0.0.1:PUERTO tests/server/router.php).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch ($path) {
    case '/ok':
        header('Content-Type: text/html; charset=utf-8');
        header('X-Test: uno');
        header('X-Test: dos', false);
        echo '<html><body>host=' . ($_SERVER['HTTP_HOST'] ?? '') . ' ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? '') . '</body></html>';
        break;
    case '/big':
        header('Content-Type: text/html');
        echo str_repeat('a', 3 * 1024 * 1024);
        break;
    case '/redir':
        header('Location: /ok', true, 302);
        break;
    case '/cabeceras':
        // Servidor hostil: unas 800 KB de cabeceras (SEG-12)
        for ($i = 0; $i < 4000; $i++) {
            header('X-Relleno-' . $i . ': ' . str_repeat('a', 200));
        }
        echo 'ok';
        break;
    case '/slow':
        sleep(3);
        echo 'tarde';
        break;
    default:
        http_response_code(404);
        echo 'no encontrado';
}
