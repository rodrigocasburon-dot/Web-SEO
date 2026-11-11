<?php
// Normalización de las notas por área (aprobada por el grupo el 11/11/2026; docs/CALIBRACION.md).
//
// Cada área resta Σ(w × P × reparto) multiplicado por un factor = referencia / resta_maxima, para que las tres
// tengan la misma escala: con todas sus comprobaciones fallando una vez, cada área restaría lo mismo (la referencia).
// - resta_maxima: Σ P(gravedad por defecto) × reparto de las comprobaciones que pueden restar (C29 y C31 solo avisan).
//   tests/scorer_test.php comprueba que coincide con el catálogo: si se añade o cambia una comprobación, hay que
//   recalcularla (php tests/calibracion.php --maximos) y volver a calibrar.
// - referencia: resta de referencia por área, fijada en la calibración (punto de partida: 60, la media de las tres).
// - version: se guarda con cada análisis del historial para no comparar notas calculadas con escalas distintas.
return [
    'version' => 'n1-2026-11-11',
    'referencia' => ['seo' => 60.0, 'ia' => 60.0, 'accesibilidad' => 60.0],
    'resta_maxima' => ['seo' => 73.1, 'ia' => 45.5, 'accesibilidad' => 61.4],
];
