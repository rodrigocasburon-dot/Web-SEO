<?php
/**
 * Informe: a partir de los registros y la puntuación deriva el resumen ejecutivo, los problemas
 * críticos, las recomendaciones priorizadas y el plan de acción (matriz impacto × esfuerzo).
 * No mide nada: solo ordena y resume lo medido. No incluye textos de la web analizada (detalle,
 * extractos): esos los pinta la pantalla con textContent desde los registros.
 *
 * Supuestos (pendientes de validar con el equipo):
 * - «Problemas críticos» = fallos de gravedad crítica.
 * - Prioridad: primero los fallos, por penalización w × P (lo que más resta a la nota); después los
 *   avisos, por gravedad. Empates: mayor impacto, menor esfuerzo, id.
 * - Matriz 2×2: impacto alto = ALTO; esfuerzo bajo = BAJO. MEDIO cuenta como impacto bajo y como
 *   esfuerzo alto. Impacto y esfuerzo son estimaciones de borrador.
 */
class Report
{
    private const LEVEL_RANK = ['ALTO' => 0, 'MEDIO' => 1, 'BAJO' => 2];
    public const SUMMARY_TOP = 3;

    public const QUADRANTS = [
        'inmediata' => ['titulo' => 'Hacer primero', 'descripcion' => 'Impacto alto y esfuerzo bajo.'],
        'planificar' => ['titulo' => 'Planificar', 'descripcion' => 'Impacto alto y esfuerzo medio o alto.'],
        'rapidas' => ['titulo' => 'Mejoras rápidas', 'descripcion' => 'Impacto medio o bajo y esfuerzo bajo.'],
        'valorar' => ['titulo' => 'Valorar después', 'descripcion' => 'Impacto medio o bajo y esfuerzo medio o alto.'],
    ];

    /**
     * @param array $checks registros de las comprobaciones
     * @param array $score  resultado de Scorer::score()
     * @param bool  $blocked el sitio bloqueó el acceso (no se muestran notas)
     * @param int   $simulateTop nº de fallos del simulador de mejora (config 'simulator_top_n')
     */
    public static function build(array $checks, array $score, bool $blocked = false, int $simulateTop = Simulator::DEFAULT_TOP): array
    {
        $priorities = self::priorities($checks);

        $critical = [];
        $matrix = [];
        foreach (self::QUADRANTS as $key => $q) {
            $matrix[$key] = $q + ['ids' => []];
        }
        foreach ($priorities as $p) {
            if ($p['estado'] === BaseCheck::FAIL && $p['severidad'] === 'CRITICO') {
                $critical[] = $p['id'];
            }
            $matrix[$p['cuadrante']]['ids'][] = $p['id'];
        }

        return [
            'resumen' => self::summary($checks, $score, $priorities, $blocked),
            'criticos' => $critical,
            'prioridades' => $priorities,
            'matriz' => $matrix,
            // Estimación informativa; sin notas (acceso bloqueado) no hay nada que simular
            'simulacion' => $blocked ? null : Simulator::simulate($checks, $simulateTop, $score),
            // Presentación (no cambian notas ni estados): cobertura, área donde se muestra cada punto y resumen breve
            'cobertura' => self::coverage($checks),
            'bloque' => self::blocks($checks),
            'resumen_breve' => self::brief($checks, $priorities, $blocked),
        ];
    }

    /**
     * Cobertura por área: comprobaciones que afectan al área (peso > 0 y que aplican) y cuántas se han
     * podido evaluar (estado distinto de SIN_DATOS). Supuesto: cada «punto» es una comprobación.
     * @return array<string, array{evaluados:int, total:int}>
     */
    public static function coverage(array $checks): array
    {
        $out = [];
        foreach (Scorer::AREAS as $a) {
            $out[$a] = ['evaluados' => 0, 'total' => 0];
        }
        foreach ($checks as $c) {
            if (($c['aplica'] ?? true) === false) {
                continue;
            }
            foreach (Scorer::AREAS as $a) {
                if ((float) ($c['pilares'][$a] ?? 0) > 0) {
                    $out[$a]['total']++;
                    if (($c['estado'] ?? '') !== BaseCheck::NO_DATA) {
                        $out[$a]['evaluados']++;
                    }
                }
            }
        }
        return $out;
    }

    /** Área principal de una comprobación: la de mayor peso; en caso de empate, la primera de Scorer::AREAS. */
    public static function primaryArea(array $pilares): string
    {
        $best = Scorer::AREAS[0];
        $max = -1.0;
        foreach (Scorer::AREAS as $a) {
            $w = (float) ($pilares[$a] ?? 0);
            if ($w > $max) {
                $max = $w;
                $best = $a;
            }
        }
        return $best;
    }

    /** @return array<string, string> id → área en cuyo bloque se muestra (cada punto aparece una sola vez) */
    public static function blocks(array $checks): array
    {
        $out = [];
        foreach ($checks as $c) {
            $out[(string) ($c['id'] ?? '')] = self::primaryArea((array) ($c['pilares'] ?? []));
        }
        return $out;
    }

