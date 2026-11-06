<?php
// GEO-21 Fuentes citadas: los textos largos enlazan a fuentes externas.
return function (array $p): array {
    if (count(palabras($p['texto_principal'])) < 300) {
        return no_aplica('La página no tiene un texto largo.');
    }
    $redes = '/(facebook|instagram|twitter|x\.com|linkedin|youtube|tiktok|whatsapp|wa\.me)/i';
    $externos = array_filter(enlaces_de($p), fn($e) => !$e['interno'] && !preg_match($redes, $e['url']));
    return $externos
        ? cumple('La página enlaza a ' . count($externos) . ' fuentes externas. No se evalúa su calidad.')
        : aviso('El texto largo no enlaza a fuentes externas.');
};
