<?php
// UX-04 Foco visible: el CSS no elimina el foco sin dar una alternativa.
return function (array $p): array {
    if (trim($p['css']) === '') {
        return sin_datos('No se han podido leer los estilos de la web.');
    }
    if (str_contains($p['css'], ':focus-visible')) {
        return cumple('El CSS define estilos para :focus-visible.');
    }
    return preg_match('/[^{}]*:focus[^{}]*\{[^}]*outline(-width)?\s*:\s*(none|0)/i', $p['css'])
        ? aviso('El CSS elimina el foco con outline y no usa :focus-visible.')
        : cumple('El CSS no elimina el indicador de foco.');
};
