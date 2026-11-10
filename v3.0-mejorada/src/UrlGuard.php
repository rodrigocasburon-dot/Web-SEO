<?php
/**
 * Valida y normaliza URLs antes de hacer ninguna petición (defensa SSRF).
 *
 * - Solo http y https, sin credenciales en la URL, puertos permitidos.
 * - Resuelve el dominio y rechaza TODAS las IP privadas o reservadas.
 * - Devuelve la IP elegida para fijarla con CURLOPT_RESOLVE.
 * La URL que se devuelve se reconstruye desde las partes ya validadas, de modo que
 * cURL nunca recibe el texto original (evita diferencias de interpretación).
 */
class UrlGuard
{
    // Rangos IPv4 no públicos (se añaden a lo que detecta filter_var, que varía según versión de PHP).
    private const IPV4_BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const BLOCKED_HOST_SUFFIXES = ['.localhost', '.local', '.internal', '.home.arpa'];

    private int $maxLength;
    private array $allowedPorts;
    /** @var callable */
    private $resolver;

    /**
     * @param array         $options  allowed_ports (por defecto 80 y 443), max_url_length
     * @param callable|null $resolver fn(string $host): string[]  (inyectable para pruebas)
     */
    public function __construct(array $options = [], ?callable $resolver = null)
    {
        $this->allowedPorts = $options['allowed_ports'] ?? [80, 443];
        $this->maxLength = $options['max_url_length'] ?? 2048;
        $this->resolver = $resolver ?? [$this, 'dnsLookup'];
    }

