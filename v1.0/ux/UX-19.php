<?php
// UX-19 ID sin duplicar: ningún id se repite en la página.
return function (array $p): array {
    $ids = [];
    foreach ($p['xpath']->query('//*[@id]/@id') as $id) {
        $ids[] = $id->value;
    }
    $repetidos = array_keys(array_filter(array_count_values($ids), fn($n) => $n > 1));
    return $repetidos
        ? no_cumple(count($repetidos) . ' id repetidos, como "' . $repetidos[0] . '".')
        : cumple('Ningún id se repite.');
};
