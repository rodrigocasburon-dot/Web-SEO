<?php
// SEO-22 Sin relleno de palabras: ninguna palabra importante supera el 3 % del texto.
return function (array $p): array {
    $todas = palabras($p['texto_principal']);
    if (count($todas) < 200) {
        return no_aplica('El texto es demasiado corto para medir la densidad.');
    }
    $conteo = array_count_values(palabras_utiles($p['texto_principal']));
    arsort($conteo);
    $palabra = (string) array_key_first($conteo);
    $densidad = round($conteo[$palabra] / count($todas) * 100, 1);
    return $densidad > 3
        ? aviso("La palabra \"$palabra\" supone el $densidad % del texto.")
        : cumple("La palabra más repetida es \"$palabra\", con el $densidad % del texto.");
};
