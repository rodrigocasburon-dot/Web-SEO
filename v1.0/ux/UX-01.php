<?php
// UX-01 Estructura semántica: existen header, nav y main.
return function (array $p): array {
    $faltan = [];
    foreach (['header', 'nav', 'main'] as $etiqueta) {
        $rol = ['header' => 'banner', 'nav' => 'navigation', 'main' => 'main'][$etiqueta];
        if ($p['xpath']->query("//$etiqueta|//*[@role='$rol']")->length === 0) {
            $faltan[] = $etiqueta;
        }
    }
    return $faltan
        ? aviso('Faltan estas etiquetas semánticas: ' . implode(', ', $faltan) . '.')
        : cumple('La página usa header, nav y main.');
};
