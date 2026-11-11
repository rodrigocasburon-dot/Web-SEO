<?php
/**
 * Cliente de la API de PageSpeed Insights v5. El parámetro "fields" limita la respuesta a los campos que se
 * usan, pero no admite seleccionar claves dentro de lighthouseResult/audits: pedir solo
 * "lighthouseResult/audits/color-contrast" devuelve HTTP 400 («Request contains an invalid argument»).
 * Por eso se piden todas las auditorías, capturas de pantalla incluidas (unos 420 KB en una prueba real).
 * La clave nunca aparece en los mensajes de error.
 */
class PsiClient
{
    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    private const FIELDS = 'id,loadingExperience,originLoadingExperience,analysisUTCTimestamp,'
        . 'lighthouseResult/lighthouseVersion,lighthouseResult/finalDisplayedUrl,lighthouseResult/runtimeError,'
        . 'lighthouseResult/runWarnings,lighthouseResult/audits';

    private string $apiKey;
    private int $timeout;
    private string $userAgent;
    /** @var callable */
    private $transport;

    /**
     * @param callable|null $transport fn(string $url, int $timeout): array{status:int, body:string, errno:int, error:string}
     */
    public function __construct(string $apiKey, int $timeout = 75, string $userAgent = 'ProconsiAnalyzer/0.1', ?callable $transport = null)
    {
        $this->apiKey = trim($apiKey);
        $this->timeout = $timeout;
        $this->userAgent = $userAgent;
        $this->transport = $transport ?? [$this, 'curlGet'];
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== '';
    }

    /** URL de la petición (pública para poder probarla). */
    public function buildUrl(string $url, string $strategy = 'mobile'): string
    {
        $q = 'url=' . rawurlencode($url)
            . '&strategy=' . rawurlencode($strategy)
            . '&category=ACCESSIBILITY&category=PERFORMANCE&locale=es'
            . '&fields=' . rawurlencode(self::FIELDS);
        if ($this->apiKey !== '') {
            $q .= '&key=' . rawurlencode($this->apiKey);
        }
        return self::ENDPOINT . '?' . $q;
    }

    /**
     * @return array{ok: bool, data: ?array, error: ?array{code: string, message: string}}
     */
    public function run(string $url, string $strategy = 'mobile'): array
    {
        $r = ($this->transport)($this->buildUrl($url, $strategy), $this->timeout);

        $errno = (int) ($r['errno'] ?? 0);
        if ($errno === 28) {
            return self::fail('PSI_TIMEOUT', 'PageSpeed Insights no respondió a tiempo.');
        }
        if ($errno === 23) {
            // curlGet corta la descarga (error de escritura 23) cuando la respuesta supera el tamaño máximo
            return self::fail('PSI_TOO_LARGE', 'La respuesta de PageSpeed Insights supera el tamaño máximo admitido (5 MB).');
        }
        if ($errno !== 0) {
            return self::fail('PSI_NETWORK', 'No se pudo conectar con PageSpeed Insights.');
        }
        $data = json_decode((string) ($r['body'] ?? ''), true);
        $status = (int) ($r['status'] ?? 0);

        if ($status !== 200) {
            $apiMessage = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            // Por si Google repite la clave en su mensaje: nunca se devuelve
            if ($this->apiKey !== '') {
                $apiMessage = str_replace($this->apiKey, '[clave]', $apiMessage);
            }
            if ($status === 429) {
                return self::fail('PSI_QUOTA', $this->hasKey()
                    ? 'Se ha agotado la cuota de PageSpeed Insights. Inténtalo más tarde.'
                    : 'Falta la clave de API de PageSpeed Insights (sin clave la cuota es 0).');
            }
            if (stripos($apiMessage, 'API key') !== false || $status === 403) {
                return self::fail('PSI_KEY', 'La clave de API de PageSpeed Insights no es válida o no tiene permisos.');
            }
            // Explicación en español y, detrás, el mensaje original de Google (suele venir en inglés)
            $original = $apiMessage !== '' ? ' Mensaje de Google: «' . rtrim(mb_substr($apiMessage, 0, 300), '. ') . '».' : '';
            if ($status >= 500) {
                return self::fail('PSI_ERROR', 'PageSpeed Insights no pudo analizar la página (HTTP ' . $status . '). '
                    . 'Puede que la web no responda o tarde demasiado; vuelve a intentarlo más tarde.' . $original);
            }
            if ($status >= 400) {
                return self::fail('PSI_ERROR', 'PageSpeed Insights rechazó la consulta (HTTP ' . $status . ').' . $original);
            }
            return self::fail('PSI_ERROR', 'PageSpeed Insights devolvió una respuesta inesperada (HTTP ' . $status . ').' . $original);
        }
        if (!is_array($data)) {
            return self::fail('PSI_ERROR', 'Respuesta de PageSpeed Insights no válida.');
        }
        return ['ok' => true, 'data' => $data, 'error' => null];
    }

    private static function fail(string $code, string $message): array
    {
        return ['ok' => false, 'data' => null, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function curlGet(string $url, int $timeout): array
    {
        $ch = curl_init($url);
        $body = '';
        $max = 5 * 1024 * 1024;
        curl_setopt_array($ch, CurlTransport::caOptions() + [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $max) {
                if (strlen($body) + strlen($chunk) > $max) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        return [
            'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body' => $body,
            'errno' => curl_errno($ch),
            'error' => curl_error($ch),
        ];
    }
}
