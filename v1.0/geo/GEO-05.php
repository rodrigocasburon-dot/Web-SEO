<?php
// GEO-05 Coordenadas: el JSON-LD incluye latitude y longitude.
return function (array $p): array {
    $latitud = campo_jsonld($p, 'latitude');
    $longitud = campo_jsonld($p, 'longitude');
    return $latitud !== null && $longitud !== null
        ? cumple("El JSON-LD incluye coordenadas: $latitud, $longitud.")
        : no_cumple('El JSON-LD no incluye las coordenadas del local.');
};
