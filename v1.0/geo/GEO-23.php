<?php
// GEO-23 Respuesta tras el encabezado: el primer párrafo tiene entre 30 y 150 palabras.
return function (array $p): array {
    $revisados = 0;
    $buenos = 0;
    foreach (encabezados($p) as $h) {
        if ($h['nivel'] !== 2 && $h['nivel'] !== 3) {
            continue;
        }
        $siguiente = $h['nodo']->nextSibling;
        while ($siguiente && $siguiente->nodeType !== XML_ELEMENT_NODE) {
            $siguiente = $siguiente->nextSibling;
        }
        if ($siguiente && $siguiente->nodeName === 'p') {
            $revisados++;
            $largo = count(palabras($siguiente->textContent));
            $buenos += ($largo >= 30 && $largo <= 150) ? 1 : 0;
        }
    }
    if ($revisados === 0) {
        return no_aplica('No hay párrafos justo después de los encabezados.');
    }
    return $buenos * 2 >= $revisados
        ? cumple("$buenos de $revisados secciones empiezan con un párrafo de buena longitud.")
        : aviso("Solo $buenos de $revisados secciones empiezan con un párrafo de 30 a 150 palabras.");
};
