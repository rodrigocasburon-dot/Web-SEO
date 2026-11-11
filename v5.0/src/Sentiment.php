<?php
/**
 * Tono del contenido (análisis de sentimiento por léxico, sin red ni librerías). Informativo: no puntúa.
 *
 * Método: cada palabra del texto se busca en un léxico en español con un peso de -3 a +3. Las negaciones
 * («no», «nunca», «sin»…) invierten el peso de las 3 palabras siguientes y los intensificadores («muy»,
 * «bastante»…) lo multiplican. La puntuación es el balance (P − N) / (P + N), donde P y N son la carga
 * positiva y negativa acumuladas: va de -1 (todo negativo) a +1 (todo positivo) y no se satura en textos largos.
 *
 * Es una estimación automática: no entiende ironía, sarcasmo ni contexto, y solo está pensado para español.
 */
class Sentiment
{
    /** Máximo de palabras que se analizan (las primeras) */
    public const MAX_WORDS = 20000;
    /** Palabras tras una negación a las que afecta */
    public const NEGATION_SCOPE = 3;
    /** Por debajo de este número de palabras con carga, el resultado no es concluyente */
    public const MIN_HITS = 3;
    /** Umbral de la etiqueta positivo / negativo sobre la puntuación normalizada */
    public const THRESHOLD = 0.05;
    public const TOP_TERMS = 8;
    public const TOP_SENTENCES = 2;
    public const SENTENCE_MAX = 220;

    /** Negaciones (ya normalizadas: minúsculas y sin tildes) */
    private const NEGATORS = ['no', 'nunca', 'jamas', 'tampoco', 'ni', 'sin', 'nada', 'nadie', 'ningun', 'ninguna', 'ninguno'];

    /** Intensificadores y atenuadores => multiplicador de la palabra siguiente */
    private const BOOSTERS = [
        'muy' => 1.5, 'mas' => 1.3, 'super' => 1.5, 'mucho' => 1.4, 'mucha' => 1.4, 'muchisimo' => 1.8,
        'totalmente' => 1.5, 'completamente' => 1.5, 'realmente' => 1.4, 'sumamente' => 1.7, 'extremadamente' => 1.8,
        'increiblemente' => 1.7, 'absolutamente' => 1.6, 'bastante' => 1.2, 'tan' => 1.3, 'demasiado' => 1.3,
        'algo' => 0.7, 'poco' => 0.6, 'apenas' => 0.5, 'ligeramente' => 0.6,
    ];

