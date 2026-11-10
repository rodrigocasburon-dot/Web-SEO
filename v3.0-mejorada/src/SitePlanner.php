<?php
/**
 * v3.0 · Modo «sitio completo» (idea de la v1.0, hecho rápido):
 * en vez de rastrear todas las páginas una a una (la v1.0 tardaba ~20 min), se elige una MUESTRA
 * representativa de páginas del mismo sitio y se analizan en paralelo.
 *
 * De dónde salen las páginas candidatas:
 *  1. Enlaces de la portada (son las páginas que el propio sitio considera importantes).
 *  2. Sitemap (declarado en robots.txt o /sitemap.xml). Se leen como mucho `site_sitemap_files`
 *     archivos de hasta 2 MB cada uno: un sitemap enorme no agota la memoria (fallo de la v1.0).
 *
 * Cómo se elige la muestra: se agrupan las URL por sección (primer tramo de la ruta: /blog, /productos…)
 * y se van cogiendo por turnos de cada sección. Así una tienda con 60.000 fichas de producto no se come
 * la muestra entera y entran también las páginas de categoría, contacto, blog, etc.
 *
 * Solo URL del mismo sitio, http/https, sin parámetros, que parezcan páginas HTML y que robots.txt
 * permita a nuestro User-Agent.
 */
class SitePlanner
{
    /** Extensiones que no son páginas HTML */
    private const NOT_PAGES = '/\.(pdf|jpe?g|png|gif|webp|avif|svg|ico|css|js|json|xml|txt|zip|rar|gz|mp4|mp3|webm|avi|mov|docx?|xlsx?|pptx?|csv|woff2?|ttf|eot)$/i';

    /**
     * @param callable $fetch fn(string $url, array $options = []): array (resultado de Fetcher::fetch)
     * @return array{paginas: string[], candidatas: int, desde_enlaces: int, desde_sitemap: int, sitemaps_leidos: int, notas: string[]}
     */
    public static function plan(string $homeUrl, string $homeHtml, ?string $robotsBody, callable $fetch, array $config): array
    {
        $max = max(1, min(500, (int) ($config['site_max_pages'] ?? 50)));
        $maxFiles = max(0, (int) ($config['site_sitemap_files'] ?? 4));
        $budget = (float) ($config['site_sitemap_budget'] ?? 10);
        $host = (string) parse_url($homeUrl, PHP_URL_HOST);
        $notas = [];

        $robots = $robotsBody !== null ? RobotsTxt::parse($robotsBody) : null;
        $agent = RobotsTxt::token((string) ($config['user_agent'] ?? 'ProconsiAnalyzer'));

        $links = self::filter(self::linksFrom($homeHtml, $homeUrl), $host, $robots, $agent);

        // Sitemap: los declarados en robots.txt o, si no hay, /sitemap.xml
        $queue = $robotsBody !== null ? self::declaredSitemaps($robotsBody) : [];
        if (!$queue) {
            $p = parse_url($homeUrl);
            $queue = [$p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/sitemap.xml'];
        }
        $fromSitemap = [];
        $read = 0;
        $seen = [];
        $deadline = microtime(true) + $budget;
        while ($queue && $read < $maxFiles && microtime(true) < $deadline) {
            $sm = array_shift($queue);
            if (isset($seen[$sm])) {
                continue;
            }
            $seen[$sm] = true;
            $r = $fetch($sm, ['timeout' => 6, 'max_bytes' => 2097152]);
            if (empty($r['ok']) || (int) ($r['status'] ?? 0) !== 200) {
                continue;
            }
            $read++;
            $body = (string) $r['body'];
            if (substr($body, 0, 2) === "\x1f\x8b" && function_exists('gzdecode')) {
                $body = (string) @gzdecode($body);
            }
            $parsed = self::parseSitemap($body);
            if ($parsed['indice']) {
                // Índice: se reparten los archivos que se van a leer por todo el índice
                $queue = array_merge($queue, self::spread($parsed['urls'], max(1, $maxFiles - $read)));
            } else {
                $fromSitemap = array_merge($fromSitemap, $parsed['urls']);
            }
            unset($body, $parsed);
        }
        if ($queue && $read >= $maxFiles) {
            $notas[] = "Se han leído $read archivos de sitemap; el resto no se ha leído para no alargar el análisis.";
        }
        $fromSitemap = self::filter($fromSitemap, $host, $robots, $agent);

        // La portada se analiza aparte (completa)
        $homeKey = self::key($homeUrl);
        $links = array_values(array_filter($links, fn($u) => self::key($u) !== $homeKey));
        $fromSitemap = array_values(array_filter($fromSitemap, fn($u) => self::key($u) !== $homeKey));

        $all = self::unique(array_merge($links, $fromSitemap));
        return [
            'paginas' => self::sample($links, $fromSitemap, $max),
            'candidatas' => count($all),
            'desde_enlaces' => count($links),
            'desde_sitemap' => count($fromSitemap),
            'sitemaps_leidos' => $read,
            'notas' => $notas,
        ];
    }

    /** @return array{indice: bool, urls: string[]} */
    public static function parseSitemap(string $body): array
    {
        $indice = (bool) preg_match('/<sitemapindex[\s>]/i', $body);
        $urls = [];
        if (preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)\s*(?:\]\]>)?\s*</loc>#i', $body, $m)) {
            foreach ($m[1] as $u) {
                $urls[] = html_entity_decode($u, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        } elseif (!preg_match('/<\w/', $body)) {
            // Sitemap de texto: una URL por línea
            foreach (preg_split('/\R/', $body) as $line) {
                $line = trim($line);
                if (preg_match('#^https?://#i', $line)) {
                    $urls[] = $line;
                }
            }
        }
        return ['indice' => $indice, 'urls' => $urls];
    }

