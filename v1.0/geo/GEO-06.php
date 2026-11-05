<?php
// GEO-06 Dirección en texto: está escrita en la web, mejor dentro de address.
return function (array $p): array {
    if ($p['xpath']->query('//address')->length > 0) {
        return cumple('La dirección está dentro de una etiqueta address.');
    }
    return hay_direccion($p)
        ? cumple('Se ha encontrado una dirección en el texto.')
        : no_cumple('No se ha encontrado una dirección escrita en la web.');
};
