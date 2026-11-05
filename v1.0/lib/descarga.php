<?php
// Descarga direcciones escritas por el usuario sin fiarse de ellas.

const AGENTE = 'ProconsiAuditor/0.1';

// Devuelve la IP pública de la URL, o lanza una excepción con un mensaje para el usuario.
function comprobar_destino(string $url, array $config): string
{
    $partes = parse_url($url);
    $esquema = strtolower($partes['scheme'] ?? '');
    $host = trim($partes['host'] ?? '', '[]');

    if (!in_array($esquema, ['http', 'https'], true) || $host === '') {
        throw new RuntimeException('Escribe una dirección completa que empiece por http:// o https://.');
    }
    if (isset($partes['user']) || isset($partes['pass'])) {
        throw new RuntimeException('La dirección no puede incluir usuario ni contraseña.');
    }

    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : resolver_host($host);
    if (!$ips) {
        throw new RuntimeException("No se ha encontrado el dominio $host.");
    }
    foreach ($ips as $ip) {
        $publica = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (!$publica && !$config['permitir_locales']) {
            throw new RuntimeException('Por seguridad, no se pueden analizar direcciones internas o privadas.');
        }
    }
    return $ips[0];
}

function resolver_host(string $host): array
{
    $ips = gethostbynamel($host) ?: [];
    foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $registro) {
        $ips[] = $registro['ipv6'];
    }
    return $ips;
}

// Convierte un enlace relativo en absoluto, tomando como base la URL de la página.
function url_absoluta(string $base, string $enlace): string
{
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $enlace)) {
        return $enlace;
    }
    $b = parse_url($base);
    $origen = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($enlace, '//')) {
        return $b['scheme'] . ':' . $enlace;
    }
    if (str_starts_with($enlace, '/')) {
        return $origen . $enlace;
    }
    $carpeta = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    return $origen . $carpeta . $enlace;
}

// Hace una sola petición, sin seguir redirecciones.
function peticion(string $url, string $ip, array $config): array
{
    $cabeceras = [];
    $cuerpo = '';
    $recortada = false;
    $partes = parse_url($url);
    $puerto = $partes['port'] ?? ($partes['scheme'] === 'https' ? 443 : 80);
    $ip_curl = str_contains($ip, ':') ? "[$ip]" : $ip;

    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_USERAGENT => AGENTE,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $config['tiempo_maximo'],
        CURLOPT_ENCODING => '',
        CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
        // Fija la IP ya validada para que el dominio no pueda cambiarla después.
        CURLOPT_RESOLVE => [trim($partes['host'], '[]') . ":$puerto:$ip_curl"],
        CURLOPT_HEADERFUNCTION => function ($c, $linea) use (&$cabeceras) {
            if (str_contains($linea, ':')) {
                [$nombre, $valor] = explode(':', $linea, 2);
                $nombre = strtolower(trim($nombre));
                // Una respuesta puede traer varias cookies: se guardan todas.
                $previo = $nombre === 'set-cookie' ? ($cabeceras[$nombre] ?? '') . "\n" : '';
                $cabeceras[$nombre] = ltrim($previo . trim($valor));
            }
            return strlen($linea);
        },
        CURLOPT_WRITEFUNCTION => function ($c, $datos) use (&$cuerpo, &$recortada, $config) {
            $cuerpo .= $datos;
            if (strlen($cuerpo) > $config['tamano_maximo']) {
                $recortada = true;
                return 0;
            }
            return strlen($datos);
        },
    ]);
    curl_exec($c);
    $error = curl_errno($c);
    $respuesta = [
        'codigo' => curl_getinfo($c, CURLINFO_RESPONSE_CODE),
        'tiempo_ms' => (int) (curl_getinfo($c, CURLINFO_STARTTRANSFER_TIME) * 1000),
        'cabeceras' => $cabeceras,
        'cuerpo' => $cuerpo,
        'recortada' => $recortada,
    ];
    curl_close($c);

    if ($error && !$recortada) {
        throw new RuntimeException(mensaje_de_error($error));
    }
    return $respuesta;
}

function mensaje_de_error(int $error): string
{
    return match ($error) {
        CURLE_OPERATION_TIMEDOUT => 'La web ha tardado demasiado en responder.',
        CURLE_COULDNT_CONNECT => 'No se ha podido conectar con la web.',
        CURLE_SSL_CACERT, CURLE_SSL_PEER_CERTIFICATE, 60 => 'El certificado HTTPS de la web no es válido.',
        default => "No se ha podido descargar la web (error $error de cURL).",
    };
}

// Descarga una URL siguiendo redirecciones, y valida cada salto.
function descargar(string $url, array $config): array
{
    $saltos = [];
    for ($i = 0; $i <= $config['redirecciones_maximas']; $i++) {
        $respuesta = peticion($url, comprobar_destino($url, $config), $config);
        $destino = $respuesta['cabeceras']['location'] ?? '';
        if ($respuesta['codigo'] < 300 || $respuesta['codigo'] >= 400 || $destino === '') {
            return $respuesta + ['url' => $url, 'saltos' => $saltos];
        }
        $saltos[] = ['url' => $url, 'codigo' => $respuesta['codigo']];
        $url = url_absoluta($url, $destino);
    }
    throw new RuntimeException('La web tiene demasiadas redirecciones seguidas.');
}

// Comprueba muchos enlaces a la vez y devuelve url => código HTTP.
function comprobar_enlaces(array $urls, array $config): array
{
    $multi = curl_multi_init();
    $activos = [];
    foreach ($urls as $url) {
        try {
            $ip = comprobar_destino($url, $config);
        } catch (RuntimeException) {
            continue;
        }
        $partes = parse_url($url);
        $puerto = $partes['port'] ?? ($partes['scheme'] === 'https' ? 443 : 80);
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_USERAGENT => AGENTE,
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
            CURLOPT_RESOLVE => [trim($partes['host'], '[]') . ":$puerto:" . (str_contains($ip, ':') ? "[$ip]" : $ip)],
        ]);
        curl_multi_add_handle($multi, $c);
        $activos[$url] = $c;
    }
    do {
        curl_multi_exec($multi, $corriendo);
        curl_multi_select($multi, 0.2);
    } while ($corriendo > 0);

    $codigos = [];
    foreach ($activos as $url => $c) {
        $codigos[$url] = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($multi, $c);
        curl_close($c);
    }
    curl_multi_close($multi);
    return $codigos;
}
