<?php
// Funciones que comparten las comprobaciones.

function cumple(string $detalle): array { return ['estado' => 'cumple', 'detalle' => $detalle]; }
function no_cumple(string $detalle): array { return ['estado' => 'no_cumple', 'detalle' => $detalle]; }
function aviso(string $detalle): array { return ['estado' => 'aviso', 'detalle' => $detalle]; }
function sin_datos(string $detalle): array { return ['estado' => 'sin_datos', 'detalle' => $detalle]; }
function no_aplica(string $detalle): array { return ['estado' => 'no_aplica', 'detalle' => $detalle]; }

function palabras(string $texto): array
{
    preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($texto), $m);
    return $m[0];
}

function palabras_utiles(string $texto): array
{
    $vacias = ['para', 'como', 'cada', 'todo', 'todos', 'toda', 'todas', 'pero', 'este', 'esta', 'estos', 'estas', 'sobre', 'entre', 'desde', 'hasta', 'cuando', 'donde', 'porque', 'también', 'tiene', 'nuestro', 'nuestra', 'nuestros', 'nuestras', 'with', 'that', 'this', 'from', 'your', 'have', 'more', 'will'];
    return array_values(array_filter(palabras($texto), fn($p) => mb_strlen($p) > 3 && !in_array($p, $vacias, true)));
}

// Lista de ['nivel', 'texto'] con los encabezados en orden.
function encabezados(array $p): array
{
    $lista = [];
    foreach ($p['xpath']->query('//h1|//h2|//h3|//h4|//h5|//h6') as $h) {
        $lista[] = ['nivel' => (int) $h->nodeName[1], 'texto' => trim(preg_replace('/\s+/u', ' ', $h->textContent)), 'nodo' => $h];
    }
    return $lista;
}

