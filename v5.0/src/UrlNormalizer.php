<?php
/**
 * Normalización de URL para COMPARAR direcciones (saber si dos URL son la misma página). No se usa para
 * descargar: la descarga sigue usando la URL validada por UrlGuard::validate(). Todavía no está conectada a la
 * entrada de la herramienta. No guarda nada.
 *
 * Uso previsto: UrlNormalizer::comparisonKey($a) === UrlNormalizer::comparisonKey($b).
 *
 * Partiendo de UrlGuard::normalize() (sin DNS: esquema y host en minúsculas, sin fragmento, sin puerto por
 * defecto, dominios IDN en punycode; rechaza credenciales, esquemas no http(s) y puertos no permitidos):
 * - quita «www.» del host si queda un dominio con punto (www.com se conserva);
 * - quita los parámetros de seguimiento (TRACKING_PARAMS y utm_*, sin distinguir mayúsculas) y conserva el
 *   orden del resto;
 * - quita la barra de la raíz sin consulta (https://a.com/ → https://a.com). La barra final de otras rutas se
 *   conserva: /blog/ y /blog pueden ser páginas distintas.
 * Supuestos: quitar «www» es seguro solo para comparar, no para descargar; la lista de parámetros es provisional.
 */
class UrlNormalizer
{
    /** Parámetros de seguimiento que se eliminan (además de cualquiera que empiece por utm_). */
    public const TRACKING_PARAMS = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'dclid', 'yclid', 'mc_cid', 'mc_eid', 'igshid'];

    /** @throws UrlGuardException si la URL no es válida (mismas reglas y mensajes que UrlGuard) */
    public static function comparisonKey(string $input): string
    {
        $n = (new UrlGuard())->normalize($input);
        $parts = parse_url($n['url']);

        $host = $n['host'];
        if (!$n['is_ip_literal'] && strpos($host, 'www.') === 0 && strpos(substr($host, 4), '.') !== false) {
            $host = substr($host, 4);
        }
        if ($n['is_ip_literal'] && strpos($host, ':') !== false) {
            $host = '[' . $host . ']';
        }
        $defaultPort = $n['scheme'] === 'https' ? 443 : 80;
        $authority = $host . ($n['port'] !== $defaultPort ? ':' . $n['port'] : '');

        $kept = [];
        foreach (explode('&', (string) ($parts['query'] ?? '')) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            if (strpos($name, 'utm_') === 0 || in_array($name, self::TRACKING_PARAMS, true)) {
                continue;
            }
            $kept[] = $pair;
        }
        $query = $kept ? '?' . implode('&', $kept) : '';

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '/' && $query === '') {
            $path = '';
        }
        return $n['scheme'] . '://' . $authority . $path . $query;
    }
}
