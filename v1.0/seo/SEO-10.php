<?php
// SEO-10 Title y H1 coherentes: comparten palabras importantes. Es una aproximación.
return function (array $p): array {
    $title = palabras_utiles((string) $p['xpath']->evaluate('string(//head/title)'));
    $h1 = palabras_utiles((string) $p['xpath']->evaluate('string(//h1[1])'));
    if (!$title || !$h1) {
        return sin_datos('Falta el title o el H1 para compararlos.');
    }
    return array_intersect($title, $h1)
        ? cumple('El title y el H1 comparten palabras importantes.')
        : aviso('El title y el H1 no comparten ninguna palabra importante.');
};
