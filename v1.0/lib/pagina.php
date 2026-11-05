<?php
// Convierte una descarga en el array $pagina que reciben todas las comprobaciones.

function crear_pagina(array $descarga, string $url_pedida, bool $es_local, array $config, ?string $robots, bool $es_portada): array
{
    $html = a_utf8($descarga['cuerpo'], $descarga['cabeceras']['content-type'] ?? '');
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . ($html !== '' ? $html : '<html></html>'), LIBXML_NONET);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    [$jsonld, $jsonld_errores] = leer_jsonld($xpath);

    return [
        'url' => $descarga['url'],
        'url_pedida' => $url_pedida,
        'codigo' => $descarga['codigo'],
        'tiempo_ms' => $descarga['tiempo_ms'],
        'saltos' => $descarga['saltos'],
        'cabeceras' => $descarga['cabeceras'],
        'html' => $html,
        'dom' => $dom,
        'xpath' => $xpath,
        'texto' => leer_texto($dom),
        'texto_principal' => leer_texto($dom, true),
        'jsonld' => $jsonld,
        'jsonld_errores' => $jsonld_errores,
        'robots' => $robots,
        // Los estilos solo se leen en la portada, que es donde se comprueban.
        'css' => $es_portada ? leer_css($xpath, $descarga['url'], $config) : '',
        'es_local' => $es_local,
        'es_portada' => $es_portada,
        'config' => $config,
    ];
}

// Une los estilos de la página: bloques style y hasta 5 hojas de estilo enlazadas.
function leer_css(DOMXPath $xpath, string $url, array $config): string
{
    $css = '';
    foreach ($xpath->query('//style') as $bloque) {
        $css .= $bloque->textContent . "\n";
    }
    foreach ($xpath->query('//link[contains(translate(@rel, "STYLEHS", "stylehs"), "stylesheet")]/@href') as $i => $href) {
        if ($i >= 5) {
            break;
        }
        try {
            $hoja = descargar(url_absoluta($url, $href->value), $config);
            $css .= $hoja['codigo'] === 200 ? $hoja['cuerpo'] . "\n" : '';
        } catch (RuntimeException) {
            continue;
        }
    }
    return $css;
}

function a_utf8(string $html, string $tipo): string
{
    if (preg_match('/charset=["\']?([\w-]+)/i', $tipo . substr($html, 0, 2048), $m)
        && strtoupper($m[1]) !== 'UTF-8'
        && in_array(strtoupper($m[1]), array_map('strtoupper', mb_list_encodings()), true)) {
        $html = mb_convert_encoding($html, 'UTF-8', $m[1]);
    } elseif (!mb_check_encoding($html, 'UTF-8')) {
        $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
    }
    // El meta original haría que el lector volviera a convertir el texto.
    return preg_replace('/(<meta[^>]*charset=["\']?)[\w-]+/i', '${1}utf-8', $html, 1);
}

// Texto visible de la página, sin scripts ni estilos y con los espacios normalizados.
// Con $solo_principal deja fuera menús, cabecera y pie, que distorsionan los recuentos.
function leer_texto(DOMDocument $dom, bool $solo_principal = false): string
{
    $copia = clone $dom;
    $xpath = new DOMXPath($copia);
    $quitar = '//script|//style|//noscript|//template';
    if ($solo_principal) {
        $quitar .= '|//header|//nav|//footer|//aside';
    }
    foreach (iterator_to_array($xpath->query($quitar)) as $nodo) {
        if ($nodo->parentNode) {
            $nodo->parentNode->removeChild($nodo);
        }
    }
    $zona = $solo_principal ? $copia->getElementsByTagName('main')->item(0) : null;
    $zona ??= $copia->getElementsByTagName('body')->item(0);
    return $zona ? trim(preg_replace('/\s+/u', ' ', $zona->textContent)) : '';
}

// Devuelve [lista de objetos JSON-LD, número de bloques con errores de sintaxis].
function leer_jsonld(DOMXPath $xpath): array
{
    $objetos = [];
    $errores = 0;
    foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
        $datos = json_decode(trim($script->textContent), true);
        if (!is_array($datos)) {
            $errores++;
            continue;
        }
        $lista = array_is_list($datos) ? $datos : [$datos];
        foreach ($lista as $objeto) {
            if (is_array($objeto)) {
                array_push($objetos, ...($objeto['@graph'] ?? [$objeto]));
            }
        }
    }
    return [$objetos, $errores];
}

// Devuelve el texto de robots.txt, o null si la web no lo tiene.
function leer_robots(string $url, array $config): ?string
{
    try {
        $respuesta = descargar(url_absoluta($url, '/robots.txt'), $config);
        return $respuesta['codigo'] === 200 ? $respuesta['cuerpo'] : null;
    } catch (RuntimeException) {
        return null;
    }
}

// Indica si robots.txt deja a un bot entrar en una ruta. Sin robots.txt, se permite todo.
function robots_permite(?string $robots, string $agente, string $ruta = '/'): bool
{
    $grupos = [];
    $actuales = [];
    $leyendo_agentes = false;
    foreach (preg_split('/\r\n|\r|\n/', (string) $robots) as $linea) {
        $linea = trim(preg_replace('/#.*/', '', $linea));
        if (!str_contains($linea, ':')) {
            continue;
        }
        [$campo, $valor] = array_map('trim', explode(':', $linea, 2));
        $campo = strtolower($campo);
        if ($campo === 'user-agent') {
            if (!$leyendo_agentes) {
                $actuales = [];
            }
            $actuales[] = strtolower($valor);
            $grupos[strtolower($valor)] ??= [];
            $leyendo_agentes = true;
        } elseif ($campo === 'allow' || $campo === 'disallow') {
            $leyendo_agentes = false;
            foreach ($actuales as $nombre) {
                $grupos[$nombre][] = [$campo, $valor];
            }
        } else {
            $leyendo_agentes = false;
        }
    }

    $reglas = $grupos[strtolower($agente)] ?? $grupos['*'] ?? [];
    $permitido = true;
    $mejor = -1;
    foreach ($reglas as [$tipo, $patron]) {
        if ($patron === '') {
            continue;
        }
        $regex = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($patron, '#')) . '#';
        $largo = strlen($patron);
        if (preg_match($regex, $ruta) && ($largo > $mejor || ($largo === $mejor && $tipo === 'allow'))) {
            $mejor = $largo;
            $permitido = $tipo === 'allow';
        }
    }
    return $permitido;
}
