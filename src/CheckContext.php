<?php
/**
 * Datos que reciben las comprobaciones: respuesta descargada, robots.txt, configuración y
 * el DOM analizado (se construye una sola vez y se comparte).
 */
class CheckContext
{
    public string $url;
    public string $requestedUrl;
    public int $status;
    public array $headers;
    public ?string $contentType;
    public string $body;
    public bool $truncated;
    public ?array $blocked;
    public array $redirects;
    /** @var array|null status, body, error (null = no se obtuvo información) */
    public ?array $robots;
    public array $config;
    public array $options;
    /** Respuesta de PageSpeed Insights (C08, C14); null si no se obtuvo */
    public ?array $psi = null;
    /** Motivo por el que no hay datos de PageSpeed: ['code' => ..., 'message' => ...] */
    public ?array $psiError = null;

    private ?DOMDocument $doc = null;
    private ?DOMXPath $xpath = null;

    /**
     * @param array      $page    resultado de Fetcher::fetch()
     * @param array|null $robots  ['status'=>int|null, 'body'=>string|null, 'error'=>string|null]
     * @param array      $options opciones de la petición (p. ej. noindex_intentional)
     */
    public static function fromFetch(array $page, array $config = [], ?array $robots = null, array $options = []): self
    {
        $c = new self();
        $c->url = (string) ($page['final_url'] ?? $page['requested_url'] ?? '');
        $c->requestedUrl = (string) ($page['requested_url'] ?? $c->url);
        $c->status = (int) ($page['status'] ?? 0);
        $c->headers = (array) ($page['headers'] ?? []);
        $c->contentType = $page['content_type'] ?? ($c->headers['content-type'] ?? null);
        $c->body = (string) ($page['body'] ?? '');
        $c->truncated = !empty($page['truncated']);
        $c->blocked = $page['blocked'] ?? null;
        $c->redirects = (array) ($page['redirects'] ?? []);
        $c->robots = $robots;
        $c->config = $config;
        $c->options = $options;
        return $c;
    }

    /** Contexto para las comprobaciones que solo usan PageSpeed Insights. */
    public static function forPsi(string $url, ?array $psi, ?array $psiError, array $config = []): self
    {
        $c = self::fromFetch(['final_url' => $url, 'requested_url' => $url, 'status' => 0], $config);
        $c->psi = $psi;
        $c->psiError = $psiError;
        return $c;
    }

    public function header(string $name): string
    {
        return (string) ($this->headers[strtolower($name)] ?? '');
    }

    /** ¿La respuesta parece HTML? */
    public function isHtml(): bool
    {
        if ($this->contentType !== null && $this->contentType !== '') {
            return stripos($this->contentType, 'html') !== false;
        }
        return ltrim(substr($this->body, 0, 512)) !== '' && ltrim($this->body)[0] === '<';
    }

    public function dom(): DOMDocument
    {
        if ($this->doc === null) {
            $this->doc = self::parse(self::toUtf8($this->body, $this->contentType));
        }
        return $this->doc;
    }

    public function xpath(): DOMXPath
    {
        if ($this->xpath === null) {
            $this->xpath = new DOMXPath($this->dom());
        }
        return $this->xpath;
    }

    /** Analiza HTML (UTF-8) con el analizador HTML de libxml, tolerante a errores. */
    public static function parse(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        // Quita la instrucción de proceso añadida para forzar UTF-8
        $remove = [];
        foreach ($doc->childNodes as $child) {
            if ($child->nodeType === XML_PI_NODE) {
                $remove[] = $child;
            }
        }
        foreach ($remove as $n) {
            $doc->removeChild($n);
        }
        return $doc;
    }

    /** Convierte a UTF-8 según cabecera, BOM o meta charset (por defecto UTF-8 o Windows-1252). */
    public static function toUtf8(string $html, ?string $contentType): string
    {
        $charset = null;
        if ($contentType !== null && preg_match('/charset\s*=\s*"?([\w\-]+)/i', $contentType, $m)) {
            $charset = $m[1];
        } elseif (strncmp($html, "\xEF\xBB\xBF", 3) === 0) {
            $charset = 'UTF-8';
        } elseif (preg_match('/<meta[^>]+charset\s*=\s*["\']?([\w\-]+)/i', substr($html, 0, 4096), $m)) {
            $charset = $m[1];
        }
        if (strncmp($html, "\xEF\xBB\xBF", 3) === 0) {
            $html = substr($html, 3);
        }
        if ($charset === null) {
            $charset = mb_check_encoding($html, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
        }
        try {
            return mb_convert_encoding($html, 'UTF-8', $charset);
        } catch (ValueError $e) {
            return mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }
    }
}
