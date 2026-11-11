<?php
/**
 * v4 · Estado de un análisis de sitio en el servidor (storage/runs/siterun_*.json). Antes el tope de páginas y el
 * modo (rápido o profundo) solo los aplicaba el navegador: cualquiera podía seguir pidiendo páginas firmadas. Ahora:
 *  - el modo y el tope se fijan al crear el análisis (api/site.php) y no se pueden cambiar después;
 *  - se cuentan las páginas analizadas y las firmadas: no se analiza ni se firma ninguna más allá del tope;
 *  - se reparte el ritmo: entre una petición y la siguiente al sitio analizado pasa al menos el intervalo
 *    mínimo o el Crawl-delay de robots.txt (con un máximo de MAX_DELAY s);
 *  - si el sitio responde 429 o 503 (pide frenar), el análisis se detiene y no se analizan más páginas.
 * Cada cambio se hace con un bloqueo exclusivo (flock) para que las peticiones en paralelo no se pisen.
 */
class SiteRun
{
    /** Crawl-delay máximo que se respeta (s): por encima se usa este valor y se avisa */
    public const MAX_DELAY = 10.0;
    /** Espera máxima dentro de una petición (s); si hace falta más, se pide al navegador que reintente */
    public const MAX_WAIT = 8.0;
    /** Intervalo mínimo por defecto entre peticiones al sitio analizado (ms) */
    public const DEFAULT_INTERVAL_MS = 250;

    private static function dir(): string
    {
        return (getenv('WEBSEO_STORAGE_DIR') ?: __DIR__ . '/../storage') . '/runs';
    }

    private static function file(string $run): string
    {
        return self::dir() . '/siterun_' . hash('sha256', $run) . '.json';
    }

    /** Clave corta de una URL para no firmar dos veces la misma página */
    private static function key(string $url): string
    {
        try {
            $url = UrlNormalizer::comparisonKey($url);
        } catch (Throwable $e) {
            // URL no normalizable: se compara tal cual
        }
        return substr(hash('sha256', $url), 0, 16);
    }

    /**
     * Crea el estado del análisis. $urls: la portada y las páginas de la lista inicial (ya firmadas).
     * @param float|null $crawlDelay Crawl-delay de robots.txt para nuestro user-agent (o «*»)
     */
    public static function create(string $run, string $mode, int $max, array $urls, ?float $crawlDelay, int $intervalMs, int $expires): bool
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $delay = $crawlDelay === null ? 0.0 : min(self::MAX_DELAY, max(0.0, $crawlDelay));
        $state = [
            'modo' => $mode === 'profundo' ? 'profundo' : 'rapido',
            'max' => max(1, $max),
            'analizadas' => 0,
            // Páginas firmadas (sin contar la portada, que no pasa por site_page.php)
            'firmadas' => max(0, count($urls) - 1),
            'vistas' => array_fill_keys(array_map([self::class, 'key'], $urls), 1),
            'intervalo' => max($delay, max(0, $intervalMs) / 1000),
            'crawl_delay' => $crawlDelay,
            'siguiente' => 0.0,
            'parado' => null,
            'expira' => $expires,
        ];
        return @file_put_contents(self::file($run), json_encode($state), LOCK_EX) !== false;
    }

    /** Estado actual (sin bloqueo), o null si no existe o ha caducado. */
    public static function get(string $run): ?array
    {
        $raw = @file_get_contents(self::file($run));
        $s = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($s) && (int) ($s['expira'] ?? 0) >= time() ? $s : null;
    }

    /**
     * Aplica $fn al estado con bloqueo exclusivo y guarda lo que devuelva como nuevo estado.
     * $fn recibe el estado y devuelve [nuevoEstado, resultado]. Si el análisis no existe, devuelve null.
     */
    private static function update(string $run, callable $fn)
    {
        $h = @fopen(self::file($run), 'c+');
        if ($h === false) {
            return null;
        }
        try {
            if (!flock($h, LOCK_EX)) {
                return null;
            }
            $s = json_decode((string) stream_get_contents($h), true);
            if (!is_array($s) || (int) ($s['expira'] ?? 0) < time()) {
                return null;
            }
            [$s, $result] = $fn($s);
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, (string) json_encode($s));
            fflush($h);
            return $result;
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /**
     * Reserva el turno de una página. Devuelve:
     *  ['ok' => true, 'espera' => s]       se puede analizar tras esperar «espera» segundos;
     *  ['ok' => false, 'codigo' => ..., 'mensaje' => ..., 'reintentar' => ?s]  no se puede (ahora o nunca).
     */
    public static function reserve(string $run, ?float $now = null): array
    {
        if (!is_file(self::file($run))) {
            return ['ok' => false, 'codigo' => 'RUN_UNKNOWN', 'mensaje' => 'El análisis del sitio no existe o ha caducado.', 'reintentar' => null];
        }
        $r = self::update($run, function (array $s) use ($now) {
            $now = $now ?? microtime(true);
            if ($s['parado'] !== null) {
                return [$s, ['ok' => false, 'codigo' => 'SITE_STOPPED', 'mensaje' => $s['parado'], 'reintentar' => null]];
            }
            if ($s['analizadas'] >= $s['max']) {
                return [$s, ['ok' => false, 'codigo' => 'LIMIT_REACHED', 'mensaje' => 'Se ha llegado al tope de ' . $s['max'] . ' páginas de este análisis.', 'reintentar' => null]];
            }
            $start = max($now, (float) $s['siguiente']);
            $wait = $start - $now;
            if ($wait > self::MAX_WAIT) {
                return [$s, ['ok' => false, 'codigo' => 'RETRY', 'mensaje' => 'Ritmo de peticiones al sitio: se reintenta en breve.', 'reintentar' => round($wait - self::MAX_WAIT + 0.5, 1)]];
            }
            $s['siguiente'] = $start + (float) $s['intervalo'];
            $s['analizadas']++;
            return [$s, ['ok' => true, 'espera' => $wait]];
        });
        return $r ?? ['ok' => false, 'codigo' => 'RUN_UNKNOWN', 'mensaje' => 'El análisis del sitio no existe o ha caducado.', 'reintentar' => null];
    }

    /**
     * Enlaces nuevos que se pueden firmar: solo en el modo profundo, sin repetir páginas ya firmadas y sin pasar
     * del tope. @param string[] $urls  @return string[] los permitidos, en el mismo orden
     */
    public static function allowLinks(string $run, array $urls): array
    {
        $r = self::update($run, function (array $s) use ($urls) {
            if ($s['modo'] !== 'profundo' || $s['parado'] !== null) {
                return [$s, []];
            }
            $out = [];
            foreach ($urls as $u) {
                if ($s['firmadas'] >= $s['max']) {
                    break;
                }
                $k = self::key((string) $u);
                if (isset($s['vistas'][$k])) {
                    continue;
                }
                $s['vistas'][$k] = 1;
                $s['firmadas']++;
                $out[] = (string) $u;
            }
            return [$s, $out];
        });
        return is_array($r) ? $r : [];
    }

    /** Detiene el análisis (el sitio ha pedido frenar). Las peticiones siguientes se rechazan con este motivo. */
    public static function stop(string $run, string $reason): void
    {
        self::update($run, function (array $s) use ($reason) {
            if ($s['parado'] === null) {
                $s['parado'] = $reason;
            }
            return [$s, null];
        });
    }
}
