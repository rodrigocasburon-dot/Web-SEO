<?php
/**
 * Orquesta un análisis: descarga la página, descarga robots.txt del mismo origen y ejecuta
 * las comprobaciones. No guarda nada.
 */
class Analyzer
{
    private array $config;
    private Fetcher $pageFetcher;
    private Fetcher $robotsFetcher;

    public function __construct(array $config, ?Fetcher $pageFetcher = null, ?Fetcher $robotsFetcher = null)
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
    }

    public function analyze(string $url, array $options = []): array
    {
        $page = $this->pageFetcher->fetch($url);
        $summary = $page;
        unset($summary['body']);
        if (!$page['ok']) {
            return ['ok' => false, 'error' => $page['error'], 'fetch' => $summary, 'checks' => []];
        }
        $robots = $this->fetchRobots((string) $page['final_url']);
        $ctx = CheckContext::fromFetch($page, $this->config, $robots, $options);
        $checks = (new CheckRunner())->run($ctx);
        return [
            'ok' => true,
            'url' => $page['final_url'],
            'blocked' => $page['blocked'],
            'fetch' => $summary,
            'robots' => ['status' => $robots['status'], 'error' => $robots['error']],
            'checks' => $checks,
            'puntuacion' => (new Scorer())->score($checks),
        ];
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
