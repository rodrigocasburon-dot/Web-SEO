<?php
// WEB-01 Página de error 404: una URL inventada responde 404 o 410.
return function (array $sitio): array {
    try {
        $respuesta = descargar(url_absoluta($sitio['url'], '/pagina-que-no-existe-' . bin2hex(random_bytes(4))), $sitio['config']);
    } catch (RuntimeException) {
        return sin_datos('No se ha podido comprobar la página de error.');
    }
    return in_array($respuesta['codigo'], [404, 410], true)
        ? cumple("Una dirección inexistente responde {$respuesta['codigo']}.")
        : no_cumple("Una dirección inexistente responde {$respuesta['codigo']}.");
};
