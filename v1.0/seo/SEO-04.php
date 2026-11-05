<?php
// SEO-04 Sin noindex: ni en meta robots, ni en meta googlebot, ni en X-Robots-Tag.
return function (array $p): array {
    if (stripos($p['cabeceras']['x-robots-tag'] ?? '', 'noindex') !== false) {
        return aviso('La cabecera X-Robots-Tag tiene noindex. Puede ser intencionado.');
    }
    foreach ($p['xpath']->query('//meta[translate(@name,"ROBTSGL","robtsgl")="robots" or translate(@name,"ROBTSGL","robtsgl")="googlebot"]/@content') as $meta) {
        if (stripos($meta->value, 'noindex') !== false) {
            return aviso('La página tiene noindex en una etiqueta meta. Puede ser intencionado.');
        }
    }
    return cumple('La página no tiene noindex.');
};
