<?php
/**
 * C25 Elegibilidad de fragmento (GEO-25). Google solo usa en AI Overviews y AI Mode páginas indexadas que
 * pueden mostrar fragmento.
 * - nosnippet (o max-snippet:0, que Google trata igual) en meta robots/googlebot o X-Robots-Tag → NO_CUMPLE.
 * - max-snippet con valor de 1 a 49 → AVISO (umbral orientativo; -1 = sin límite).
 * - data-nosnippet sobre la mayor parte (> 50 %) del texto principal → AVISO; en menús o elementos
 *   accesorios → CUMPLE.
 */
class C25SnippetEligibility extends BaseCheck
{
    /** Longitud mínima orientativa del fragmento, en caracteres. */
    public const MIN_SNIPPET = 50;

    protected string $id = 'C25';
    protected string $severity = 'CRITICO';
    protected string $criterio = 'Documentación de Google sobre AI Overviews/AI Mode y las directivas nosnippet, max-snippet y data-nosnippet. No es un criterio WCAG.';
    protected string $impacto = 'ALTO';
    protected string $esfuerzo = 'BAJO';
    protected string $respaldo = 'oficial';

    protected function evaluate(CheckContext $ctx): array
    {
        $umbral = 'Sin nosnippet; max-snippet de al menos ' . self::MIN_SNIPPET . ' caracteres o -1; data-nosnippet solo en elementos accesorios';
        $nosnippet = [];
        $short = [];
        $maxSnippet = null;
        foreach (RobotsDirectives::forPage($ctx) as $d) {
            $ev = $d['origen'] instanceof DOMElement ? DomHelper::evidence($d['origen']) : DomHelper::note($d['origen'], 'cabecera HTTP');
            if ($d['directiva'] === 'nosnippet') {
                $nosnippet[] = $ev;
            } elseif (preg_match('/^max-snippet:(-?\d+)$/', $d['directiva'], $m)) {
                $n = (int) $m[1];
                $maxSnippet = $maxSnippet === null ? $n : min($maxSnippet, $n);
                if ($n === 0) {
                    $nosnippet[] = $ev;
                } elseif ($n > 0 && $n < self::MIN_SNIPPET) {
                    $short[] = $ev;
                }
            }
        }

        // data-nosnippet: parte del texto principal que desaparece al quitar esos elementos (copia del DOM)
        $x = $ctx->xpath();
        [$source, $text] = DomHelper::mainContent($x);
        $total = mb_strlen($text);
        $hiddenEv = [];
        foreach ($x->query('//*[@data-nosnippet][not(ancestor::*[@data-nosnippet])]') as $el) {
            $hiddenEv[] = DomHelper::evidence($el);
        }
        $share = 0;
        if ($hiddenEv && $total > 0) {
            $copy = clone $ctx->dom();
            $cx = new DOMXPath($copy);
            foreach (iterator_to_array($cx->query('//*[@data-nosnippet]')) as $el) {
                if ($el->parentNode) {
                    $el->parentNode->removeChild($el);
                }
            }
            [, $rest] = DomHelper::mainContent($cx);
            $share = max(0, min(1, 1 - mb_strlen($rest) / $total));
        }
        $valor = ['nosnippet' => (bool) $nosnippet, 'max_snippet' => $maxSnippet, 'data_nosnippet' => count($hiddenEv),
            'texto_principal_excluido' => round($share * 100) . ' %'];

        if ($nosnippet) {
            return $this->result(self::FAIL, $valor, $umbral, $nosnippet,
                'La página prohíbe mostrar fragmentos (nosnippet o max-snippet:0): Google no puede usarla en AI Overviews ni en AI Mode.', 1);
        }
        if ($short) {
            return $this->result(self::WARN, $valor, $umbral, $short,
                'max-snippet limita el fragmento a ' . $maxSnippet . ' caracteres. El umbral de ' . self::MIN_SNIPPET . ' es orientativo: un fragmento tan corto puede impedir que se cite la página.');
        }
        if ($share > 0.5) {
            return $this->result(self::WARN, $valor, $umbral, array_slice($hiddenEv, 0, 10),
                'data-nosnippet excluye el ' . round($share * 100) . ' % del texto principal (' . $source . ') de los fragmentos.');
        }
        $detail = $hiddenEv
            ? 'data-nosnippet solo afecta a una parte menor del texto principal o a elementos accesorios.'
            : 'No hay nosnippet, max-snippet restrictivo ni data-nosnippet.';
        return $this->result(self::OK, $valor, $umbral, $hiddenEv ? array_slice($hiddenEv, 0, 3) : [], $detail);
    }
}
