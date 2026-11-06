<?php
// UX-28 Preparación para lector de pantalla: referencias ARIA, encabezados, enlaces y tablas.
return function (array $p): array {
    $ids = [];
    foreach ($p['xpath']->query('//*[@id]/@id') as $id) {
        $ids[$id->value] = true;
    }
    $rotas = 0;
    foreach ($p['xpath']->query('//@aria-labelledby|//@aria-describedby') as $referencia) {
        foreach (preg_split('/\s+/', trim($referencia->value), -1, PREG_SPLIT_NO_EMPTY) as $id) {
            $rotas += isset($ids[$id]) ? 0 : 1;
        }
    }
    $vacios = 0;
    foreach (encabezados($p) as $h) {
        $vacios += $h['texto'] === '' && $h['nodo']->getAttribute('aria-label') === ''
            && $p['xpath']->query('.//img[normalize-space(@alt)!=""]', $h['nodo'])->length === 0 ? 1 : 0;
    }
    $destinos = [];
    foreach ($p['xpath']->query('//a[@href][not(@aria-label) and not(@aria-labelledby)]') as $a) {
        if (preg_match('/^(leer más|ver más|saber más|más información|más info|click aquí|haz clic aquí|pincha aquí|aquí|read more|learn more|click here)$/iu', trim($a->textContent))) {
            $destinos[$a->getAttribute('href')] = true;
        }
    }
    $sin_rol = $p['xpath']->query('//*[(@role="presentation" or @role="none") and (self::a[@href] or self::button)]')->length;
    $sin_nombre = $p['xpath']->query('//*[@role="img"][not(@alt) and not(@aria-label) and not(@aria-labelledby) and not(title) and not(ancestor-or-self::*[@aria-hidden="true"])]')->length;
    $tablas = $p['xpath']->query('//table[not(@role="presentation")][count(.//tr)>1][not(.//th)]')->length;

    $problemas = array_filter([
        $rotas ? "$rotas referencias ARIA apuntan a un id que no existe" : '',
        $vacios ? "$vacios encabezados están vacíos" : '',
        count($destinos) >= 3 ? count($destinos) . ' enlaces distintos se anuncian con el mismo texto genérico' : '',
        $sin_rol ? "$sin_rol controles han perdido su rol" : '',
        $sin_nombre ? "$sin_nombre elementos con role=img no tienen nombre" : '',
        $tablas ? "$tablas tablas no tienen celdas de encabezado" : '',
    ]);
    return $problemas
        ? aviso(ucfirst(implode('; ', $problemas)) . '.')
        : cumple('La estructura que lee un lector de pantalla no tiene errores detectables en el código.');
};
