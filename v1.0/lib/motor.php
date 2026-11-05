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

function ejecutar_punto(array $punto, array $pagina): array
{
    if ($punto['solo_local'] && !$pagina['es_local']) {
        return ['estado' => 'no_aplica', 'detalle' => 'Solo cuenta en negocios locales.'];
    }
    $archivo = archivo_de_punto($punto['id']);
    if (!is_file($archivo)) {
        return ['estado' => 'pendiente', 'detalle' => 'Esta comprobación aún no está programada.'];
    }
    // Un fallo en una comprobación no debe tumbar el análisis completo.
    try {
        $comprobacion = require $archivo;
        $resultado = $comprobacion($pagina);
    } catch (Throwable $e) {
        return ['estado' => 'sin_datos', 'detalle' => 'Error en ' . basename($archivo) . ': ' . $e->getMessage()];
    }
    if (!in_array($resultado['estado'] ?? '', ESTADOS_VALIDOS, true)) {
        return ['estado' => 'sin_datos', 'detalle' => basename($archivo) . ' ha devuelto un estado no válido.'];
    }
    return ['estado' => $resultado['estado'], 'detalle' => (string) ($resultado['detalle'] ?? '')];
}

// $resultados es un array de punto_id => estado. Devuelve una nota por bloque, o null si no hay datos.
function calcular_notas(array $resultados, array $checklist): array
{
    $notas = [];
    foreach (['seo', 'geo', 'ux'] as $nota) {
        $conseguidos = 0;
        $posibles = 0;
        foreach ($checklist['puntos'] as $punto) {
            $valor = $checklist['valor_resultado'][$resultados[$punto['id']] ?? ''] ?? null;
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
