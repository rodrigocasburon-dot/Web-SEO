<?php
// Carga la configuración y conecta con MySQL.

function cargar_config(): array
{
    $archivo = __DIR__ . '/../config.php';
    if (!is_file($archivo)) {
        throw new RuntimeException('Falta config.php. Copia config.example.php con ese nombre.');
    }
    return require $archivo;
}

function conectar(array $config): PDO
{
    $dsn = "mysql:host={$config['db_host']};port={$config['db_puerto']};dbname={$config['db_nombre']};charset=utf8mb4";
    try {
        return new PDO($dsn, $config['db_usuario'], $config['db_clave'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException) {
        throw new RuntimeException('No se puede conectar con MySQL. Revisa config.php y que hayas importado install.sql.');
    }
}
