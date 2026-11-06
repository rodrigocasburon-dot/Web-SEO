<?php
/**
 * Análisis complementario (no puntúa): vistas informativas calculadas con la misma descarga que las
 * comprobaciones. No asigna estados ni resta puntos; solo muestra lo extraído de la página.
 */
class Insights
{
    /** Máximo de caracteres del texto «lo que ve un bot» que se envían a la pantalla */
    public const BOT_TEXT_MAX = 100000;

    public static function build(CheckContext $ctx): array
    {
        $reason = self::contentUnavailable($ctx);
        return [
            'sin_contenido' => $reason,
            'vista_bot' => $reason === null ? self::botView($ctx) : null,
            'vista_previa' => $reason === null ? self::preview($ctx) : null,
            // robots.txt se descarga aparte: la tabla se muestra aunque la página esté bloqueada
            'bots_ia' => RobotsAnalyzer::table($ctx->url, $ctx->robots, $ctx->config),
            'palabra_clave' => $reason === null ? self::keyword($ctx, (string) ($ctx->options['palabra_clave'] ?? '')) : null,
        ];
    }

    /** Motivo por el que no se puede usar el HTML recibido; null si se puede. */
    public static function contentUnavailable(CheckContext $ctx): ?string
    {
        if ($ctx->blocked) {
            return 'El sitio bloquea el acceso automático o exige acceso: el contenido recibido no es el de la página real.';
        }
        if (!$ctx->isHtml()) {
            return 'El recurso no es HTML.';
        }
        return null;
    }

    /**
     * Función 02 «Lo que ve un bot»: texto del contenido principal en el HTML inicial, sin ejecutar
     * JavaScript. Usa la misma extracción y el mismo recuento que C03 (DomHelper::mainContent / countWords).
     */
    public static function botView(CheckContext $ctx): array
    {
        [$source, $text] = DomHelper::mainContent($ctx->xpath());
        $min = (int) ($ctx->config['min_words_raw_html'] ?? 50);
        $words = DomHelper::countWords($text);
        $chars = mb_strlen($text, 'UTF-8');
        $cut = $chars > self::BOT_TEXT_MAX;
        return [
            'fuente' => $source,
            'palabras' => $words,
            'minimo_palabras' => $min,
            'poco_texto' => $words < $min,
            'caracteres' => $chars,
            'texto' => $cut ? mb_substr($text, 0, self::BOT_TEXT_MAX, 'UTF-8') : $text,
            'texto_recortado' => $cut,
            'html_recortado' => $ctx->truncated,
        ];
    }

    /** Recorte aproximado de los resultados de Google (el real depende del ancho en píxeles) */
    public const SNIPPET_TITLE_MAX = 60;
    public const SNIPPET_URL_MAX = 60;
    public const SNIPPET_DESC_MAX = 155;
    /** Etiquetas de redes sociales que se comprueban */
    public const SOCIAL_TAGS = ['og:title', 'og:description', 'og:image', 'twitter:card'];

    /**
     * Función 03 «Vista previa en Google y redes»: title, URL y meta description recortados como en un
     * resultado de búsqueda, y la tarjeta social (Open Graph / Twitter). Es una aproximación: cada buscador
     * y red decide qué muestra y puede usar otros textos.
     */
    public static function preview(CheckContext $ctx): array
    {
        $x = $ctx->xpath();
        $titleEl = $x->query('//title[not(ancestor::svg)]')->item(0);
        $title = $titleEl ? self::clean($titleEl->textContent) : '';
        $meta = self::metaTags($x);
        $desc = $meta['description'] ?? '';

        $google = [
            'titulo' => $title === '' ? null : $title,
            'url' => $ctx->url,
            'descripcion' => $desc === '' ? null : $desc,
        ];
        foreach (['titulo' => self::SNIPPET_TITLE_MAX, 'url' => self::SNIPPET_URL_MAX, 'descripcion' => self::SNIPPET_DESC_MAX] as $k => $max) {
            $v = (string) $google[$k];
            $google[$k . '_mostrado'] = $v === '' ? null : self::truncate($v, $max);
            $google[$k . '_longitud'] = mb_strlen($v, 'UTF-8');
            $google[$k . '_recortado'] = mb_strlen($v, 'UTF-8') > $max;
        }

        $tags = [];
        foreach (self::SOCIAL_TAGS as $t) {
            $tags[$t] = isset($meta[$t]) && $meta[$t] !== '' ? $meta[$t] : null;
        }
        // Lo que mostraría una red: Open Graph, si no Twitter, si no title/description (aproximación)
        [$sTitle, $sTitleFrom] = self::firstOf($meta, ['og:title', 'twitter:title']) ?? [$google['titulo'], $google['titulo'] === null ? null : 'title'];
        [$sDesc, $sDescFrom] = self::firstOf($meta, ['og:description', 'twitter:description']) ?? [$google['descripcion'], $google['descripcion'] === null ? null : 'meta description'];
        [$img, $imgFrom] = self::firstOf($meta, ['og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image', 'twitter:image:src']) ?? [null, null];
        $imgUrl = $img === null ? null : Fetcher::resolveUrl($ctx->url, $img);
        $imgHttps = $imgUrl !== null && preg_match('#^https://[^/\s]+#i', $imgUrl) === 1;

        return [
            'google' => $google,
            'social' => [
                'etiquetas' => $tags,
                'ausentes' => array_keys(array_filter($tags, fn($v) => $v === null)),
                'titulo' => $sTitle,
                'titulo_origen' => $sTitleFrom,
                'descripcion' => $sDesc,
                'descripcion_origen' => $sDescFrom,
                'imagen' => $imgUrl,
                'imagen_origen' => $imgFrom,
                // Solo se pide al navegador una imagen https (en http se mostraría contenido mixto)
                'imagen_https' => $imgHttps,
                'tarjeta' => $tags['twitter:card'],
                'dominio' => (string) parse_url($ctx->url, PHP_URL_HOST),
            ],
        ];
    }

