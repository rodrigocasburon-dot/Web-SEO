<?php
// SEO-02 HTTPS y certificado: usa https con certificado válido y http redirige a él.
return function (array $p): array {
    if (!str_starts_with($p['url'], 'https://')) {
        return no_cumple('La web no usa HTTPS.');
    }
    $http = 'http://' . parse_url($p['url'], PHP_URL_HOST) . '/';
    try {
        $respuesta = descargar($http, $p['config']);
    } catch (RuntimeException $e) {
        return cumple('La web usa HTTPS con certificado válido. La versión http:// no responde.');
    }
    return str_starts_with($respuesta['url'], 'https://')
        ? cumple('La web usa HTTPS con certificado válido y la versión http:// redirige a ella.')
        : no_cumple('La versión http:// no redirige a HTTPS.');
};
