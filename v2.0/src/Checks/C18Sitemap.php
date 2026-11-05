<?php
/**
 * C18 Sitemap: hay un sitemap accesible (declarado en robots.txt o, si no hay ninguno, /sitemap.xml)
 * y la URL analizada figura en él. Se leen como máximo 5 archivos (índices incluidos), de hasta 2 MB
 * cada uno, con un tiempo total limitado. Formatos: XML (urlset y sitemapindex), texto con una URL por
 * línea y XML comprimido con gzip (si PHP tiene zlib).
 * - figura tal cual (sin distinguir mayúsculas del dominio ni barra final) → CUMPLE
 * - figura con otra variante (http/https o www) → AVISO
 * - no figura y no se ha podido leer todo (archivo truncado, más archivos o tiempo) → AVISO
 * - no hay sitemap accesible (404, formato no válido) o se ha leído entero y no la incluye → NO_CUMPLE
 * - bloqueo o error de red en todos los intentos → SIN_DATOS
 * - noindex intencionado → no aplica (una página que no se quiere indexar no debe estar en el sitemap)
 */
class C18Sitemap extends BaseCheck
{
    protected string $id = 'C18';
    protected string $severity = 'MEDIO';
    protected string $criterio = 'Protocolo Sitemaps (sitemaps.org) y directiva Sitemap de robots.txt. No es un criterio WCAG.';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'BAJO';
    protected bool $needsHtml = false;

    private const BLOCK_STATUS = [401, 403, 407, 429];

    protected function evaluate(CheckContext $ctx): array
    {
        $umbral = 'Sitemap accesible (declarado en robots.txt o en /sitemap.xml) que incluya ' . $ctx->url;
        if (!empty($ctx->options['noindex_intentional'])) {
            return $this->result(self::OK, null, $umbral, [], 'La página tiene noindex intencionado: no debe figurar en el sitemap.', 0, false);
        }
        if ($ctx->fetch === null) {
            return $this->result(self::NO_DATA, null, $umbral, [], 'No se ha podido consultar el sitemap.');
        }

        $maxFiles = max(1, (int) ($ctx->config['sitemap_max_files'] ?? 5));
        $budget = (float) ($ctx->config['sitemap_budget'] ?? 8);
        $maxBytes = (int) ($ctx->config['max_bytes'] ?? 2097152);

        $declared = self::declared($ctx);
        $queue = $declared ?: [self::origin($ctx->url) . '/sitemap.xml'];
        $target = self::key($ctx->url, true);
        $loose = self::key($ctx->url, false);
        $deadline = microtime(true) + $budget;

        $seen = [];
        $ev = [];
        $read = 0;          // archivos leídos como sitemap
        $urlsRead = 0;
        $missing = 0;       // 404, otros 4xx o formato no válido
        $unreachable = 0;   // bloqueo, 5xx o error de red
        $partial = false;
        $match = null;
        $variant = null;
        $note = function (string $text) use (&$ev) {
            if (count($ev) < 10) {
                $ev[] = DomHelper::note($text, 'sitemap');
            }
        };

        while ($queue) {
            $url = (string) array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            if (count($seen) >= $maxFiles || $deadline - microtime(true) < 1) {
                $queue[] = $url;
                break;
            }
            $seen[$url] = true;
            $r = ($ctx->fetch)($url, ['timeout' => (int) min(5, floor($deadline - microtime(true))), 'max_bytes' => $maxBytes]);
            if (empty($r['ok'])) {
                $code = (string) ($r['error']['code'] ?? 'UNKNOWN');
                $code === 'DNS_FAILED' || $code === 'INVALID_URL' ? $missing++ : $unreachable++;
                $note("$url: no se pudo descargar ($code).");
                continue;
            }
            $status = (int) $r['status'];
            if (!empty($r['blocked']) || in_array($status, self::BLOCK_STATUS, true)) {
                $unreachable++;
                $note("$url: HTTP $status, acceso bloqueado.");
                continue;
            }
            if ($status !== 200) {
                $status >= 500 ? $unreachable++ : $missing++;
                $note("$url: HTTP $status.");
                continue;
            }
            $parsed = self::parse((string) $r['body'], (string) ($r['content_type'] ?? ''));
            if ($parsed === null) {
                $missing++;
                $note("$url: no es un sitemap válido (XML urlset o sitemapindex, o texto con una URL por línea).");
                continue;
            }
            $read++;
            $truncated = !empty($r['truncated']);
            $partial = $partial || $truncated;
            $suffix = $truncated ? ' (truncado al límite de tamaño: lectura parcial)' : '';
            if ($parsed['type'] === 'index') {
                $note("$url: índice con " . count($parsed['locs']) . " sitemaps$suffix.");
                foreach ($parsed['locs'] as $loc) {
                    $queue[] = $loc;
                }
                continue;
            }
            $urlsRead += count($parsed['locs']);
            $note("$url: " . count($parsed['locs']) . " URL$suffix.");
            foreach ($parsed['locs'] as $loc) {
                if (self::key($loc, true) === $target) {
                    $match = $loc;
                    break 2;
                }
                if ($variant === null && self::key($loc, false) === $loose) {
                    $variant = $loc;
                }
            }
        }
        // Quedan archivos sin leer o alguno no se pudo descargar: la búsqueda es parcial
        $partial = $partial || ($match === null && ($queue || $unreachable > 0));

        $valor = [
            'sitemaps_en_robots' => count($declared),
            'archivos_leidos' => $read,
            'urls_leidas' => $urlsRead,
            'incluye_la_url' => $match !== null ? 'sí' : ($variant !== null ? 'con otra variante' : 'no'),
        ];
        if ($match !== null) {
            $note('Entrada encontrada: <loc>' . $match . '</loc>');
            return $this->result(self::OK, $valor, $umbral, $ev, 'La URL figura en el sitemap.');
        }
        if ($read === 0) {
            if ($missing > 0 && $unreachable === 0) {
                $msg = $declared
                    ? 'Los sitemaps declarados en robots.txt no están accesibles o no son válidos.'
                    : 'No hay sitemap declarado en robots.txt ni en /sitemap.xml.';
                return $this->result(self::FAIL, $valor, $umbral, $ev, $msg, 1);
            }
            return $this->result(self::NO_DATA, $valor, $umbral, $ev, 'No se ha podido leer el sitemap (bloqueo o error de red). No indica un fallo del sitio.');
        }
        if ($variant !== null) {
            $note('Entrada con otra variante: <loc>' . $variant . '</loc>');
            return $this->result(self::WARN, $valor, $umbral, $ev, 'La URL figura en el sitemap con otra variante (http/https o www): ' . $variant);
        }
        if ($partial) {
            return $this->result(self::WARN, $valor, $umbral, $ev, "No se ha encontrado la URL en la parte revisada del sitemap ($read archivo(s), $urlsRead URL); no se ha podido revisar entero.");
        }
        return $this->result(self::FAIL, $valor, $umbral, $ev, "El sitemap no incluye esta URL ($urlsRead URL revisadas).", 1);
    }

