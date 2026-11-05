<?php
// Copiar a config/config.php y rellenar. config.php NO se sube a git.
return [
    // Clave de la API de PageSpeed Insights (pendiente de obtener)
    'psi_api_key' => '',

    // C03: nº mínimo de palabras en el HTML inicial antes de avisar de posible dependencia de JavaScript
    'min_words_raw_html' => 50,

    // Límites del Fetcher
    'max_redirects'  => 5,
    'timeout'        => 10,        // segundos
    'max_bytes'      => 2097152,   // 2 MB

    // Límite de uso por IP (se guarda en storage/)
    'rate_limit' => ['max_requests' => 10, 'window_seconds' => 600],

    // User-Agent propio e identificable (ajustar con el contacto real de Proconsi)
    'user_agent' => 'ProconsiAnalyzer/0.1 (herramienta interna de analisis web)',

    // Bots de IA a comprobar en robots.txt [lista por completar]
    'ai_search_bots'   => ['OAI-SearchBot', 'PerplexityBot'],
    'ai_training_bots' => ['GPTBot', 'ClaudeBot', 'Google-Extended'],
];
