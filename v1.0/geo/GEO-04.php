<?php
// GEO-04 Enlace de identidad: el JSON-LD de la organización incluye @id o url.
return function (array $p): array {
    $organizacion = organizacion_jsonld($p);
    if (!$organizacion) {
        return no_cumple('El JSON-LD no describe a la organización.');
    }
    return !empty($organizacion['@id']) || !empty($organizacion['url'])
        ? cumple('El JSON-LD de la organización incluye @id o url.')
        : no_cumple('El JSON-LD de la organización no incluye @id ni url.');
};
