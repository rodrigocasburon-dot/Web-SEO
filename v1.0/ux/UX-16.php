<?php
// UX-16 Título en iframes: cada iframe tiene title.
return function (array $pagina): array {
    $iframes = $pagina['xpath']->query('//iframe');
    if ($iframes->length === 0) {
        return ['estado' => 'no_aplica', 'detalle' => 'La página no tiene iframes.'];
    }

    $sin_titulo = $pagina['xpath']->query('//iframe[not(@title) or normalize-space(@title)=""]');
    if ($sin_titulo->length > 0) {
        return ['estado' => 'no_cumple', 'detalle' => "{$sin_titulo->length} de {$iframes->length} iframes no tienen title."];
    }

    return ['estado' => 'cumple', 'detalle' => "Los {$iframes->length} iframes tienen title."];
};
