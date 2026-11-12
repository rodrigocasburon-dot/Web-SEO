<?php
/**
 * Tono y citabilidad para IA (12/11/2026). Sustituye en pantalla al «Tono del contenido» (src/Sentiment.php, que se
 * conserva): en webs de empresa el sentimiento sale casi siempre positivo y no ayuda a decidir. Mide si el texto es
 * concreto, verificable y fácil de citar por un asistente de IA: frases con datos, afirmaciones sin respaldo, frases
 * citables, frases largas, trato al lector y tono (solo para señalar frases negativas).
 *
 * Reglas y léxico en español: no es un modelo de IA, no entiende ironía ni contexto y no envía el texto fuera.
 * Informativo: no puntúa. Umbrales orientativos sin calibrar (LIMITS). Página para texto suelto: tono-ia.php.
 */
final class ToneForAi
{
    /** Umbrales orientativos (fracciones de frases) */
    public const LIMITS = ['concretas_min' => 0.30, 'sin_respaldo_max' => 0.15, 'citables_min' => 3, 'largas_max' => 0.25];
    public const MIN_WORDS_SENTENCE = 4;
    public const LONG_SENTENCE = 30;
    public const MAX_WORDS = 20000;
    public const EXAMPLES = 3;

    /** Superlativos y autopromoción (formas normalizadas: minúsculas, sin tildes ni eñes) */
    private const HYPE = ['lider', 'lideres', 'liderazgo', 'lideramos', 'referente', 'referentes', 'mejor', 'mejores',
        'unico', 'unica', 'unicos', 'unicas', 'innovador', 'innovadora', 'innovadores', 'innovadoras', 'revolucionario',
        'revolucionaria', 'excelencia', 'excepcional', 'increible', 'inigualable', 'insuperable', 'extraordinario',
        'extraordinaria', 'perfecto', 'perfecta', 'espectacular', 'impresionante', 'garantizado', 'garantizada',
        'vanguardia', 'puntero', 'puntera', 'premium', 'exclusivo', 'exclusiva'];

    /** Primera persona de la empresa: indica que la frase habla de sí misma */
    private const SELF = ['somos', 'nuestro', 'nuestra', 'nuestros', 'nuestras', 'nos', 'ofrecemos', 'lideramos',
        'contamos', 'tenemos', 'trabajamos', 'hacemos', 'creamos', 'disenamos', 'desarrollamos'];

    /** Frases hechas que no informan */
    private const VAGUE = ['soluciones integrales', 'solucion integral', 'servicio integral', 'a medida', 'valor anadido',
        'alta calidad', 'maxima calidad', 'transformacion digital', 'mejores practicas', 'amplia experiencia',
        'gran experiencia', 'equipo de profesionales', 'altamente cualificado', 'atencion personalizada',
        'todo tipo de', 'comprometidos con', 'adaptadas a tus necesidades', 'adaptadas a sus necesidades',
        'nos diferencia', 'calidad y confianza'];

    /** Señales de respaldo además de las cifras */
    private const SOURCES = ['segun', 'fuente', 'estudio', 'informe', 'encuesta', 'certificado', 'certificada',
        'certificacion', 'iso', 'une', 'norma', 'normativa', 'ley', 'real decreto', 'premio', 'auditado', 'auditoria'];

    /** Palabras que dejan la frase sin sentido fuera de contexto si la abren */
    private const ANAPHORA = ['esto', 'esta', 'este', 'estos', 'estas', 'ello', 'ellos', 'ellas', 'eso', 'aqui',
        'asi', 'tambien', 'ademas', 'por eso', 'por ello', 'dicho', 'dicha'];

