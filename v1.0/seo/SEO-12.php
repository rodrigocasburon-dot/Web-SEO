<?php
// SEO-12 Encabezados sin saltos: no se salta ningún nivel.
return function (array $p): array {
    $lista = encabezados($p);
    if (!$lista) {
        return no_aplica('La página no tiene encabezados.');
    }
    $anterior = 0;
    foreach ($lista as $h) {
        if ($anterior > 0 && $h['nivel'] > $anterior + 1) {
            return aviso("Hay un salto de H$anterior a H{$h['nivel']} en \"" . mb_strimwidth($h['texto'], 0, 80, '...') . '".');
        }
        $anterior = $h['nivel'];
    }
    return cumple('Los encabezados siguen un orden sin saltos.');
};
