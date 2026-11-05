<?php
// SEO-03 Sin contenido mixto: ningún recurso se carga por http en una página https.
return function (array $p): array {
    if (!str_starts_with($p['url'], 'https://')) {
        return no_aplica('La página no usa HTTPS.');
    }
    $recursos = $p['xpath']->query('//img/@src|//script/@src|//iframe/@src|//source/@src|//video/@src|//audio/@src|//link[@rel="stylesheet"]/@href');
    $inseguros = [];
    foreach ($recursos as $r) {
        if (stripos(trim($r->value), 'http://') === 0) {
            $inseguros[] = trim($r->value);
        }
    }
    return $inseguros
        ? no_cumple(count($inseguros) . " recursos se cargan por http://, como $inseguros[0].")
        : cumple('Todos los recursos se cargan de forma segura.');
};
