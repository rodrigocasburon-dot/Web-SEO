<?php
/**
 * v4 · Pistas automáticas para dos revisiones manuales (sin IA, no puntúan):
 *  - RM-01 Calidad del texto alternativo: alt que con seguridad no describen la imagen (nombre de archivo,
 *    palabra genérica, una letra, demasiado largo, el mismo en muchas imágenes distintas, o «imagen de…»).
 *  - RM-09 Calidad del contenido: legibilidad del texto principal con el índice de Fernández-Huerta
 *    (adaptación al español del de Flesch), longitud de frases y de párrafos.
 * Solo señalan dónde mirar: no saben si un alt describe bien una foto ni si un texto responde a lo que se busca.
 * Eso lo sigue decidiendo una persona.
 */
class ManualHints
{
    /** Máximo de imágenes sospechosas que se devuelven con detalle */
    public const MAX_ALT_ITEMS = 50;
    /** Un alt así de largo ya no es una descripción breve */
    public const ALT_MAX_CHARS = 150;
    /** El mismo alt en al menos estas imágenes distintas */
    public const ALT_REPEAT = 3;
    /** Frases y párrafos largos (en palabras) */
    public const LONG_SENTENCE = 30;
    public const LONG_PARAGRAPH = 120;
    /** Por debajo de estas palabras, la legibilidad no es concluyente */
    public const MIN_WORDS = 100;

    /** Palabras genéricas que no describen ninguna imagen (ya normalizadas con Insights::fold) */
    private const GENERIC = ['imagen', 'image', 'img', 'imagenes', 'images', 'foto', 'fotos', 'fotografia', 'photo', 'picture', 'pic',
        'banner', 'icono', 'icon', 'logo', 'logotipo', 'grafico', 'graphic', 'untitled', 'sin titulo', 'default', 'placeholder',
        'alt', 'null', 'undefined', 'slide', 'slider', 'thumbnail', 'miniatura', 'image description', 'descripcion', 'texto alternativo', 'alt text'];

    public const MOTIVOS = [
        'archivo' => 'Parece un nombre de archivo',
        'generico' => 'Palabra genérica que no describe la imagen',
        'corto' => 'Demasiado corto para describir nada',
        'largo' => 'Demasiado largo (más de ' . self::ALT_MAX_CHARS . ' caracteres): mejor una descripción breve',
        'repetido' => 'El mismo texto en varias imágenes distintas',
        'redundante' => 'Empieza por «imagen de» o «foto de»: el lector de pantalla ya dice que es una imagen',
    ];

    /** Motivo por el que un alt es sospechoso (o null). No mira si se repite: eso depende de las demás imágenes. */
    public static function altReason(string $alt): ?string
    {
        $alt = trim((string) preg_replace('/\s+/u', ' ', $alt));
        if ($alt === '') {
            return null;   // alt="" es correcto en imágenes decorativas (las que faltan las mira C06)
        }
        if (preg_match('/\.(jpe?g|png|gif|webp|svg|avif|bmp|tiff?)\b/i', $alt)
            || preg_match('/^(img|dsc|dscn|pxl|imag|screenshot|captura|whatsapp|photo|foto)[\s_\-]?\d/i', $alt)
            || (!preg_match('/\s/u', $alt) && preg_match('/[_\-]/', $alt) && mb_strlen($alt) > 12)) {
            return 'archivo';
        }
        $folded = Insights::fold($alt);
        if (in_array($folded, self::GENERIC, true)) {
            return 'generico';
        }
        if (mb_strlen($alt) <= 2) {
            return 'corto';
        }
        if (mb_strlen($alt) > self::ALT_MAX_CHARS) {
            return 'largo';
        }
        if (preg_match('/^(imagen|foto|fotografia|image|picture|photo) (de|del|of) /', $folded)) {
            return 'redundante';
        }
        return null;
    }

