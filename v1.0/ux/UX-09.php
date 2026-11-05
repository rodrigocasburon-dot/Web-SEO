<?php
// UX-09 Lenguaje sencillo: Flesch-Szigriszt de 55 o más. Solo en español.
return function (array $p): array {
    $lang = strtolower((string) $p['xpath']->evaluate('string(/html/@lang)'));
    if ($lang !== '' && !str_starts_with($lang, 'es')) {
        return no_aplica('El índice solo funciona con textos en español.');
    }
    $parrafos = '';
    foreach ($p['xpath']->query('//p[not(ancestor::nav or ancestor::footer or ancestor::header)]') as $parrafo) {
        $texto = trim($parrafo->textContent);
        $parrafos .= $texto . (preg_match('/[.!?:]$/u', $texto) ? ' ' : '. ');
    }
    if (count(palabras($parrafos)) < 100) {
        return no_aplica('Hay muy pocos párrafos para medir la legibilidad.');
    }
    $indice = round((float) flesch_szigriszt($parrafos));
    return $indice >= 55
        ? cumple("Índice de legibilidad: $indice.")
        : aviso("Índice de legibilidad: $indice. Se recomienda 55 o más.");
};
