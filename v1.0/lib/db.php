<?php
// Carga la configuración y conecta con MySQL.

// Valores para quien tenga un config.php anterior a estas opciones.
const CONFIG_POR_DEFECTO = [
    'chrome_ruta' => '',
    'chrome_sin_sandbox' => false,
    'muestra_paginas' => 5,
    'navegador_segundos' => 170,
    'ia_clave' => '',
    'ia_modelo' => 'claude-opus-5-5',
    'ia_url' => 'https://api.anthropic.com/v1/messages',
    'ia_imagenes' => 12,
];

function cargar_config(): array
{
    $archivo = __DIR__ . '/../config.php';
    if (!is_file($archivo)) {
        throw new RuntimeException('Falta config.php. Copia config.example.php con ese nombre.');
    }
    return (require $archivo) + CONFIG_POR_DEFECTO;
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
