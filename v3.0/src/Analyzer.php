<?php
/**
 * Orquesta un análisis: descarga la página, descarga robots.txt del mismo origen y ejecuta
 * las comprobaciones. C16 (enlaces) y C18 (sitemap) descargan recursos adicionales mediante
 * $ctx->fetch, con las mismas defensas que la página. No guarda nada.
 */
class Analyzer
{
    private array $config;
    private Fetcher $pageFetcher;
    private Fetcher $robotsFetcher;
    /** @var callable fn(string $url, array $options = []): array */
    private $resourceFetch;

    /**
     * @param callable|null $resourceFetch descarga de enlaces y sitemaps (por defecto, un Fetcher nuevo por petición)
     */
    public function __construct(array $config, ?Fetcher $pageFetcher = null, ?Fetcher $robotsFetcher = null, ?callable $resourceFetch = null)
    {
        $this->config = $config;
        $guard = new UrlGuard();
        $opt = [
            'user_agent' => $config['user_agent'] ?? 'ProconsiAnalyzer/0.1',
            'timeout' => $config['timeout'] ?? 10,
            'max_redirects' => $config['max_redirects'] ?? 5,
            'max_bytes' => $config['max_bytes'] ?? 2097152,
        ];
        $this->pageFetcher = $pageFetcher ?? new Fetcher($guard, $opt);
        // robots.txt: presupuesto y tamaño menores
        $this->robotsFetcher = $robotsFetcher ?? new Fetcher($guard, array_merge($opt, ['timeout' => 5, 'max_bytes' => 512000]));
        $this->resourceFetch = $resourceFetch ?? function (string $url, array $options = []) use ($guard, $opt): array {
            return (new Fetcher($guard, array_merge($opt, $options)))->fetch($url);
        };
    }

    public function analyze(string $url, array $options = []): array
    {
        $page = $this->pageFetcher->fetch($url);
        $summary = $page;
        unset($summary['body']);
        if (!$page['ok']) {
            return ['ok' => false, 'error' => $page['error'], 'fetch' => $summary, 'checks' => []];
        }
        $sitePage = !empty($options['pagina_de_sitio']);
        $robots = $sitePage ? $this->cachedRobots((string) $page['final_url']) : $this->fetchRobots((string) $page['final_url']);
        $ctx = CheckContext::fromFetch($page, $this->config, $robots, $options);
        $ctx->fetch = $this->resourceFetch;
        if ($sitePage) {
            // v3.0: página de la muestra de un sitio; respuesta reducida y sin vistas informativas
            $checks = (new CheckRunner())->runSitePage($ctx);
            return [
                'ok' => true,
                'url' => $page['final_url'],
                'blocked' => $page['blocked'],
                'fetch' => $summary,
                'checks' => $checks,
                'puntuacion' => (new Scorer())->score($checks),
                'robots_body' => $robots['body'],
                // Para detectar títulos repetidos entre páginas del sitio (v3.0, WEB-08 de la v1.0)
                'titulo' => $ctx->isHtml() ? trim((string) preg_replace('/\s+/u', ' ', (string) $ctx->xpath()->evaluate('string(//head/title)'))) : '',
            ];
        }
        $checks = (new CheckRunner())->run($ctx);
        // Fragmentos de corrección (borrador informativo; no cambia estados ni puntuación)
        $checks = FixSnippets::attach($checks, (string) $page['final_url']);
        return [
            'ok' => true,
            'url' => $page['final_url'],
            'blocked' => $page['blocked'],
            'fetch' => $summary,
            'robots' => ['status' => $robots['status'], 'error' => $robots['error']],
            'checks' => $checks,
            'puntuacion' => (new Scorer())->score($checks),
            // Vistas informativas (no puntúan)
            'complementario' => Insights::build($ctx, $summary),
            'robots_body' => $robots['body'],
            // v3.0: el modo sitio necesita el HTML de la portada para sacar sus enlaces (no se devuelve al navegador)
            'html' => !empty($options['con_html']) ? (string) $page['body'] : null,
        ];
    }

    /**
     * v3.0: robots.txt guardado 10 minutos en storage/ por origen, para no descargarlo en cada página
     * de la muestra. Solo se guarda robots.txt (público), nunca contenido de las páginas.
     */
    private function cachedRobots(string $pageUrl): array
    {
        $p = parse_url($pageUrl);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $dir = (getenv('WEBSEO_STORAGE_DIR') ?: __DIR__ . '/../storage') . '/robots_cache';
        $file = $dir . '/' . hash('sha256', $origin) . '.json';
        if (is_file($file) && filemtime($file) > time() - 600) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data) && array_key_exists('status', $data)) {
                return $data;
            }
        }
        $robots = $this->fetchRobots($pageUrl);
        if (is_dir($dir) || @mkdir($dir, 0700, true)) {
            @file_put_contents($file, json_encode($robots), LOCK_EX);
        }
        return $robots;
    }

    private function fetchRobots(string $pageUrl): array
    {
        $p = parse_url($pageUrl);
        $url = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/robots.txt';
        $r = $this->robotsFetcher->fetch($url);
        if (!$r['ok']) {
            return ['status' => null, 'body' => null, 'error' => $r['error']['code'] ?? 'UNKNOWN'];
        }
        return ['status' => $r['status'], 'body' => $r['body'], 'error' => null];
    }
}
