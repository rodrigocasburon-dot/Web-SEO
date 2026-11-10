<?php
/**
 * Directivas de indexación y fragmento de meta robots y de la cabecera X-Robots-Tag (C02 y C25).
 * Devuelve las directivas en minúsculas y sin espacios: «noindex», «nosnippet», «max-snippet:20»…
 * En X-Robots-Tag un prefijo «bot:» limita lo que sigue a ese bot; solo cuentan las directivas sin
 * prefijo o para googlebot.
 */
class RobotsDirectives
{
    /** Directivas que llevan valor tras «:» (no son prefijos de bot). */
    private const WITH_VALUE = ['max-snippet', 'max-image-preview', 'max-video-preview', 'unavailable_after'];

    /** Directivas del atributo content de meta name="robots" o "googlebot". */
    public static function fromContent(string $content): array
    {
        $out = [];
        foreach (explode(',', strtolower($content)) as $t) {
            $t = preg_replace('/\s+/', '', $t) ?? '';
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** Directivas de X-Robots-Tag que se aplican a Google (sin prefijo, «googlebot:» o «*:»). */
    public static function fromHeader(string $header): array
    {
        $out = [];
        $scope = '';
        foreach (explode(',', strtolower($header)) as $token) {
            $token = trim($token);
            if (preg_match('/^([a-z0-9_\-*]+)\s*:\s*(.*)$/', $token, $m) && !in_array($m[1], self::WITH_VALUE, true)) {
                $scope = $m[1];
                $token = trim($m[2]);
            }
            $token = preg_replace('/\s+/', '', $token) ?? '';
            if ($token !== '' && ($scope === '' || $scope === 'googlebot' || $scope === '*')) {
                $out[] = $token;
            }
        }
        return $out;
    }

    /** Directivas de la página para Google: meta robots/googlebot y X-Robots-Tag, con su procedencia. */
    public static function forPage(CheckContext $ctx): array
    {
        $out = [];
        if ($ctx->isHtml()) {
            foreach ($ctx->xpath()->query('//meta[@name]') as $meta) {
                $name = strtolower(trim($meta->getAttribute('name')));
                if ($name === 'robots' || $name === 'googlebot') {
                    foreach (self::fromContent($meta->getAttribute('content')) as $d) {
                        $out[] = ['directiva' => $d, 'origen' => $meta];
                    }
                }
            }
        }
        $header = $ctx->header('x-robots-tag');
        if ($header !== '') {
            foreach (self::fromHeader($header) as $d) {
                $out[] = ['directiva' => $d, 'origen' => 'X-Robots-Tag: ' . $header];
            }
        }
        return $out;
    }
}
