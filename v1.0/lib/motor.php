<?php
// Ejecuta las comprobaciones de la checklist y calcula las notas.

const ESTADOS_VALIDOS = ['cumple', 'no_cumple', 'aviso', 'sin_datos', 'no_aplica'];

function cargar_checklist(): array
{
    return json_decode(file_get_contents(__DIR__ . '/../checklist/checklist.json'), true);
}

// Cada comprobación vive en seo/, geo/, ux/ o web/, en un archivo con el ID del punto.
function archivo_de_punto(string $id): string
{
    $carpeta = strtolower(strstr($id, '-', true));
    return __DIR__ . "/../$carpeta/$id.php";
}

// $datos es la página o, en los puntos que comparan páginas, el sitio completo.
function ejecutar_punto(array $punto, array $datos): array
{
    $archivo = archivo_de_punto($punto['id']);
    if (!is_file($archivo)) {
        return ['estado' => 'pendiente', 'detalle' => 'Esta comprobación aún no está programada.'];
    }
    // Un fallo en una comprobación no debe tumbar el análisis completo.
    try {
        $comprobacion = require $archivo;
        $resultado = $comprobacion($datos);
    } catch (Throwable $e) {
        return ['estado' => 'sin_datos', 'detalle' => 'Error en ' . basename($archivo) . ': ' . $e->getMessage()];
    }
    if (!in_array($resultado['estado'] ?? '', ESTADOS_VALIDOS, true)) {
        return ['estado' => 'sin_datos', 'detalle' => basename($archivo) . ' ha devuelto un estado no válido.'];
    }
    return ['estado' => $resultado['estado'], 'detalle' => (string) ($resultado['detalle'] ?? '')];
}

// Indica si un punto se comprueba al visitar una página concreta.
function toca_en_pagina(array $punto, bool $es_portada, int $codigo): bool
{
    if ($punto['alcance'] === 'comparacion' || $punto['metodo'] === 'pagespeed') {
        return false;
    }
    // Una página de error no tiene contenido que evaluar: solo cuenta su código.
    if ($codigo !== 200) {
        return $punto['id'] === 'SEO-01';
    }
    return $punto['alcance'] === 'pagina' || $punto['metodo'] === 'html' || $es_portada;
}

// Reúne los resultados de un punto en todas las páginas en un solo resultado con su valor.
// $filas es una lista de ['estado', 'detalle', 'url'].
function resumir_punto(array $punto, array $filas, array $valores): array
{
    $evaluadas = array_values(array_filter($filas, fn($f) => isset($valores[$f['estado']])));
    if (!$evaluadas) {
        $primera = $filas[0] ?? ['estado' => 'pendiente', 'detalle' => ''];
        return ['estado' => $primera['estado'], 'detalle' => $primera['detalle'], 'valor' => null, 'afectadas' => [], 'evaluadas' => 0];
    }

    // En los puntos de toda la web basta con que se cumpla en una página.
    if ($punto['alcance'] === 'dominio') {
        usort($evaluadas, fn($a, $b) => $valores[$b['estado']] <=> $valores[$a['estado']]);
        $mejor = $evaluadas[0];
        return ['estado' => $mejor['estado'], 'detalle' => $mejor['detalle'], 'valor' => $valores[$mejor['estado']], 'afectadas' => [], 'evaluadas' => 1];
    }

    $total = count($evaluadas);
    $valor = array_sum(array_map(fn($f) => $valores[$f['estado']], $evaluadas)) / $total;
    $fallos = array_values(array_filter($evaluadas, fn($f) => $f['estado'] !== 'cumple'));
    if (!$fallos) {
        return ['estado' => 'cumple', 'detalle' => $evaluadas[0]['detalle'], 'valor' => 1.0, 'afectadas' => [], 'evaluadas' => $total];
    }

    usort($fallos, fn($a, $b) => $valores[$a['estado']] <=> $valores[$b['estado']]);
    $detalle = $fallos[0]['detalle'];
    if ($total > 1) {
        $detalle = count($fallos) . " de $total páginas. Ejemplo: $detalle";
    }
    return ['estado' => $fallos[0]['estado'], 'detalle' => $detalle, 'valor' => $valor,
        'afectadas' => array_slice(array_column($fallos, 'url'), 0, 5), 'evaluadas' => $total];
}

// $resumen es un array de punto_id => resumen. Devuelve una nota por bloque, o null si no hay datos.
function calcular_notas(array $resumen, array $checklist): array
{
    $notas = [];
    foreach (['seo', 'geo', 'ux'] as $nota) {
        $conseguidos = 0;
        $posibles = 0;
        foreach ($checklist['puntos'] as $punto) {
            $valor = $resumen[$punto['id']]['valor'] ?? null;
            if ($valor !== null && in_array($nota, $punto['notas'], true)) {
                $peso = $checklist['pesos'][$punto['gravedad']];
                $conseguidos += $peso * $valor;
                $posibles += $peso;
            }
        }
        $notas[$nota] = $posibles ? (int) round($conseguidos / $posibles * 100) : null;
    }
    return $notas;
}
