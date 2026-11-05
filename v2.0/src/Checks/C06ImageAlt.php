<?php
/**
 * C06 Texto ALT: toda imagen visible con atributo alt (vacío si es decorativa).
 * Se aceptan también role="presentation|none", aria-label y aria-labelledby.
 * La calidad del texto alternativo no se evalúa (revisión manual).
 */
class C06ImageAlt extends BaseCheck
{
    protected string $id = 'C06';
    protected string $severity = 'ALTO';
    protected string $criterio = 'Referencia: WCAG 2.2 SC 1.1.1 (Contenido no textual).';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'BAJO';
    protected bool $manual = true;

    protected function evaluate(CheckContext $ctx): array
    {
        $total = 0;
        $empty = 0;
        $ev = [];
        foreach ($ctx->xpath()->query('//img') as $img) {
            if (DomHelper::isHidden($img)) {
                continue;
            }
            $total++;
            $role = strtolower(trim($img->getAttribute('role')));
            if ($img->hasAttribute('alt')) {
                if (trim($img->getAttribute('alt')) === '') {
                    $empty++;
                }
                continue;
            }
            if ($role === 'presentation' || $role === 'none'
                || trim($img->getAttribute('aria-label')) !== '' || trim($img->getAttribute('aria-labelledby')) !== '') {
                continue;
            }
            $ev[] = DomHelper::evidence($img);
        }
        $missing = count($ev);
        $valor = ['sin_alt' => $missing, 'total' => $total, 'alt_vacio' => $empty];
        $umbral = 'Todas las imágenes con atributo alt';

        if ($total === 0) {
            return $this->result(self::OK, $valor, $umbral, [], 'No hay imágenes img visibles que evaluar.', 0, false);
        }
        if ($missing === 0) {
            return $this->result(self::OK, $valor, $umbral, [], 'Las ' . $total . ' imágenes tienen atributo alt (' . $empty . ' vacío, como decorativas). La calidad del texto requiere revisión manual.');
        }
        return $this->result(self::FAIL, $valor, $umbral, $ev, $missing . ' de ' . $total . ' imágenes sin atributo alt.', $missing);
    }
}
