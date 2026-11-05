<?php
// GEO-14 Marcado FAQPage: si hay preguntas frecuentes, existe FAQPage en el JSON-LD.
return function (array $p): array {
    if (preguntas_con_respuesta($p) < 2) {
        return no_aplica('La página no tiene preguntas frecuentes.');
    }
    return in_array('FAQPage', tipos_jsonld($p), true)
        ? cumple('Las preguntas están marcadas con FAQPage.')
        : no_cumple('Hay preguntas frecuentes sin marcado FAQPage.');
};
