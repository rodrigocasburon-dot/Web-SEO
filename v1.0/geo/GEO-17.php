<?php
// GEO-17 Teléfono con un toque: si hay teléfono, tiene enlace tel:.
return function (array $p): array {
    if (!telefonos_en($p['texto'])) {
        return no_aplica('La página no muestra teléfono.');
    }
    return $p['xpath']->query('//a[starts-with(@href,"tel:")]')->length > 0
        ? cumple('El teléfono tiene enlace tel:.')
        : no_cumple('El teléfono no tiene enlace tel:.');
};
