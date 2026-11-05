<?php
// SEO-18 JSON-LD válido: sintaxis correcta y @type definido.
return function (array $p): array {
    if ($p['jsonld_errores'] > 0) {
        return no_cumple("{$p['jsonld_errores']} bloques JSON-LD tienen errores de sintaxis.");
    }
    if (!$p['jsonld']) {
        return no_cumple('La página no tiene JSON-LD.');
    }
    foreach ($p['jsonld'] as $objeto) {
        if (empty($objeto['@type'])) {
            return no_cumple('Hay un bloque JSON-LD sin @type.');
        }
    }
    return cumple('JSON-LD válido con estos tipos: ' . implode(', ', array_slice(tipos_jsonld($p), 0, 6)) . '.');
};
