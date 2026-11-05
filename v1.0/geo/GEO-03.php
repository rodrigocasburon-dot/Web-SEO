<?php
// GEO-03 Nombre del negocio en texto: aparece como texto, no solo en el logo.
return function (array $p): array {
    $organizacion = organizacion_jsonld($p);
    $nombre = $organizacion['name'] ?? trim((string) $p['xpath']->evaluate('string(//meta[@property="og:site_name"]/@content)'));
    if (!is_string($nombre) || $nombre === '') {
        return sin_datos('No se ha podido saber el nombre del negocio: falta en el JSON-LD y en og:site_name.');
    }
    return mb_stripos($p['texto'], $nombre) !== false
        ? cumple("El nombre \"$nombre\" aparece como texto.")
        : aviso("El nombre \"$nombre\" no aparece como texto en la página.");
};
