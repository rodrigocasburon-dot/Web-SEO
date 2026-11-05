<?php
// UX-10 Sin reproducción automática: no hay autoplay con sonido, marquee ni blink.
return function (array $p): array {
    $sonido = $p['xpath']->query('//audio[@autoplay]|//video[@autoplay][not(@muted)]')->length;
    $moviendo = $p['xpath']->query('//marquee|//blink')->length;
    return $sonido || $moviendo
        ? aviso("$sonido elementos con reproducción automática y sonido, y $moviendo marquee o blink.")
        : cumple('No hay reproducción automática con sonido.');
};
