<?php
// GEO-07 Horarios: hay horarios en el texto y openingHours en el JSON-LD.
return function (array $p): array {
    $en_texto = preg_match('/\b(lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bados?|domingos?|l-v)\b.{0,60}\d{1,2}([:.]\d{2}|\s?h)/iu', $p['texto']);
    $en_codigo = campo_jsonld($p, 'openingHours') !== null || campo_jsonld($p, 'openingHoursSpecification') !== null;
    if ($en_texto && $en_codigo) {
        return cumple('Los horarios están en el texto y en el JSON-LD.');
    }
    if (!$en_texto && !$en_codigo) {
        return no_cumple('No se han encontrado horarios.');
    }
    return no_cumple($en_texto ? 'Los horarios están en el texto, pero no en el JSON-LD.' : 'Los horarios están en el JSON-LD, pero no en el texto.');
};
