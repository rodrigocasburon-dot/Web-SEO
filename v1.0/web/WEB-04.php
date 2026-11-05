<?php
// WEB-04 NAP coherente: los datos de contacto coinciden entre páginas.
return function (array $sitio): array {
    $por_pagina = [];
    foreach ($sitio['paginas'] as $pagina) {
        if (!empty($pagina['datos']['telefonos'])) {
            $por_pagina[] = $pagina['datos']['telefonos'];
        }
    }
    if (count($por_pagina) < 2) {
        return sin_datos('El teléfono no aparece en suficientes páginas para compararlo.');
    }
    $comunes = array_intersect(...$por_pagina);
    return $comunes
        ? cumple('El teléfono coincide en las ' . count($por_pagina) . ' páginas donde aparece. El nombre y la dirección son revisión manual.')
        : aviso('No hay un teléfono común a todas las páginas donde aparece.');
};