    /**
     * Resumen ejecutivo breve (8 líneas como máximo), por reglas: fallos críticos y altos por área, los
     * fallos que más restan con su recomendación, la cobertura y el recordatorio de revisión manual.
     * @return array<int, array{tipo:string, texto:string, id:?string}>
     */
    private static function brief(array $checks, array $priorities, bool $blocked): array
    {
        $areaNames = Lang::all()['areas'];
        $manual = ['tipo' => 'manual', 'texto' => 'Hay aspectos que solo puede valorar una persona: consulta las revisiones manuales al final del informe.', 'id' => null];
        if ($blocked) {
            return [
                ['tipo' => 'bloqueo', 'texto' => 'El sitio ha bloqueado el acceso automático: no se ha podido evaluar el contenido y no hay notas.', 'id' => null],
                $manual,
            ];
        }
        $out = [];
        $fails = array_values(array_filter($priorities, fn($p) => $p['estado'] === BaseCheck::FAIL));
        $byId = [];
        foreach ($checks as $c) {
            $byId[(string) ($c['id'] ?? '')] = $c;
        }
        foreach (Scorer::AREAS as $a) {
            $n = ['CRITICO' => 0, 'ALTO' => 0];
            foreach ($fails as $f) {
                if (isset($n[$f['severidad']]) && self::primaryArea((array) ($byId[$f['id']]['pilares'] ?? [])) === $a) {
                    $n[$f['severidad']]++;
                }
            }
            $parts = [];
            if ($n['CRITICO']) {
                $parts[] = self::plural($n['CRITICO'], 'fallo crítico', 'fallos críticos');
            }
            if ($n['ALTO']) {
                $parts[] = self::plural($n['ALTO'], 'fallo de gravedad alta', 'fallos de gravedad alta');
            }
            $out[] = ['tipo' => 'area', 'texto' => ($areaNames[$a] ?? $a) . ': ' . ($parts ? self::joinList($parts) : 'ningún fallo crítico ni de gravedad alta') . '.', 'id' => null];
        }
        foreach (array_slice($fails, 0, self::SUMMARY_TOP) as $i => $f) {
            $out[] = ['tipo' => 'prioridad', 'texto' => ($i === 0 ? 'Primero: ' : 'Después: ') . $f['nombre'] . '. ' . $f['recomendacion'], 'id' => $f['id']];
        }
        if (!$fails) {
            $out[] = ['tipo' => 'sin_fallos', 'texto' => 'Sin fallos detectables automáticamente' . (count($priorities) ? '; revisa los avisos.' : '.'), 'id' => null];
        }
        $evaluated = 0;
        $total = 0;
        foreach ($checks as $c) {
            if (($c['aplica'] ?? true) !== false) {
                $total++;
                if (($c['estado'] ?? '') !== BaseCheck::NO_DATA) {
                    $evaluated++;
                }
            }
        }
        $out[] = ['tipo' => 'cobertura', 'texto' => "Se han evaluado $evaluated de $total comprobaciones; las que no tienen datos no restan puntos.", 'id' => null];
        $out[] = $manual;
        return $out;
    }

    /** Fallos y avisos ordenados por prioridad, con su recomendación y su cuadrante. */
    public static function priorities(array $checks): array
    {
        $items = [];
        foreach ($checks as $c) {
            $estado = (string) ($c['estado'] ?? '');
            if (($c['aplica'] ?? true) === false || ($estado !== BaseCheck::FAIL && $estado !== BaseCheck::WARN)) {
                continue;
            }
            $severity = (string) ($c['severidad'] ?? 'BAJO');
            $impact = (string) ($c['impacto'] ?? 'MEDIO');
            $effort = (string) ($c['esfuerzo'] ?? 'MEDIO');
            $isFail = $estado === BaseCheck::FAIL;
            $k = $isFail ? max(1, (int) ($c['ocurrencias'] ?? 1)) : (int) ($c['ocurrencias'] ?? 0);
            $penalty = $isFail ? Scorer::repetitionWeight($k) * (Scorer::PENALTIES[$severity] ?? 0) : 0.0;
            $items[] = [
                'id' => (string) ($c['id'] ?? ''),
                'nombre' => (string) ($c['nombre'] ?? $c['id'] ?? ''),
                'estado' => $estado,
                'severidad' => $severity,
                'impacto' => $impact,
                'esfuerzo' => $effort,
                'ocurrencias' => $k,
                'penalizacion' => round($penalty, 1),
                'recomendacion' => (string) ($c['recomendacion'] ?? ''),
                'cuadrante' => self::quadrant($impact, $effort),
                '_sort' => [
                    $isFail ? 0 : 1,
                    -round($penalty, 6),
                    -(Scorer::PENALTIES[$severity] ?? 0),
                    self::LEVEL_RANK[$impact] ?? 1,
                    -(self::LEVEL_RANK[$effort] ?? 1),
                    (string) ($c['id'] ?? ''),
                ],
            ];
        }
        usort($items, fn($a, $b) => $a['_sort'] <=> $b['_sort']);
        foreach ($items as $i => &$item) {
            unset($item['_sort']);
            $item['orden'] = $i + 1;
        }
        unset($item);
        return $items;
    }

