<?php
// UX-07 Tamaño de letra: el tamaño base del CSS es de 16 px o más.
return function (array $p): array {
    if (trim($p['css']) === '') {
        return sin_datos('No se han podido leer los estilos de la web.');
    }
    $leer = function (string $etiqueta) use ($p): ?array {
        $patron = '/(?:^|[},\s])' . $etiqueta . '\s*\{[^}]*?font-size\s*:\s*([\d.]+)(px|rem|em|%)/i';
        return preg_match($patron, $p['css'], $m) ? [(float) $m[1], strtolower($m[2])] : null;
    };
    $a_px = fn(array $tamano, float $base) => match ($tamano[1]) {
        'px' => $tamano[0],
        '%' => $tamano[0] / 100 * $base,
        default => $tamano[0] * $base,
    };
    $html = $leer('html');
    $body = $leer('body');
    if (!$html && !$body) {
        return sin_datos('El CSS no fija un tamaño base. El navegador usa 16 px por defecto.');
    }
    // El body hereda del html: un html al 62,5 % con body a 1,6rem son 16 px.
    $base = $html ? $a_px($html, 16) : 16;
    $tamano = round($body ? $a_px($body, $base) : $base, 1);
    return $tamano >= 16
        ? cumple("El tamaño base es de $tamano px.")
        : aviso("El tamaño base es de $tamano px. Se recomiendan 16 px.");
};
