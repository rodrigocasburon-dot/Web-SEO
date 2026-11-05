<?php
// SEO-16 URL amigable: legible, con guiones y sin cadenas largas de parámetros.
return function (array $p): array {
    $ruta = rawurldecode(parse_url($p['url'], PHP_URL_PATH) ?? '/');
    parse_str(parse_url($p['url'], PHP_URL_QUERY) ?? '', $parametros);
    if (count($parametros) > 2) {
        return aviso('La URL tiene ' . count($parametros) . ' parámetros.');
    }
    if (str_contains($ruta, '_')) {
        return aviso('La URL separa palabras con guiones bajos en lugar de guiones.');
    }
    if (preg_match('/[A-Z]/', $ruta)) {
        return aviso('La URL usa mayúsculas.');
    }
    if (mb_strlen($ruta) > 100) {
        return aviso('La URL es muy larga.');
    }
    return cumple('La URL es legible.');
};
