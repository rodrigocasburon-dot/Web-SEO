<?php
// UX-05 Controles usables con teclado: sin tabindex positivos ni clics falsos.
return function (array $p): array {
    $positivos = 0;
    foreach ($p['xpath']->query('//*[@tabindex]') as $nodo) {
        $positivos += (int) $nodo->getAttribute('tabindex') > 0 ? 1 : 0;
    }
    $falsos = $p['xpath']->query('//div[@onclick][not(@role) or not(@tabindex)]|//span[@onclick][not(@role) or not(@tabindex)]')->length;
    return $positivos || $falsos
        ? aviso("$positivos elementos con tabindex positivo y $falsos elementos con clic sin rol ni tabindex.")
        : cumple('No hay tabindex positivos ni elementos con clic sin rol.');
};
