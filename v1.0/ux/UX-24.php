<?php
// UX-24 Mensajes de error: los formularios explican los errores con texto.
return function (array $p): array {
    $formularios = 0;
    $sin_mensaje = 0;
    foreach ($p['xpath']->query('//form') as $form) {
        if ($p['xpath']->query('.//*[@required or @aria-required="true"]', $form)->length === 0) {
            continue;
        }
        $formularios++;
        // Sin novalidate, el propio navegador explica el error con texto.
        if (!$form->hasAttribute('novalidate')) {
            continue;
        }
        $mensajes = './/*[@role="alert" or @role="status" or @aria-live or @aria-describedby or @aria-errormessage or contains(@class, "error") or contains(@class, "invalid") or contains(@class, "response-output")]';
        if ($p['xpath']->query($mensajes, $form)->length === 0) {
            $sin_mensaje++;
        }
    }
    if ($formularios === 0) {
        return no_aplica('La página no tiene formularios con campos obligatorios.');
    }
    return $sin_mensaje
        ? aviso("$sin_mensaje de $formularios formularios desactivan la validación del navegador y no preparan una zona para mensajes de error.")
        : cumple("Formularios revisados: $formularios. Todos pueden explicar los errores con texto.");
};
