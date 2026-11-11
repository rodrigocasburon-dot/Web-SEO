<?php
/**
 * Indicador de fiabilidad de las notas. NO cambia las notas, los pesos ni los criterios: solo dice cuánto
 * de lo que la nota podía medir se ha medido de verdad.
 *
 * Una nota de 100 con todas las comprobaciones evaluadas no significa lo mismo que una de 100 con la mitad
 * «sin datos» (que no restan puntos). Este indicador lo hace visible con tres datos calculados:
 *
 *  - evaluados / posibles: comprobaciones que aplican y tienen resultado (CUMPLE, NO_CUMPLE o AVISO) sobre
 *    las que aplican. Las que no aplican no cuentan. Cada «punto» es una comprobación (como Report::coverage).
 *  - sin_datos: posibles − evaluados (incluye las pendientes de PageSpeed y los errores internos).
 *  - peso_evaluado (%): la misma proporción, pero ponderada con lo que cada comprobación puede restar a la
 *    nota: P de su gravedad (Scorer::PENALTIES) × su reparto en el área (config/weights.php) y, en la global,
 *    × el peso del área (Scorer::GLOBAL_WEIGHTS). Así, quedarse sin una comprobación crítica pesa más que
 *    quedarse sin una baja. No usa las repeticiones (k), que solo se conocen cuando hay un fallo.
 *  - estado del análisis: «completo» (todo lo que aplica está evaluado y el HTML se leyó entero), «parcial»
 *    (falta algo: ver motivos) o «bloqueado» (el sitio impidió el acceso: no hay notas).
 *
 * No se inventan umbrales: el nivel (alta / media / baja) solo se calcula si la configuración define
 * 'fiabilidad' => ['alta' => …, 'media' => …] (porcentaje mínimo de peso evaluado). En config.example.php
 * figuran comentados, como PROPUESTA pendiente de validar por el grupo.
 */
class Reliability
{
    public const COMPLETE = 'completo';
    public const PARTIAL = 'parcial';
    public const BLOCKED = 'bloqueado';

