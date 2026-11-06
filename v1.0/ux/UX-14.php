<?php
// UX-14 Aviso de cookies: se detecta y no llegan cookies de seguimiento antes de aceptar.
return function (array $p): array {
    $html = strtolower($p['html']);
    $seguimiento = preg_match('/googletagmanager|google-analytics|gtag\(|connect\.facebook|hotjar|clarity\.ms/', $html);
    $cookies = preg_match('/(^|\n)\s*(_ga|_gid|_fbp|_gcl)/i', $p['cabeceras']['set-cookie'] ?? '');
    $aviso = preg_match('/cookiebot|onetrust|cookieyes|complianz|didomi|iubenda|usercentrics|cookie-?law|cookie-?notice|cookie-?consent|cookie-?banner|borlabs|cmplz/', $html)
        || preg_match('/cookies?.{0,200}(aceptar|rechazar|accept|configurar)/isu', $p['texto']);
    if ($cookies) {
        return aviso('El servidor envía cookies de seguimiento antes de aceptar.');
    }
    if ($aviso) {
        return cumple('Se detecta un aviso de cookies.');
    }
    return $seguimiento
        ? aviso('La web usa herramientas de seguimiento y no se detecta aviso de cookies.')
        : no_aplica('No se detectan herramientas de seguimiento ni aviso de cookies.');
};
