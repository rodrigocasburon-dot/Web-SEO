<?php
// WEB-08 Títulos únicos: ninguna página repite el title de otra.
return function (array $sitio): array {
    $titulos = [];
    foreach ($sitio['paginas'] as $pagina) {
        $titulo = $pagina['datos']['title'] ?? '';
        if ($titulo !== '' && (int) $pagina['codigo'] === 200) {
            $titulos[] = $titulo;
        }
    }
    if (count($titulos) < 2) {
        return sin_datos('Hacen falta varias páginas para comparar los títulos.');
    }
    $repetidos = array_keys(array_filter(array_count_values($titulos), fn($n) => $n > 1));
    return $repetidos
        ? aviso(count($repetidos) . ' títulos se repiten en varias páginas, como "' . $repetidos[0] . '".')
        : cumple('Los ' . count($titulos) . ' títulos son distintos.');
};
