<?php
/** C07 Idioma: atributo lang en html, presente y con formato BCP 47 válido (no se comprueba que el código exista). */
class C07Language extends BaseCheck
{
    // Etiqueta BCP 47 simplificada: idioma[-extlang][-escritura][-región][-variantes][-extensiones][-x-privado]
    private const BCP47 = '/^[a-z]{2,3}(-[a-z]{3}){0,3}(-[a-z]{4})?(-([a-z]{2}|\d{3}))?(-([a-z0-9]{5,8}|\d[a-z0-9]{3}))*(-[a-wyz0-9](-[a-z0-9]{2,8})+)*(-x(-[a-z0-9]{1,8})+)?$/i';

    protected string $id = 'C07';
    protected string $severity = 'ALTO';
    protected string $criterio = 'Referencia: WCAG 2.2 SC 3.1.1 (Idioma de la página).';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'BAJO';

    protected function evaluate(CheckContext $ctx): array
    {
        $html = $ctx->xpath()->query('/html')->item(0);
        $umbral = 'Atributo lang en <html> con un código de idioma válido (p. ej. es, es-ES)';
        if (!$html instanceof DOMElement || !$html->hasAttribute('lang')) {
            $ev = $html instanceof DOMElement ? [['selector' => 'html', 'extracto' => DomHelper::openTag($html), 'linea' => $html->getLineNo() ?: null]] : [];
            return $this->result(self::FAIL, null, $umbral, $ev, 'El elemento html no tiene atributo lang.', 1);
        }
        $lang = trim($html->getAttribute('lang'));
        $ev = [['selector' => 'html', 'extracto' => DomHelper::openTag($html), 'linea' => $html->getLineNo() ?: null]];
        if ($lang === '') {
            return $this->result(self::FAIL, '', $umbral, $ev, 'El atributo lang está vacío.', 1);
        }
        if (!preg_match(self::BCP47, $lang)) {
            return $this->result(self::FAIL, $lang, $umbral, $ev, 'El valor «' . $lang . '» no tiene formato de código de idioma válido.', 1);
        }
        return $this->result(self::OK, $lang, $umbral, $ev, 'Idioma declarado: ' . $lang . '. Que coincida con el idioma real del contenido no se comprueba.');
    }
}
