<?php
/**
 * C20 Certificado y contenido mixto (solo páginas HTTPS).
 * - Certificado: se da por válido porque cURL lo verificó al descargar la página (cadena de confianza y
 *   nombre del host); si no, la descarga habría fallado con error de certificado y no habría informe.
 *   Si caduca en menos de 14 días → AVISO.
 * - Contenido mixto activo en el HTML inicial (script, hoja de estilos, iframe, object, embed, base y
 *   formularios que envían por http) → NO_CUMPLE (k = nº de recursos), salvo que la página declare
 *   upgrade-insecure-requests en su CSP, que lo convierte en AVISO.
 * - Contenido mixto pasivo (imágenes, audio, vídeo, iconos) → AVISO: los navegadores lo piden por https o lo bloquean.
 * - Página servida por http → no aplica (la falta de HTTPS la penaliza C01).
 * No se ven los recursos que añade JavaScript ni los que se cargan desde CSS.
 */
class C20HttpsSecurity extends BaseCheck
{
    protected string $id = 'C20';
    protected string $severity = 'ALTO';
    protected string $criterio = 'Certificado TLS verificado (cadena de confianza y nombre del host) y contenido mixto (W3C Mixed Content). No es un criterio WCAG.';
    protected string $impacto = 'ALTO';
    protected string $esfuerzo = 'BAJO';

    public const EXPIRY_WARN_DAYS = 14;

    /** [consulta XPath, atributo, ¿activo?] */
    private const SOURCES = [
        ['//script[@src]', 'src', true],
        ['//iframe[@src]', 'src', true],
        ['//frame[@src]', 'src', true],
        ['//object[@data]', 'data', true],
        ['//embed[@src]', 'src', true],
        ['//form[@action]', 'action', true],
        ['//base[@href]', 'href', true],
        ['//img[@src]', 'src', false],
        ['//img[@srcset]', 'srcset', false],
        ['//source[@src]', 'src', false],
        ['//source[@srcset]', 'srcset', false],
        ['//audio[@src]', 'src', false],
        ['//video[@src]', 'src', false],
        ['//video[@poster]', 'poster', false],
        ['//track[@src]', 'src', false],
        ['//input[@type="image"][@src]', 'src', false],
    ];

    protected function evaluate(CheckContext $ctx): array
    {
        $umbral = 'Certificado verificado y válido al menos ' . self::EXPIRY_WARN_DAYS . ' días más; ningún recurso del HTML cargado por http';
        if (strtolower((string) parse_url($ctx->url, PHP_URL_SCHEME)) !== 'https') {
            return $this->result(self::OK, null, $umbral, [], 'La página no se sirve por HTTPS: no hay certificado ni contenido mixto que evaluar (la falta de HTTPS la penaliza C01).', 0, false);
        }

        // Certificado
        $days = null;
        $expires = null;
        $cert = $ctx->cert;
        if ($cert && !empty($cert['valid_to'])) {
            $days = (int) floor(((int) $cert['valid_to'] - time()) / 86400);
            $expires = gmdate('d/m/Y', (int) $cert['valid_to']);
        }
        $issuer = $cert ? self::issuerName((string) ($cert['issuer'] ?? '')) : '';
        $certText = 'Certificado verificado por cURL (cadena de confianza y nombre del host)'
            . ($issuer !== '' ? '; emisor: ' . $issuer : '')
            . ($expires !== null ? "; caduca el $expires ($days días)" : '; sin datos de caducidad') . '.';
        $ev = [DomHelper::note($certText, 'certificado TLS')];

        // Contenido mixto
        $active = [];
        $passive = [];
        foreach (self::SOURCES as [$query, $attr, $isActive]) {
            foreach ($ctx->xpath()->query($query) as $el) {
                if (!self::insecure($el->getAttribute($attr), $attr === 'srcset')) {
                    continue;
                }
                if ($isActive) {
                    $active[] = $el;
                } else {
                    $passive[] = $el;
                }
            }
        }
        foreach ($ctx->xpath()->query('//link[@href][@rel]') as $el) {
            if (!self::insecure($el->getAttribute('href'), false)) {
                continue;
            }
            $rels = preg_split('/\s+/', strtolower(trim($el->getAttribute('rel'))));
            if (array_intersect($rels, ['stylesheet', 'preload', 'modulepreload', 'manifest'])) {
                $active[] = $el;
            } elseif (array_intersect($rels, ['icon', 'apple-touch-icon'])) {
                $passive[] = $el;
            }
        }
        foreach (array_merge($active, $passive) as $el) {
            if (count($ev) >= 10) {
                break;
            }
            $ev[] = DomHelper::evidence($el);
        }

        $upgrade = stripos($ctx->header('content-security-policy'), 'upgrade-insecure-requests') !== false;
        foreach ($ctx->xpath()->query('//meta[@http-equiv][@content]') as $m) {
            if (strtolower(trim($m->getAttribute('http-equiv'))) === 'content-security-policy'
                && stripos($m->getAttribute('content'), 'upgrade-insecure-requests') !== false) {
                $upgrade = true;
            }
        }

        $valor = [
            'certificado' => 'verificado',
            'caduca' => $expires,
            'días_para_caducar' => $days,
            'contenido_mixto_activo' => count($active),
            'contenido_mixto_pasivo' => count($passive),
            'upgrade_insecure_requests' => $upgrade,
        ];

        if ($active && !$upgrade) {
            return $this->result(self::FAIL, $valor, $umbral, $ev, count($active) . ' recurso(s) activo(s) (scripts, hojas de estilo, iframes o formularios) usan http en una página https: el navegador los bloquea o envía datos sin cifrar.', count($active));
        }
        $warnings = [];
        if ($active) {
            $warnings[] = count($active) . ' recurso(s) activo(s) usan http, aunque upgrade-insecure-requests hace que el navegador los pida por https.';
        }
        if ($passive) {
            $warnings[] = count($passive) . ' recurso(s) pasivo(s) (imágenes, audio, vídeo o iconos) usan http; los navegadores los piden por https o los bloquean.';
        }
        if ($days !== null && $days < self::EXPIRY_WARN_DAYS) {
            $warnings[] = "El certificado caduca en $days días.";
        }
        if ($warnings) {
            return $this->result(self::WARN, $valor, $umbral, $ev, implode(' ', $warnings));
        }
        return $this->result(self::OK, $valor, $umbral, $ev, 'Certificado verificado y sin contenido mixto en el HTML inicial.');
    }

    /** ¿El valor apunta a http://? En srcset se revisa cada candidato. */
    private static function insecure(string $value, bool $srcset): bool
    {
        $candidates = $srcset ? array_map(fn($c) => preg_split('/\s+/', trim($c))[0] ?? '', explode(',', $value)) : [$value];
        foreach ($candidates as $c) {
            if (stripos(trim($c), 'http://') === 0) {
                return true;
            }
        }
        return false;
    }

    /** Nombre legible del emisor: organización (O) o, si no hay, nombre común (CN). */
    private static function issuerName(string $issuer): string
    {
        foreach (['O', 'CN'] as $field) {
            if (preg_match('/(?:^|[,\/;]\s*)' . $field . '\s*=\s*([^,\/;]+)/', $issuer, $m)) {
                return trim($m[1]);
            }
        }
        return trim($issuer);
    }
}
