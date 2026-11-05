<?php
// SEO-13 Viewport: existe con width=device-width.
return function (array $p): array {
    $viewport = (string) $p['xpath']->evaluate('string(//meta[translate(@name,"VIEWPORT","viewport")="viewport"]/@content)');
    return stripos(str_replace(' ', '', $viewport), 'width=device-width') !== false
        ? cumple('La página tiene viewport con width=device-width.')
        : no_cumple($viewport === '' ? 'La página no tiene viewport.' : "El viewport no usa width=device-width: $viewport.");
};
