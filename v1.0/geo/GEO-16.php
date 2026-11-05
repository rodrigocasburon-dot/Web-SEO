<?php
// GEO-16 Perfiles oficiales: el JSON-LD incluye sameAs con redes o directorios.
return function (array $pagina): array {
    if (!$pagina['jsonld']) {
        return ['estado' => 'no_cumple', 'detalle' => 'La página no tiene JSON-LD, así que no declara perfiles oficiales.'];
    }

    foreach ($pagina['jsonld'] as $objeto) {
        if (!empty($objeto['sameAs'])) {
            $perfiles = count((array) $objeto['sameAs']);
            return ['estado' => 'cumple', 'detalle' => "Perfiles declarados en sameAs: $perfiles."];
        }
    }

    return ['estado' => 'no_cumple', 'detalle' => 'El JSON-LD no incluye sameAs.'];
};
