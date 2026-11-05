<?php
// GEO-18 Descripción natural de fotos: los alt son frases, no archivos ni números.
return function (array $p): array {
    $alts = $p['xpath']->query('//img[@alt and normalize-space(@alt)!=""]/@alt');
    if ($alts->length === 0) {
        return no_aplica('La página no tiene imágenes con texto alternativo.');
    }
    $malos = [];
    foreach ($alts as $alt) {
        $texto = trim($alt->value);
        if (preg_match('/\.(jpe?g|png|gif|webp|svg)$/i', $texto) || preg_match('/^(img|image|imagen|foto|dsc)?[\s_-]*\d+$/i', $texto) || substr_count($texto, ',') >= 3) {
            $malos[] = $texto;
        }
    }
    return $malos
        ? aviso(count($malos) . " de {$alts->length} textos alternativos no parecen frases, como \"$malos[0]\".")
        : cumple("Los {$alts->length} textos alternativos parecen descripciones.");
};
