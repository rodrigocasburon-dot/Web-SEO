<?php
// WEB-09 Core Web Vitals: LCP, INP y CLS. Datos de campo si existen; si no, de laboratorio.
return function (array $sitio): array {
    if (!$sitio['pagespeed']) {
        return sin_datos('Sin datos de PageSpeed.');
    }
    $campo = $sitio['pagespeed']['loadingExperience']['metrics'] ?? [];
    $lcp = $campo['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ?? null;
    $inp = $campo['INTERACTION_TO_NEXT_PAINT']['percentile'] ?? null;
    $cls = isset($campo['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile']) ? $campo['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] / 100 : null;
    $origen = 'datos de campo';
    if ($lcp === null) {
        $lcp = auditoria_pagespeed($sitio, 'largest-contentful-paint')['numericValue'] ?? null;
        $cls = auditoria_pagespeed($sitio, 'cumulative-layout-shift')['numericValue'] ?? null;
        $origen = 'datos de laboratorio';
    }
    if ($lcp === null) {
        return sin_datos('PageSpeed no ha devuelto métricas.');
    }
    $textos = ['LCP ' . round($lcp / 1000, 1) . ' s'];
    $textos[] = $inp === null ? 'INP sin datos' : "INP $inp ms";
    $textos[] = 'CLS ' . round((float) $cls, 2);
    $resumen = implode(', ', $textos) . " ($origen).";
    return $lcp <= 2500 && ($inp === null || $inp <= 200) && $cls <= 0.1 ? cumple($resumen) : no_cumple($resumen);
};
