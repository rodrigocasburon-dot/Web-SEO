<?php
// UX-21 Recorrido con teclado: nada enfocable oculto, diálogos con salida y sin trampas de Tab.
return function (array $p): array {
    $enfocable = 'self::a[@href] or self::button or self::input or self::select or self::textarea';
    if ($p['xpath']->query("//*[$enfocable]")->length === 0) {
        return no_aplica('La página no tiene elementos que reciban el foco.');
    }
    $problemas = [];
    $ocultos = $p['xpath']->query("//*[@aria-hidden=\"true\"]/descendant-or-self::*[$enfocable][not(@tabindex=\"-1\") and not(@disabled)]")->length;
    if ($ocultos) {
        $problemas[] = "$ocultos elementos reciben el foco dentro de una zona oculta con aria-hidden";
    }
    $sin_salida = 0;
    foreach ($p['xpath']->query('//dialog|//*[@role="dialog" or @role="alertdialog"]') as $dialogo) {
        if (trim($dialogo->textContent) !== '' && $p['xpath']->query(".//*[$enfocable]", $dialogo)->length === 0) {
            $sin_salida++;
        }
    }
    if ($sin_salida) {
        $problemas[] = "$sin_salida diálogos no tienen ningún control para salir";
    }
    foreach ($p['xpath']->query('//script[not(@src)]') as $script) {
        $codigo = $script->textContent;
        if (preg_match('/keydown.{0,300}(keyCode\s*===?\s*9|key\s*===?\s*[\'"]Tab[\'"]).{0,300}preventDefault/s', $codigo)
            && !preg_match('/Escape|keyCode\s*===?\s*27/', $codigo)) {
            $problemas[] = 'un script bloquea la tecla Tab sin permitir salir con Escape';
            break;
        }
    }
    return $problemas
        ? aviso(ucfirst(implode('; ', $problemas)) . '.')
        : cumple('No se detectan trampas de foco en el código.');
};
