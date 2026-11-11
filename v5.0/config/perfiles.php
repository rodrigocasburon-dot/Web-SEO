<?php
/**
 * Perfiles de lectura del informe (marketing, dirección, desarrollo web). Solo datos, sin secretos.
 *
 * El perfil NO cambia notas, estados, pesos ni datos medidos: solo el orden a igualdad de peso, el lenguaje,
 * el nivel de detalle y la acción sugerida (textos en lang/perfiles.php). Se valida con lista blanca en
 * src/Perfil.php; cualquier otro valor cae al perfil por defecto.
 */
return [
    // Perfil si no se elige ninguno: el más completo (conserva toda la información)
    'por_defecto' => 'desarrollo',

    // Orden en el selector
    'perfiles' => ['marketing', 'direccion', 'desarrollo'],

    // Rangos de nota (una sola definición para pantalla, resumen y PDF). Propuesta pendiente de validar con el
    // grupo. Se aplican a la nota redondeada a un decimal: «desde» incluido.
    'rangos' => [
        ['desde' => 90, 'clave' => 'muy_bueno', 'texto' => 'Muy bueno'],
        ['desde' => 75, 'clave' => 'bueno', 'texto' => 'Bueno'],
        ['desde' => 50, 'clave' => 'mejorable', 'texto' => 'Mejorable'],
        ['desde' => 0, 'clave' => 'critico', 'texto' => 'Crítico'],
    ],

    // Proporción de puntos sin datos a partir de la cual el resumen muestra un aviso visible
    'aviso_sin_datos' => 0.25,

    // Relevancia de cada punto para cada perfil (alta, media, baja). Solo desempata a igualdad de peso
    // (gravedad); nunca oculta ni rebaja un fallo. Propuesta pendiente de validar con el grupo.
    // Orden de las claves: marketing, direccion, desarrollo.
    'relevancia' => [
        'C01' => ['alta', 'alta', 'alta'],
        'C02' => ['alta', 'alta', 'alta'],
        'C03' => ['media', 'alta', 'alta'],
        'C04' => ['baja', 'alta', 'alta'],
        'C05' => ['media', 'baja', 'media'],
        'C06' => ['alta', 'media', 'media'],
        'C07' => ['baja', 'baja', 'media'],
        'C08' => ['media', 'media', 'media'],
        'C09' => ['alta', 'alta', 'alta'],
        'C10' => ['alta', 'media', 'baja'],
        'C11' => ['baja', 'baja', 'alta'],
        'C12' => ['media', 'media', 'alta'],
        'C13' => ['alta', 'media', 'media'],
        'C14' => ['alta', 'alta', 'alta'],
        'C15' => ['alta', 'baja', 'baja'],
        'C16' => ['alta', 'media', 'media'],
        'C18' => ['media', 'media', 'alta'],
        'C20' => ['alta', 'alta', 'alta'],
        'C21' => ['baja', 'baja', 'media'],
        'C22' => ['baja', 'baja', 'alta'],
        'C23' => ['media', 'media', 'baja'],
        'C24' => ['baja', 'baja', 'media'],
        'C25' => ['alta', 'alta', 'alta'],
        'C29' => ['alta', 'baja', 'baja'],
        'C30' => ['media', 'media', 'alta'],
        'C31' => ['media', 'baja', 'baja'],
        // Puntos de todo el sitio que no son una comprobación con ID
        'WEB-TITULOS' => ['alta', 'media', 'media'],
        'WEB-404' => ['media', 'baja', 'media'],
    ],
];