    private const NEGATIVE = ['problema', 'problemas', 'error', 'errores', 'fallo', 'fallos', 'averia', 'retraso',
        'retrasos', 'queja', 'quejas', 'reclamacion', 'reclamaciones', 'denuncia', 'estafa', 'fraude', 'multa',
        'sancion', 'peligro', 'riesgo', 'riesgos', 'perdida', 'perdidas', 'crisis', 'cierre', 'despido', 'despidos',
        'malo', 'mala', 'peor', 'pesimo', 'terrible', 'horrible', 'lamentable', 'decepcion', 'deficiente', 'grave',
        'caro', 'lento', 'imposible', 'cancelado', 'cancelacion', 'incidencia', 'incidencias', 'ataque', 'brecha'];
    private const POSITIVE = ['bueno', 'buena', 'excelente', 'facil', 'rapido', 'rapida', 'eficaz', 'eficiente',
        'util', 'fiable', 'seguro', 'segura', 'ahorro', 'ahorrar', 'mejora', 'mejorar', 'exito', 'satisfaccion',
        'satisfecho', 'satisfechos', 'confianza', 'ventaja', 'ventajas', 'beneficio', 'beneficios', 'gratis',
        'gratuito', 'sencillo', 'comodo', 'ayuda', 'ayudar', 'solucion', 'soluciones', 'premio', 'crecimiento'];
    private const NEGATORS = ['no', 'nunca', 'sin', 'ni', 'jamas', 'tampoco', 'evita', 'evitar', 'reduce', 'reducir', 'elimina', 'eliminar'];

    private const TU = ['tu', 'tus', 'te', 'ti', 'contigo', 'puedes', 'tienes', 'quieres', 'necesitas', 'sabes', 'eres',
        'descubre', 'contacta', 'llamanos', 'escribenos', 'pide', 'solicita', 'consulta', 'conoce', 'accede', 'prueba'];
    private const USTED = ['usted', 'ustedes', 'contacte', 'llamenos', 'escribanos', 'pida', 'solicite', 'consulte',
        'conozca', 'acceda', 'descubra', 'pruebe', 'necesita usted', 'le ofrecemos', 'le ayudamos'];

