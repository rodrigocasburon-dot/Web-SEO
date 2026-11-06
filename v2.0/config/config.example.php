<?php
// Copiar a config/config.php y rellenar. config.php NO se sube a git.
return [
    // Clave de la API de PageSpeed Insights (pendiente de obtener)
    // Sin clave la API responde 429 (cuota 0) y C08 y C14 quedan «Sin datos».
    'psi_api_key' => '',
    // Espera máxima a PageSpeed Insights (s); Lighthouse suele tardar entre 15 y 60 s
    'psi_timeout' => 75,

    // C03: nº mínimo de palabras en el HTML inicial antes de avisar de posible dependencia de JavaScript
    'min_words_raw_html' => 50,

    // Límites del Fetcher
    'max_redirects'  => 5,
    'timeout'        => 10,        // segundos
    'max_bytes'      => 2097152,   // 2 MB

    // C16 Enlaces rotos: nº máximo de enlaces, espera por enlace y tiempo total (s)
    'links_max'     => 20,
    'links_timeout' => 5,
    'links_budget'  => 12,
    // C18 Sitemap: nº máximo de archivos leídos (índices incluidos) y tiempo total (s)
    'sitemap_max_files' => 5,
    'sitemap_budget'    => 8,

    // Simulador de mejora: nº de fallos (los que más puntos restan) que se suponen corregidos (1 a 15)
    'simulator_top_n' => 3,

    // Límite de uso por IP (se guarda en storage/)
    'rate_limit' => ['max_requests' => 10, 'window_seconds' => 600],

    // User-Agent propio e identificable (ajustar con el contacto real de Proconsi)
    'user_agent' => 'ProconsiAnalyzer/0.1 (herramienta interna de analisis web)',

    // Bots de IA a comprobar en robots.txt [lista por completar]
    'ai_search_bots'   => ['OAI-SearchBot', 'PerplexityBot'],
    'ai_training_bots' => ['GPTBot', 'ClaudeBot', 'Google-Extended'],
];
