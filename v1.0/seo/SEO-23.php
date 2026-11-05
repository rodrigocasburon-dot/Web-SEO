<?php
// SEO-23 Favicon: la web lo declara o existe /favicon.ico.
return function (array $p): array {
    if ($p['xpath']->query('//link[contains(translate(@rel,"ICON","icon"),"icon")]')->length > 0) {
        return cumple('La web declara un favicon.');
    }
    try {
        $respuesta = descargar(url_absoluta($p['url'], '/favicon.ico'), $p['config']);
    } catch (RuntimeException) {
        return sin_datos('No se ha podido comprobar /favicon.ico.');
    }
    return $respuesta['codigo'] === 200 ? cumple('Existe /favicon.ico.') : aviso('La web no tiene favicon.');
};
