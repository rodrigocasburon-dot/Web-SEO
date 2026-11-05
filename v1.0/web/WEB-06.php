<?php
// WEB-06 Páginas bien enlazadas: se alcanzan en 3 clics o menos desde la portada.
return function (array $sitio): array {
    $lejanas = array_filter($sitio['paginas'], fn($p) => (int) $p['profundidad'] > 3);
    if ($lejanas) {
        $ejemplo = reset($lejanas)['url'];
        return aviso(count($lejanas) . " páginas necesitan más de 3 clics desde la portada, como $ejemplo.");
    }
    $maxima = max(array_map('intval', array_column($sitio['paginas'], 'profundidad')));
    return cumple("Todas las páginas analizadas están a $maxima clics o menos de la portada.");
};