    /**
     * @param string      $text texto plano del contenido principal
     * @param string|null $lang atributo lang de <html> ('' o null: se supone español y se avisa)
     */
    public static function analyze(string $text, ?string $lang = null): array
    {
        $lang = strtolower(trim((string) $lang));
        $out = ['disponible' => false, 'motivo' => null, 'idioma_supuesto' => $lang === ''];
        if ($lang !== '' && !preg_match('/^es(-|$)/', $lang)) {
            $out['motivo'] = 'Solo se analiza texto en español y la página declara el idioma «' . $lang . '».';
            return $out;
        }
        // Texto que no es UTF-8 válido (p. ej. pegado desde un programa antiguo): se convierte desde Windows-1252
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $sentences = self::sentences($text);
        if (count($sentences) < 3) {
            $out['motivo'] = 'Hay muy poco texto para valorar el tono (menos de 3 frases).';
            return $out;
        }

        $n = count($sentences);
        $words = 0;
        $concrete = $long = 0;
        $citable = $unbacked = $negative = [];
        $pos = $neg = 0;
        $tu = $usted = 0;
        foreach ($sentences as $s) {
            $f = ' ' . self::fold($s) . ' ';
            $tokens = preg_split('/\s+/', trim($f));
            $wc = count($tokens);
            $words += $wc;
            $hasData = (bool) preg_match('/\d/u', $s) || self::hasAny($f, self::SOURCES);
            // Los superlativos cuentan cuando la web habla de sí misma («somos líderes»); las frases hechas, siempre
            $isHype = (self::hasAny($f, self::HYPE) && self::hasAny($f, self::SELF)) || self::hasAny($f, self::VAGUE);
            if ($hasData) {
                $concrete++;
            }
            if ($isHype && !$hasData) {
                $unbacked[] = $s;
            }
            if ($wc > self::LONG_SENTENCE) {
                $long++;
            }
            $endsWell = (bool) preg_match('/[.!?…]["»”)]?$/u', $s);
            if ($hasData && !self::hasAny($f, self::HYPE) && !self::hasAny($f, self::VAGUE) && $endsWell && $wc >= 8 && $wc <= self::LONG_SENTENCE && !self::startsWithAny($f, self::ANAPHORA)) {
                $citable[] = $s;
            }
            [$p, $q] = self::polarity($tokens);
            $pos += $p;
            $neg += $q;
            if ($q > $p) {
                $negative[] = $s;
            }
            $tu += self::countAny($f, self::TU);
            $usted += self::countAny($f, self::USTED);
        }

        $pc = $concrete / $n;
        $pu = count($unbacked) / $n;
        $pl = $long / $n;
        $L = self::LIMITS;
        $state = fn(bool $ok): string => $ok ? 'bien' : 'mejorable';
        $mixed = min($tu, $usted) >= 2;   // al menos dos formas de cada trato
        $tone = ($pos + $neg) === 0 ? 'neutral' : (($pos - $neg) / ($pos + $neg) >= 0.2 ? 'positivo' : (($pos - $neg) / ($pos + $neg) <= -0.2 ? 'negativo' : 'neutral'));

        $ind = [
            ['clave' => 'concretas', 'nombre' => 'Frases con datos', 'valor' => self::pct($pc), 'estado' => $state($pc >= $L['concretas_min']),
                'explicacion' => 'Cifras, fechas, porcentajes o fuentes. Orientativo: al menos ' . self::pct($L['concretas_min']) . '.'],
            ['clave' => 'sin_respaldo', 'nombre' => 'Afirmaciones sin respaldo', 'valor' => self::pct($pu), 'estado' => $state($pu <= $L['sin_respaldo_max']),
                'explicacion' => 'Superlativos o frases hechas sin ningún dato. Orientativo: como mucho ' . self::pct($L['sin_respaldo_max']) . '.'],
            ['clave' => 'citables', 'nombre' => 'Frases citables', 'valor' => (string) count($citable), 'estado' => $state(count($citable) >= $L['citables_min']),
                'explicacion' => 'Autocontenidas, de 8 a ' . self::LONG_SENTENCE . ' palabras y con un dato. Orientativo: al menos ' . $L['citables_min'] . '.'],
            ['clave' => 'largas', 'nombre' => 'Frases largas', 'valor' => self::pct($pl), 'estado' => $state($pl <= $L['largas_max']),
                'explicacion' => 'Más de ' . self::LONG_SENTENCE . ' palabras. Orientativo: como mucho ' . self::pct($L['largas_max']) . '.'],
            ['clave' => 'trato', 'nombre' => 'Trato al lector', 'valor' => $mixed ? 'mezcla tú y usted' : ($tu > $usted ? 'tú' : ($usted > $tu ? 'usted' : 'impersonal')),
                'estado' => $state(!$mixed), 'explicacion' => 'Formas de tú: ' . $tu . '; de usted: ' . $usted . '.'],
            ['clave' => 'tono', 'nombre' => 'Tono', 'valor' => $tone, 'estado' => $tone === 'negativo' ? 'revisar' : 'bien',
                'explicacion' => count($negative) . ' frases con más carga negativa que positiva.'],
        ];

        $recs = [];
        if (count($unbacked) && $pu > $L['sin_respaldo_max']) {
            $recs[] = 'Cambia los superlativos por pruebas: en lugar de «somos líderes», di cuántos clientes, desde qué año o qué certificación tienes.';
        }
        if ($pc < $L['concretas_min']) {
            $recs[] = 'Añade datos verificables (cifras, fechas, plazos, precios, normas): es lo que un asistente de IA puede citar.';
        }
        if (count($citable) < $L['citables_min']) {
            $recs[] = 'Escribe frases que se entiendan solas: sujeto explícito, un dato y punto final. Evita empezar por «esto» o «además».';
        }
        if ($pl > $L['largas_max']) {
            $recs[] = 'Divide las frases de más de ' . self::LONG_SENTENCE . ' palabras: se entienden y se extraen mejor.';
        }
        if ($mixed) {
            $recs[] = 'Unifica el trato: la página mezcla tú y usted.';
        }
        if ($tone === 'negativo') {
            $recs[] = 'Revisa las frases negativas de la lista: pueden transmitir problemas que no quieres asociar a la marca.';
        }

        $weak = count(array_filter($ind, fn($i) => $i['estado'] !== 'bien'));
        $fewText = $n < 15;
        $summary = $weak === 0
            ? 'El texto es concreto y fácil de citar: no se detectan mejoras claras de forma automática.'
            : ($weak === 1 ? 'Un aspecto mejorable' : $weak . ' aspectos mejorables') . ' para que un asistente de IA entienda y cite esta página.';

        return [
            'disponible' => true,
            'motivo' => null,
            'idioma_supuesto' => $lang === '',
            'palabras' => $words,
            'frases' => $n,
            'resumen' => $summary,
            'poco_texto' => $fewText,
            'indicadores' => $ind,
            'recomendaciones' => array_slice($recs, 0, 3),
            'ejemplos' => [
                'citables' => self::examples($citable),
                'sin_respaldo' => self::examples($unbacked),
                'negativas' => self::examples($negative),
            ],
        ];
    }

