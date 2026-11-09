<?php
/** C15 Meta description: existe y no está vacía; longitud fuera de 120-155 caracteres → AVISO. */
class C15MetaDescription extends BaseCheck
{
    private const MIN = 120;
    private const MAX = 155;

    protected string $id = 'C15';
    protected string $severity = 'BAJO';
    protected string $criterio = 'Buenas prácticas de SEO (fragmento en resultados de búsqueda). No es un criterio WCAG.';
    protected string $impacto = 'BAJO';
    protected string $esfuerzo = 'BAJO';

    protected function evaluate(CheckContext $ctx): array
    {
        $meta = null;
        foreach ($ctx->xpath()->query('//meta[@name]') as $m) {
            if (strtolower(trim($m->getAttribute('name'))) === 'description') {
                $meta = $m;
                break;
            }
        }
        $umbral = 'Meta description no vacía, idealmente de ' . self::MIN . ' a ' . self::MAX . ' caracteres';
        if (!$meta) {
            return $this->result(self::FAIL, null, $umbral, [DomHelper::note('(no hay meta description)', 'meta[name="description"]')], 'La página no tiene meta description.', 1);
        }
        $text = trim(preg_replace('/\s+/u', ' ', $meta->getAttribute('content')) ?? '');
        $len = mb_strlen($text);
        $ev = [DomHelper::evidence($meta)];
        $valor = ['texto' => $text, 'longitud' => $len];
        if ($text === '') {
            return $this->result(self::FAIL, $valor, $umbral, $ev, 'La meta description está vacía.', 1);
        }
        if ($len < self::MIN || $len > self::MAX) {
            return $this->result(self::WARN, $valor, $umbral, $ev, 'La meta description tiene ' . $len . ' caracteres (recomendado: ' . self::MIN . '-' . self::MAX . ').');
        }
        return $this->result(self::OK, $valor, $umbral, $ev, 'Meta description de ' . $len . ' caracteres.');
    }
}