    /** RM-01 · Imágenes con texto alternativo sospechoso. */
    public static function altText(DOMXPath $x): array
    {
        $images = [];
        foreach ($x->query('//img[@alt]') as $img) {
            if (!$img instanceof DOMElement || DomHelper::isHidden($img)) {
                continue;
            }
            $alt = trim((string) preg_replace('/\s+/u', ' ', $img->getAttribute('alt')));
            if ($alt === '') {
                continue;
            }
            $images[] = ['el' => $img, 'alt' => $alt, 'src' => self::realSrc($img)];
        }
        // El mismo alt en varias imágenes con distinto src
        $srcByAlt = [];
        foreach ($images as $i) {
            $srcByAlt[Insights::fold($i['alt'])][$i['src']] = true;
        }
        $items = [];
        $counts = array_fill_keys(array_keys(self::MOTIVOS), 0);
        $total = 0;
        foreach ($images as $i) {
            $reason = self::altReason($i['alt']);
            if ($reason === null && count($srcByAlt[Insights::fold($i['alt'])] ?? []) >= self::ALT_REPEAT) {
                $reason = 'repetido';
            }
            if ($reason === null) {
                continue;
            }
            $total++;
            $counts[$reason]++;
            if (count($items) < self::MAX_ALT_ITEMS) {
                $items[] = [
                    'alt' => Insights::truncate($i['alt'], 200),
                    'src' => Insights::truncate($i['src'], 200),
                    'motivo' => $reason,
                    'selector' => DomHelper::cssPath($i['el']),
                ];
            }
        }
        return [
            'imagenes_con_alt' => count($images),
            'sospechosos' => $total,
            'por_motivo' => array_filter($counts),
            'ejemplos' => $items,
        ];
    }

    /** Dirección real de la imagen: con carga diferida, src suele ser un marcador (data:…) y la buena va en data-src. */
    private static function realSrc(DOMElement $img): string
    {
        // data-src, data-lazy-src, nitro-lazy-src… (cualquier atributo *-src) y, si no, src
        $candidates = [];
        foreach ($img->attributes as $attr) {
            if (preg_match('/.-src$|^data-original$/i', $attr->name)) {
                $candidates[] = $attr->value;
            }
        }
        $candidates[] = $img->getAttribute('src');
        foreach ($candidates as $v) {
            $v = trim($v);
            if ($v !== '' && stripos($v, 'data:') !== 0) {
                return $v;
            }
        }
        $srcset = trim($img->getAttribute('data-srcset') ?: $img->getAttribute('srcset'));
        if ($srcset !== '' && stripos($srcset, 'data:') !== 0) {
            return (string) preg_replace('/\s.*$/s', '', $srcset);
        }
        return trim($img->getAttribute('src'));
    }

    /** Sílabas de una palabra en español (aproximación: grupos de vocales, separando hiatos). */
    public static function syllables(string $word): int
    {
        $w = mb_strtolower($word, 'UTF-8');
        // «qu» y «gu» delante de e/i: la u no suena
        $w = (string) preg_replace('/([qg])u([eéií])/u', '$1$2', $w);
        if (!preg_match_all('/[aeiouáéíóúü]+/u', $w, $m)) {
            return 1;
        }
        $n = 0;
        foreach ($m[0] as $group) {
            $chars = preg_split('//u', $group, -1, PREG_SPLIT_NO_EMPTY);
            $n++;
            // Dos vocales fuertes seguidas (o una débil con tilde) forman hiato: una sílaba más
            for ($k = 1; $k < count($chars); $k++) {
                $strong = fn(string $c) => in_array($c, ['a', 'e', 'o', 'á', 'é', 'ó', 'í', 'ú'], true);
                if ($strong($chars[$k - 1]) && $strong($chars[$k])) {
                    $n++;
                }
            }
        }
        return max(1, $n);
    }

    /** Índice de Fernández-Huerta: 206,84 − 0,60·P − 1,02·F (P: sílabas por 100 palabras; F: frases por 100 palabras). */
    public static function fernandezHuerta(int $words, int $syllables, int $sentences): ?float
    {
        if ($words === 0 || $sentences === 0) {
            return null;
        }
        $score = 206.84 - 0.60 * ($syllables / $words * 100) - 1.02 * ($sentences / $words * 100);
        return round(max(0.0, min(100.0, $score)), 1);
    }

    /** Escala habitual del índice (de 0, muy difícil, a 100, muy fácil). */
    public static function level(?float $score): string
    {
        if ($score === null) {
            return 'sin datos';
        }
        foreach ([[90, 'muy fácil'], [80, 'fácil'], [70, 'bastante fácil'], [60, 'normal'], [50, 'bastante difícil'], [30, 'difícil']] as [$min, $label]) {
            if ($score >= $min) {
                return $label;
            }
        }
        return 'muy difícil';
    }

