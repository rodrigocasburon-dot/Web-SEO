<?php
/**
 * Carga la configuración: valores por defecto de config.example.php sobrescritos por
 * config/config.php (si existe, no se sube a git).
 */
class Config
{
    public static function load(?string $dir = null): array
    {
        // WEBSEO_CONFIG_DIR lo usan las pruebas para no cargar el config.php local, que contiene la clave real
        $dir = $dir ?? (getenv('WEBSEO_CONFIG_DIR') ?: __DIR__ . '/../config');
        $config = is_file($dir . '/config.example.php') ? (array) require $dir . '/config.example.php' : [];
        if (is_file($dir . '/config.php')) {
            $config = array_replace($config, (array) require $dir . '/config.php');
        }
        return $config;
    }
}
