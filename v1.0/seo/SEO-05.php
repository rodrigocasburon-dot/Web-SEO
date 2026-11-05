<?php
// SEO-05 robots.txt permite a Google: deja rastrear las páginas y sus recursos CSS y JS.
return function (array $p): array {
    if ($p['robots'] === null) {
        return cumple('La web no tiene robots.txt, así que se permite rastrear todo.');
    }
    $ruta = parse_url($p['url'], PHP_URL_PATH) ?: '/';
    if (!robots_permite($p['robots'], 'Googlebot', $ruta)) {
        return no_cumple("robots.txt bloquea a Googlebot en $ruta.");
    }
    $bloqueados = 0;
    foreach ($p['xpath']->query('//script/@src|//link[@rel="stylesheet"]/@href') as $r) {
        $url = url_absoluta($p['url'], $r->value);
        if (parse_url($url, PHP_URL_HOST) === parse_url($p['url'], PHP_URL_HOST)
            && !robots_permite($p['robots'], 'Googlebot', parse_url($url, PHP_URL_PATH) ?: '/')) {
            $bloqueados++;
        }
    }
    return $bloqueados
        ? no_cumple("robots.txt bloquea $bloqueados archivos CSS o JS que necesita la página.")
        : cumple('robots.txt permite a Googlebot rastrear la página y sus recursos.');
};
