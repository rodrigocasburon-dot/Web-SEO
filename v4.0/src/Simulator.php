<?php
/**
 * Simulador de mejora: estima cómo quedarían las notas si se corrigieran los N fallos que más
 * puntos restan. Es informativo: no cambia la puntuación del análisis.
 *
 * - No duplica la fórmula: puntúa con Scorer los mismos registros, con esos fallos pasados a CUMPLE.
 * - El orden de los fallos es el de Report::priorities() (penalización w × P de mayor a menor).
 * - Supuestos (se indican en la pantalla): cada fallo se corrige por completo y no aparecen otros;
 *   avisos, comprobaciones sin datos y las que no aplican se quedan como están.
 */
class Simulator
{
    public const DEFAULT_TOP = 3;
    public const MAX_TOP = 15;

    /**
     * @param array      $records registros de las comprobaciones (con id, estado, severidad, ocurrencias, pilares…)
     * @param int        $top     nº de fallos que se suponen corregidos (1 a MAX_TOP)
     * @param array|null $before  puntuación ya calculada de $records (si no, se calcula)
     */
    public static function simulate(array $records, int $top = self::DEFAULT_TOP, ?array $before = null, ?Scorer $scorer = null): array
    {
        $scorer = $scorer ?? new Scorer();
        $top = max(1, min(self::MAX_TOP, $top));
        $before = $before ?? $scorer->score($records);

        $fails = array_values(array_filter(Report::priorities($records), fn($p) => $p['estado'] === BaseCheck::FAIL));
        $chosen = array_slice($fails, 0, $top);
        $ids = array_column($chosen, 'id');

        $fixed = [];
        foreach ($records as $r) {
            if (in_array((string) ($r['id'] ?? ''), $ids, true)) {
                $r['estado'] = BaseCheck::OK;
                $r['ocurrencias'] = 0;
            }
            $fixed[] = $r;
        }
        $after = $scorer->score($fixed);

        $notas = [];
        foreach (Scorer::AREAS as $a) {
            $notas[$a] = self::pair($before['areas'][$a]['redondeada'], $after['areas'][$a]['redondeada']);
        }
        $notas['global'] = self::pair($before['global']['redondeada'], $after['global']['redondeada']);

        return [
            'n' => $top,
            'fallos_totales' => count($fails),
            'corregidos' => array_map(fn($p) => [
                'id' => $p['id'],
                'nombre' => $p['nombre'],
                'penalizacion' => $p['penalizacion'],
            ], $chosen),
            'notas' => $notas,
            'riesgo_alto' => ['antes' => (bool) $before['riesgo_alto'], 'despues' => (bool) $after['riesgo_alto']],
        ];
    }

    /** Antes, después y diferencia (calculada con los valores redondeados, para que cuadre lo que se ve). */
    private static function pair(float $before, float $after): array
    {
        return ['antes' => $before, 'despues' => $after, 'mejora' => round($after - $before, 1)];
    }
}
