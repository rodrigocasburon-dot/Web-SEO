<?php
/**
 * Calcula las notas por área y la global a partir de los registros de las comprobaciones.
 *
 * - Cada área (seo, ia, accesibilidad) parte de 100.
 * - Solo NO_CUMPLE penaliza: w × P, con P según severidad y w = 1 + ln(k) (k = ocurrencias).
 * - Un fallo resta una sola vez; su penalización se reparte entre áreas según 'pilares' (config/weights.php).
 * - Área = máx(0, 100 − Σ). Global = 0,35 SEO + 0,30 IA + 0,35 Accesibilidad.
 * - Riesgo alto: algún área < 50 o al menos dos fallos críticos.
 * - SIN_DATOS y "no aplica" no penalizan y se listan aparte. Si más de la mitad de las
 *   comprobaciones que aplican a un área son SIN_DATOS, la nota del área es incompleta.
 */
class Scorer
{
    public const AREAS = ['seo', 'ia', 'accesibilidad'];
    public const PENALTIES = ['CRITICO' => 15, 'ALTO' => 8, 'MEDIO' => 4, 'BAJO' => 1];
    public const GLOBAL_WEIGHTS = ['seo' => 0.35, 'ia' => 0.30, 'accesibilidad' => 0.35];
    public const RISK_AREA_THRESHOLD = 50;
    public const RISK_CRITICAL_FAILURES = 2;

    private array $matrix;

    /** @param array|null $matrix matriz de reparto (por defecto config/weights.php); se usa si un registro no trae 'pilares' */
    public function __construct(?array $matrix = null)
    {
        $this->matrix = $matrix ?? (array) require __DIR__ . '/../config/weights.php';
    }

    /** Peso por repetición: w = 1 + ln(k), con k ≥ 1. */
    public static function repetitionWeight(int $k): float
    {
        return 1 + log(max(1, $k));
    }

    public function score(array $records): array
    {
        $areas = [];
        foreach (self::AREAS as $a) {
            $areas[$a] = [
                'puntuacion' => 100.0,
                'penalizacion' => 0.0,
                'descuentos' => [],
                'comprobaciones' => 0,
                'sin_datos' => 0,
                'incompleta' => false,
            ];
        }

        $criticalFailures = [];
        $noData = [];
        $notApplicable = [];

        foreach ($records as $r) {
            $id = (string) ($r['id'] ?? '');
            $estado = (string) ($r['estado'] ?? 'SIN_DATOS');
            $shares = $this->shares($r);
            $applies = ($r['aplica'] ?? true) !== false;

            if (!$applies) {
                $notApplicable[] = $id;
                continue;
            }
            if ($estado === BaseCheck::NO_DATA) {
                $noData[] = $id;
            }

            foreach (self::AREAS as $a) {
                if ($shares[$a] <= 0) {
                    continue;
                }
                $areas[$a]['comprobaciones']++;
                if ($estado === BaseCheck::NO_DATA) {
                    $areas[$a]['sin_datos']++;
                }
            }

            if ($estado !== BaseCheck::FAIL) {
                continue;
            }
            $severity = (string) ($r['severidad'] ?? '');
            if (!isset(self::PENALTIES[$severity])) {
                throw new InvalidArgumentException("Severidad desconocida en $id: $severity");
            }
            if ($severity === 'CRITICO') {
                $criticalFailures[] = $id;
            }
            $k = max(1, (int) ($r['ocurrencias'] ?? 1));
            $w = self::repetitionWeight($k);
            $p = self::PENALTIES[$severity];
            foreach (self::AREAS as $a) {
                if ($shares[$a] <= 0) {
                    continue;
                }
                $d = $w * $p * $shares[$a];
                $areas[$a]['penalizacion'] += $d;
                $areas[$a]['descuentos'][] = [
                    'id' => $id, 'severidad' => $severity, 'k' => $k, 'w' => $w, 'p' => $p,
                    'reparto' => $shares[$a], 'descuento' => $d,
                ];
            }
        }

        $global = 0.0;
        $anyIncomplete = false;
        $lowAreas = [];
        foreach (self::AREAS as $a) {
            $areas[$a]['puntuacion'] = max(0.0, 100.0 - $areas[$a]['penalizacion']);
            $areas[$a]['incompleta'] = $areas[$a]['comprobaciones'] > 0
                && $areas[$a]['sin_datos'] > $areas[$a]['comprobaciones'] / 2;
            $areas[$a]['redondeada'] = round($areas[$a]['puntuacion'], 1);
            $anyIncomplete = $anyIncomplete || $areas[$a]['incompleta'];
            $global += self::GLOBAL_WEIGHTS[$a] * $areas[$a]['puntuacion'];
            if ($areas[$a]['puntuacion'] < self::RISK_AREA_THRESHOLD) {
                $lowAreas[] = $a;
            }
        }

        $reasons = [];
        if ($lowAreas) {
            $reasons[] = 'Área(s) por debajo de ' . self::RISK_AREA_THRESHOLD . ': ' . implode(', ', array_map(
                fn($a) => Lang::all()['areas'][$a] ?? $a, $lowAreas
            ));
        }
        if (count($criticalFailures) >= self::RISK_CRITICAL_FAILURES) {
            $reasons[] = count($criticalFailures) . ' fallos críticos: ' . implode(', ', $criticalFailures);
        }

        return [
            'areas' => $areas,
            'global' => [
                'puntuacion' => $global,
                'redondeada' => round($global, 1),
                // Supuesto: la global es incompleta si lo es alguna de sus áreas
                'incompleta' => $anyIncomplete,
            ],
            'riesgo_alto' => (bool) $reasons,
            'riesgo_mensaje' => $reasons ? (string) (Lang::all()['riesgo_alto'] ?? 'Riesgo alto: revisar de inmediato') : '',
            'riesgo_motivos' => $reasons,
            'fallos_criticos' => $criticalFailures,
            'sin_datos' => $noData,
            'no_aplica' => $notApplicable,
        ];
    }

    /** Reparto del registro entre áreas; valida que esté entre 0 y 1 y sume 1. */
    private function shares(array $r): array
    {
        $p = $r['pilares'] ?? null;
        if (!is_array($p)) {
            $row = $this->matrix[$r['id'] ?? ''] ?? [0, 0, 0];
            $p = ['seo' => $row[0], 'ia' => $row[1], 'accesibilidad' => $row[2]];
        }
        $out = [];
        foreach (self::AREAS as $a) {
            $out[$a] = (float) ($p[$a] ?? 0);
            if ($out[$a] < 0 || $out[$a] > 1) {
                throw new InvalidArgumentException('Reparto fuera de rango en ' . ($r['id'] ?? '?'));
            }
        }
        $sum = array_sum($out);
        if ($sum > 0 && abs($sum - 1) > 1e-6) {
            throw new InvalidArgumentException('El reparto de ' . ($r['id'] ?? '?') . " suma $sum, no 1");
        }
        return $out;
    }
}
