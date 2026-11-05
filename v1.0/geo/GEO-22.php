<?php
// GEO-22 Listas en textos largos: usan ul, ol o table.
return function (array $p): array {
    if (count(palabras($p['texto_principal'])) < 300) {
        return no_aplica('La página no tiene un texto largo.');
    }
    return $p['xpath']->query('//main//ul|//main//ol|//article//ul|//article//ol|//table')->length > 0
        || $p['xpath']->query('//ul[not(ancestor::nav or ancestor::header or ancestor::footer)]|//ol[not(ancestor::nav or ancestor::header or ancestor::footer)]')->length > 0
        ? cumple('El texto usa listas o tablas.')
        : aviso('El texto largo no usa listas ni tablas.');
};
