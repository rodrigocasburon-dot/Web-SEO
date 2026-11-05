<?php
// GEO-02 Contenido en el HTML inicial: no depende de JavaScript. Es un indicador.
return function (array $p): array {
    $total = count(palabras($p['texto']));
    if ($total >= 50) {
        return cumple("El HTML inicial ya incluye $total palabras de texto.");
    }
    $scripts = $p['xpath']->query('//script[@src]')->length;
    return $scripts > 0
        ? aviso("El HTML inicial solo tiene $total palabras y carga $scripts scripts. El contenido podría depender de JavaScript.")
        : aviso("La página solo tiene $total palabras de texto.");
};
