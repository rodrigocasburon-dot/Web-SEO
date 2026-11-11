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

    // Sitio entero (v4: se analizan TODAS las páginas que se encuentren): tope de seguridad de páginas por análisis
    // (máx. 20.000), páginas a la vez, archivos de sitemap que se leen como máximo y tiempo total para leerlos (s)
    'site_max_pages'      => 5000,
    'site_parallel'       => 6,
    // v4 · Intervalo mínimo entre páginas del mismo análisis (ms). Si robots.txt pide un Crawl-delay mayor, manda
    // ese (hasta 10 s). Si el sitio responde 429 o 503, el análisis se detiene (src/SiteRun.php).
    'site_min_interval_ms' => 250,
    'site_sitemap_files'  => 50,
    'site_sitemap_budget' => 30,
    // Informe de sitio · recomendaciones agrupadas: páginas afectadas que se listan como máximo en cada una
    'site_group_max_pages' => 20,

    // v3.0 · Pruebas con Chrome en la portada del sitio (teclado, foco, zoom, cookies, carrusel, formulario).
    // Chrome ejecuta el JavaScript de la web analizada: activar solo en red interna. Informativas, no puntúan.
    // 'ruta' vacía = se busca Chrome o Edge en las rutas habituales. 'max_segundos': tiempo total de la sesión.
    // Antes de activarlo, leer docs/SEGURIDAD.md (requisitos de red del servidor).
    'chrome' => ['activo' => false, 'ruta' => '', 'sin_sandbox' => false, 'max_segundos' => 90],

    // Indicador de fiabilidad de las notas (no cambia ninguna nota). Siempre se muestran los datos calculados:
    // estado del análisis, puntos evaluados sobre posibles y peso evaluado. El NIVEL (alta / media / baja) solo
    // se muestra si se definen estos umbrales: porcentaje mínimo de «peso evaluado» para cada nivel.
    // PROPUESTA pendiente de validar por el grupo (por eso está comentada): 90 y 50. El 50 coincide con la
    // regla ya aprobada de «nota incompleta» (más de la mitad sin datos); el 90 es una suposición.
    // 'fiabilidad' => ['alta' => 90, 'media' => 50],

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

    // Los bots que se comprueban en robots.txt y su categoría están en config/ai_bots.php (sin secretos).
    // Las claves antiguas ai_search_bots, ai_training_bots y search_engine_bots ya no se usan.
];
