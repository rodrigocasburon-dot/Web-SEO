<?php
/**
 * C08 Contraste de color, a partir de la auditoría «color-contrast» (axe-core) de Lighthouse
 * que devuelve PageSpeed Insights. k = número de elementos con contraste insuficiente.
 * No detecta el texto sobre imágenes ni degradados: requiere revisión manual.
 */
class C08Contrast extends BaseCheck
{
    protected string $id = 'C08';
    protected string $severity = 'ALTO';
    protected string $criterio = 'Referencia: WCAG 2.2 SC 1.4.3 (Contraste mínimo: 4,5:1 en texto normal y 3:1 en texto grande), evaluado por Lighthouse (axe-core) mediante PageSpeed Insights.';
    protected string $impacto = 'ALTO';
    protected string $esfuerzo = 'MEDIO';
    protected bool $manual = true;
    protected bool $needsHtml = false;

    protected function evaluate(CheckContext $ctx): array
    {
        $umbral = 'Contraste ≥ 4,5:1 (texto normal) y ≥ 3:1 (texto grande)';
        if ($ctx->psi === null) {
            return $this->result(self::NO_DATA, null, $umbral, [], 'Sin datos de PageSpeed Insights'
                . ($ctx->psiError ? ': ' . $ctx->psiError['message'] : '.'));
        }
        $lh = $ctx->psi['lighthouseResult'] ?? null;
        if (!is_array($lh)) {
            return $this->result(self::NO_DATA, null, $umbral, [], 'PageSpeed Insights no devolvió resultados de Lighthouse.');
        }
        if (!empty($lh['runtimeError']['code'])) {
            return $this->result(self::NO_DATA, null, $umbral, [], 'Lighthouse no pudo analizar la página ('
                . $lh['runtimeError']['code'] . ').');
        }
        $audit = $lh['audits']['color-contrast'] ?? null;
        if (!is_array($audit)) {
            return $this->result(self::NO_DATA, null, $umbral, [], 'La respuesta no incluye la auditoría de contraste.');
        }
        $mode = (string) ($audit['scoreDisplayMode'] ?? '');
        if ($mode === 'notApplicable') {
            return $this->result(self::OK, 0, $umbral, [], 'No hay texto al que aplicar la auditoría de contraste.', 0, false);
        }
        if ($mode === 'error' || !array_key_exists('score', $audit) || $audit['score'] === null) {
            return $this->result(self::NO_DATA, null, $umbral, [], 'La auditoría de contraste no se pudo completar'
                . (!empty($audit['errorMessage']) ? ': ' . mb_substr((string) $audit['errorMessage'], 0, 200) : '.'));
        }

        $items = $audit['details']['items'] ?? [];
        $items = is_array($items) ? $items : [];
        if ((float) $audit['score'] >= 1 || !$items) {
            return $this->result(self::OK, 0, $umbral, [], 'Lighthouse no detecta texto con contraste insuficiente (no evalúa texto sobre imágenes ni degradados).');
        }

        $ev = [];
        foreach ($items as $item) {
            $node = is_array($item['node'] ?? null) ? $item['node'] : [];
            $explanation = self::ratioSummary((string) ($node['explanation'] ?? ''));
            $snippet = (string) ($node['snippet'] ?? '');
            $ev[] = [
                'selector' => isset($node['selector']) ? (string) $node['selector'] : null,
                'extracto' => trim(mb_substr($snippet, 0, 240) . ($explanation !== '' ? "\n" . $explanation : '')),
                'linea' => null,
            ];
        }
        $k = count($items);
        return $this->result(self::FAIL, $k, $umbral, $ev, $k . ' elemento(s) con contraste insuficiente según Lighthouse.', $k);
    }

    /**
     * Resume la explicación de axe-core, que llega en el idioma pedido (locale=es) o en inglés:
     * se extraen la relación de contraste, los colores y la relación esperada si se reconocen.
     */
    public static function ratioSummary(string $explanation): string
    {
        $parts = [];
        // Primera cifra tras «contrast»/«contraste» (en: "contrast of 2.5"; es: "contraste de color insuficiente de 2.5")
        if (preg_match('/contrast[^\d#]{0,40}?(\d+(?:[.,]\d+)?)/iu', $explanation, $m)) {
            $parts[] = 'Contraste ' . str_replace('.', ',', $m[1]) . ':1';
        }
        if (preg_match_all('/#[0-9a-f]{3,8}\b/i', $explanation, $c) && count($c[0]) >= 2) {
            $parts[] = 'texto ' . $c[0][0] . ' sobre ' . $c[0][1];
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*:\s*1\b/u', $explanation, $e)) {
            $parts[] = 'se espera ' . str_replace('.', ',', $e[1]) . ':1';
        }
        if ($parts) {
            return implode('; ', $parts);
        }
        // Formato no reconocido: se muestra la explicación original abreviada
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $explanation) ?? ''), 0, 240);
    }
}