    /**
     * @param array $checks  registros de las comprobaciones
     * @param bool  $blocked el sitio bloqueó el acceso
     * @param array $context 'truncado' (bool): el HTML se cortó al llegar al límite;
     *                       'pendientes' (string[]): comprobaciones previstas que aún no están en $checks
     *                       (C08 y C14 mientras no llega PageSpeed); 'umbrales' (array): ver arriba
     */
    public static function build(array $checks, bool $blocked = false, array $context = []): array
    {
        // Comprobaciones previstas que todavía no tienen registro: cuentan como posibles y sin datos
        $present = [];
        foreach ($checks as $c) {
            $present[(string) ($c['id'] ?? '')] = true;
        }
        $pending = [];
        $catalog = null;
        foreach ((array) ($context['pendientes'] ?? []) as $id) {
            if (!is_string($id) || isset($present[$id])) {
                continue;
            }
            $catalog = $catalog ?? CheckRunner::catalog();
            if (isset($catalog[$id])) {
                $checks[] = $catalog[$id]->describe(BaseCheck::NO_DATA);
                $pending[] = $id;
            }
        }

        $zero = ['evaluados' => 0, 'posibles' => 0, 'sin_datos' => 0, 'peso' => 0.0, 'peso_total' => 0.0];
        $global = $zero;
        $areas = [];
        foreach (Scorer::AREAS as $a) {
            $areas[$a] = $zero;
        }
        $noDataIds = [];
        $internalErrors = 0;
        $notApplicable = 0;

        foreach ($checks as $c) {
            if (($c['aplica'] ?? true) === false) {
                $notApplicable++;
                continue;
            }
            $evaluated = ($c['estado'] ?? BaseCheck::NO_DATA) !== BaseCheck::NO_DATA;
            $p = (float) (Scorer::PENALTIES[$c['severidad'] ?? ''] ?? 0);
            $global['posibles']++;
            if ($evaluated) {
                $global['evaluados']++;
            } else {
                $noDataIds[] = (string) ($c['id'] ?? '');
                if (!empty($c['error_interno'])) {
                    $internalErrors++;
                }
            }
            foreach (Scorer::AREAS as $a) {
                $share = (float) ($c['pilares'][$a] ?? 0);
                if ($share <= 0) {
                    continue;
                }
                $areas[$a]['posibles']++;
                $areas[$a]['peso_total'] += $p * $share;
                $global['peso_total'] += Scorer::GLOBAL_WEIGHTS[$a] * $p * $share;
                if ($evaluated) {
                    $areas[$a]['evaluados']++;
                    $areas[$a]['peso'] += $p * $share;
                    $global['peso'] += Scorer::GLOBAL_WEIGHTS[$a] * $p * $share;
                }
            }
        }

        $truncated = !empty($context['truncado']);
        $reasons = [];
        if ($blocked) {
            $reasons[] = 'El sitio ha bloqueado el acceso automático: no se ha podido evaluar el contenido.';
        }
        $psiMissing = array_values(array_intersect(History::PSI_IDS, $noDataIds));
        $otherMissing = array_values(array_diff($noDataIds, $psiMissing));
        if ($psiMissing) {
            $reasons[] = 'Sin datos de PageSpeed Insights (' . implode(', ', $psiMissing) . ').';
        }
        if ($otherMissing && !$blocked) {
            $reasons[] = count($otherMissing) === 1
                ? 'Una comprobación sin datos (' . $otherMissing[0] . ').'
                : count($otherMissing) . ' comprobaciones sin datos (' . implode(', ', $otherMissing) . ').';
        }
        if ($internalErrors > 0) {
            $reasons[] = $internalErrors === 1
                ? 'Una comprobación no se pudo evaluar por un error interno de la herramienta.'
                : $internalErrors . ' comprobaciones no se pudieron evaluar por un error interno de la herramienta.';
        }
        if ($truncated) {
            $reasons[] = 'El HTML se truncó al llegar al límite de tamaño: lo evaluado corresponde solo a la parte leída.';
        }

        $missing = $global['posibles'] - $global['evaluados'];
        $state = $blocked ? self::BLOCKED : ($missing > 0 || $truncated ? self::PARTIAL : self::COMPLETE);
        $thresholds = (array) ($context['umbrales'] ?? []);
        $out = [
            'estado' => $state,
            'motivos' => $reasons,
            'global' => self::finish($global, $blocked, $thresholds),
            'areas' => [],
            'sin_datos_ids' => $noDataIds,
            'pendientes' => $pending,
            'errores_internos' => $internalErrors,
            'no_aplican' => $notApplicable,
            'truncado' => $truncated,
        ];
        foreach (Scorer::AREAS as $a) {
            $out['areas'][$a] = self::finish($areas[$a], $blocked, $thresholds);
        }
        return $out;
    }

