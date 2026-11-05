<?php
// SEO-25 Servicio y localidad: el texto menciona la localidad del negocio.
return function (array $p): array {
    $localidad = campo_jsonld($p, 'addressLocality');
    if (!is_string($localidad) || $localidad === '') {
        return sin_datos('El JSON-LD no indica la localidad, así que no se puede buscar en el texto.');
    }
    return mb_stripos($p['texto_principal'], $localidad) !== false
        ? cumple("El contenido menciona la localidad: $localidad.")
        : aviso("El contenido no menciona la localidad: $localidad.");
};
