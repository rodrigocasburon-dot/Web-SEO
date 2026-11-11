<?php
/** C01 Estado y HTTPS: la URL final responde 200 por HTTPS. */
class C01HttpsStatus extends BaseCheck
{
    protected string $id = 'C01';
    protected string $severity = 'CRITICO';
    protected string $criterio = 'Códigos de estado HTTP (RFC 9110) y transporte cifrado HTTPS. No es un criterio WCAG.';
    protected string $impacto = 'ALTO';
    protected string $esfuerzo = 'MEDIO';
    protected bool $needsHtml = false;

    protected function evaluate(CheckContext $ctx): array
    {
        $https = strtolower((string) parse_url($ctx->url, PHP_URL_SCHEME)) === 'https';
        $valor = ['estado_http' => $ctx->status, 'https' => $https, 'url_final' => $ctx->url, 'redirecciones' => count($ctx->redirects)];
        $umbral = 'HTTP 200 servido por HTTPS';

        $ev = [];
        foreach ($ctx->redirects as $r) {
            $ev[] = DomHelper::note($r['status'] . ': ' . $r['from'] . ' → ' . $r['to']);
        }
        $ev[] = DomHelper::note('Respuesta final: HTTP ' . $ctx->status . ' en ' . $ctx->url);

        if (!$https) {
            return $this->result(self::FAIL, $valor, $umbral, $ev, 'La URL final se sirve sin HTTPS.', 1);
        }
        if ($ctx->status === 200) {
            return $this->result(self::OK, $valor, $umbral, $ev, 'La URL final responde 200 por HTTPS.');
        }
        if ($ctx->blocked) {
            return $this->result(self::NO_DATA, $valor, $umbral, $ev, 'El sitio bloquea el acceso automático (HTTP ' . $ctx->status . '); no se puede determinar si la página funciona. No indica un fallo del sitio.');
        }
        return $this->result(self::FAIL, $valor, $umbral, $ev, 'La URL final responde HTTP ' . $ctx->status . ' en lugar de 200.', 1);
    }
}
