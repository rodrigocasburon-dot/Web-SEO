<?php
// SEO-06 Sitemap: existe un sitemap XML válido con URLs.
return function (array $p): array {
    preg_match_all('/^\s*sitemap:\s*(\S+)/im', (string) $p['robots'], $m);
    $candidatos = array_merge($m[1], [url_absoluta($p['url'], '/sitemap.xml'), url_absoluta($p['url'], '/sitemap_index.xml')]);
    foreach (array_slice(array_unique($candidatos), 0, 4) as $url) {
        try {
            $respuesta = descargar($url, $p['config']);
        } catch (RuntimeException) {
            continue;
        }
        if ($respuesta['codigo'] === 200 && preg_match('/<(urlset|sitemapindex)[\s>]/i', $respuesta['cuerpo'])) {
            $total = preg_match_all('/<loc>/i', $respuesta['cuerpo']);
            if ($total > 0) {
                return cumple("Sitemap encontrado en $url, con $total direcciones.");
            }
        }
    }
    return no_cumple('No se ha encontrado un sitemap XML válido.');
};
