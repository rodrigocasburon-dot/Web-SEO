<?php
/**
 * C12 Canonical: presente y coherente con la URL analizada (URL final tras redirecciones).
 * - igual (sin distinguir barra final ni mayúsculas del dominio) → CUMPLE
 * - mismo dominio (con o sin www) y ruta, pero distinto esquema, www o consulta → AVISO
 * - ausente → AVISO (el canonical es opcional: sin él, el buscador elige la URL canónica)
 * - apunta a otra página → AVISO (puede ser intencionado: contenido duplicado o sindicado)
 * - vacío, no http(s) o varios distintos → NO_CUMPLE (señal errónea o contradictoria)
 * También se lee la cabecera HTTP Link rel="canonical" si no hay etiqueta.
 */
class C12Canonical extends BaseCheck
{
    protected string $id = 'C12';
    protected string $severity = 'MEDIO';
    protected string $criterio = 'Indicación de URL canónica (RFC 6596, rel="canonical"). No es un criterio WCAG.';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'BAJO';

    protected function evaluate(CheckContext $ctx): array
    {
        $hrefs = [];
        $ev = [];
        foreach ($ctx->xpath()->query('//link[@rel]') as $link) {
            $rels = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel'))));
            if (in_array('canonical', $rels, true)) {
                $hrefs[] = trim($link->getAttribute('href'));
                $ev[] = DomHelper::evidence($link);
            }
        }
        if (!$hrefs && preg_match('/<([^>]+)>\s*;[^,]*rel\s*=\s*"?canonical"?/i', $ctx->header('link'), $m)) {
            $hrefs[] = trim($m[1]);
            $ev[] = DomHelper::note('Link: ' . $ctx->header('link'), 'cabecera HTTP');
        }

        $umbral = 'Canonical presente y coherente con ' . $ctx->url;
        if (!$hrefs) {
            return $this->result(self::WARN, null, $umbral, [DomHelper::note('(no hay link rel="canonical")', 'link[rel="canonical"]')], 'No se declara URL canónica (es opcional; sin ella, el buscador elige la versión preferida).');
        }

        $resolved = [];
        foreach ($hrefs as $h) {
            if ($h === '') {
                return $this->result(self::FAIL, '', $umbral, $ev, 'El canonical tiene el href vacío.', 1);
            }
            $abs = Fetcher::resolveUrl($ctx->url, $h);
            $scheme = strtolower((string) parse_url($abs, PHP_URL_SCHEME));
            if ($scheme !== 'http' && $scheme !== 'https') {
                return $this->result(self::FAIL, $h, $umbral, $ev, 'El canonical no es una URL http(s) válida.', 1);
            }
            $resolved[] = $abs;
        }
        $distinct = array_values(array_unique(array_map(fn($u) => implode('|', $this->key($u, true)), $resolved)));
        if (count($distinct) > 1) {
            return $this->result(self::FAIL, $resolved, $umbral, $ev, 'Hay ' . count($hrefs) . ' canonical con URL distintas.', 1);
        }

        $canonical = $resolved[0];
        $valor = ['canonical' => $canonical, 'url_analizada' => $ctx->url];
        if ($this->key($canonical, true) === $this->key($ctx->url, true)) {
            return $this->result(self::OK, $valor, $umbral, $ev, 'El canonical coincide con la URL analizada.');
        }
        if ($this->key($canonical, false) === $this->key($ctx->url, false)) {
            return $this->result(self::WARN, $valor, $umbral, $ev, 'El canonical apunta a una variante de la misma página (cambia el esquema, www o los parámetros): ' . $canonical);
        }
        return $this->result(self::WARN, $valor, $umbral, $ev, 'El canonical apunta a otra página: ' . $canonical . '. Puede ser intencionado (contenido duplicado); requiere confirmación.');
    }

    /** Clave de comparación. $strict: incluye esquema, www, puerto y consulta. */
    private function key(string $url, bool $strict): array
    {
        $p = parse_url($url) ?: [];
        $host = strtolower($p['host'] ?? '');
        $path = rtrim($p['path'] ?? '/', '/');
        if ($strict) {
            $scheme = strtolower($p['scheme'] ?? '');
            $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
            return [$scheme, $host, $port, $path, $p['query'] ?? ''];
        }
        return [preg_replace('/^www\./', '', $host), $path];
    }
}
