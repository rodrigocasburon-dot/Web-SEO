<?php
// UX-22 Foco no tapado: si hay un menú fijo, el CSS reserva espacio con scroll-padding.
return function (array $p): array {
    if (trim($p['css']) === '') {
        return sin_datos('No se han podido leer los estilos de la web.');
    }
    $fijo = null;
    foreach (reglas_css(preg_replace('#/\*.*?\*/#s', '', $p['css']) ?? $p['css']) as [$selector, $declaraciones]) {
        if (preg_match('/header|nav|menu|topbar|cabecera|sticky/i', $selector)
            && preg_match('/position\s*:\s*(fixed|sticky)/i', $declaraciones)
            && !preg_match('/(^|;|\s)bottom\s*:/i', $declaraciones)) {
            $fijo = $selector;
            break;
        }
    }
    if ($fijo === null) {
        return no_aplica('No se detecta ningún menú fijo que pueda tapar el foco.');
    }
    $fijo = mb_strimwidth($fijo, 0, 60, '...');
    return preg_match('/scroll-(padding|margin)(-top)?\s*:/i', $p['css'])
        ? cumple("Hay un menú fijo ($fijo) y el CSS reserva espacio al desplazarse.")
        : aviso("El menú fijo ($fijo) puede tapar el elemento activo: el CSS no usa scroll-padding-top.");
};
