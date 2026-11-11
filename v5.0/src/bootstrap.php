<?php
// Fuera de la consola, los errores van al registro de PHP y nunca a la página (revelarían rutas del servidor).
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    // Cabeceras comunes de páginas y API: sin versión de PHP, sin incrustar en marcos de otros sitios y sin
    // acceso a cámara, micrófono, ubicación ni pagos (la herramienta no los usa).
    if (!headers_sent()) {
        header_remove('X-Powered-By');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    }
}
// Autocarga mínima (sin Composer): src/Clase.php y src/Checks/Clase.php
spl_autoload_register(function ($class) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $class)) {
        return;
    }
    foreach ([__DIR__ . '/', __DIR__ . '/Checks/'] as $dir) {
        $file = $dir . $class . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});
