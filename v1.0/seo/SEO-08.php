<?php
// SEO-08 Title: hay exactamente un title no vacío. La longitud solo se anota.
return function (array $pagina): array {
    $titulos = $pagina['xpath']->query('//head/title');

    if ($titulos->length === 0) {
        return ['estado' => 'no_cumple', 'detalle' => 'La página no tiene title.'];
    }
    if ($titulos->length > 1) {
        return ['estado' => 'no_cumple', 'detalle' => "La página tiene {$titulos->length} etiquetas title."];
    }

    $texto = trim($titulos->item(0)->textContent);
    if ($texto === '') {
        return ['estado' => 'no_cumple', 'detalle' => 'El title está vacío.'];
    }

    $largo = mb_strlen($texto);
    $nota = ($largo < 35 || $largo > 60) ? ' Se recomiendan entre 35 y 60 caracteres.' : '';
    return ['estado' => 'cumple', 'detalle' => "Title: \"$texto\" ($largo caracteres).$nota"];
};
