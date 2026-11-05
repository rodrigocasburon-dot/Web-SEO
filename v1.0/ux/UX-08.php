<?php
// UX-08 Objetivos táctiles: sin fallos de tamaño según PageSpeed.
return function (array $sitio): array {
    $auditoria = auditoria_pagespeed($sitio, 'target-size');
    if ($auditoria === null || $auditoria['score'] === null) {
        return sin_datos('Sin datos de PageSpeed.');
    }
    return (float) $auditoria['score'] === 1.0
        ? cumple('PageSpeed no detecta botones demasiado pequeños en la portada.')
        : aviso('PageSpeed detecta ' . count($auditoria['details']['items'] ?? []) . ' botones o enlaces demasiado pequeños o juntos.');
};
