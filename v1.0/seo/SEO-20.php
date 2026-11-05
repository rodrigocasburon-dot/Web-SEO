<?php
// SEO-20 Hreflang: si hay versiones en otros idiomas, se enlazan con hreflang.
return function (array $p): array {
    $enlaces = $p['xpath']->query('//link[@rel="alternate"][@hreflang]');
    if ($enlaces->length === 0) {
        return no_aplica('No se detectan versiones en otros idiomas.');
    }
    foreach ($enlaces as $enlace) {
        $codigo = $enlace->getAttribute('hreflang');
        if (!preg_match('/^(x-default|[a-z]{2,3}(-[a-z0-9]{2,4})?)$/i', $codigo)) {
            return aviso("El código hreflang no es válido: $codigo.");
        }
    }
    return cumple("La página declara {$enlaces->length} versiones con hreflang.");
};