    /** RM-09 · Legibilidad del contenido principal (solo español). */
    public static function readability(DOMXPath $x): array
    {
        $lang = strtolower(trim((string) $x->evaluate('string(/html/@lang)')));
        if ($lang !== '' && substr($lang, 0, 2) !== 'es') {
            return ['disponible' => false, 'motivo' => 'Solo se mide en páginas en español (la página declara «' . $lang . '»).'];
        }
        [, , $nodes] = DomHelper::mainContent($x);
        // Bloques de texto. Muchas webs maquetan sin <p>: también cuentan los div «hoja» (sin otros bloques dentro).
        $blocks = 'p|li|h1|h2|h3|h4|h5|h6|dd|figcaption|blockquote|td|div|table|ul|ol|section|article';
        $noInner = 'not(' . implode(' or ', array_map(fn($t) => './/' . $t, explode('|', $blocks))) . ')';
        $blockQuery = './/p | .//li[not(.//p)] | .//h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6 | .//dd | .//figcaption'
            . ' | .//blockquote[not(.//p)] | .//td[not(.//p)] | .//div[' . $noInner . ']';
        $words = 0;
        $syllables = 0;
        $sentences = 0;
        $long = [];
        $longCount = 0;
        $paragraphs = 0;
        $longParagraphs = 0;
        $seen = [];
        foreach ($nodes as $root) {
            foreach ($x->query($blockQuery, $root) as $block) {
                if (!$block instanceof DOMElement || DomHelper::isHidden($block)) {
                    continue;
                }
                $text = DomHelper::text($block);
                if ($text === '' || isset($seen[$text])) {
                    continue;
                }
                $seen[$text] = true;
                $blockWords = 0;
                // Frases: se cortan en . ! ? … y al final de cada bloque (títulos y elementos de lista sin punto)
                foreach (preg_split('/(?<=[.!?…])\s+/u', $text) as $sentence) {
                    if (!preg_match_all('/\p{L}[\p{L}\'’\-]*/u', $sentence, $m)) {
                        continue;
                    }
                    $n = count($m[0]);
                    $sentences++;
                    $words += $n;
                    $blockWords += $n;
                    foreach ($m[0] as $w) {
                        $syllables += self::syllables($w);
                    }
                    if ($n > self::LONG_SENTENCE) {
                        $longCount++;
                        if (count($long) < 3) {
                            $long[] = ['texto' => Insights::truncate($sentence, 220), 'palabras' => $n];
                        }
                    }
                }
                if (strtolower($block->tagName) === 'p') {
                    $paragraphs++;
                    if ($blockWords > self::LONG_PARAGRAPH) {
                        $longParagraphs++;
                    }
                }
            }
        }
        $score = self::fernandezHuerta($words, $syllables, $sentences);
        return [
            'disponible' => true,
            'idioma_supuesto' => $lang === '',
            'indice' => $score,
            'nivel' => self::level($score),
            'concluyente' => $words >= self::MIN_WORDS,
            'palabras' => $words,
            'frases' => $sentences,
            'palabras_por_frase' => $sentences ? round($words / $sentences, 1) : null,
            'silabas_por_palabra' => $words ? round($syllables / $words, 2) : null,
            'frases_largas' => $longCount,
            'ejemplos_frases_largas' => $long,
            'parrafos' => $paragraphs,
            'parrafos_largos' => $longParagraphs,
        ];
    }

    /** Resumen corto para cada página de un sitio (se agrega en assets/js/site.js). */
    public static function compact(DOMXPath $x): array
    {
        $alt = self::altText($x);
        $read = self::readability($x);
        return [
            'alt_sospechosos' => $alt['sospechosos'],
            'alt_ejemplos' => array_slice(array_map(fn($i) => ['alt' => $i['alt'], 'motivo' => $i['motivo']], $alt['ejemplos']), 0, 3),
            'legibilidad' => !empty($read['disponible']) && !empty($read['concluyente']) ? $read['indice'] : null,
        ];
    }
}
