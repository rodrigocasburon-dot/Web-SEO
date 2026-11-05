<?php
// WEB-07 Menú consistente: nav tiene los mismos enlaces en todas las páginas.
return function (array $sitio): array {
    $menus = [];
    foreach ($sitio['paginas'] as $pagina) {
        if ((int) $pagina['codigo'] === 200) {
            $lista = $pagina['datos']['nav'] ?? [];
            sort($lista);
            $menus[] = implode('|', $lista);
        }
    }
    if (count($menus) < 2) {
        return sin_datos('Hacen falta varias páginas para comparar el menú.');
    }
    $conteo = array_count_values($menus);
    arsort($conteo);
    if (array_key_first($conteo) === '') {
        return aviso('La mayoría de páginas no tienen menú en una etiqueta nav.');
    }
    $iguales = reset($conteo);
    return $iguales / count($menus) >= 0.8
        ? cumple("El menú coincide en $iguales de " . count($menus) . ' páginas.')
        : aviso("El menú solo coincide en $iguales de " . count($menus) . ' páginas.');
};
