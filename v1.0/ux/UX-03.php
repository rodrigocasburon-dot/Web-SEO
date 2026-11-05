<?php
// UX-03 Formularios con etiqueta: cada campo tiene label, aria-label o aria-labelledby.
return function (array $p): array {
    $campos = $p['xpath']->query('//input[not(@type="hidden" or @type="submit" or @type="button" or @type="reset" or @type="image")]|//select|//textarea');
    if ($campos->length === 0) {
        return no_aplica('La página no tiene campos de formulario.');
    }
    $sin_etiqueta = 0;
    $ejemplo = '';
    foreach ($campos as $campo) {
        $id = $campo->getAttribute('id');
        $con_label = $id !== '' && $p['xpath']->query('//label[@for="' . str_replace('"', '', $id) . '"]')->length > 0;
        $dentro = $p['xpath']->query('ancestor::label', $campo)->length > 0;
        if (!$con_label && !$dentro && trim($campo->getAttribute('aria-label') . $campo->getAttribute('aria-labelledby')) === '') {
            $sin_etiqueta++;
            $ejemplo = $ejemplo ?: ($campo->getAttribute('name') ?: $campo->nodeName);
        }
    }
    return $sin_etiqueta
        ? no_cumple("$sin_etiqueta de {$campos->length} campos no tienen etiqueta, como \"$ejemplo\".")
        : cumple("Los {$campos->length} campos tienen etiqueta.");
};
