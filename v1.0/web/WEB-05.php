<?php
// WEB-05 Datos visibles y código iguales: el JSON-LD coincide con el texto visible.
return function (array $sitio): array {
    $telefono = '';
    $direccion = '';
    $visibles = [];
    $texto = '';
    foreach ($sitio['paginas'] as $pagina) {
        $datos = $pagina['datos'];
        $telefono = $telefono ?: ($datos['jsonld_telefono'] ?? '');
        $direccion = $direccion ?: ($datos['jsonld_direccion'] ?? '');
        $visibles = array_merge($visibles, $datos['telefonos'] ?? []);
        $texto .= ' ' . ($datos['texto_contacto'] ?? '');
    }
    if ($telefono === '' && $direccion === '') {
        return sin_datos('El JSON-LD no incluye teléfono ni dirección para comparar.');
    }
    $diferencias = [];
    if ($telefono !== '' && !array_intersect(telefonos_en($telefono), $visibles)) {
        $diferencias[] = "el teléfono $telefono";
    }
    if ($direccion !== '' && !str_contains($texto, mb_strtolower($direccion))) {
        $diferencias[] = "la dirección $direccion";
    }
    return $diferencias
        ? aviso('No se ha encontrado en el texto visible ' . implode(' ni ', $diferencias) . '.')
        : cumple('Los datos del JSON-LD coinciden con el texto visible.');
};
