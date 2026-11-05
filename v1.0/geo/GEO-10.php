<?php
// GEO-10 Precios: hay precios en el texto o price en el JSON-LD.
return function (array $p): array {
    $total = hay_precios($p['texto']);
    if ($total > 0) {
        return cumple("Se han encontrado $total precios en el texto.");
    }
    return campo_jsonld($p, 'price') !== null || campo_jsonld($p, 'priceRange') !== null
        ? cumple('El JSON-LD incluye precios.')
        : aviso('No se han encontrado precios.');
};
