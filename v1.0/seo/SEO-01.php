<?php
// SEO-01 Código 200: la página responde con código HTTP 200.
return function (array $p): array {
    return $p['codigo'] === 200
        ? cumple('La página responde con código 200.')
        : no_cumple("La página responde con código {$p['codigo']}.");
};
