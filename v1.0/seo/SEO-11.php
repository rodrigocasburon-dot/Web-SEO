<?php
// SEO-11 H1 único: la página tiene un solo H1 con texto.
return function (array $p): array {
    $h1 = array_filter(encabezados($p), fn($h) => $h['nivel'] === 1 && $h['texto'] !== '');
    return match (count($h1)) {
        0 => aviso('La página no tiene H1 con texto.'),
        1 => cumple('La página tiene un solo H1: "' . reset($h1)['texto'] . '".'),
        default => aviso('La página tiene ' . count($h1) . ' H1.'),
    };
};
