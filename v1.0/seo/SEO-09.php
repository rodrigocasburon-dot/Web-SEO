<?php
// SEO-09 Meta descripción: existe con texto. La longitud solo se anota.
return function (array $p): array {
    $texto = trim((string) $p['xpath']->evaluate('string(//meta[translate(@name,"DESCRIPTON","descripton")="description"]/@content)'));
    if ($texto === '') {
        return no_cumple('La página no tiene meta descripción.');
    }
    $largo = mb_strlen($texto);
    $nota = ($largo < 120 || $largo > 160) ? ' Se recomiendan entre 120 y 160 caracteres.' : '';
    return cumple("Meta descripción de $largo caracteres.$nota");
};
