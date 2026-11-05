<?php
// GEO-15 Categoría precisa: el @type es concreto, no solo LocalBusiness.
return function (array $p): array {
    $organizacion = organizacion_jsonld($p);
    if (!$organizacion) {
        return no_cumple('El JSON-LD no describe el negocio.');
    }
    $tipos = (array) $organizacion['@type'];
    $concretos = array_diff($tipos, ['LocalBusiness', 'Organization', 'Corporation', 'Thing', 'Place']);
    return $concretos
        ? cumple('Categoría del negocio: ' . implode(', ', $concretos) . '.')
        : no_cumple('El tipo es genérico: ' . implode(', ', $tipos) . '.');
};
