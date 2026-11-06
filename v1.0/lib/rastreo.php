<?php
// Recorre la web por tandas. Cada recarga de progreso.php hace una tanda, para que PHP no se corte.

// Profundidad de las páginas que solo se conocen por el sitemap: ningún enlace lleva a ellas.
const SOLO_EN_SITEMAP = 9999;
const SITEMAPS_MAXIMOS = 200;

const EXTENSIONES_NO_HTML = '/\.(pdf|jpe?g|png|gif|webp|svg|ico|zip|rar|gz|mp4|mp3|webm|docx?|xlsx?|pptx?|css|js|xml|json)$/i';

function host_base(string $url): string
{
    return preg_replace('/^www\./', '', strtolower(parse_url($url, PHP_URL_HOST) ?? ''));
}

function limpiar_url(string $url): string
{
    $url = strtok($url, '#');
    return parse_url($url, PHP_URL_PATH) === null && !str_contains($url, '?') ? $url . '/' : $url;
}

// Crea el análisis y procesa la portada. Si la web no responde, lanza el error sin guardar nada.
function iniciar_analisis(PDO $db, string $url, array $config): int
{
    // Sin historial: los análisis antiguos se borran solos.
    // Uno a medias se guarda más tiempo: una web muy grande puede tardar días.
    $db->exec("DELETE FROM analisis WHERE (estado = 'terminado' AND creado < NOW() - INTERVAL 1 DAY) OR creado < NOW() - INTERVAL 7 DAY");

    $descarga = descargar($url, $config);
    if (!str_contains(strtolower($descarga['cabeceras']['content-type'] ?? 'text/html'), 'html')) {
        throw new RuntimeException('Esa dirección no es una página web.');
    }
    $robots = leer_robots($descarga['url'], $config);
    $url = limpiar_url($descarga['url']);

    $db->prepare('INSERT INTO analisis (url, robots) VALUES (?, ?)')->execute([$url, $robots]);
    $analisis = ['id' => (int) $db->lastInsertId(), 'url' => $url, 'robots' => $robots];

    encolar($db, $analisis['id'], $url, 0);
    $fila = $db->query("SELECT * FROM paginas WHERE analisis_id = {$analisis['id']}")->fetch(PDO::FETCH_ASSOC);
    procesar_pagina($db, $analisis, $fila, $descarga, $config);
    return $analisis['id'];
}

function encolar(PDO $db, int $analisis_id, string $url, int $profundidad): void
{
    $db->prepare('INSERT IGNORE INTO paginas (analisis_id, url, url_hash, profundidad) VALUES (?, ?, ?, ?)')
        ->execute([$analisis_id, $url, sha1($url), $profundidad]);
}

// Direcciones de los sitemaps de la web, incluidos los que cuelgan de un índice.
function urls_de_sitemap(string $url, ?string $robots, array $config): array
{
    preg_match_all('/^\s*sitemap:\s*(\S+)/im', (string) $robots, $m);
    $pendientes = $m[1] ?: [url_absoluta($url, '/sitemap.xml'), url_absoluta($url, '/sitemap_index.xml')];
    $vistos = [];
    $urls = [];
    while ($pendientes && count($vistos) < SITEMAPS_MAXIMOS) {
        $sitemap = array_shift($pendientes);
        if (isset($vistos[$sitemap])) {
            continue;
        }
        $vistos[$sitemap] = true;
        try {
            $respuesta = descargar($sitemap, ['tamano_maximo' => 50 * 1024 * 1024] + $config);
        } catch (RuntimeException) {
            continue;
        }
        if ($respuesta['codigo'] !== 200) {
            continue;
        }
        preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</loc>#is', $respuesta['cuerpo'], $locs);
        $direcciones = array_map(fn($u) => html_entity_decode($u, ENT_QUOTES | ENT_XML1), $locs[1]);
        if (stripos($respuesta['cuerpo'], '<sitemapindex') !== false) {
            array_push($pendientes, ...$direcciones);
        } else {
            array_push($urls, ...$direcciones);
        }
    }
    return array_values(array_unique($urls));
}

// Añade a la cola lo que está en el sitemap y no se ha alcanzado por enlaces.
function encolar_sitemap(PDO $db, array $analisis, array $config): void
{
    foreach (urls_de_sitemap($analisis['url'], $analisis['robots'], $config) as $url) {
        $ruta = parse_url($url, PHP_URL_PATH) ?: '/';
        if (host_base($url) === host_base($analisis['url']) && !preg_match(EXTENSIONES_NO_HTML, $ruta)
            && robots_permite($analisis['robots'], AGENTE, $ruta)) {
            encolar($db, $analisis['id'], limpiar_url($url), SOLO_EN_SITEMAP);
        }
    }
}

