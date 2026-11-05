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

    // Rastreo: tope de páginas, duración de cada tanda y pausa entre peticiones.
    'paginas_maximas' => 100,
    'tanda_segundos' => 8,
    'pausa_ms' => 300,
    'enlaces_maximos' => 60,

    // Bots de IA que se comprueban en robots.txt.
    'bots_ia_busqueda' => ['OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Perplexity-User', 'Claude-SearchBot'],
    'bots_ia_entrenamiento' => ['GPTBot', 'ClaudeBot', 'Google-Extended', 'CCBot'],

    // Déjalo en false. En true se pueden analizar direcciones internas, solo para pruebas.
    'permitir_locales' => false,
];
