<?php
// GEO-20 Contenido actualizado: la fecha tiene menos de 12 meses.
return function (array $p): array {
    $fecha = fecha_modificacion($p);
    if ($fecha === null) {
        return sin_datos('La página no indica ninguna fecha.');
    }
    $meses = (int) floor((time() - $fecha) / (30 * 86400));
    $texto = date('d/m/Y', $fecha);
    return $meses < 12
        ? cumple("Última fecha indicada: $texto.")
        : aviso("La última fecha indicada es de $texto, hace $meses meses.");
};
