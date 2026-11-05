<?php
// GEO-01 Bots de IA permitidos: robots.txt no bloquea a los bots de búsqueda de IA.
return function (array $p): array {
    if ($p['robots'] === null) {
        return cumple('La web no tiene robots.txt, así que no bloquea a ningún bot de IA.');
    }
    $bloquea = fn($bot) => !robots_permite($p['robots'], $bot, '/');
    $busqueda = array_filter($p['config']['bots_ia_busqueda'], $bloquea);
    $entrenamiento = array_filter($p['config']['bots_ia_entrenamiento'], $bloquea);
    if ($busqueda) {
        return no_cumple('robots.txt bloquea bots de búsqueda de IA: ' . implode(', ', $busqueda) . '.');
    }
    if ($entrenamiento) {
        return aviso('robots.txt bloquea bots de entrenamiento: ' . implode(', ', $entrenamiento) . '. Puede ser intencionado.');
    }
    return cumple('robots.txt permite a los bots de IA comprobados.');
};