    /** Longitud máxima de la palabra clave y nº de palabras iniciales del contenido en que se busca */
    public const KEYWORD_MAX = 100;
    public const KEYWORD_FIRST_WORDS = 100;

    /** Normaliza la palabra clave recibida en la petición ('' si no hay). */
    public static function keywordInput($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = self::clean((string) preg_replace('/[\p{C}]/u', ' ', $value));
        return mb_substr($value, 0, self::KEYWORD_MAX, 'UTF-8');
    }

    /**
     * Función 05 «Palabra clave objetivo»: presencia (sin distinguir mayúsculas ni tildes, como frase
     * completa) en title, H1, meta description, URL y primeras 100 palabras del contenido principal.
     * Informativa: no puntúa. null si no se ha indicado palabra clave.
     */
    public static function keyword(CheckContext $ctx, string $keyword): ?array
    {
        $keyword = self::keywordInput($keyword);
        $needle = self::fold($keyword);
        if ($needle === '') {
            return null;
        }
        $x = $ctx->xpath();
        $titleEl = $x->query('//title[not(ancestor::svg)]')->item(0);
        $title = $titleEl ? self::clean($titleEl->textContent) : '';
        $h1s = [];
        foreach ($x->query('//h1[not(ancestor::template)]') as $h) {
            $t = DomHelper::text($h);
            if ($t !== '') {
                $h1s[] = $t;
            }
        }
        $desc = self::metaTags($x)['description'] ?? '';
        $url = rawurldecode((string) preg_replace('#^https?://#i', '', $ctx->url));
        [, $main] = DomHelper::mainContent($x);
        $first = self::firstWords($main, self::KEYWORD_FIRST_WORDS);

        $h1Match = null;
        foreach ($h1s as $h) {
            if (self::contains($h, $needle)) {
                $h1Match = $h;
                break;
            }
        }
        $items = [
            ['elemento' => 'title', 'nombre' => 'Title', 'presente' => self::contains($title, $needle), 'texto' => $title === '' ? null : $title],
            ['elemento' => 'h1', 'nombre' => 'H1', 'presente' => $h1Match !== null, 'texto' => $h1Match ?? ($h1s[0] ?? null)],
            ['elemento' => 'meta_description', 'nombre' => 'Meta description', 'presente' => self::contains($desc, $needle), 'texto' => $desc === '' ? null : $desc],
            ['elemento' => 'url', 'nombre' => 'URL', 'presente' => self::contains($url, $needle), 'texto' => $ctx->url],
            ['elemento' => 'primeras_palabras', 'nombre' => 'Primeras ' . self::KEYWORD_FIRST_WORDS . ' palabras del contenido principal', 'presente' => self::contains($first, $needle), 'texto' => null],
        ];
        return [
            'palabra_clave' => $keyword,
            'elementos' => $items,
            'presente_en' => count(array_filter($items, fn($i) => $i['presente'])),
        ];
    }

    /** Minúsculas sin tildes ni diéresis (la ñ se conserva) y signos de puntuación como espacios. */
    public static function fold(string $s): string
    {
        // Guiones blandos y caracteres invisibles de formato (U+00AD, U+200B…) no separan palabras
        $s = mb_strtolower((string) preg_replace('/\p{Cf}+/u', '', $s), 'UTF-8');
        $s = strtr($s, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s));
    }

    /** ¿Aparece la frase (ya normalizada) como palabras completas? */
    private static function contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && strpos(' ' . self::fold($haystack) . ' ', ' ' . $needle . ' ') !== false;
    }

    /** Texto hasta el final de la palabra n.º $n (mismo criterio de palabra que DomHelper::countWords). */
    private static function firstWords(string $text, int $n): string
    {
        if (!preg_match_all(DomHelper::WORD_RE, $text, $m, PREG_OFFSET_CAPTURE) || count($m[0]) <= $n) {
            return $text;
        }
        [$word, $offset] = $m[0][$n - 1];
        return substr($text, 0, $offset + strlen($word));
    }

    /**
     * Metaetiquetas por nombre en minúsculas (name o property; la primera no vacía de cada una).
     * @return array<string,string>
     */
    private static function metaTags(DOMXPath $x): array
    {
        $out = [];
        foreach ($x->query('//meta[@content][not(ancestor::template)]') as $m) {
            if (!$m instanceof DOMElement) {
                continue;
            }
            foreach (['property', 'name'] as $attr) {
                $key = strtolower(trim($m->getAttribute($attr)));
                $val = self::clean($m->getAttribute('content'));
                if ($key !== '' && $val !== '' && !isset($out[$key])) {
                    $out[$key] = $val;
                }
            }
        }
        return $out;
    }

    /** @return array{0:string,1:string}|null primer valor presente y la etiqueta de la que sale */
    private static function firstOf(array $meta, array $keys): ?array
    {
        foreach ($keys as $k) {
            if (isset($meta[$k]) && $meta[$k] !== '') {
                return [$meta[$k], $k];
            }
        }
        return null;
    }

    private static function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** Recorta a unos $max caracteres sin partir palabras (si se puede) y añade «…». */
    public static function truncate(string $s, int $max): string
    {
        if (mb_strlen($s, 'UTF-8') <= $max) {
            return $s;
        }
        $cut = mb_substr($s, 0, $max, 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($space !== false && $space >= (int) ($max * 0.7)) {
            $cut = mb_substr($cut, 0, $space, 'UTF-8');
        }
        return (preg_replace('/[\s,.;:|·\-–—]+$/u', '', $cut) ?? $cut) . '…';
    }
}
