<?php
// GEO-08 Reseñas: hay Review o aggregateRating en el JSON-LD.
return function (array $p): array {
    return campo_jsonld($p, 'aggregateRating') !== null || campo_jsonld($p, 'review') !== null || in_array('Review', tipos_jsonld($p), true)
        ? cumple('El JSON-LD incluye reseñas o valoraciones. No se puede comprobar que sean reales.')
        : aviso('No se han encontrado reseñas marcadas en el JSON-LD.');
};
