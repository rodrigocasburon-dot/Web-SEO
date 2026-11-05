<?php
// UX-06 Contraste de texto: sin fallos según PageSpeed.
return function (array $sitio): array {
    $auditoria = auditoria_pagespeed($sitio, 'color-contrast');
    if ($auditoria === null || $auditoria['score'] === null) {
        return sin_datos('Sin datos de PageSpeed.');
    }
    if ((float) $auditoria['score'] === 1.0) {
        return cumple('PageSpeed no detecta fallos de contraste en la portada.');
    }
    $fallos = count($auditoria['details']['items'] ?? []);
    return no_cumple("PageSpeed detecta $fallos elementos con contraste insuficiente en la portada.");
};
