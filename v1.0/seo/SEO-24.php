<?php
// SEO-24 Ubicación del negocio: enlace a Google Maps o al Perfil de Empresa.
return function (array $p): array {
    $patron = '#(google\.[a-z.]+/maps|maps\.google\.|goo\.gl/maps|maps\.app\.goo\.gl|g\.page/)#i';
    foreach ($p['xpath']->query('//a/@href|//iframe/@src') as $enlace) {
        if (preg_match($patron, $enlace->value)) {
            return cumple('La web enlaza a Google Maps.');
        }
    }
    return aviso('No se ha encontrado un enlace a Google Maps ni al Perfil de Empresa.');
};