    /** @return string[] sitemaps declarados en robots.txt */
    public static function declaredSitemaps(string $robots): array
    {
        preg_match_all('/^\s*sitemap\s*:\s*(\S+)/im', $robots, $m);
        return array_values(array_unique(array_filter($m[1], fn($u) => (bool) preg_match('#^https?://#i', $u))));
    }

    /** @return string[] enlaces absolutos de un HTML */
    public static function linksFrom(string $html, string $base): array
    {
        if ($html === '') {
            return [];
        }
        $x = new DOMXPath(CheckContext::parse($html));
        $baseHref = trim((string) $x->evaluate('string(//base/@href)'));
        if ($baseHref !== '') {
            $base = self::resolve($baseHref, $base) ?? $base;
        }
        $out = [];
        foreach ($x->query('//a[@href]') as $a) {
            $abs = self::resolve(trim($a->getAttribute('href')), $base);
            if ($abs !== null) {
                $out[] = $abs;
            }
        }
        return $out;
    }

    /** Resuelve una URL relativa (suficiente para href habituales). */
    public static function resolve(string $href, string $base): ?string
    {
        if ($href === '' || $href[0] === '#' || preg_match('/^(mailto|tel|javascript|data|ftp):/i', $href)) {
            return null;
        }
        $href = preg_replace('/#.*$/s', '', $href);
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        if (!$b || empty($b['scheme']) || empty($b['host'])) {
            return null;
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (strpos($href, '//') === 0) {
            return $b['scheme'] . ':' . $href;
        }
        if ($href !== '' && $href[0] === '/') {
            return $origin . $href;
        }
        if ($href !== '' && $href[0] === '?') {
            return $origin . ($b['path'] ?? '/') . $href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
        $path = $dir . $href;
        // Quita ./ y ../
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                array_pop($parts);
            } elseif ($seg !== '.') {
                $parts[] = $seg;
            }
        }
        $path = implode('/', $parts);
        return $origin . ($path === '' || $path[0] !== '/' ? '/' . $path : $path);
    }

    /** Se queda con páginas del mismo sitio, sin parámetros, que parezcan HTML y que robots.txt permita. */
    public static function filter(array $urls, string $host, ?RobotsTxt $robots, string $agent): array
    {
        $bare = self::bareHost($host);
        $out = [];
        foreach ($urls as $u) {
            $p = parse_url($u);
            if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
                continue;
            }
            if (self::bareHost($p['host']) !== $bare || isset($p['query']) || isset($p['user'])) {
                continue;
            }
            $path = $p['path'] ?? '/';
            if (preg_match(self::NOT_PAGES, $path)) {
                continue;
            }
            if ($robots && !$robots->isAllowed($agent, $path)) {
                continue;
            }
            // Misma forma que la portada (http/https y www) para no analizar redirecciones
            $out[] = $u;
        }
        return self::unique($out);
    }

    /**
     * Muestra por turnos de sección. Primero las secciones enlazadas desde la portada.
     * @return string[]
     */
    public static function sample(array $fromLinks, array $fromSitemap, int $max): array
    {
        $groups = [];
        foreach (self::unique(array_merge($fromLinks, $fromSitemap)) as $u) {
            $groups[self::section($u)][] = $u;
        }
        $out = [];
        while (count($out) < $max && $groups) {
            foreach ($groups as $k => &$list) {
                if (count($out) >= $max) {
                    break;
                }
                $out[] = array_shift($list);
                if (!$list) {
                    unset($groups[$k]);
                }
            }
            unset($list);
        }
        return $out;
    }

    /** Sección de una URL: primer tramo de la ruta (o «/»). */
    public static function section(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return '/';
        }
        $first = strtolower(explode('/', $path)[0]);
        // /es/, /en/…: el idioma no es una sección; se usa el siguiente tramo
        if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $first) && substr_count($path, '/') >= 1) {
            $first .= '/' . strtolower(explode('/', $path)[1]);
        }
        return $first;
    }

    /** Elige $n elementos repartidos por toda la lista. */
    public static function spread(array $list, int $n): array
    {
        $count = count($list);
        if ($count <= $n) {
            return $list;
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $list[(int) floor($i * $count / $n)];
        }
        return $out;
    }

    private static function unique(array $urls): array
    {
        $out = [];
        foreach ($urls as $u) {
            $out[self::key($u)] ??= $u;
        }
        return array_values($out);
    }

    private static function key(string $url): string
    {
        $p = parse_url($url);
        return self::bareHost($p['host'] ?? '') . rtrim($p['path'] ?? '/', '/');
    }

    private static function bareHost(string $host): string
    {
        return preg_replace('/^www\./', '', strtolower($host));
    }
}
