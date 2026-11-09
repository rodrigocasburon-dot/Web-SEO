<?php
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
