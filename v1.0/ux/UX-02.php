<?php
// UX-02 Nombres accesibles: botones y enlaces tienen texto, aria-label o imagen con alt.
return function (array $p): array {
    $controles = $p['xpath']->query('//button|//a[@href]|//*[@role="button"]');
    if ($controles->length === 0) {
        return no_aplica('La página no tiene botones ni enlaces.');
    }
    $sin_nombre = 0;
    $ejemplo = '';
    foreach ($controles as $c) {
        $nombre = trim($c->textContent) . $c->getAttribute('aria-label') . $c->getAttribute('aria-labelledby') . $c->getAttribute('title');
        $imagen = $p['xpath']->query('.//img[normalize-space(@alt)!=""]|.//svg[@aria-label]|.//svg/title', $c)->length;
        if (trim($nombre) === '' && $imagen === 0) {
            $sin_nombre++;
            $ejemplo = $ejemplo ?: ($c->getAttribute('href') ?: $c->nodeName);
        }
    }
    return $sin_nombre
        ? no_cumple("$sin_nombre de {$controles->length} botones o enlaces no tienen nombre accesible, como $ejemplo.")
        : cumple("Los {$controles->length} botones y enlaces tienen nombre accesible.");
};
