<?php
/**
 * Cliente de la API de PageSpeed Insights v5. Pide solo los campos necesarios (parámetro
 * "fields") para no descargar capturas ni el informe completo de Lighthouse.
 * La clave nunca aparece en los mensajes de error.
 */
class PsiClient
{
    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    private const FIELDS = 'id,loadingExperience,originLoadingExperience,analysisUTCTimestamp,'
        . 'lighthouseResult/lighthouseVersion,lighthouseResult/finalDisplayedUrl,lighthouseResult/runtimeError,'
        . 'lighthouseResult/runWarnings,lighthouseResult/audits/color-contrast';

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

        if (($r['errno'] ?? 0) !== 0) {
            return self::fail((int) $r['errno'] === 28 ? 'PSI_TIMEOUT' : 'PSI_NETWORK',
                (int) $r['errno'] === 28 ? 'PageSpeed Insights no respondió a tiempo.' : 'No se pudo conectar con PageSpeed Insights.');
        }
        $data = json_decode((string) ($r['body'] ?? ''), true);
        $status = (int) ($r['status'] ?? 0);

        if ($status !== 200) {
            $apiMessage = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            if ($status === 429) {
                return self::fail('PSI_QUOTA', $this->hasKey()
                    ? 'Se ha agotado la cuota de PageSpeed Insights. Inténtalo más tarde.'
                    : 'Falta la clave de API de PageSpeed Insights (sin clave la cuota es 0).');
            }
            if (stripos($apiMessage, 'API key') !== false || $status === 403) {
                return self::fail('PSI_KEY', 'La clave de API de PageSpeed Insights no es válida o no tiene permisos.');
            }
            // Errores de Lighthouse (la página no carga, etc.): el mensaje de Google no contiene la clave
            return self::fail('PSI_ERROR', 'PageSpeed Insights devolvió un error (HTTP ' . $status . ')'
                . ($apiMessage !== '' ? ': ' . mb_substr($apiMessage, 0, 300) : '.'));
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
        curl_setopt_array($ch, [
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
