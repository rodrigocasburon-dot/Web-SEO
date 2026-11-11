<?php
/**
 * C21 Título en iframes (v3.0, traída de la v1.0 · UX-16): cada iframe visible tiene title (o aria-label)
 * para que un lector de pantalla diga qué contiene. Los ocultos (aria-hidden, hidden, display:none en línea) no cuentan.
 * - alguno sin nombre → NO_CUMPLE (k = nº de iframes sin nombre)
 * - sin iframes → no aplica
 */
class C21IframeTitle extends BaseCheck
{
    protected string $id = 'C21';
    protected string $severity = 'MEDIO';
    protected string $criterio = 'Referencia: WCAG 2.2 SC 4.1.2 (Nombre, función, valor) y técnica H64.';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'BAJO';

    protected function evaluate(CheckContext $ctx): array
    {
        $umbral = 'Cada iframe visible con title o aria-label no vacíos';
        $all = [];
        foreach ($ctx->xpath()->query('//iframe') as $f) {
            if ($f instanceof DOMElement && !DomHelper::isHidden($f) && $f->getAttribute('aria-hidden') !== 'true') {
                $all[] = $f;
            }
        }
        if (!$all) {
            return $this->result(self::OK, 0, $umbral, [], 'La página no tiene iframes visibles.', 0, false);
        }
        $bad = array_values(array_filter($all, fn(DOMElement $f) => trim($f->getAttribute('title')) === '' && trim($f->getAttribute('aria-label')) === ''));
        if ($bad) {
            $ev = array_map(fn(DOMElement $f) => DomHelper::evidence($f), array_slice($bad, 0, 10));
            return $this->result(self::FAIL, count($bad), $umbral, $ev, count($bad) . ' de ' . count($all) . ' iframes no tienen title.', count($bad));
        }
        return $this->result(self::OK, 0, $umbral, [], 'Los ' . count($all) . ' iframes tienen title.');
    }
}