    /**
     * Fiabilidad de un análisis de sitio (nota media de una muestra de páginas).
     *
     * @param array $pages  portada y páginas CON NOTA, sin duplicados: cada una con 'checks'
     * @param array $counts 'previstas' (portada + muestra), 'bloqueadas', 'errores', 'sin_resultado', 'duplicadas'
     */
    public static function site(array $pages, bool $homeBlocked, array $counts, array $thresholds = []): array
    {
        $sum = ['evaluados' => 0, 'posibles' => 0];
        foreach ($pages as $p) {
            foreach ((array) ($p['checks'] ?? []) as $c) {
                if (($c['aplica'] ?? true) === false) {
                    continue;
                }
                $sum['posibles']++;
                if (($c['estado'] ?? BaseCheck::NO_DATA) !== BaseCheck::NO_DATA) {
                    $sum['evaluados']++;
                }
            }
        }
        $withScore = count($pages);
        $planned = max(0, (int) ($counts['previstas'] ?? $withScore));
        $duplicates = max(0, (int) ($counts['duplicadas'] ?? 0));
        $blockedPages = max(0, (int) ($counts['bloqueadas'] ?? 0));
        $errors = max(0, (int) ($counts['errores'] ?? 0));
        $missing = max(0, (int) ($counts['sin_resultado'] ?? 0));
        // Dos URL de la muestra que acaban en la misma página cuentan una vez: no son páginas perdidas
        $expected = max($withScore, $planned - $duplicates);
        $noData = $sum['posibles'] - $sum['evaluados'];

        $reasons = [];
        if ($homeBlocked) {
            $reasons[] = 'La portada ha bloqueado el acceso automático: no hay notas.';
        }
        if (!$homeBlocked && $withScore < $expected) {
            $reasons[] = ($expected - $withScore) . ' de las ' . $expected . ' páginas previstas no tienen nota'
                . ' (bloqueadas: ' . $blockedPages . '; no descargadas: ' . $errors . '; sin resultado en el servidor: ' . $missing
                . '; no analizadas: ' . max(0, $expected - $withScore - $blockedPages - $errors - $missing) . ').';
        }
        if (!$homeBlocked && $noData > 0) {
            $reasons[] = $noData . ' de ' . $sum['posibles'] . ' comprobaciones de las páginas con nota no tienen datos.';
        }
        $state = $homeBlocked ? self::BLOCKED : ($withScore < $expected || $noData > 0 ? self::PARTIAL : self::COMPLETE);
        $pct = $sum['posibles'] > 0 ? round(100 * $sum['evaluados'] / $sum['posibles'], 1) : null;
        return [
            'estado' => $state,
            'motivos' => $reasons,
            'global' => [
                'evaluados' => $sum['evaluados'],
                'posibles' => $sum['posibles'],
                'sin_datos' => $noData,
                'porcentaje' => $pct,
                // En el modo sitio no se pondera por gravedad: las páginas llegan resumidas, sin reparto por área
                'peso_evaluado' => null,
                'nivel' => self::level($homeBlocked ? null : $pct, $thresholds),
            ],
            'paginas' => [
                'con_nota' => $withScore,
                'previstas' => $expected,
                'bloqueadas' => $blockedPages,
                'errores' => $errors,
                'sin_resultado' => $missing,
            ],
        ];
    }

    private static function finish(array $t, bool $blocked, array $thresholds): array
    {
        $weight = $t['peso_total'] > 0 ? round(100 * $t['peso'] / $t['peso_total'], 1) : null;
        return [
            'evaluados' => $t['evaluados'],
            'posibles' => $t['posibles'],
            'sin_datos' => $t['posibles'] - $t['evaluados'],
            'porcentaje' => $t['posibles'] > 0 ? round(100 * $t['evaluados'] / $t['posibles'], 1) : null,
            'peso_evaluado' => $weight,
            'nivel' => self::level($blocked ? null : $weight, $thresholds),
        ];
    }

    /** Nivel según los umbrales de la configuración; null si no hay umbrales válidos (no se inventan). */
    public static function level(?float $percent, array $thresholds): ?string
    {
        $high = $thresholds['alta'] ?? null;
        $mid = $thresholds['media'] ?? null;
        if ($percent === null || !is_numeric($high) || !is_numeric($mid) || $mid > $high) {
            return null;
        }
        return $percent >= (float) $high ? 'alta' : ($percent >= (float) $mid ? 'media' : 'baja');
    }

    /** Resumen para las columnas del historial: estado, evaluados y posibles. */
    public static function forHistory(?array $reliability): ?array
    {
        if (!is_array($reliability) || !isset($reliability['estado'], $reliability['global'])) {
            return null;
        }
        return [
            'estado' => (string) $reliability['estado'],
            'evaluados' => (int) ($reliability['global']['evaluados'] ?? 0),
            'posibles' => (int) ($reliability['global']['posibles'] ?? 0),
        ];
    }
}
