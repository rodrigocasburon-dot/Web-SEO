<?php
// WEB-03 Enlaces rotos: los enlaces no devuelven 404 ni 410.
return function (array $sitio): array {
    $conocidos = [];
    $enlaces = [];
    foreach ($sitio['paginas'] as $pagina) {
        $conocidos[$pagina['url']] = (int) $pagina['codigo'];
        foreach ($pagina['datos']['enlaces'] ?? [] as $url) {
            $enlaces[strtok($url, '#')] = true;
        }
    }
    if (!$enlaces) {
        return no_aplica('La web no tiene enlaces que comprobar.');
    }

    // Las páginas ya visitadas no se piden otra vez.
    $rotos = array_keys(array_filter(array_intersect_key($conocidos, $enlaces), fn($c) => in_array($c, [404, 410], true)));
    $maximo = $sitio['config']['enlaces_maximos'];
    $resto = array_keys(array_diff_key($enlaces, $conocidos));
    foreach (comprobar_enlaces(array_slice($resto, 0, $maximo), $sitio['config']) as $url => $codigo) {
        if (in_array($codigo, [404, 410], true)) {
            $rotos[] = $url;
        }
    }
    $revisados = count(array_intersect_key($conocidos, $enlaces)) + min(count($resto), $maximo);
    return $rotos
        ? no_cumple(count($rotos) . " enlaces rotos de $revisados revisados, como $rotos[0].")
        : cumple("Sin enlaces rotos en $revisados enlaces revisados de " . count($enlaces) . '.');
};
