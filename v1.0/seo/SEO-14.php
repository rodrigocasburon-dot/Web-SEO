<?php
// SEO-14 Idioma declarado: html tiene un atributo lang válido.
return function (array $p): array {
    $lang = trim((string) $p['xpath']->evaluate('string(/html/@lang)'));
    if ($lang === '') {
        return no_cumple('La etiqueta html no tiene atributo lang.');
    }
    return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/i', $lang)
        ? cumple("Idioma declarado: $lang.")
        : no_cumple("El atributo lang no es válido: $lang.");
};
