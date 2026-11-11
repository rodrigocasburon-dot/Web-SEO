<?php
/** C10 Title: exactamente un title no vacío; longitud fuera de 35-60 caracteres → AVISO. */
class C10Title extends BaseCheck
{
    private const MIN = 35;
    private const MAX = 60;

    protected string $id = 'C10';
    protected string $severity = 'ALTO';
    protected string $criterio = 'Referencia: WCAG 2.2 SC 2.4.2 (Titulado de páginas) y buenas prácticas de SEO.';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'BAJO';

    protected function evaluate(CheckContext $ctx): array
    {
        $titles = [];
        foreach ($ctx->xpath()->query('//title[not(ancestor::svg)]') as $t) {
            $titles[] = $t;
        }
        $umbral = 'Un único title no vacío, idealmente de ' . self::MIN . ' a ' . self::MAX . ' caracteres';
        if (count($titles) === 0) {
            return $this->result(self::FAIL, null, $umbral, [DomHelper::note('(no hay elemento title)', 'title')], 'La página no tiene title.', 1);
        }
        $ev = array_map([DomHelper::class, 'evidence'], $titles);
        if (count($titles) > 1) {
            return $this->result(self::FAIL, count($titles), $umbral, $ev, 'La página tiene ' . count($titles) . ' elementos title.', count($titles));
        }
        $text = trim(preg_replace('/\s+/u', ' ', $titles[0]->textContent) ?? '');
        $len = mb_strlen($text);
        $valor = ['texto' => $text, 'longitud' => $len];
        if ($text === '') {
            return $this->result(self::FAIL, $valor, $umbral, $ev, 'El title está vacío.', 1);
        }
        if ($len < self::MIN || $len > self::MAX) {
            return $this->result(self::WARN, $valor, $umbral, $ev, 'El title tiene ' . $len . ' caracteres (recomendado: ' . self::MIN . '-' . self::MAX . ').');
        }
        return $this->result(self::OK, $valor, $umbral, $ev, 'Title único de ' . $len . ' caracteres.');
    }
}
