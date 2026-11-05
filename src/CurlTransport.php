<?php
/**
 * Una única petición HTTP(S) con cURL: sin seguir redirecciones, con IP fijada,
 * verificación de certificado, límite de tamaño y de tiempo.
 * El Fetcher lo invoca por cada salto; es sustituible en las pruebas.
 */
class CurlTransport
{
    /**
     * @param array $target    resultado de UrlGuard::validate (url, host, port, ip, is_ip_literal)
     * @param int   $timeoutMs tiempo máximo restante para esta petición
     * @param array $opt       user_agent, max_bytes
     * @return array errno, error, status, headers (nombres en minúsculas), body, truncated, timings, remote_ip
     */
    public function __invoke(array $target, int $timeoutMs, array $opt): array
    {
        $headers = [];
        $body = '';
        $truncated = false;
        $max = (int) $opt['max_bytes'];

        $ch = curl_init($target['url']);
        $options = [
            CURLOPT_FOLLOWLOCATION => false,           // las redirecciones las gestiona el Fetcher
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => $opt['user_agent'],
            CURLOPT_TIMEOUT_MS => max(1, $timeoutMs),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, min($timeoutMs, 5000)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',                    // acepta gzip/deflate; el límite se aplica ya descomprimido
            CURLOPT_PROXY => '',                       // ignora proxies del entorno
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
                'Accept-Language: es,en;q=0.8',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $len = strlen($line);
                $line = trim($line);
                if (stripos($line, 'HTTP/') === 0) {
                    $headers = [];            // nueva respuesta (p. ej. tras un 100 Continue)
                } elseif (($pos = strpos($line, ':')) !== false) {
                    $name = strtolower(trim(substr($line, 0, $pos)));
                    $value = trim(substr($line, $pos + 1));
                    $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$body, &$truncated, $max) {
                $len = strlen($data);
                $room = $max - strlen($body);
                if ($len > $room) {
                    $body .= substr($data, 0, max(0, $room));
                    $truncated = true;
                    return 0;                  // aborta la descarga
                }
                $body .= $data;
                return $len;
            },
        ];
        // Fija la IP ya validada: cURL no vuelve a resolver el dominio (evita DNS rebinding).
        if (empty($target['is_ip_literal'])) {
            $ip = strpos($target['ip'], ':') !== false ? '[' . $target['ip'] . ']' : $target['ip'];
            $options[CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . $ip];
        }
        curl_setopt_array($ch, $options);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);

        // Abortar al llegar al límite no es un error: se devuelve el HTML recibido como truncado.
        if ($truncated && $errno === 23) {
            $errno = 0;
            $error = '';
        }

        $ms = fn($key) => isset($info[$key]) ? (int) round($info[$key] * 1000) : 0;
        return [
            'errno' => $errno,
            'error' => $error,
            'status' => (int) ($info['http_code'] ?? 0),
            'headers' => $headers,
            'body' => $body,
            'truncated' => $truncated,
            'remote_ip' => $info['primary_ip'] ?? '',
            'timings' => [
                'namelookup_ms' => $ms('namelookup_time'),
                'connect_ms' => $ms('connect_time'),
                'tls_ms' => $ms('appconnect_time'),
                'ttfb_ms' => $ms('starttransfer_time'),
                'total_ms' => $ms('total_time'),
            ],
        ];
    }
}
