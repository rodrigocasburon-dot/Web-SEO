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
