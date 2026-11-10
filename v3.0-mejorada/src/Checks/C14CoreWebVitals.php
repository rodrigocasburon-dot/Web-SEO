<?php
/**
 * C14 Core Web Vitals con datos de campo (CrUX) de la propia URL, tal como los devuelve
 * PageSpeed Insights en loadingExperience (percentil 75, últimos 28 días).
 *
 * - Sin datos de la URL (solo del origen o ninguno) → SIN_DATOS: los datos de laboratorio no sustituyen
 *   a los de campo y los del origen no son de esta página.
 * - Evaluación: BUENO si todas las métricas disponibles son buenas; DEFICIENTE si alguna es deficiente;
 *   MEJORABLE en otro caso. BUENO → CUMPLE, MEJORABLE → AVISO, DEFICIENTE → NO_CUMPLE.
 * - Umbrales publicados por Google (web.dev): LCP ≤ 2,5 s / > 4 s; INP ≤ 200 ms / > 500 ms; CLS ≤ 0,1 / > 0,25.
 */
class C14CoreWebVitals extends BaseCheck
{
    public const METRICS = [
        // clave CrUX => [nombre, bueno ≤, deficiente >, divisor del percentil, unidad]
        'LARGEST_CONTENTFUL_PAINT_MS' => ['LCP', 2500, 4000, 1, 'ms'],
        'INTERACTION_TO_NEXT_PAINT' => ['INP', 200, 500, 1, 'ms'],
        'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['CLS', 0.1, 0.25, 100, ''],
    ];
    private const LABELS = ['BUENO' => 'bueno', 'MEJORABLE' => 'mejorable', 'DEFICIENTE' => 'deficiente'];

    protected string $id = 'C14';
    protected string $severity = 'MEDIO';
    protected string $criterio = 'Referencia: Core Web Vitals de Google (percentil 75 de usuarios reales, Chrome UX Report) obtenidos mediante PageSpeed Insights.';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'ALTO';
    protected bool $needsHtml = false;

    protected function evaluate(CheckContext $ctx): array
    {
        $umbral = 'Bueno: LCP ≤ 2,5 s, INP ≤ 200 ms y CLS ≤ 0,1 (percentil 75). Deficiente: LCP > 4 s, INP > 500 ms o CLS > 0,25.';
        if ($ctx->psi === null) {
            return $this->result(self::NO_DATA, null, $umbral, [], 'Sin datos de PageSpeed Insights'
                . ($ctx->psiError ? ': ' . $ctx->psiError['message'] : '.'));
        }
        $le = $ctx->psi['loadingExperience'] ?? null;
        $metrics = is_array($le) && is_array($le['metrics'] ?? null) ? $le['metrics'] : [];
        if (!empty($le['origin_fallback'])) {
            return $this->result(self::NO_DATA, null, $umbral, [], 'No hay datos de usuarios reales suficientes para esta URL; '
                . 'PageSpeed Insights solo ofrece datos del dominio completo, que no se usan para puntuar esta página.');
        }

        $valor = [];
        $ev = [];
        $ratings = [];
        $missing = [];
        foreach (self::METRICS as $key => [$name, $good, $poor, $div, $unit]) {
            $p = $metrics[$key]['percentile'] ?? null;
            if (!is_numeric($p)) {
                $missing[] = $name;
                continue;
            }
            $v = (float) $p / $div;
            $rating = self::rate($v, $good, $poor);
            $ratings[] = $rating;
            $shown = self::format($name, $v);
            $valor[$name . ' (p75)'] = $shown . ' (' . self::LABELS[$rating] . ')';
            $ev[] = DomHelper::note($name . ' p75 = ' . $shown . ' → ' . self::LABELS[$rating]
                . ' (bueno ≤ ' . self::format($name, $good) . ', deficiente > ' . self::format($name, $poor) . ')');
        }
        if (!$ratings) {
            return $this->result(self::NO_DATA, null, $umbral, [], 'No hay datos de usuarios reales (Chrome UX Report) para esta URL.');
        }

        if (in_array('DEFICIENTE', $ratings, true)) {
            $overall = 'DEFICIENTE';
        } elseif (in_array('MEJORABLE', $ratings, true)) {
            $overall = 'MEJORABLE';
        } else {
            $overall = 'BUENO';
        }
        $valor = ['Evaluación' => ucfirst(self::LABELS[$overall])] + $valor;
        $detalle = 'Core Web Vitals de usuarios reales: ' . self::LABELS[$overall] . '.';
        if ($missing) {
            $detalle .= ' Sin datos de ' . implode(', ', $missing) . '; se evalúa con las métricas disponibles.';
        }
        $estado = ['BUENO' => self::OK, 'MEJORABLE' => self::WARN, 'DEFICIENTE' => self::FAIL][$overall];
        return $this->result($estado, $valor, $umbral, $ev, $detalle, $estado === self::FAIL ? 1 : 0);
    }

    public static function rate(float $v, float $good, float $poor): string
    {
        if ($v <= $good) {
            return 'BUENO';
        }
        return $v > $poor ? 'DEFICIENTE' : 'MEJORABLE';
    }

    private static function format(string $name, float $v): string
    {
        if ($name === 'CLS') {
            return number_format($v, 2, ',', '.');
        }
        if ($name === 'LCP') {
            return number_format($v / 1000, 1, ',', '.') . ' s';
        }
        return number_format($v, 0, ',', '.') . ' ms';
    }
}
