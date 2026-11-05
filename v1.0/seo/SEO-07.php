<?php
// SEO-07 Canonical: existe y coincide con la URL analizada.
return function (array $p): array {
    $enlace = trim((string) $p['xpath']->evaluate('string(//link[translate(@rel,"CANOICL","canoicl")="canonical"]/@href)'));
    if ($enlace === '') {
        return aviso('La página no tiene canonical.');
    }
    $limpiar = fn($u) => rtrim(preg_replace('#^https?://(www\.)?#i', '', strtok($u, '#')), '/');
    return $limpiar(url_absoluta($p['url'], $enlace)) === $limpiar($p['url'])
        ? cumple('La canonical coincide con la URL analizada.')
        : aviso("La canonical apunta a otra dirección: $enlace.");
};