    /** Sitemaps declarados en robots.txt (directiva Sitemap, URL absolutas http/https). */
    private static function declared(CheckContext $ctx): array
    {
        $status = (int) ($ctx->robots['status'] ?? 0);
        if ($status < 200 || $status >= 300 || !preg_match_all('/^\s*sitemap\s*:\s*(\S+)/im', (string) ($ctx->robots['body'] ?? ''), $m)) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $u) {
            $u = Fetcher::resolveUrl(self::origin($ctx->url) . '/', $u);
            if (preg_match('#^https?://#i', $u)) {
                $out[$u] = true;
            }
        }
        return array_slice(array_keys($out), 0, 10);
    }

    private static function origin(string $url): string
    {
        $p = parse_url($url) ?: [];
        return strtolower($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /**
     * Extrae las URL de un sitemap. Se usan expresiones regulares (no un analizador XML) para tolerar
     * archivos truncados y no procesar entidades externas. Solo cuenta <loc> sin prefijo: los <image:loc>
     * y similares de las extensiones no son URL de páginas.
     * @return array{type: string, locs: string[]}|null
     */
    public static function parse(string $body, string $contentType = ''): ?array
    {
        if (strncmp($body, "\x1f\x8b", 2) === 0) {
            $body = function_exists('gzdecode') ? (string) @gzdecode($body) : '';
            if ($body === '') {
                return null;
            }
        }
        if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) {
            $body = substr($body, 3);
        }
        $isIndex = (bool) preg_match('/<(?:\w+:)?sitemapindex\b/i', $body);
        if ($isIndex || preg_match('/<(?:\w+:)?urlset\b/i', $body)) {
            preg_match_all('#<loc>\s*(.*?)\s*</loc>#is', $body, $m);
            $locs = array_map(fn($l) => html_entity_decode($l, ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1]);
            return ['type' => $isIndex ? 'index' : 'urlset', 'locs' => $locs];
        }
        // Formato de texto: una URL por línea
        if (stripos($contentType, 'html') === false && (ltrim($body)[0] ?? '') !== '<') {
            $locs = [];
            foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (!preg_match('#^https?://\S+$#i', $line)) {
                    return null;
                }
                $locs[] = $line;
            }
            return $locs ? ['type' => 'urlset', 'locs' => $locs] : null;
        }
        return null;
    }

    /** Clave de comparación. $strict: esquema, dominio, puerto, ruta y consulta; si no, dominio sin www, ruta y consulta. */
    private static function key(string $url, bool $strict): array
    {
        $p = parse_url(trim($url)) ?: [];
        $host = strtolower($p['host'] ?? '');
        $path = rtrim($p['path'] ?? '', '/');
        $query = $p['query'] ?? '';
        if ($strict) {
            $scheme = strtolower($p['scheme'] ?? '');
            return [$scheme, $host, $p['port'] ?? ($scheme === 'https' ? 443 : 80), $path, $query];
        }
        return [preg_replace('/^www\./', '', $host), $path, $query];
    }
}
