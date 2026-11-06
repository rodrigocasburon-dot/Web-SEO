<?php
// UX-20 Calidad del texto alt: sin textos genéricos, archivos, redundancias ni repeticiones.
return function (array $p): array {
    $imagenes = $p['xpath']->query('//img[normalize-space(@alt)!=""]');
    if ($imagenes->length === 0) {
        return no_aplica('La página no tiene imágenes con texto alternativo.');
    }
    $malos = [];
    $por_texto = [];
    foreach ($imagenes as $img) {
        $alt = trim(preg_replace('/\s+/u', ' ', $img->getAttribute('alt')));
        $por_texto[mb_strtolower($alt)][$img->getAttribute('src')] = true;
        if (preg_match('/^(imagen|image|img|foto|fotografía|photo|picture|icono|icon|logo|banner|gráfico|sin título|untitled)$/iu', $alt)
            || preg_match('/^(imagen|foto|fotografía|image|picture|photo) (de|del|of)\b/iu', $alt)
            || preg_match('/\.(jpe?g|png|gif|webp|svg|avif)$/i', $alt)
            || preg_match('/^[\w-]*[_-][\w-]*\d[\w-]*$/u', $alt)
            || mb_strlen($alt) > 150) {
            $malos[] = $alt;
        }
    }
    foreach ($por_texto as $alt => $fuentes) {
        if (count($fuentes) >= 3) {
            $malos[] = "$alt (repetido en " . count($fuentes) . ' imágenes distintas)';
        }
    }
    return $malos
        ? aviso('Textos alternativos poco útiles: ' . count($malos) . '. Por ejemplo, "' . mb_strimwidth($malos[0], 0, 80, '...') . '".')
        : cumple("No se detectan textos genéricos entre los {$imagenes->length} textos alternativos.");
};
