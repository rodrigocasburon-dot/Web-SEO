<?php
// UX-12 Acción principal: hay un enlace claro de contacto, compra, cita o cómo llegar.
return function (array $p): array {
    if ($p['xpath']->query('//a[starts-with(@href,"tel:") or starts-with(@href,"mailto:") or contains(@href,"wa.me")]')->length > 0) {
        return cumple('La página tiene un enlace directo de contacto.');
    }
    foreach ($p['xpath']->query('//a|//button') as $control) {
        if (preg_match('/contact|llam|cita|reserv|compr|presupuesto|c[oó]mo llegar|solicita|demo|pedir/iu', $control->textContent)) {
            return cumple('Acción principal encontrada: "' . trim($control->textContent) . '".');
        }
    }
    return aviso('No se ha encontrado una acción principal clara.');
};
