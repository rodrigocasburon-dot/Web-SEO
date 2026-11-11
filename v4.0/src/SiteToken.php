<?php
/**
 * v3.0 · Firma de las páginas de un análisis de sitio.
 * api/site.php decide qué páginas se analizan y firma cada una (HMAC-SHA256 con caducidad).
 * api/site_page.php solo analiza URL con firma válida: así no sirve como «proxy» para descargar
 * cualquier dirección sin pasar por el límite de uso.
 * La clave se genera sola en storage/site_secret (no se sube a git; storage/ está ignorado).
 */
class SiteToken
{
    private string $secret;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 16) {
            throw new InvalidArgumentException('Clave demasiado corta.');
        }
        $this->secret = $secret;
    }

    public static function fromStorage(): self
    {
        $dir = getenv('WEBSEO_STORAGE_DIR') ?: __DIR__ . '/../storage';
        $file = rtrim($dir, '/\\') . '/site_secret';
        if (!is_file($file)) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            // 'x': si dos peticiones la crean a la vez, gana la primera
            $fh = @fopen($file, 'x');
            if ($fh) {
                fwrite($fh, bin2hex(random_bytes(32)));
                fclose($fh);
                @chmod($file, 0600);
            }
        }
        $secret = trim((string) @file_get_contents($file));
        return new self($secret);
    }

    /** v4 · Lo que se firma de cada página: ligada a su análisis ($run), para saber después de qué análisis es. */
    public static function pageScope(string $run, string $url): string
    {
        return 'pagina|' . $run . '|' . $url;
    }

    public function sign(string $url, int $expires): string
    {
        return hash_hmac('sha256', $expires . '|' . $url, $this->secret);
    }

    /**
     * Firma del RESULTADO de una página (para el historial): así api/site_record.php solo guarda notas
     * que ha calculado el servidor. Va ligada a la firma de la página ($pageSig), que ya incluye la caducidad.
     * @param array $notas ['seo'=>…, 'ia'=>…, 'accesibilidad'=>…, 'global'=>…]
     * @param array $failing ['C04' => 'CRITICO', …] comprobaciones NO_CUMPLE
     */
    public function signResult(string $pageSig, string $url, array $notas, array $failing, bool $blocked): string
    {
        return hash_hmac('sha256', self::resultPayload($pageSig, $url, $notas, $failing, $blocked), $this->secret);
    }

    public function verifyResult(string $pageSig, string $url, array $notas, array $failing, bool $blocked, string $signature): bool
    {
        return hash_equals($this->signResult($pageSig, $url, $notas, $failing, $blocked), $signature);
    }

    private static function resultPayload(string $pageSig, string $url, array $notas, array $failing, bool $blocked): string
    {
        ksort($failing, SORT_STRING);
        $n = [];
        foreach (['seo', 'ia', 'accesibilidad', 'global'] as $k) {
            $n[] = isset($notas[$k]) && $notas[$k] !== null ? number_format((float) $notas[$k], 4, '.', '') : '-';
        }
        $f = [];
        foreach ($failing as $id => $sev) {
            $f[] = $id . ':' . $sev;
        }
        return "resultado\n$pageSig\n$url\n" . implode('|', $n) . "\n" . implode(',', $f) . "\n" . ($blocked ? 1 : 0);
    }

    /** Comprobaciones NO_CUMPLE (que aplican) de una lista de registros: ['C04' => 'CRITICO', …]. */
    public static function failing(array $checks): array
    {
        $out = [];
        foreach ($checks as $c) {
            if (($c['estado'] ?? '') === 'NO_CUMPLE' && ($c['aplica'] ?? true) !== false) {
                $out[(string) $c['id']] = (string) ($c['severidad'] ?? '');
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    public function verify(string $url, int $expires, string $signature, ?int $now = null): bool
    {
        if ($expires < ($now ?? time())) {
            return false;
        }
        return hash_equals($this->sign($url, $expires), $signature);
    }
}
