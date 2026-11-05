<?php
// SEO-17 Enlaces rastreables y descriptivos: a href reales con texto no genérico.
return function (array $p): array {
    $total = $p['xpath']->query('//a')->length;
    if ($total === 0) {
        return no_aplica('La página no tiene enlaces.');
    }
    $falsos = $p['xpath']->query('//a[not(@href) or starts-with(@href,"javascript:")]')->length;
    $genericos = [];
    foreach ($p['xpath']->query('//a[@href]') as $a) {
        $texto = mb_strtolower(trim($a->textContent));
        if (preg_match('/^(clic aqu[ií]|click aqu[ií]|haz clic( aqu[ií])?|pincha aqu[ií]|aqu[ií]|leer m[aá]s|ver m[aá]s|m[aá]s informaci[oó]n|saber m[aá]s|click here|read more|more)$/u', $texto)) {
            $genericos[] = $texto;
        }
    }
    if ($falsos || $genericos) {
        $ejemplo = $genericos ? ', como "' . $genericos[0] . '"' : '';
        return aviso("Enlaces no rastreables: $falsos. Con texto genérico: " . count($genericos) . "$ejemplo.");
    }
    return cumple("Los $total enlaces son rastreables y descriptivos.");
};