// Lista de ['url', 'texto', 'interno'] con los enlaces http de la página.
function enlaces_de(array $p): array
{
    $lista = [];
    $base = preg_replace('/^www\./', '', strtolower(parse_url($p['url'], PHP_URL_HOST) ?? ''));
    foreach ($p['xpath']->query('//a[@href]') as $a) {
        $href = trim($a->getAttribute('href'));
        if ($href === '' || preg_match('/^(#|javascript:|mailto:|tel:|data:)/i', $href)) {
            continue;
        }
        $url = url_absoluta($p['url'], $href);
        $host = preg_replace('/^www\./', '', strtolower(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host !== '' && preg_match('#^https?://#i', $url)) {
            $lista[] = ['url' => $url, 'texto' => trim($a->textContent), 'interno' => $host === $base];
        }
    }
    return $lista;
}

// Todos los tipos @type del JSON-LD, también los anidados.
function tipos_jsonld(array $p): array
{
    $tipos = [];
    $pila = $p['jsonld'];
    while ($pila) {
        $nodo = array_shift($pila);
        if (is_array($nodo)) {
            array_push($tipos, ...array_map('strval', (array) ($nodo['@type'] ?? [])));
            array_push($pila, ...array_values(array_filter($nodo, 'is_array')));
        }
    }
    return array_values(array_unique($tipos));
}

// Busca un campo en cualquier nivel del JSON-LD y devuelve el primer valor.
function campo_jsonld(array $p, string $campo): mixed
{
    $pila = $p['jsonld'];
    while ($pila) {
        $nodo = array_shift($pila);
        if (!is_array($nodo)) {
            continue;
        }
        if (isset($nodo[$campo]) && $nodo[$campo] !== '' && $nodo[$campo] !== []) {
            return $nodo[$campo];
        }
        foreach ($nodo as $valor) {
            if (is_array($valor)) {
                $pila[] = $valor;
            }
        }
    }
    return null;
}

function es_tipo_organizacion(array $objeto): bool
{
    $otros = ['WebSite', 'WebPage', 'BreadcrumbList', 'FAQPage', 'Article', 'BlogPosting', 'ImageObject', 'Product', 'ItemList', 'SiteNavigationElement', 'Person', 'CollectionPage', 'Offer', 'Question', 'Answer'];
    $tipos = (array) ($objeto['@type'] ?? []);
    return $tipos && !array_intersect($tipos, $otros);
}

function organizacion_jsonld(array $p): ?array
{
    foreach ($p['jsonld'] as $objeto) {
        if (is_array($objeto) && isset($objeto['@type']) && es_tipo_organizacion($objeto)) {
            return $objeto;
        }
    }
    return null;
}

// Teléfonos españoles encontrados en un texto, solo con sus dígitos.
function telefonos_en(string $texto): array
{
    preg_match_all('/(?<![\d.,])(?:\+34[\s.-]?|0034[\s.-]?)?[6789]\d{2}(?:[\s.-]?\d{2,3}){3}(?![\d])|(?<![\d.,])(?:\+34[\s.-]?)?[6789]\d{2}[\s.-]?\d{3}[\s.-]?\d{3}(?!\d)/u', $texto, $m);
    $lista = [];
    foreach ($m[0] as $numero) {
        $digitos = preg_replace('/\D/', '', $numero);
        $digitos = preg_replace('/^(0034|34)(?=\d{9}$)/', '', $digitos);
        if (strlen($digitos) === 9) {
            $lista[] = $digitos;
        }
    }
    return array_values(array_unique($lista));
}

function hay_direccion(array $p): bool
{
    return $p['xpath']->query('//address')->length > 0
        || preg_match('/\b(calle|c\/|avda\.?|avenida|plaza|pza\.?|paseo|carretera|ctra\.?|camino|ronda|pol[ií]gono)\s+[\p{L}\p{N} .,ºª\'-]{2,50}?\d+/iu', $p['texto']);
}

function hay_precios(string $texto): int
{
    return preg_match_all('/\d[\d.,]*\s?(€|euros?\b)|€\s?\d/iu', $texto);
}

function es_pregunta(string $texto): bool
{
    return str_ends_with($texto, '?') || str_starts_with($texto, '¿');
}

// Encabezados con forma de pregunta seguidos de un párrafo.
function preguntas_con_respuesta(array $p): int
{
    $total = 0;
    foreach (encabezados($p) as $h) {
        if (!es_pregunta($h['texto'])) {
            continue;
        }
        $siguiente = $h['nodo']->nextSibling;
        while ($siguiente && $siguiente->nodeType !== XML_ELEMENT_NODE) {
            $siguiente = $siguiente->nextSibling;
        }
        if ($siguiente && trim($siguiente->textContent) !== '') {
            $total++;
        }
    }
    return $total;
}

// Índice de legibilidad Flesch-Szigriszt para textos en español.
function flesch_szigriszt(string $texto): ?float
{
    $frases = max(1, preg_match_all('/[.!?:;]+(\s|$)/u', $texto));
    $lista = array_filter(palabras($texto), fn($p) => preg_match('/\p{L}/u', $p));
    if (!$lista) {
        return null;
    }
    $silabas = 0;
    foreach ($lista as $palabra) {
        $silabas += max(1, preg_match_all('/[aeiouáéíóúü]+/u', $palabra));
    }
    return 206.835 - 62.3 * ($silabas / count($lista)) - count($lista) / $frases;
}

function fecha_modificacion(array $p): ?int
{
    $meta = $p['xpath']->evaluate('string(//meta[@property="article:modified_time"]/@content)');
    $fecha = campo_jsonld($p, 'dateModified') ?? ($meta !== '' ? $meta : null) ?? campo_jsonld($p, 'datePublished');
    $marca = is_string($fecha) ? strtotime($fecha) : false;
    return $marca ?: null;
}

function auditoria_pagespeed(array $sitio, string $id): ?array
{
    return $sitio['pagespeed']['lighthouseResult']['audits'][$id] ?? null;
}

// CSS sin comentarios ni bloques @media: lo que se aplica en cualquier tamaño de pantalla.
function css_base(string $css): string
{
    $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    return preg_replace('/@media[^{]*+\{(?:[^{}]*+\{[^{}]*+\})*+[^{}]*+\}/i', '', $css) ?? $css;
}

// Lista de [selector, declaraciones] de un CSS.
function reglas_css(string $css): array
{
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);
    return array_map(fn($r) => [trim($r[1]), $r[2]], $m);
}
