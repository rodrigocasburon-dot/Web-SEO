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

    // v3.0 · Modo «sitio completo»: nº de páginas de la muestra (además de la portada), páginas a la vez,
    // archivos de sitemap que se leen como máximo y tiempo total para leerlos (s)
    'site_max_pages'      => 50,
    'site_parallel'       => 6,
    'site_sitemap_files'  => 4,
    'site_sitemap_budget' => 10,

    // v3.0 · Pruebas con Chrome en la portada del modo sitio (teclado, foco, zoom, cookies, carrusel, formulario).
    // Chrome ejecuta el JavaScript de la web analizada: activar solo en red interna. Informativas, no puntúan.
    // 'ruta' vacía = se busca Chrome o Edge en las rutas habituales.
    'chrome' => ['activo' => false, 'ruta' => '', 'sin_sandbox' => false],

    // Simulador de mejora: nº de fallos (los que más puntos restan) que se suponen corregidos (1 a 15)
    'simulator_top_n' => 3,

    // Historial de notas por URL en MySQL (Fase 4). Con 'dsn' vacío está desactivado.
    // Crear la base con install.sql y copiar este bloque a config.php con los datos reales, p. ej.:
    // 'dsn' => 'mysql:host=localhost;dbname=radar_web;charset=utf8mb4'
    'historial' => ['dsn' => '', 'usuario' => '', 'clave' => '', 'meses' => 12],

    // Límite de uso por IP (se guarda en storage/)
    'rate_limit' => ['max_requests' => 10, 'window_seconds' => 600],

    // User-Agent propio e identificable (ajustar con el contacto real de Proconsi)
    'user_agent' => 'ProconsiAnalyzer/0.1 (herramienta interna de analisis web)',

    // Bots de IA a comprobar en robots.txt [lista por completar]
    'ai_search_bots'   => ['OAI-SearchBot', 'PerplexityBot'],
    'ai_training_bots' => ['GPTBot', 'ClaudeBot', 'Google-Extended'],
    // Buscadores que se añaden a la tabla informativa de bots (no afecta a C02) [lista por completar]
    'search_engine_bots' => ['Googlebot'],
];
