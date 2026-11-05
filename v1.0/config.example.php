<?php
// Copia este archivo como config.php y pon tus datos. config.php no se sube a GitHub.
return [
    'db_host' => '127.0.0.1',
    'db_puerto' => 3306,
    'db_nombre' => 'web_seo',
    'db_usuario' => 'root',
    'db_clave' => '',

    // Opcional. Sin clave, los puntos de PageSpeed salen como "sin datos".
    'pagespeed_clave' => '',

    // Límites de cada descarga.
    'tiempo_maximo' => 15,
    'tamano_maximo' => 5 * 1024 * 1024,
    'redirecciones_maximas' => 5,

    // Déjalo en false. En true se pueden analizar direcciones internas, solo para pruebas.
    'permitir_locales' => false,
];