    /**
     * Léxico (formas normalizadas). Se prueban también la forma sin «s»/«es» final y en masculino
     * (-a → -o, -as → -os), así no hace falta listar todas las flexiones.
     */
    private const LEXICON = [
        // Positivas
        'bueno' => 2, 'buen' => 2, 'bien' => 1, 'mejor' => 2, 'excelente' => 3, 'excelencia' => 3, 'genial' => 3,
        'fantastico' => 3, 'maravilloso' => 3, 'estupendo' => 3, 'perfecto' => 3, 'increible' => 3, 'magnifico' => 3,
        'extraordinario' => 3, 'espectacular' => 3, 'impresionante' => 3, 'brillante' => 2, 'optimo' => 2,
        'feliz' => 3, 'felicidad' => 3, 'alegria' => 3, 'alegre' => 2, 'contento' => 2, 'encantado' => 3, 'encanta' => 3,
        'encantar' => 3, 'gusta' => 2, 'gustar' => 2, 'disfrutar' => 2, 'disfruta' => 2, 'amor' => 3, 'amar' => 3,
        'satisfecho' => 2, 'satisfaccion' => 2, 'agradable' => 2, 'agradecido' => 2, 'gracias' => 1, 'agradecer' => 2,
        'recomendable' => 2, 'recomiendo' => 2, 'recomendar' => 1, 'facil' => 1, 'sencillo' => 1, 'comodo' => 2,
        'rapido' => 1, 'eficaz' => 2, 'eficiente' => 2, 'eficiencia' => 2, 'util' => 2, 'utilidad' => 1, 'valioso' => 2,
        'calidad' => 1, 'fiable' => 2, 'confianza' => 2, 'confiable' => 2,
        'profesional' => 1, 'experto' => 1, 'innovador' => 2, 'innovacion' => 1, 'moderno' => 1, 'ideal' => 2,
        'exito' => 3, 'exitoso' => 3, 'ganar' => 2, 'ventaja' => 2, 'beneficio' => 2, 'beneficiar' => 2, 'mejora' => 2,
        'mejorar' => 1, 'ayuda' => 1, 'ayudar' => 1, 'apoyo' => 1, 'solucion' => 1, 'resolver' => 1, 'logro' => 2,
        'lograr' => 1, 'conseguir' => 1, 'garantia' => 1, 'garantizado' => 2, 'premio' => 2, 'premiado' => 2,
        'bonito' => 2, 'hermoso' => 3, 'precioso' => 3, 'atractivo' => 2, 'amable' => 2, 'atento' => 2,
        'cercano' => 1, 'positivo' => 2, 'favorito' => 2, 'interesante' => 1, 'divertido' => 2, 'emocionante' => 2,
        'orgulloso' => 2, 'tranquilo' => 1, 'tranquilidad' => 1, 'esperanza' => 2, 'oportunidad' => 1, 'gratis' => 1,
        'gratuito' => 1, 'ahorro' => 1, 'ahorrar' => 1, 'bienvenido' => 2, 'enhorabuena' => 3, 'felicidades' => 3,
        'acierto' => 2, 'correcto' => 1, 'claro' => 1, 'limpio' => 1, 'sano' => 1, 'saludable' => 2, 'lider' => 1,
        'referente' => 1, 'satisfactorio' => 2, 'compromiso' => 1, 'pasion' => 2, 'ilusion' => 2, 'sonrisa' => 2,
        // Negativas
        'malo' => -2, 'mal' => -2, 'peor' => -2, 'pesimo' => -3, 'horrible' => -3, 'terrible' => -3, 'fatal' => -3,
        'desastre' => -3, 'desastroso' => -3, 'espantoso' => -3, 'lamentable' => -3, 'deficiente' => -2, 'mediocre' => -2,
        'triste' => -2, 'tristeza' => -2, 'enfadado' => -2, 'enojado' => -2, 'enfado' => -2, 'furioso' => -3,
        'odio' => -3, 'odiar' => -3, 'asco' => -3, 'decepcion' => -3, 'decepcionado' => -3, 'decepcionante' => -3,
        'insatisfecho' => -2, 'molesto' => -2, 'molestia' => -2, 'frustrado' => -2, 'frustrante' => -2,
        'problema' => -1, 'problematico' => -2, 'error' => -2, 'fallo' => -2, 'fallar' => -2, 'falla' => -2,
        'averia' => -2, 'roto' => -2, 'defecto' => -2, 'defectuoso' => -2, 'dificil' => -1, 'complicado' => -1,
        'lento' => -2, 'caro' => -1, 'costoso' => -1, 'inutil' => -2, 'peligro' => -2, 'peligroso' => -2, 'riesgo' => -1,
        'miedo' => -2, 'preocupacion' => -2, 'preocupado' => -2, 'perder' => -2, 'perdida' => -2, 'fracaso' => -3,
        'fracasar' => -3, 'queja' => -2, 'quejar' => -2, 'reclamacion' => -1, 'estafa' => -3, 'fraude' => -3,
        'engano' => -3, 'enganar' => -3, 'mentira' => -3, 'abuso' => -3, 'injusto' => -2, 'grave' => -2,
        'crisis' => -2, 'dano' => -2, 'danino' => -2, 'dolor' => -2, 'sufrir' => -2, 'retraso' => -2,
        'cancelado' => -1, 'caos' => -2, 'feo' => -2, 'sucio' => -2, 'ruido' => -1, 'aburrido' => -2, 'negativo' => -2,
        'imposible' => -2, 'desgracia' => -3, 'lamentablemente' => -2, 'desafortunadamente' => -2, 'peligrar' => -2,
        'grosero' => -3, 'maleducado' => -3, 'incompetente' => -3, 'negligencia' => -3, 'robo' => -3, 'ataque' => -2,
        'amenaza' => -2, 'culpa' => -2, 'castigo' => -2, 'muerte' => -3, 'enfermedad' => -2, 'perjuicio' => -2,
    ];

