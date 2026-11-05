<?php
// WEB-02 Redirecciones: las permanentes usan 301 o 308, sin cadenas innecesarias.
return function (array $sitio): array {
    $temporales = 0;
    $cadenas = 0;
    $revisadas = 0;
    foreach ($sitio['paginas'] as $pagina) {
        $saltos = $pagina['datos']['saltos'] ?? [];
        $revisadas += $saltos ? 1 : 0;
        $cadenas += count($saltos) > 1 ? 1 : 0;
        foreach ($saltos as $salto) {
            $temporales += in_array($salto['codigo'], [302, 303, 307], true) ? 1 : 0;
        }
    }
    if ($revisadas === 0) {
        return no_aplica('Las páginas analizadas no tienen redirecciones.');
    }
    return $temporales || $cadenas
        ? aviso("$temporales redirecciones temporales y $cadenas cadenas de redirecciones.")
        : cumple("Las $revisadas redirecciones son permanentes y directas.");
};
