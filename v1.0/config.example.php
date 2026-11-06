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

    // Opcional. Sin clave, la calidad del alt y la intención de búsqueda se estiman por código.
    // Cada análisis tiene un coste en la cuenta de Anthropic.
    'ia_clave' => '',
    'ia_modelo' => 'claude-opus-5-5',
    'ia_imagenes' => 12,

    // Chrome mide teclado, foco, zoom, formularios, carruseles y cookies en estas páginas de muestra.
    // Vacío busca Chrome o Edge en su sitio habitual. Sin navegador, esos puntos se estiman por código.
    'chrome_ruta' => '',
    'muestra_paginas' => 5,

    // Límites de cada descarga.
    'tiempo_maximo' => 15,
    'tamano_maximo' => 5 * 1024 * 1024,
    'redirecciones_maximas' => 5,

    // Rastreo: duración de cada tanda y pausa entre peticiones.
    'tanda_segundos' => 8,
    'pausa_ms' => 300,
    'enlaces_maximos' => 60,

    // Bots de IA que se comprueban en robots.txt.
    'bots_ia_busqueda' => ['OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Perplexity-User', 'Claude-SearchBot'],
    'bots_ia_entrenamiento' => ['GPTBot', 'ClaudeBot', 'Google-Extended', 'CCBot'],

    // Déjalo en false. En true se pueden analizar direcciones internas, solo para pruebas.
    'permitir_locales' => false,
];