    /**
     * @param string      $text texto plano (p. ej. el contenido principal de la página)
     * @param string|null $lang valor del atributo lang de <html> (null o '' si no lo hay)
     */
    public static function analyze(string $text, ?string $lang = null): array
    {
        $lang = strtolower(trim((string) $lang));
        $base = [
            'disponible' => false,
            'motivo' => null,
            'idioma' => $lang === '' ? null : $lang,
            // Sin atributo lang se supone español (se avisa en el informe)
            'idioma_supuesto' => $lang === '',
        ];
        if ($lang !== '' && !preg_match('/^es(-|$)/', $lang)) {
            return array_merge($base, ['motivo' => 'El léxico solo está en español y la página declara el idioma «' . $lang . '».']);
        }

        $sentences = self::sentences($text);
        $load = ['p' => 0.0, 'n' => 0.0];
        $words = 0;
        $pos = [];
        $neg = [];
        $scored = [];
        foreach ($sentences as $sentence) {
            if ($words >= self::MAX_WORDS) {
                break;
            }
            $tokens = explode(' ', Insights::fold($sentence));
            $tokens = array_slice(array_values(array_filter($tokens, 'strlen')), 0, self::MAX_WORDS - $words);
            $words += count($tokens);
            $s = self::scoreTokens($tokens, $pos, $neg, $load);
            if ($s != 0.0) {
                $scored[] = ['texto' => Insights::truncate($sentence, self::SENTENCE_MAX), 'valor' => round($s, 2)];
            }
        }
        if ($words === 0) {
            return array_merge($base, ['motivo' => 'La página no tiene texto en el contenido principal.']);
        }

        $hits = array_sum($pos) + array_sum($neg);
        $sum = $load['p'] + $load['n'];
        $score = $sum > 0 ? ($load['p'] - $load['n']) / $sum : 0.0;
        $label = $score >= self::THRESHOLD ? 'positivo' : ($score <= -self::THRESHOLD ? 'negativo' : 'neutral');

        usort($scored, fn($a, $b) => $b['valor'] <=> $a['valor']);
        $best = array_slice(array_values(array_filter($scored, fn($x) => $x['valor'] > 0)), 0, self::TOP_SENTENCES);
        $worst = array_slice(array_reverse(array_values(array_filter($scored, fn($x) => $x['valor'] < 0))), 0, self::TOP_SENTENCES);

        return array_merge($base, [
            'disponible' => true,
            'puntuacion' => round($score, 3),
            'etiqueta' => $label,
            'concluyente' => $hits >= self::MIN_HITS,
            'palabras_analizadas' => $words,
            'palabras_recortadas' => $words >= self::MAX_WORDS,
            'positivas' => array_sum($pos),
            'negativas' => array_sum($neg),
            'terminos_positivos' => self::top($pos),
            'terminos_negativos' => self::top($neg),
            'frases_positivas' => $best,
            'frases_negativas' => $worst,
        ]);
    }

    /** Puntuación de una frase ya normalizada; acumula las apariciones de cada término. */
    private static function scoreTokens(array $tokens, array &$pos, array &$neg, array &$load): float
    {
        $sum = 0.0;
        $negLeft = 0;
        $boost = 1.0;
        foreach ($tokens as $i => $t) {
            // «sin embargo» es un conector, no una negación
            if ($t === 'sin' && ($tokens[$i + 1] ?? '') === 'embargo') {
                continue;
            }
            if ($t === 'embargo' && ($tokens[$i - 1] ?? '') === 'sin') {
                continue;
            }
            if (in_array($t, self::NEGATORS, true)) {
                $negLeft = self::NEGATION_SCOPE;
                continue;
            }
            if (isset(self::BOOSTERS[$t])) {
                $boost *= self::BOOSTERS[$t];
                continue;
            }
            [$lemma, $value] = self::lookup($t);
            if ($lemma !== null) {
                $v = $value * $boost;
                // Una negación invierte y suaviza («no es malo» no equivale a «es bueno»)
                if ($negLeft > 0) {
                    $v = -$v * 0.75;
                }
                $sum += $v;
                if ($v > 0) {
                    $pos[$lemma] = ($pos[$lemma] ?? 0) + 1;
                    $load['p'] += $v;
                } else {
                    $neg[$lemma] = ($neg[$lemma] ?? 0) + 1;
                    $load['n'] -= $v;
                }
            }
            $boost = 1.0;
            $negLeft = max(0, $negLeft - 1);
        }
        return $sum;
    }

    /** @return array{0:?string,1:int} forma del léxico encontrada y su peso */
    private static function lookup(string $t): array
    {
        $candidates = [$t];
        if (strlen($t) > 4) {
            if (substr($t, -2) === 'es') {
                $candidates[] = substr($t, 0, -2);
            }
            if (substr($t, -1) === 's') {
                $candidates[] = substr($t, 0, -1);
            }
        }
        foreach ($candidates as $c) {
            if (isset(self::LEXICON[$c])) {
                return [$c, self::LEXICON[$c]];
            }
            // Femenino → masculino (buena → bueno)
            if (strlen($c) > 3 && substr($c, -1) === 'a' && isset(self::LEXICON[substr($c, 0, -1) . 'o'])) {
                $m = substr($c, 0, -1) . 'o';
                return [$m, self::LEXICON[$m]];
            }
        }
        return [null, 0];
    }

    /** Frases del texto: corte en . ! ? ; … y saltos de línea. */
    private static function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?;…])\s+|[\r\n]+/u', $text) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
    }

    /** @return list<array{termino:string, veces:int}> */
    private static function top(array $counts): array
    {
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, self::TOP_TERMS, true) as $term => $n) {
            $out[] = ['termino' => (string) $term, 'veces' => $n];
        }
        return $out;
    }
}