    public static function quadrant(string $impact, string $effort): string
    {
        $highImpact = $impact === 'ALTO';
        $lowEffort = $effort === 'BAJO';
        if ($highImpact) {
            return $lowEffort ? 'inmediata' : 'planificar';
        }
        return $lowEffort ? 'rapidas' : 'valorar';
    }

    /** Resumen ejecutivo: frases breves con cifras; nunca afirma cumplimiento de WCAG ni de ninguna ley. */
    private static function summary(array $checks, array $score, array $priorities, bool $blocked): array
    {
        $areaNames = Lang::all()['areas'];
        $fmt = fn($n): string => number_format((float) $n, 1, ',', '.');
        $out = [];

        if ($blocked) {
            $out[] = 'El sitio ha bloqueado el acceso automático: no se ha podido evaluar el contenido de la página y no se calcula ninguna nota.';
        } else {
            $parts = [];
            foreach (Scorer::AREAS as $a) {
                $parts[] = ($areaNames[$a] ?? $a) . ' ' . $fmt($score['areas'][$a]['redondeada'] ?? 0);
            }
            $line = 'Nota global: ' . $fmt($score['global']['redondeada'] ?? 0) . ' sobre 100 (' . implode('; ', $parts) . ').';
            if (!empty($score['global']['incompleta'])) {
                $line .= ' La nota está incompleta: en algún área faltan datos de más de la mitad de las comprobaciones.';
            }
            $out[] = $line;
        }

        $evaluated = 0;
        foreach ($checks as $c) {
            if (($c['aplica'] ?? true) !== false && ($c['estado'] ?? '') !== BaseCheck::NO_DATA) {
                $evaluated++;
            }
        }
        $fails = array_values(array_filter($priorities, fn($p) => $p['estado'] === BaseCheck::FAIL));
        $warns = count($priorities) - count($fails);

        if ($evaluated === 0) {
            $out[] = 'No se ha podido evaluar ninguna comprobación.';
        } elseif ($fails) {
            $bySeverity = [];
            foreach ($fails as $f) {
                $bySeverity[$f['severidad']] = ($bySeverity[$f['severidad']] ?? 0) + 1;
            }
            $detail = [];
            foreach (array_keys(Scorer::PENALTIES) as $sev) {
                if (!empty($bySeverity[$sev])) {
                    $detail[] = $bySeverity[$sev] . ' de gravedad ' . mb_strtolower((string) (Lang::all()['severidades'][$sev] ?? $sev));
                }
            }
            $out[] = 'Se han detectado ' . self::plural(count($fails), 'fallo', 'fallos') . ' (' . self::joinList($detail) . ')'
                . ($warns ? ' y ' . self::plural($warns, 'aviso', 'avisos') : '')
                . ' en ' . self::plural($evaluated, 'comprobación evaluada', 'comprobaciones evaluadas') . '.';
        } else {
            $out[] = 'Sin errores detectables automáticamente en ' . self::plural($evaluated, 'comprobación evaluada', 'comprobaciones evaluadas')
                . ($warns ? '; hay ' . self::plural($warns, 'aviso', 'avisos') . ' que conviene revisar' : '') . '.';
        }

        $noData = count($score['sin_datos'] ?? []);
        $notApplicable = count($score['no_aplica'] ?? []);
        $extra = [];
        if ($noData) {
            $extra[] = $noData === 1 ? '1 comprobación no tiene datos' : "$noData comprobaciones no tienen datos";
        }
        if ($notApplicable) {
            $extra[] = $notApplicable === 1 ? '1 no aplica a esta página' : "$notApplicable no aplican a esta página";
        }
        if ($extra) {
            $out[] = ucfirst(self::joinList($extra)) . '; no restan puntos.';
        }

        if ($priorities) {
            $top = array_map(fn($p) => $p['nombre'], array_slice($priorities, 0, self::SUMMARY_TOP));
            $out[] = 'Por dónde empezar: ' . self::joinList($top) . '.';
        }
        return $out;
    }

    private static function plural(int $n, string $one, string $many): string
    {
        return $n . ' ' . ($n === 1 ? $one : $many);
    }

    /** Une una lista en español: «a, b y c» («e» ante palabra que empieza por el sonido i). */
    public static function joinList(array $items): string
    {
        $items = array_values($items);
        $n = count($items);
        if ($n <= 1) {
            return $n ? (string) $items[0] : '';
        }
        $last = (string) $items[$n - 1];
        $conj = preg_match('/^h?[ií](?![aeiouáéíóú])/iu', $last) ? ' e ' : ' y ';
        return implode(', ', array_slice($items, 0, -1)) . $conj . $last;
    }
}