    /** Texto plano del contenido principal de un HTML (sin menús, cabecera, pie ni scripts) y su atributo lang. */
    public static function textFromHtml(string $html): array
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return self::textFromXpath(new DOMXPath($doc));
    }

    /**
     * Texto del contenido principal, un bloque por línea (títulos, párrafos, elementos de lista, celdas…), sin menús,
     * cabecera, pie, formularios ni elementos ocultos. No modifica el documento. Devuelve [texto, lang].
     */
    public static function textFromXpath(DOMXPath $xp): array
    {
        $lang = (string) $xp->evaluate('string(/html/@lang)');
        $root = $xp->query('//main[not(ancestor::template)]|//*[@role="main"]|//article[not(ancestor::template)]')->item(0)
            ?? $xp->query('//body')->item(0);
        if (!$root) {
            return ['', $lang];
        }
        $blocks = 'self::h1 or self::h2 or self::h3 or self::h4 or self::p or self::li or self::td or self::th or self::blockquote or self::dd or self::dt or self::figcaption';
        $outside = 'ancestor::nav or ancestor::header or ancestor::footer or ancestor::aside or ancestor::form or ancestor::template'
            . ' or ancestor-or-self::*[@hidden] or ancestor-or-self::*[@aria-hidden="true"]';
        $parts = [];
        // Solo los bloques que no contienen otros bloques (evita repetir el texto de un <li> que tiene un <p>)
        foreach ($xp->query('.//*[' . $blocks . '][not(.//*[' . $blocks . '])][not(' . $outside . ')]', $root) as $el) {
            $bits = [];
            foreach ($xp->query('.//text()[not(ancestor::script or ancestor::style or ancestor::noscript)]', $el) as $tn) {
                $bits[] = $tn->nodeValue;
            }
            $t = trim((string) preg_replace('/\s+/u', ' ', implode(' ', $bits)));
            if ($t !== '') {
                $parts[] = $t;
            }
        }
        if (!$parts) {
            // Páginas maquetadas solo con <div>: todo el texto del contenido principal
            $parts[] = trim((string) preg_replace('/\s+/u', ' ', $root->textContent));
        }
        return [implode("\n", array_values(array_unique($parts))), $lang];
    }

    // ---------- Utilidades ----------

    private static function sentences(string $text): array
    {
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $chunks = preg_split('/(?<=[.!?…])\s+(?=[\p{Lu}¿¡«"0-9])|\n+/u', $text) ?: [];
        $out = [];
        $words = 0;
        foreach ($chunks as $c) {
            $c = trim($c);
            $wc = count(preg_split('/\s+/u', $c));
            if ($c === '' || $wc < self::MIN_WORDS_SENTENCE) {
                continue;
            }
            if (($words += $wc) > self::MAX_WORDS) {
                break;
            }
            $out[] = $c;
        }
        return $out;
    }

    public static function fold(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ò' => 'o']);
        return trim((string) preg_replace('/[^a-z0-9%€]+/u', ' ', $s));
    }

    private static function hasAny(string $padded, array $terms): bool
    {
        return self::countAny($padded, $terms) > 0;
    }

    private static function countAny(string $padded, array $terms): int
    {
        $c = 0;
        foreach ($terms as $t) {
            $c += substr_count($padded, ' ' . $t . ' ');
        }
        return $c;
    }

    private static function startsWithAny(string $padded, array $terms): bool
    {
        foreach ($terms as $t) {
            if (strpos($padded, ' ' . $t . ' ') === 0) {
                return true;
            }
        }
        return false;
    }

    /** [carga positiva, carga negativa]; una negación en las 3 palabras previas invierte la palabra. */
    private static function polarity(array $tokens): array
    {
        $p = $q = 0;
        foreach ($tokens as $i => $t) {
            $isPos = in_array($t, self::POSITIVE, true);
            $isNeg = in_array($t, self::NEGATIVE, true);
            if (!$isPos && !$isNeg) {
                continue;
            }
            $negated = (bool) array_intersect(array_slice($tokens, max(0, $i - 3), min(3, $i)), self::NEGATORS);
            if ($isPos xor $negated) {
                $p++;
            } else {
                $q++;
            }
        }
        return [$p, $q];
    }

    private static function pct(float $v): string
    {
        return str_replace('.', ',', (string) round($v * 100)) . ' %';
    }

    private static function examples(array $list): array
    {
        return array_map(fn($s) => mb_strlen($s) > 220 ? mb_substr($s, 0, 219) . '…' : $s, array_slice($list, 0, self::EXAMPLES));
    }
}
