<?php
// GEO-19 Autoría y fecha: hay autor o empresa, y fecha de modificación.
return function (array $p): array {
    $autor = campo_jsonld($p, 'author') !== null || campo_jsonld($p, 'publisher') !== null
        || trim((string) $p['xpath']->evaluate('string(//meta[@name="author"]/@content)')) !== '';
    $fecha = fecha_modificacion($p) !== null;
    if ($autor && $fecha) {
        return cumple('La página indica autor o empresa, y fecha.');
    }
    return aviso($autor ? 'La página no indica fecha en sus metadatos.' : ($fecha ? 'La página no indica autor ni empresa en sus metadatos.' : 'La página no indica autor ni fecha en sus metadatos.'));
};
