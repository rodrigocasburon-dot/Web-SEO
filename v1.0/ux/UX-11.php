<?php
// UX-11 Movimiento reducido: si hay animaciones, incluye prefers-reduced-motion.
return function (array $p): array {
    if (trim($p['css']) === '') {
        return sin_datos('No se han podido leer los estilos de la web.');
    }
    if (!preg_match('/@keyframes|animation\s*:/i', $p['css'])) {
        return no_aplica('El CSS no tiene animaciones.');
    }
    return stripos($p['css'], 'prefers-reduced-motion') !== false
        ? cumple('El CSS tiene en cuenta prefers-reduced-motion.')
        : aviso('El CSS tiene animaciones y no usa prefers-reduced-motion.');
};
