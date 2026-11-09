<?php
/**
 * Filtro de la URL que escribe el usuario, antes de descargar nada o consultar PageSpeed. Se suma a UrlGuard
 * (no lo sustituye ni lo debilita: después se sigue llamando a UrlGuard::validate()). Solo se aplica a la entrada;
 * las redirecciones y los enlaces de la página siguen las reglas de siempre.
 *
 * - Google Maps y acortadores de enlaces → NOT_BUSINESS_SITE: no son la web del negocio.
 * - Credenciales, dominios internos, localhost e IP literales no públicas → mismo código que UrlGuard, con un
 *   mensaje que indica qué introducir. Los dominios que resuelven a IP privadas los rechaza después UrlGuard.
 * - El resto de errores de formato conserva el mensaje de UrlGuard.
 * Supuesto: la lista de acortadores es provisional y no exhaustiva.
 */
class InputUrlPolicy
{
    public const HINT = 'Introduce la dirección de la web del negocio.';

    /** Acortadores conocidos (el dominio y sus subdominios). goo.gl incluye goo.gl/maps y maps.app.goo.gl. */
    public const SHORTENERS = ['g.page', 'goo.gl', 'bit.ly', 'tinyurl.com', 't.co', 'ow.ly', 'buff.ly', 'is.gd',
        'cutt.ly', 'rebrand.ly', 'shorturl.at', 't.ly', 'lnkd.in', 'rb.gy', 'tiny.cc'];

    /** @throws UrlGuardException */
    public static function check(string $input): void
    {
        $guard = new UrlGuard();
        try {
            $n = $guard->normalize($input);
        } catch (UrlGuardException $e) {
            $code = $e->getErrorCode();
            if ($code === 'CREDENTIALS_IN_URL' || $code === 'PRIVATE_ADDRESS') {
                throw new UrlGuardException($code, $e->getMessage() . ' ' . self::HINT);
            }
            if ($code === 'INVALID_HOST' && self::isLocalhost($input)) {
                throw new UrlGuardException('PRIVATE_ADDRESS', 'Las direcciones locales no se analizan. ' . self::HINT);
            }
            throw $e;
        }

        $host = $n['host'];
        if ($n['is_ip_literal']) {
            if (!$guard->isPublicIp($host)) {
                throw new UrlGuardException('PRIVATE_ADDRESS', 'Las direcciones locales o privadas no se analizan. ' . self::HINT);
            }
            return;
        }
        $path = (string) (parse_url($n['url'], PHP_URL_PATH) ?? '/');
        if (preg_match('/^maps\.google\.[a-z.]+$/', $host)
            || (preg_match('/^(www\.)?google\.[a-z.]+$/', $host) && preg_match('#^/maps(/|$)#', $path))) {
            throw new UrlGuardException('NOT_BUSINESS_SITE', 'Esa dirección es de Google Maps, no de una web. ' . self::HINT);
        }
        foreach (self::SHORTENERS as $shortener) {
            if ($host === $shortener || substr($host, -strlen($shortener) - 1) === '.' . $shortener) {
                throw new UrlGuardException('NOT_BUSINESS_SITE', 'Esa dirección es de un acortador de enlaces (' . $shortener . '). ' . self::HINT);
            }
        }
    }

    private static function isLocalhost(string $input): bool
    {
        $url = trim($input);
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        return $host === 'localhost';
    }
}