    /**
     * Valida sintaxis, esquema, host y DNS.
     *
     * @return array url, scheme, host, port, ip, ips, is_ip_literal, dns_ms
     * @throws UrlGuardException
     */
    public function validate(string $input): array
    {
        $n = $this->normalize($input);
        $t0 = microtime(true);

        if ($n['is_ip_literal']) {
            $ips = [$n['host']];
        } else {
            $ips = array_values(array_unique(array_filter((array) ($this->resolver)($n['host']), 'is_string')));
            if (!$ips) {
                throw new UrlGuardException('DNS_FAILED', 'No se ha podido resolver el dominio «' . $n['host'] . '».');
            }
        }

        // Si cualquiera de las IP es no pública se rechaza todo (evita respuestas DNS mixtas).
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new UrlGuardException(
                    'PRIVATE_ADDRESS',
                    'La dirección de destino no es pública y no se analiza (' . $n['host'] . ' → ' . $ip . ').'
                );
            }
        }

        // Preferimos IPv4 si existe; si no, la primera.
        $chosen = $ips[0];
        foreach ($ips as $ip) {
            if (strpos($ip, ':') === false) {
                $chosen = $ip;
                break;
            }
        }

        $n['ip'] = $chosen;
        $n['ips'] = $ips;
        $n['dns_ms'] = (int) round((microtime(true) - $t0) * 1000);
        return $n;
    }

    /**
     * Normaliza sin resolver DNS.
     *
     * @throws UrlGuardException
     */
    public function normalize(string $input): array
    {
        $url = trim($input);
        if ($url === '') {
            throw new UrlGuardException('INVALID_URL', 'Introduce una URL.');
        }
        if (strlen($url) > $this->maxLength) {
            throw new UrlGuardException('INVALID_URL', 'La URL es demasiado larga.');
        }
        // Espacios internos, caracteres de control y barras invertidas (ambiguas entre analizadores).
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new UrlGuardException('INVALID_URL', 'La URL contiene caracteres no válidos.');
        }

        // Sin esquema: se supone https. Con esquema distinto de http/https se rechaza más abajo.
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
            if (preg_match('#^[a-z][a-z0-9+.\-]*:(?!\d+(/|$))#i', $url)) {
                throw new UrlGuardException('SCHEME_NOT_ALLOWED', 'Solo se admiten URL http y https.');
            }
            $url = 'https://' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme'])) {
            throw new UrlGuardException('INVALID_URL', 'La URL no es válida.');
        }

        // El esquema se comprueba antes que el host (file:/// no tiene host).
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new UrlGuardException('SCHEME_NOT_ALLOWED', 'Solo se admiten URL http y https.');
        }
        if (!isset($parts['host']) || $parts['host'] === '') {
            throw new UrlGuardException('INVALID_URL', 'La URL no es válida.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UrlGuardException('CREDENTIALS_IN_URL', 'La URL no puede incluir usuario ni contraseña.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, $this->allowedPorts, true)) {
            throw new UrlGuardException('PORT_NOT_ALLOWED', 'Puerto no permitido: ' . $port . '.');
        }

        [$host, $isLiteral] = $this->normalizeHost($parts['host']);

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $authority = $isLiteral && strpos($host, ':') !== false ? '[' . $host . ']' : $host;
        if ($port !== $defaultPort) {
            $authority .= ':' . $port;
        }

        $path = $parts['path'] ?? '';
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        // Codifica en porcentaje los bytes fuera de ASCII imprimible. El fragmento se descarta.
        $tail = preg_replace_callback('/[^\x21-\x7e]/', fn($m) => rawurlencode($m[0]), $path . $query);

        return [
            'url' => $scheme . '://' . $authority . $tail,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'is_ip_literal' => $isLiteral,
        ];
    }

    /** Indica si una IP es pública y enrutable. */
    public function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        return strlen($bin) === 4 ? !$this->ipv4Blocked($ip) : $this->isPublicIpv6($bin);
    }

    // ---- Internos -------------------------------------------------------------------------

    /** @return array{0:string,1:bool} host normalizado y si es una IP literal */
    private function normalizeHost(string $rawHost): array
    {
        // IPv6 literal entre corchetes
        if ($rawHost[0] === '[') {
            $ip = substr($rawHost, 1, -1);
            if (substr($rawHost, -1) !== ']' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new UrlGuardException('INVALID_HOST', 'La dirección IPv6 no es válida.');
            }
            return [strtolower($ip), true];
        }

        $host = strtolower(rtrim($rawHost, '.'));
        if ($host === '') {
            throw new UrlGuardException('INVALID_HOST', 'El nombre de dominio no es válido.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return [$host, true];
        }

        // Formas numéricas ambiguas (2130706433, 0x7f.0.0.1, 0177.0.0.1, 127.1): se rechazan.
        $allNumeric = true;
        foreach (explode('.', $host) as $label) {
            if (!preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', $label)) {
                $allNumeric = false;
                break;
            }
        }
        if ($allNumeric) {
            throw new UrlGuardException('INVALID_HOST', 'Dirección numérica no admitida; usa el nombre de dominio.');
        }

        // Dominios internacionalizados
        if (preg_match('/[^\x00-\x7f]/', $host)) {
            $ascii = function_exists('idn_to_ascii')
                ? idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46)
                : false;
            if ($ascii === false || $ascii === '') {
                throw new UrlGuardException('INVALID_HOST', 'No se admite este dominio con caracteres especiales.');
            }
            $host = strtolower($ascii);
        }

        if (strlen($host) > 253 || strpos($host, '.') === false) {
            throw new UrlGuardException('INVALID_HOST', 'El nombre de dominio no es válido.');
        }
        foreach (explode('.', $host) as $label) {
            if (!preg_match('/^(?!-)[a-z0-9_-]{1,63}(?<!-)$/', $label)) {
                throw new UrlGuardException('INVALID_HOST', 'El nombre de dominio no es válido.');
            }
        }
        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                throw new UrlGuardException('PRIVATE_ADDRESS', 'Los dominios internos no se analizan.');
            }
        }
        return [$host, false];
    }

    /** Resolución DNS por defecto: A y AAAA, con respaldo a IPv4 del sistema. */
    private function dnsLookup(string $host): array
    {
        $ips = [];
        // El punto final evita que se añadan sufijos de búsqueda locales.
        $records = @dns_get_record($host . '.', DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (isset($r['ip'])) {
                    $ips[] = $r['ip'];
                } elseif (isset($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        if (!$ips) {
            $fallback = @gethostbynamel($host . '.');
            if (is_array($fallback)) {
                $ips = $fallback;
            }
        }
        return $ips;
    }

    private function ipv4Blocked(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }
        foreach (self::IPV4_BLOCKED as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = ((int) $bits === 0) ? 0 : ((~0 << (32 - (int) $bits)) & 0xFFFFFFFF);
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return true;
            }
        }
        return $long === 0xFFFFFFFF;
    }

    private function isPublicIpv6(string $bin): bool
    {
        $b = array_values(unpack('C*', $bin));

        // IPv4 mapeada (::ffff:a.b.c.d): vale lo que valga la IPv4 incrustada.
        if (substr($bin, 0, 10) === str_repeat("\0", 10) && $b[10] === 0xFF && $b[11] === 0xFF) {
            return !$this->ipv4Blocked(implode('.', array_slice($b, 12, 4)));
        }
        // NAT64 64:ff9b::/96
        if ($this->bitsMatch($bin, inet_pton('64:ff9b::'), 96)) {
            return !$this->ipv4Blocked(implode('.', array_slice($b, 12, 4)));
        }
        // 6to4 2002::/16
        if ($b[0] === 0x20 && $b[1] === 0x02) {
            return !$this->ipv4Blocked(implode('.', array_slice($b, 2, 4)));
        }
        // Solo unicast global 2000::/3 (descarta ::, ::1, fc00::/7, fe80::/10, ff00::/8, etc.)
        if (($b[0] & 0xE0) !== 0x20) {
            return false;
        }
        // Documentación (2001:db8::/32, 3fff::/20) y Teredo (2001::/32)
        if ($this->bitsMatch($bin, inet_pton('2001:db8::'), 32)
            || $this->bitsMatch($bin, inet_pton('2001::'), 32)
            || $this->bitsMatch($bin, inet_pton('3fff::'), 20)) {
            return false;
        }
        return true;
    }

    private function bitsMatch(string $a, string $b, int $bits): bool
    {
        $full = intdiv($bits, 8);
        if (substr($a, 0, $full) !== substr($b, 0, $full)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($a[$full]) & $mask) === (ord($b[$full]) & $mask);
    }
}
