<?php
// WEB-10 Respuesta del servidor: el primer byte llega en menos de 600 ms.
return function (array $pagina): array {
    $tiempo = $pagina['tiempo_ms'];
    if ($tiempo <= 0) {
        return ['estado' => 'sin_datos', 'detalle' => 'No se ha podido medir el tiempo de respuesta.'];
    }
    if ($tiempo >= 600) {
        return ['estado' => 'aviso', 'detalle' => "El servidor ha tardado $tiempo ms. La medición varía según la red."];
    }
    return ['estado' => 'cumple', 'detalle' => "El servidor ha respondido en $tiempo ms."];
};
