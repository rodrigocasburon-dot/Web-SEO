<?php
/**
 * C16 Enlaces rotos: comprueba los primeros 20 enlaces http(s) distintos de la página, en orden de
 * aparición, con las mismas defensas que la descarga principal (UrlGuard, IP fijada, redirecciones
 * revalidadas), un tiempo límite por enlace y otro para el conjunto. Se pide con GET y se descarta
 * el cuerpo a partir de 64 KB.
 * - 4xx (salvo 401, 403, 407 y 429), dominio inexistente o URL mal formada → roto (NO_CUMPLE, k = nº de rotos)
 * - 5xx, tiempo agotado, error de conexión o de certificado, bucle o exceso de redirecciones → sin confirmar (AVISO)
 * - 401/403/407/429, captcha, IP privada o puerto no permitido → no comprobable (no cuenta)
 * No se siguen enlaces mailto:, tel:, javascript: ni anclas de la propia página.
 */
class C16BrokenLinks extends BaseCheck
{
    protected string $id = 'C16';
    protected string $severity = 'MEDIO';
    protected string $criterio = 'Códigos de estado HTTP de los destinos enlazados (RFC 9110). No es un criterio WCAG.';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'BAJO';

    private const BLOCK_STATUS = [401, 403, 407, 429];
    private const BROKEN_ERRORS = ['DNS_FAILED' => 'Dominio inexistente', 'INVALID_URL' => 'URL no válida'];
    private const UNCHECKABLE_ERRORS = ['PRIVATE_ADDRESS', 'PORT_NOT_ALLOWED', 'SCHEME_NOT_ALLOWED', 'CREDENTIALS_IN_URL', 'INVALID_HOST'];
    private const UNCERTAIN_TEXT = [
        'TIMEOUT' => 'Tiempo agotado',
        'CONNECTION_FAILED' => 'Conexión fallida',
        'SSL_ERROR' => 'Certificado no válido',
        'REDIRECT_LOOP' => 'Redirecciones en bucle',
        'TOO_MANY_REDIRECTS' => 'Demasiadas redirecciones',
        'BAD_REDIRECT' => 'Redirección sin destino',
    ];

    protected function evaluate(CheckContext $ctx): array
    {
        $max = max(1, (int) ($ctx->config['links_max'] ?? 20));
        $perLink = max(1, (int) ($ctx->config['links_timeout'] ?? 5));
        $budget = (float) ($ctx->config['links_budget'] ?? 12);
        $umbral = "Ningún enlace responde con error 4xx ni apunta a un dominio inexistente (se comprueban hasta $max enlaces)";

        $links = $this->collect($ctx);
        $total = count($links);
        if ($total === 0) {
            return $this->result(self::OK, ['enlaces_en_la_pagina' => 0], $umbral, [], 'La página no tiene enlaces http(s) que comprobar.', 0, false);
        }
        if ($ctx->fetch === null) {
            return $this->result(self::NO_DATA, ['enlaces_en_la_pagina' => $total], $umbral, [], 'No se han podido comprobar los enlaces.');
        }

        $deadline = microtime(true) + $budget;
        $broken = [];
        $uncertain = [];
        $ok = 0;
        $unchecked = max(0, $total - $max);
        foreach (array_slice($links, 0, $max, true) as $url => $el) {
            $left = $deadline - microtime(true);
            if ($left < 1) {
                $unchecked++;
                continue;
            }
            $r = ($ctx->fetch)((string) $url, ['timeout' => (int) min($perLink, floor($left)), 'max_bytes' => 65536]);
            [$kind, $why] = $this->classify($r);
            if ($kind === 'broken') {
                $broken[] = [$el, $url, $why];
            } elseif ($kind === 'uncertain') {
                $uncertain[] = [$el, $url, $why];
            } elseif ($kind === 'ok') {
                $ok++;
            } else {
                $unchecked++;
            }
        }

        $checked = $ok + count($broken) + count($uncertain);
        $valor = [
            'enlaces_en_la_pagina' => $total,
            'comprobados' => $checked,
            'rotos' => count($broken),
            'sin_confirmar' => count($uncertain),
            'no_comprobados' => $unchecked,
        ];
        $ev = [];
        foreach (array_merge($broken, $uncertain) as [$el, $url, $why]) {
            if (count($ev) >= 10) {
                break;
            }
            $e = DomHelper::evidence($el);
            $e['extracto'] = $why . ': ' . $url . "\n" . $e['extracto'];
            $ev[] = $e;
        }
        $scope = " de $checked comprobados" . ($unchecked ? " ($unchecked sin comprobar)" : '') . '.';

        if ($checked === 0) {
            return $this->result(self::NO_DATA, $valor, $umbral, [], "No se ha podido comprobar ninguno de los $total enlaces (bloqueo, destinos no públicos o tiempo agotado).");
        }
        if ($broken) {
            return $this->result(self::FAIL, $valor, $umbral, $ev, count($broken) . ' enlace(s) roto(s)' . $scope, count($broken));
        }
        if ($uncertain) {
            return $this->result(self::WARN, $valor, $umbral, $ev, count($uncertain) . ' enlace(s) sin confirmar por error del servidor de destino, tiempo agotado o conexión fallida' . $scope);
        }
        return $this->result(self::OK, $valor, $umbral, [], "Sin errores detectables automáticamente en $checked enlaces comprobados" . ($unchecked ? " ($unchecked sin comprobar)" : '') . '.');
    }

    /** @return array<string, DOMElement> URL absoluta (sin fragmento) → primer enlace que la usa */
    private function collect(CheckContext $ctx): array
    {
        $base = $ctx->url;
        $baseEl = $ctx->xpath()->query('//base[@href]')->item(0);
        if ($baseEl instanceof DOMElement) {
            $b = Fetcher::resolveUrl($ctx->url, trim($baseEl->getAttribute('href')));
            if (preg_match('#^https?://#i', $b)) {
                $base = $b;
            }
        }
        $self = explode('#', $ctx->url, 2)[0];
        $out = [];
        foreach ($ctx->xpath()->query('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || $href[0] === '#') {
                continue;
            }
            // Otros esquemas (mailto:, tel:, javascript:…) no se comprueban
            if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $href, $m) && !in_array(strtolower($m[1]), ['http', 'https'], true)) {
                continue;
            }
            $abs = explode('#', Fetcher::resolveUrl($base, str_replace(' ', '%20', $href)), 2)[0];
            if (!preg_match('#^https?://#i', $abs) || $abs === $self || isset($out[$abs])) {
                continue;
            }
            $out[$abs] = $a;
        }
        return $out;
    }

    /** @return array{0: string, 1: string} tipo (ok, broken, uncertain, skip) y motivo */
    private function classify(array $r): array
    {
        if (!empty($r['ok'])) {
            $s = (int) ($r['status'] ?? 0);
            if (!empty($r['blocked']) || in_array($s, self::BLOCK_STATUS, true)) {
                return ['skip', "HTTP $s"];
            }
            if ($s >= 400 && $s < 500) {
                return ['broken', "HTTP $s"];
            }
            if ($s >= 500) {
                return ['uncertain', "HTTP $s"];
            }
            return ['ok', "HTTP $s"];
        }
        $code = (string) ($r['error']['code'] ?? 'UNKNOWN');
        if (isset(self::BROKEN_ERRORS[$code])) {
            return ['broken', self::BROKEN_ERRORS[$code]];
        }
        if (in_array($code, self::UNCHECKABLE_ERRORS, true)) {
            return ['skip', $code];
        }
        return ['uncertain', self::UNCERTAIN_TEXT[$code] ?? 'Error de conexión'];
    }
}
