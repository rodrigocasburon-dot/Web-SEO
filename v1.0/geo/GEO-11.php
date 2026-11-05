<?php
// GEO-11 Subtítulos H2 y H3: las páginas largas se dividen con subtítulos.
return function (array $p): array {
    if (count(palabras($p['texto_principal'])) < 300) {
        return no_aplica('La página no tiene contenido largo.');
    }
    $h2 = count(array_filter(encabezados($p), fn($h) => $h['nivel'] === 2));
    return $h2 > 0
        ? cumple("El contenido se divide con $h2 subtítulos H2.")
        : aviso('El contenido es largo y no tiene subtítulos H2.');
};
