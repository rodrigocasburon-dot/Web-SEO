<?php
/**
 * Identidad detectada de la web (DESIGN.md §7). Informativo: no puntúa ni asigna estados.
 * Extrae de la página y de sus hojas de estilo los colores principales, las tipografías, el logotipo, el
 * favicon y el theme-color. Cada dato lleva su origen y una confianza (alta, media, baja).
 * Limitación: solo se lee el CSS del HTML inicial y de las primeras hojas enlazadas; no se ejecuta
 * JavaScript ni se siguen @import, así que los estilos inyectados por scripts no se ven.
 */
class IdentityDetector
{
    public const MAX_SHEETS = 3;
    public const SHEET_BYTES = 262144;
    public const SHEET_TIMEOUT = 4;
    /** Presupuesto total para descargar hojas de estilo (segundos) */
    public const BUDGET = 8;
    public const MAX_COLORS = 6;
    public const MAX_FONTS = 4;
    /** Distancia RGB por debajo de la cual dos tonos se agrupan */
    private const NEAR = 28;
    /** Variables CSS cuyo nombre sugiere un color o una fuente de marca */
    private const BRAND_VAR = '/(primar|brand|marca|accent|acento|principal|corporat|secondar|secundar|main|theme|tema)/i';
    /** Variables de librerías y temas con valores por defecto (no son elección de la web) */
    private const LIBRARY_VAR = '/^--(swiper|wp--preset|wp-admin|wp--style|plyr|toastify|fa-|glide|splide|slick|tw-)/i';
    private const GENERIC_FONTS = ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-sans-serif',
        'ui-serif', 'ui-monospace', 'ui-rounded', '-apple-system', 'blinkmacsystemfont', 'inherit', 'initial', 'unset',
        'revert', 'emoji', 'math', 'fangsong'];
    /** Fuentes del sistema: indican la pila de reserva, no una elección de marca */
    private const SYSTEM_FONTS = ['arial', 'helvetica', 'helvetica neue', 'segoe ui', 'times new roman', 'times', 'courier new',
        'courier', 'verdana', 'tahoma', 'georgia', 'apple color emoji', 'segoe ui emoji', 'segoe ui symbol', 'noto color emoji',
        'liberation sans', 'menlo', 'monaco', 'consolas'];
    private const NAMED = ['white' => '#FFFFFF', 'black' => '#000000', 'red' => '#FF0000', 'blue' => '#0000FF',
        'green' => '#008000', 'orange' => '#FFA500', 'navy' => '#000080', 'gray' => '#808080', 'grey' => '#808080'];

    public const LIMITATION = 'Datos extraídos del HTML inicial y de las primeras hojas de estilo enlazadas, sin ejecutar JavaScript ni seguir @import: los estilos inyectados por scripts o cargados después no se ven. La frecuencia de un color en el CSS no garantiza que sea de marca; revísalo manualmente.';

    public static function detect(CheckContext $ctx): array
    {
        $x = $ctx->xpath();
        $base = self::baseUrl($ctx, $x);
        $sources = [];
        foreach ($x->query('//style') as $s) {
            $sources[] = ['css' => (string) $s->textContent, 'origen' => 'CSS en <style>'];
        }
        $attr = '';
        foreach ($x->query('//*[@style]') as $el) {
            $attr .= $el->getAttribute('style') . ";\n";
        }
        if ($attr !== '') {
            $sources[] = ['css' => '*{' . $attr . '}', 'origen' => 'atributos style'];
        }
        [$sheets, $fetched] = self::stylesheets($ctx, $x, $base);
        $sources = array_merge($sources, $fetched);

        return [
            'theme_color' => self::themeColor($x),
            'colores' => self::colors($x, $sources),
            'tipografias' => self::fonts($x, $sources),
            'logotipo' => self::logo($x, $base),
            'favicon' => self::favicon($x, $base),
            'hojas_analizadas' => $sheets,
            'limitacion' => self::LIMITATION,
        ];
    }

    private static function baseUrl(CheckContext $ctx, DOMXPath $x): string
    {
        $b = $x->query('//base[@href]')->item(0);
        return $b instanceof DOMElement ? Fetcher::resolveUrl($ctx->url, trim($b->getAttribute('href'))) : $ctx->url;
    }

    /**
     * Descarga hasta MAX_SHEETS hojas enlazadas (primero las del mismo dominio) con el fetch del contexto
     * (UrlGuard incluido). Sin fetch (pruebas) se listan como no descargadas.
     * @return array{0:array,1:array} [lista informativa, fuentes CSS descargadas]
     */
    private static function stylesheets(CheckContext $ctx, DOMXPath $x, string $base): array
    {
        $host = strtolower((string) parse_url($ctx->url, PHP_URL_HOST));
        $links = [];
        foreach ($x->query('//link[@href]') as $l) {
            $rel = ' ' . strtolower((string) preg_replace('/\s+/', ' ', $l->getAttribute('rel'))) . ' ';
            if (strpos($rel, ' stylesheet ') === false || strpos($rel, ' alternate ') !== false) {
                continue;
            }
            $media = strtolower(trim($l->getAttribute('media')));
            if ($media === 'print') {
                continue;
            }
            $url = explode('#', Fetcher::resolveUrl($base, trim($l->getAttribute('href'))), 2)[0];
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $h = strtolower((string) parse_url($url, PHP_URL_HOST));
            // Google Fonts se interpreta desde la URL; no hace falta descargarla
            if (!in_array($scheme, ['http', 'https'], true) || $h === 'fonts.googleapis.com' || isset($links[$url])) {
                continue;
            }
            $links[$url] = $h === $host ? 0 : 1;
        }
        asort($links, SORT_NUMERIC);
        $list = [];
        $css = [];
        $deadline = microtime(true) + self::BUDGET;
        foreach (array_keys($links) as $i => $url) {
            if ($i >= self::MAX_SHEETS) {
                $list[] = ['url' => $url, 'estado' => 'omitida', 'nota' => 'Fuera del límite de ' . self::MAX_SHEETS . ' hojas.'];
                continue;
            }
            $left = $deadline - microtime(true);
            if (!is_callable($ctx->fetch) || $left < 1) {
                $list[] = ['url' => $url, 'estado' => 'no_descargada', 'nota' => is_callable($ctx->fetch) ? 'Sin tiempo disponible.' : 'Descarga no disponible.'];
                continue;
            }
            $r = ($ctx->fetch)($url, ['timeout' => (int) min(self::SHEET_TIMEOUT, floor($left)), 'max_bytes' => self::SHEET_BYTES]);
            $status = (int) ($r['status'] ?? 0);
            $body = (string) ($r['body'] ?? '');
            if ($status < 200 || $status >= 300 || $body === '' || preg_match('/^\s*<(!doctype|html)/i', $body)) {
                $list[] = ['url' => $url, 'estado' => 'no_descargada', 'nota' => $status > 0 ? 'HTTP ' . $status . '.' : (string) ($r['error'] ?? 'Error de descarga.')];
                continue;
            }
            $list[] = ['url' => $url, 'estado' => 'analizada', 'nota' => null];
            $css[] = ['css' => $body, 'origen' => 'hoja ' . self::shortName($url)];
        }
        return [$list, $css];
    }

    private static function shortName(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $name = basename($path);
        return $name !== '' ? $name : $url;
    }

    private static function themeColor(DOMXPath $x): ?array
    {
        foreach ($x->query('//meta[@name]') as $m) {
            if (strtolower(trim($m->getAttribute('name'))) !== 'theme-color') {
                continue;
            }
            $hex = self::parseColor($m->getAttribute('content'));
            if ($hex !== null) {
                return self::swatch($hex, 'meta theme-color', 'alta', 1);
            }
        }
        return null;
    }

    /** Quita comentarios del CSS. */
    private static function clean(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /**
     * Colores: theme-color y variables CSS con nombre de marca (confianza alta) y, después, los más
     * frecuentes en declaraciones de color (media si aparecen 3 o más veces; baja si menos).
     */
    private static function colors(DOMXPath $x, array $sources): array
    {
        $cands = [];
        $theme = self::themeColor($x);
        if ($theme) {
            $cands[] = ['hex' => $theme['hex'], 'origen' => 'meta theme-color', 'confianza' => 'alta', 'n' => 1000];
        }
        foreach ($x->query('//meta[@name]') as $m) {
            if (strtolower(trim($m->getAttribute('name'))) === 'msapplication-tilecolor' && ($hex = self::parseColor($m->getAttribute('content')))) {
                $cands[] = ['hex' => $hex, 'origen' => 'meta msapplication-TileColor', 'confianza' => 'media', 'n' => 500];
            }
        }
        $freq = [];
        foreach ($sources as $src) {
            $css = self::clean($src['css']);
            if (preg_match_all('/(--[\w-]+)\s*:\s*([^;}{]+)/', $css, $vm, PREG_SET_ORDER)) {
                foreach ($vm as $v) {
                    $hex = self::parseColor(trim($v[2]));
                    if ($hex !== null && preg_match(self::BRAND_VAR, $v[1]) && !preg_match(self::LIBRARY_VAR, $v[1])) {
                        $cands[] = ['hex' => $hex, 'origen' => 'variable ' . $v[1] . ' (' . $src['origen'] . ')', 'confianza' => 'alta', 'n' => 800];
                    }
                }
            }
            if (preg_match_all('/(?<![\w-])(color|background(?:-color)?|border(?:-[a-z]+)?(?:-color)?|fill|stroke|outline(?:-color)?)\s*:\s*([^;}{]+)/i', $css, $dm, PREG_SET_ORDER)) {
                foreach ($dm as $d) {
                    foreach (self::colorsIn($d[2]) as $hex) {
                        if (!isset($freq[$hex])) {
                            $freq[$hex] = ['n' => 0, 'origen' => $src['origen']];
                        }
                        $freq[$hex]['n']++;
                    }
                }
            }
        }
        uasort($freq, function ($a, $b) {
            return $b['n'] <=> $a['n'];
        });
        // Suma los usos de tonos cercanos al más frecuente (#ff5500 y #ff5501 cuentan como uno)
        $merged = [];
        foreach ($freq as $hex => $f) {
            foreach ($merged as $mHex => $m) {
                if (self::distance($mHex, $hex) < self::NEAR) {
                    $merged[$mHex]['n'] += $f['n'];
                    continue 2;
                }
            }
            $merged[$hex] = $f;
        }
        $freq = $merged;
        foreach ($freq as $hex => $f) {
            $cands[] = ['hex' => $hex, 'origen' => 'frecuencia en el CSS (' . $f['n'] . ($f['n'] === 1 ? ' uso; ' : ' usos; ') . $f['origen'] . ')',
                'confianza' => $f['n'] >= 3 ? 'media' : 'baja', 'n' => $f['n']];
        }

        // Agrupa tonos cercanos (gana el primero: mayor prioridad u ocurrencia)
        $groups = [];
        foreach ($cands as $c) {
            foreach ($groups as $g) {
                if (self::distance($g['hex'], $c['hex']) < self::NEAR) {
                    continue 2;
                }
            }
            $groups[] = $c;
        }
        $chromatic = array_values(array_filter($groups, function ($g) {
            return !self::isNeutral($g['hex']);
        }));
        $neutral = array_values(array_filter($groups, function ($g) {
            // El blanco casi nunca es un color de marca: es el fondo por defecto
            return self::isNeutral($g['hex']) && !self::isWhite($g['hex']) && ($g['confianza'] === 'alta' || $g['n'] >= 3);
        }));
        $out = [];
        foreach (array_merge($chromatic, array_slice($neutral, 0, 2)) as $g) {
            if (count($out) >= self::MAX_COLORS) {
                break;
            }
            $out[] = self::swatch($g['hex'], $g['origen'], $g['confianza'], $g['n']) + ['neutro' => self::isNeutral($g['hex'])];
        }
        return $out;
    }

    private static function swatch(string $hex, string $origin, string $confidence, int $n): array
    {
        return ['hex' => $hex, 'contraste_blanco' => self::contrastWithWhite($hex), 'origen' => $origin, 'confianza' => $confidence];
    }

    /** @return string[] colores opacos (#RRGGBB) de un valor CSS */
    public static function colorsIn(string $value): array
    {
        $out = [];
        // Los nombres de variables y las URL pueden contener «blue», «red»…: no son colores
        $value = (string) preg_replace('/(?:var|url)\([^)]*\)?/i', ' ', $value);
        if (preg_match_all('/#[0-9a-f]{3,8}\b|(?:rgba?|hsla?)\([^)]*\)|\b(?:' . implode('|', array_keys(self::NAMED)) . ')\b/i', $value, $m)) {
            foreach ($m[0] as $token) {
                $hex = self::parseColor($token);
                if ($hex !== null) {
                    $out[] = $hex;
                }
            }
        }
        return $out;
    }

    /** Normaliza un color CSS a #RRGGBB; null si no se reconoce o es casi transparente (alfa < 0,5). */
    public static function parseColor(string $v): ?string
    {
        $v = strtolower(trim($v));
        if (isset(self::NAMED[$v])) {
            return self::NAMED[$v];
        }
        if (preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $v, $m)) {
            $h = $m[1];
            if (strlen($h) <= 4) {
                $h = preg_replace('/(.)/', '$1$1', $h);
            }
            if (strlen($h) === 8 && hexdec(substr($h, 6, 2)) < 128) {
                return null;
            }
            return '#' . strtoupper(substr($h, 0, 6));
        }
        if (preg_match('/^(rgba?|hsla?)\(([^)]*)\)$/', $v, $m)) {
            $parts = preg_split('/[\s,\/]+/', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY);
            if (count($parts) < 3) {
                return null;
            }
            if (isset($parts[3])) {
                $a = substr($parts[3], -1) === '%' ? (float) $parts[3] / 100 : (float) $parts[3];
                if ($a < 0.5) {
                    return null;
                }
            }
            if ($m[1][0] === 'r') {
                $rgb = [];
                for ($i = 0; $i < 3; $i++) {
                    $p = $parts[$i];
                    if (!is_numeric(rtrim($p, '%'))) {
                        return null;
                    }
                    $rgb[] = substr($p, -1) === '%' ? (float) $p * 2.55 : (float) $p;
                }
            } else {
                if (!is_numeric(preg_replace('/deg$/', '', $parts[0])) || !is_numeric(rtrim($parts[1], '%')) || !is_numeric(rtrim($parts[2], '%'))) {
                    return null;
                }
                $rgb = self::hslToRgb((float) $parts[0], (float) rtrim($parts[1], '%') / 100, (float) rtrim($parts[2], '%') / 100);
            }
            return vsprintf('#%02X%02X%02X', array_map(function ($c) {
                return (int) round(max(0, min(255, $c)));
            }, $rgb));
        }
        return null;
    }

    private static function hslToRgb(float $h, float $s, float $l): array
    {
        $h = fmod(fmod($h, 360) + 360, 360) / 360;
        if ($s <= 0) {
            return [$l * 255, $l * 255, $l * 255];
        }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $f = function ($t) use ($p, $q) {
            if ($t < 0) {
                $t += 1;
            }
            if ($t > 1) {
                $t -= 1;
            }
            if ($t < 1 / 6) {
                return $p + ($q - $p) * 6 * $t;
            }
            if ($t < 1 / 2) {
                return $q;
            }
            if ($t < 2 / 3) {
                return $p + ($q - $p) * (2 / 3 - $t) * 6;
            }
            return $p;
        };
        return [$f($h + 1 / 3) * 255, $f($h) * 255, $f($h - 1 / 3) * 255];
    }

    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private static function distance(string $a, string $b): float
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);
        return sqrt(($r1 - $r2) ** 2 + ($g1 - $g2) ** 2 + ($b1 - $b2) ** 2);
    }

    /** Gris, blanco o negro: poca diferencia entre canales. */
    public static function isNeutral(string $hex): bool
    {
        $c = self::rgb($hex);
        return max($c) - min($c) < 24;
    }

    private static function isWhite(string $hex): bool
    {
        return min(self::rgb($hex)) >= 240;
    }

    /** Relación de contraste con #FFFFFF según la fórmula de luminancia relativa de WCAG 2. */
    public static function contrastWithWhite(string $hex): float
    {
        $l = 0.0;
        foreach (self::rgb($hex) as $i => $c) {
            $c /= 255;
            $c = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            $l += [0.2126, 0.7152, 0.0722][$i] * $c;
        }
        return round(1.05 / ($l + 0.05), 2);
    }

    /**
     * Tipografías: Google Fonts y @font-face (alta), variables CSS de fuente con nombre de marca (alta) y la
     * primera familia de cada font-family que no sea genérica ni del sistema (media si se repite; baja si no).
     */
    private static function fonts(DOMXPath $x, array $sources): array
    {
        $found = [];
        $add = function (string $name, string $origin, string $confidence, int $n) use (&$found) {
            $name = trim($name, " \t\n\r'\"");
            $key = strtolower($name);
            if ($name === '' || mb_strlen($name) > 60 || in_array($key, self::GENERIC_FONTS, true) || in_array($key, self::SYSTEM_FONTS, true)
                || strpos($key, 'var(') !== false || preg_match('/^(font ?awesome|fa|icomoon|glyphicons|dashicons|material icons|eicons|swiper-icons)/i', $name)) {
                return;
            }
            if (!isset($found[$key])) {
                $found[$key] = ['nombre' => $name, 'origen' => $origin, 'confianza' => $confidence, 'n' => 0];
            } elseif ($confidence === 'alta' && $found[$key]['confianza'] !== 'alta') {
                $found[$key]['origen'] = $origin;
                $found[$key]['confianza'] = 'alta';
            }
            $found[$key]['n'] += $n;
        };
        foreach ($x->query('//link[@href]') as $l) {
            $href = html_entity_decode($l->getAttribute('href'));
            if (!preg_match('#fonts\.googleapis\.com/css2?\?#i', $href)) {
                continue;
            }
            $query = (string) parse_url($href, PHP_URL_QUERY);
            foreach (explode('&', $query) as $pair) {
                [$k, $val] = array_pad(explode('=', $pair, 2), 2, '');
                if ($k !== 'family') {
                    continue;
                }
                foreach (explode('|', urldecode($val)) as $fam) {
                    $add(str_replace('+', ' ', explode(':', $fam, 2)[0]), 'Google Fonts', 'alta', 1);
                }
            }
        }
        foreach ($sources as $src) {
            $css = self::clean($src['css']);
            if (preg_match_all('/@font-face\s*{[^}]*?font-family\s*:\s*([^;}]+)/i', $css, $ff)) {
                foreach ($ff[1] as $f) {
                    $add($f, '@font-face (' . $src['origen'] . ')', 'alta', 1);
                }
            }
            if (preg_match_all('/(--[\w-]+)\s*:\s*([^;}{]+)/', $css, $vm, PREG_SET_ORDER)) {
                foreach ($vm as $v) {
                    $val = trim($v[2]);
                    if (preg_match('/font|family|typo/i', $v[1]) && !preg_match('/size|weight|height|spacing|style|color|icon/i', $v[1])
                        && !preg_match('/^[\d.]/', $val) && self::parseColor($val) === null) {
                        $add(explode(',', $val)[0], 'variable ' . $v[1] . ' (' . $src['origen'] . ')', 'media', 1);
                    }
                }
            }
            $noFace = (string) preg_replace('/@font-face\s*{[^}]*}/i', '', $css);
            if (preg_match_all('/(?<![\w-])(font(?:-family)?)\s*:\s*([^;}{]+)/i', $noFace, $fm, PREG_SET_ORDER)) {
                foreach ($fm as $m) {
                    $decl = $m[2];
                    // En el atajo «font» la familia va al final, tras el tamaño y la altura de línea
                    if (strtolower($m[1]) === 'font') {
                        if (!preg_match('/[\d.]+(?:px|r?em|%|pt|vw|vh)(?:\s*\/\s*[\w.%]+)?\s+(.+)$/i', $decl, $sm)) {
                            continue;
                        }
                        $decl = $sm[1];
                    }
                    $add(explode(',', $decl)[0], 'font-family (' . $src['origen'] . ')', 'baja', 1);
                }
            }
        }
        foreach ($found as &$f) {
            if ($f['confianza'] === 'baja' && $f['n'] >= 2) {
                $f['confianza'] = 'media';
            }
        }
        unset($f);
        $rank = ['alta' => 0, 'media' => 1, 'baja' => 2];
        uasort($found, function ($a, $b) use ($rank) {
            return [$rank[$a['confianza']], -$a['n']] <=> [$rank[$b['confianza']], -$b['n']];
        });
        $out = [];
        foreach (array_slice(array_values($found), 0, self::MAX_FONTS) as $f) {
            $out[] = ['nombre' => $f['nombre'], 'origen' => $f['origen'], 'confianza' => $f['confianza']];
        }
        return $out;
    }

    /** Logotipo: «logo» de Organization en JSON-LD (alta) o imagen con «logo» en alt, clase, id o archivo (media). */
    private static function logo(DOMXPath $x, string $base): ?array
    {
        foreach (JsonLd::nodes($x) as $node) {
            if (!JsonLd::isBusiness($node) || !isset($node['logo'])) {
                continue;
            }
            $logo = $node['logo'];
            $url = is_string($logo) ? $logo : (is_array($logo) ? (string) ($logo['url'] ?? $logo['contentUrl'] ?? '') : '');
            if ($url !== '') {
                return self::image(Fetcher::resolveUrl($base, $url), 'JSON-LD (logo de la organización)', 'alta');
            }
        }
        $imgs = $x->query('//header//img | //*[contains(translate(@class,"LOGO","logo"),"logo") or contains(translate(@id,"LOGO","logo"),"logo")]//img | //img');
        foreach ($imgs as $img) {
            $src = trim($img->getAttribute('src'));
            if ($src === '' || stripos($src, 'data:') === 0) {
                $src = trim(explode(' ', trim($img->getAttribute('data-src')))[0]);
            }
            if ($src === '' || stripos($src, 'data:') === 0) {
                continue;
            }
            $hay = strtolower($img->getAttribute('alt') . ' ' . $img->getAttribute('class') . ' ' . $img->getAttribute('id') . ' ' . basename((string) parse_url($src, PHP_URL_PATH)));
            $parent = $img->parentNode instanceof DOMElement ? strtolower($img->parentNode->getAttribute('class') . ' ' . $img->parentNode->getAttribute('id')) : '';
            if (strpos($hay, 'logo') !== false || strpos($parent, 'logo') !== false) {
                $r = self::image(Fetcher::resolveUrl($base, $src), 'imagen con «logo» en el HTML', 'media');
                $r['alt'] = trim($img->getAttribute('alt'));
                return $r;
            }
        }
        return null;
    }

    private static function image(string $url, string $origin, string $confidence): ?array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        return ['url' => $url, 'origen' => $origin, 'confianza' => $confidence, 'alt' => ''];
    }

    /** Favicon declarado (icon, shortcut icon, apple-touch-icon) o, si no hay, /favicon.ico supuesto. */
    private static function favicon(DOMXPath $x, string $base): ?array
    {
        $best = null;
        foreach ($x->query('//link[@href]') as $l) {
            $rel = strtolower(trim($l->getAttribute('rel')));
            $rank = $rel === 'icon' || $rel === 'shortcut icon' ? 0 : ($rel === 'apple-touch-icon' ? 1 : null);
            if ($rank === null) {
                continue;
            }
            $img = self::image(Fetcher::resolveUrl($base, trim($l->getAttribute('href'))), '<link rel="' . $rel . '">', 'alta');
            if ($img && ($best === null || $rank < $best[0])) {
                $best = [$rank, $img];
            }
        }
        if ($best) {
            return $best[1];
        }
        return self::image(Fetcher::resolveUrl($base, '/favicon.ico'), 'ubicación por defecto /favicon.ico (no declarada ni comprobada)', 'baja');
    }
}
