<?php
// UX-13 Skip link: uno de los primeros enlaces salta al contenido principal.
return function (array $p): array {
    $enlaces = $p['xpath']->query('(//a[@href])[position()<=5]');
    foreach ($enlaces as $a) {
        $href = $a->getAttribute('href');
        if (str_starts_with($href, '#') && strlen($href) > 1
            && (preg_match('/main|content|contenido|principal/i', $href) || preg_match('/saltar|skip|ir al contenido/iu', $a->textContent))) {
            return cumple("Hay un enlace de salto: $href.");
        }
    }
    return aviso('No se ha encontrado un enlace para saltar al contenido.');
};
