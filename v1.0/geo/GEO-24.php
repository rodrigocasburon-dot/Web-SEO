<?php
// GEO-24 Texto útil frente a código: la proporción no es muy baja. Es orientativo.
return function (array $p): array {
    $codigo = strlen($p['html']);
    if ($codigo === 0) {
        return sin_datos('La página está vacía.');
    }
    $porcentaje = round(strlen($p['texto']) / $codigo * 100, 1);
    return $porcentaje >= 2
        ? cumple("El texto supone el $porcentaje % del HTML.")
        : aviso("El texto solo supone el $porcentaje % del HTML.");
};
