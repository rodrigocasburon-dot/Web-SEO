<?php
// GEO-13 Preguntas y respuestas: encabezados con pregunta y una respuesta detrás.
return function (array $p): array {
    $total = preguntas_con_respuesta($p);
    return $total > 0
        ? cumple("Preguntas con su respuesta: $total.")
        : no_cumple('No se han encontrado encabezados con forma de pregunta y su respuesta.');
};
