<?php
// UX-23 Zoom y reflow: el diseño se adapta y los contenedores no tienen anchos fijos grandes.
return function (array $p): array {
    if (trim($p['css']) === '') {
        return sin_datos('No se han podido leer los estilos de la web.');
    }
    if (!preg_match('/@media[^{]*(min|max)-width/i', $p['css'])) {
        return aviso('El CSS no tiene reglas para pantallas estrechas: al ampliar puede aparecer scroll horizontal.');
    }
    foreach (reglas_css(css_base($p['css'])) as [$selector, $declaraciones]) {
        if (!preg_match('/(^|,)\s*(html|body|main|[.#]?(wrapper|wrap|container|page|site|content))\s*(,|$)/i', $selector)) {
            continue;
        }
        if (preg_match('/(?:^|[;\s])(?:min-)?width\s*:\s*(\d+)px/i', $declaraciones, $m) && (int) $m[1] > 320
            && !preg_match('/max-width/i', $declaraciones)) {
            return aviso(mb_strimwidth($selector, 0, 50, '...') . " tiene un ancho fijo de {$m[1]} px: al ampliar al 200 % no cabrá.");
        }
    }
    return cumple('El CSS se adapta a pantallas estrechas y no se detectan contenedores de ancho fijo.');
};
