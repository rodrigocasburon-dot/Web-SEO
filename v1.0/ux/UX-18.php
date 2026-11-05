<?php
// UX-18 Zoom permitido: el viewport no bloquea el zoom.
return function (array $p): array {
    $viewport = strtolower(str_replace(' ', '', (string) $p['xpath']->evaluate('string(//meta[translate(@name,"VIEWPORT","viewport")="viewport"]/@content)')));
    if ($viewport === '') {
        return no_aplica('La página no tiene viewport.');
    }
    $bloqueado = preg_match('/user-scalable=(no|0)/', $viewport) || (preg_match('/maximum-scale=([\d.]+)/', $viewport, $m) && (float) $m[1] < 2);
    return $bloqueado
        ? no_cumple("El viewport bloquea el zoom: $viewport.")
        : cumple('El viewport permite ampliar la página.');
};
