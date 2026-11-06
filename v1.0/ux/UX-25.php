<?php
// UX-25 Alternativas al arrastre: carruseles y elementos arrastrables tienen botones.
return function (array $p): array {
    $clases = "concat(' ', translate(@class, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'))";
    $nombres = [' swiper', ' carousel', ' owl-carousel', ' slick-', ' splide', ' glide', ' flickity', ' keen-slider', ' slider'];
    $condicion = implode(' or ', array_map(fn($n) => "contains($clases, '$n')", $nombres));
    if ($p['xpath']->query("//*[$condicion]|//*[@draggable=\"true\"]")->length === 0) {
        return no_aplica('No se detectan carruseles ni elementos arrastrables.');
    }
    $flechas = ['-next', '-prev', '-arrow', '-control', '-nav', '__arrow', '__nav'];
    $botones = implode(' or ', array_map(fn($f) => "contains(@class, '$f')", $flechas));
    $etiqueta = "translate(@aria-label, 'ASPN', 'aspn')";
    $nombrados = implode(' or ', array_map(fn($t) => "contains($etiqueta, '$t')", ['anterior', 'siguiente', 'next', 'prev']));
    if ($p['xpath']->query("//*[$botones or $nombrados]")->length > 0) {
        return cumple('Los carruseles o elementos arrastrables tienen botones equivalentes.');
    }
    // Estas librerías crean las flechas con JavaScript, así que no están en el HTML.
    return preg_match('/slick|owl\.?carousel|owl-carousel|splide|glide|flickity/i', $p['html'])
        ? sin_datos('El carrusel crea sus botones con JavaScript y no se pueden ver en el HTML.')
        : aviso('Hay un carrusel o elemento arrastrable y no se detectan botones equivalentes.');
};
