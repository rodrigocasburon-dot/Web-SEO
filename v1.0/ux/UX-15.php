<?php
// UX-15 Subtítulos en vídeo: cada video tiene track.
return function (array $p): array {
    $videos = $p['xpath']->query('//video');
    if ($videos->length === 0) {
        return no_aplica('La página no tiene etiquetas video. Los vídeos incrustados son revisión manual.');
    }
    $sin_pista = $p['xpath']->query('//video[not(track)]')->length;
    return $sin_pista
        ? no_cumple("$sin_pista de {$videos->length} vídeos no tienen subtítulos.")
        : cumple("Los {$videos->length} vídeos declaran subtítulos.");
};