// Datos que necesitan las comprobaciones que comparan páginas.
function datos_de_pagina(array $p): array
{
    $enlaces = enlaces_de($p);
    $nav = [];
    foreach ($p['xpath']->query('//nav//a[@href]') as $a) {
        $nav[] = trim($a->textContent);
    }
    return [
        'title' => trim((string) $p['xpath']->evaluate('string(//head/title)')),
        'nav' => array_values(array_unique(array_filter($nav))),
        'telefonos' => telefonos_en($p['texto']),
        'jsonld_telefono' => (string) (campo_jsonld($p, 'telephone') ?? ''),
        'jsonld_direccion' => (string) (campo_jsonld($p, 'streetAddress') ?? ''),
        'texto_contacto' => mb_substr(mb_strtolower($p['texto']), -3000),
        'saltos' => $p['saltos'],
        'enlaces' => array_values(array_unique(array_column($enlaces, 'url'))),
    ];
}

function procesar_pagina(PDO $db, array $analisis, array $fila, array $descarga, array $config): void
{
    $es_portada = (int) $fila['profundidad'] === 0;

    if (!str_contains(strtolower($descarga['cabeceras']['content-type'] ?? 'text/html'), 'html')) {
        $db->prepare("UPDATE paginas SET estado = 'omitida' WHERE id = ?")->execute([$fila['id']]);
        return;
    }

    // Si una redirección lleva a una página ya vista o a otro dominio, no se analiza dos veces.
    $url_final = limpiar_url($descarga['url']);
    if ($url_final !== $fila['url']) {
        $repetida = $db->prepare('SELECT COUNT(*) FROM paginas WHERE analisis_id = ? AND url_hash = ?');
        $repetida->execute([$analisis['id'], sha1($url_final)]);
        if ($repetida->fetchColumn() > 0 || host_base($url_final) !== host_base($analisis['url'])) {
            $db->prepare("UPDATE paginas SET estado = 'omitida' WHERE id = ?")->execute([$fila['id']]);
            return;
        }
    }

    $pagina = crear_pagina($descarga, $analisis['url'], $config, $analisis['robots'], $es_portada);
    $checklist = cargar_checklist();

    $db->beginTransaction();
    $db->prepare("UPDATE paginas SET estado = 'hecha', url = ?, url_hash = ?, codigo = ?, tiempo_ms = ?, datos = ? WHERE id = ?")
        ->execute([$url_final, sha1($url_final), $pagina['codigo'], $pagina['tiempo_ms'],
            json_encode(datos_de_pagina($pagina), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $fila['id']]);
    $insertar = $db->prepare('INSERT INTO resultados (pagina_id, punto_id, estado, detalle) VALUES (?, ?, ?, ?)');
    foreach ($checklist['puntos'] as $punto) {
        if (toca_en_pagina($punto, $es_portada, $pagina['codigo'])) {
            $resultado = ejecutar_punto($punto, $pagina);
            $insertar->execute([$fila['id'], $punto['id'], $resultado['estado'], $resultado['detalle']]);
        }
    }
    $db->commit();

    if ($pagina['codigo'] === 200) {
        foreach (enlaces_de($pagina) as $enlace) {
            $ruta = parse_url($enlace['url'], PHP_URL_PATH) ?: '/';
            if ($enlace['interno'] && !preg_match(EXTENSIONES_NO_HTML, $ruta) && robots_permite($analisis['robots'], AGENTE, $ruta)) {
                encolar($db, $analisis['id'], limpiar_url($enlace['url']), (int) $fila['profundidad'] + 1);
            }
        }
    }
}

// Hace una tanda de trabajo y devuelve el estado del análisis.
function hacer_tanda(PDO $db, int $id, array $config): string
{
    // Si hay dos pestañas abiertas, solo una trabaja.
    if (!$db->query("SELECT GET_LOCK('web_seo_$id', 0)")->fetchColumn()) {
        return 'rastreando';
    }
    $consulta = $db->prepare('SELECT * FROM analisis WHERE id = ?');
    $consulta->execute([$id]);
    $analisis = $consulta->fetch(PDO::FETCH_ASSOC);

    if ($analisis['estado'] === 'rastreando') {
        $fin = microtime(true) + $config['tanda_segundos'];
        $siguiente = $db->prepare("SELECT * FROM paginas WHERE analisis_id = ? AND estado = 'pendiente' ORDER BY id LIMIT 1");
        do {
            $siguiente->execute([$id]);
            $fila = $siguiente->fetch(PDO::FETCH_ASSOC);
            if (!$fila && !$analisis['sitemap']) {
                // Cuando se acaban los enlaces, se mira qué más dice el sitemap.
                $db->prepare('UPDATE analisis SET sitemap = 1 WHERE id = ?')->execute([$id]);
                $analisis['sitemap'] = 1;
                encolar_sitemap($db, $analisis, $config);
                continue;
            }
            if (!$fila) {
                $db->prepare("UPDATE analisis SET estado = 'navegador' WHERE id = ?")->execute([$id]);
                $analisis['estado'] = 'navegador';
                break;
            }
            try {
                procesar_pagina($db, $analisis, $fila, descargar($fila['url'], $config), $config);
            } catch (RuntimeException) {
                $db->prepare("UPDATE paginas SET estado = 'hecha' WHERE id = ?")->execute([$fila['id']]);
            }
            usleep($config['pausa_ms'] * 1000);
        } while (microtime(true) < $fin);
    } elseif ($analisis['estado'] === 'navegador') {
        $db->prepare("UPDATE analisis SET estado = 'ia', navegador = ? WHERE id = ?")
            ->execute([medir_con_navegador($db, $analisis, $config), $id]);
        $analisis['estado'] = 'ia';
    } elseif ($analisis['estado'] === 'ia') {
        $db->prepare("UPDATE analisis SET estado = 'cierre', ia = ? WHERE id = ?")
            ->execute([medir_con_ia($db, $analisis, $config), $id]);
        $analisis['estado'] = 'cierre';
    } elseif ($analisis['estado'] === 'cierre') {
        cerrar_analisis($db, $analisis, $config);
        $analisis['estado'] = 'terminado';
    }

    $db->query("SELECT RELEASE_LOCK('web_seo_$id')");
    return $analisis['estado'];
}

function pedir_pagespeed(string $url, array $config): ?array
{
    if ($config['pagespeed_clave'] === '') {
        return null;
    }
    $consulta = http_build_query(['url' => $url, 'strategy' => 'mobile', 'key' => $config['pagespeed_clave']]);
    $direccion = "https://www.googleapis.com/pagespeedonline/v5/runPagespeed?$consulta&category=performance&category=accessibility";
    try {
        $respuesta = descargar($direccion, ['tiempo_maximo' => 90, 'tamano_maximo' => 30 * 1024 * 1024] + $config);
    } catch (RuntimeException) {
        return null;
    }
    return $respuesta['codigo'] === 200 ? json_decode($respuesta['cuerpo'], true) : null;
}

// Última fase: PageSpeed y las comprobaciones que comparan páginas.
function cerrar_analisis(PDO $db, array $analisis, array $config): void
{
    // Las comparaciones cargan a la vez los datos de todas las páginas.
    ini_set('memory_limit', '2048M');
    $consulta = $db->prepare("SELECT * FROM paginas WHERE analisis_id = ? AND estado = 'hecha' ORDER BY id");
    $consulta->execute([$analisis['id']]);
    $paginas = $consulta->fetchAll(PDO::FETCH_ASSOC);
    foreach ($paginas as &$fila) {
        $fila['datos'] = json_decode((string) $fila['datos'], true) ?? [];
    }
    unset($fila);

    $portada = $paginas[0];
    $pagespeed = pedir_pagespeed($portada['url'], $config);
    $sitio = [
        'url' => $portada['url'],
        'paginas' => $paginas,
        'pagespeed' => $pagespeed,
        'config' => $config,
    ];

    $insertar = $db->prepare('INSERT INTO resultados (pagina_id, punto_id, estado, detalle) VALUES (?, ?, ?, ?)');
    foreach (cargar_checklist()['puntos'] as $punto) {
        if ($punto['alcance'] === 'comparacion' || $punto['metodo'] === 'pagespeed') {
            $resultado = ejecutar_punto($punto, $sitio);
            $insertar->execute([$portada['id'], $punto['id'], $resultado['estado'], $resultado['detalle']]);
        }
    }
    $db->prepare("UPDATE analisis SET estado = 'terminado', pagespeed = ? WHERE id = ?")
        ->execute([$pagespeed ? '1' : null, $analisis['id']]);
}
