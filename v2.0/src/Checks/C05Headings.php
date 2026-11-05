<?php
/** C05 Encabezados: exactamente un h1 (NO_CUMPLE si no); saltos de nivel → AVISO. */
class C05Headings extends BaseCheck
{
    protected string $id = 'C05';
    protected string $severity = 'MEDIO';
    protected string $criterio = 'Referencia: WCAG 2.2 SC 1.3.1 (Información y relaciones) y SC 2.4.6 (Encabezados y etiquetas).';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'BAJO';

    protected function evaluate(CheckContext $ctx): array
    {
        $headings = [];
        foreach ($ctx->xpath()->query('//h1|//h2|//h3|//h4|//h5|//h6') as $h) {
            if (!DomHelper::isHidden($h)) {
                $headings[] = $h;
            }
        }
        $h1 = array_values(array_filter($headings, fn($h) => strtolower($h->tagName) === 'h1'));

        $skips = [];
        $prev = null;
        foreach ($headings as $h) {
            $level = (int) substr(strtolower($h->tagName), 1);
            if ($prev !== null && $level > $prev + 1) {
                $skips[] = ['el' => $h, 'de' => $prev, 'a' => $level];
            }
            $prev = $level;
        }

        $outline = array_map(fn($h) => strtolower($h->tagName), array_slice($headings, 0, 50));
        $valor = ['h1' => count($h1), 'saltos' => count($skips), 'esquema' => $outline];
        $umbral = 'Un único h1 y sin saltos de nivel';
        $skipText = $skips ? ' Hay ' . count($skips) . ' salto(s) de nivel.' : '';

        if (count($h1) !== 1) {
            $ev = count($h1) === 0
                ? [DomHelper::note('(no hay ningún h1 visible)', 'h1')]
                : array_map([DomHelper::class, 'evidence'], $h1);
            $msg = count($h1) === 0 ? 'La página no tiene h1.' : 'La página tiene ' . count($h1) . ' elementos h1.';
            return $this->result(self::FAIL, $valor, $umbral, $ev, $msg . $skipText, max(1, count($h1)));
        }
        if ($skips) {
            $ev = array_map(function ($s) {
                $e = DomHelper::evidence($s['el']);
                $e['extracto'] = 'h' . $s['de'] . ' → h' . $s['a'] . ': ' . $e['extracto'];
                return $e;
            }, $skips);
            return $this->result(self::WARN, $valor, $umbral, $ev, 'Un h1 correcto, pero con ' . count($skips) . ' salto(s) de nivel.');
        }
        return $this->result(self::OK, $valor, $umbral, [], 'Un único h1 y ' . count($headings) . ' encabezados sin saltos de nivel.');
    }
}
