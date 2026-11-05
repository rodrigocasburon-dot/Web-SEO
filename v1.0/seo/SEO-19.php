<?php
// SEO-19 Migas de pan: las páginas internas las tienen visibles o con BreadcrumbList.
return function (array $p): array {
    if ($p['es_portada']) {
        return no_aplica('La portada no necesita migas de pan.');
    }
    if (in_array('BreadcrumbList', tipos_jsonld($p), true)) {
        return cumple('La página tiene marcado BreadcrumbList.');
    }
    $visibles = $p['xpath']->query('//*[contains(translate(@class,"BREADCUMS","breadcums"),"breadcrumb") or contains(translate(@aria-label,"BREADCUMSIG","breadcumsig"),"breadcrumb") or contains(translate(@aria-label,"MIGAS","migas"),"migas")]');
    return $visibles->length > 0
        ? cumple('La página tiene migas de pan visibles.')
        : aviso('La página no tiene migas de pan.');
};
