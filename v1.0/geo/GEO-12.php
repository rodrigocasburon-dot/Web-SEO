<?php
// GEO-12 Tablas reales: si hay listas de precios, usan table.
return function (array $p): array {
    if (hay_precios($p['texto']) < 3) {
        return no_aplica('No se detectan listas de precios.');
    }
    return $p['xpath']->query('//table')->length > 0
        ? cumple('Los precios conviven con una tabla HTML.')
        : aviso('Hay varios precios y ninguna tabla HTML.');
};
