<?php
// SEO-15 Atributo alt: todas las imágenes lo tienen. Vacío vale para las decorativas.
return function (array $p): array {
    $total = $p['xpath']->query('//img')->length;
    if ($total === 0) {
        return no_aplica('La página no tiene imágenes.');
    }
    $sin_alt = $p['xpath']->query('//img[not(@alt)]');
    if ($sin_alt->length > 0) {
        $src = $sin_alt->item(0)->getAttribute('src');
        $ejemplo = str_starts_with($src, 'data:') ? 'una imagen incrustada' : mb_strimwidth($src, 0, 80, '...');
        return no_cumple("{$sin_alt->length} de $total imágenes no tienen alt, como $ejemplo.");
    }
    return cumple("Las $total imágenes tienen atributo alt.");
};
