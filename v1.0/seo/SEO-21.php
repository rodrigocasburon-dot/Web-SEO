<?php
// SEO-21 Contenido suficiente: al menos 300 palabras. Es orientativo.
return function (array $p): array {
    $total = count(palabras($p['texto_principal']));
    return $total >= 300
        ? cumple("El contenido principal tiene $total palabras.")
        : aviso("El contenido principal tiene $total palabras. Se recomiendan al menos 300.");
};
