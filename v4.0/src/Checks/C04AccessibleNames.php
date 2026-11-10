<?php
/**
 * C04 Nombres accesibles: enlaces y botones con texto o aria-label; campos con etiqueta.
 * Nombre calculado de forma simplificada: aria-labelledby, aria-label, contenido (texto, alt de
 * imágenes, title de SVG), label asociado (campos), value (botones input) y title.
 * No evalúa CSS externo ni la calidad del nombre (requiere revisión manual).
 */
class C04AccessibleNames extends BaseCheck
{
    private const BUTTON_INPUTS = ['submit', 'reset', 'button', 'image'];
    private const NON_FIELD_INPUTS = ['hidden', 'submit', 'reset', 'button', 'image'];

    protected string $id = 'C04';
    protected string $severity = 'CRITICO';
    protected string $criterio = 'Referencia: WCAG 2.2 SC 4.1.2 (Nombre, función, valor), SC 2.4.4 (Propósito de los enlaces) y SC 3.3.2 (Etiquetas o instrucciones).';
    protected string $impacto = 'ALTO';
    protected string $esfuerzo = 'MEDIO';
    protected bool $manual = true;

    private ?DOMDocument $doc = null;
    /** @var array<string, DOMElement[]> */
    private array $labelsFor = [];

    protected function evaluate(CheckContext $ctx): array
    {
        $this->doc = $ctx->dom();
        $x = $ctx->xpath();
        $this->labelsFor = [];
        foreach ($x->query('//label[@for]') as $lb) {
            $this->labelsFor[$lb->getAttribute('for')][] = $lb;
        }

        $totals = ['enlaces' => 0, 'botones' => 0, 'campos' => 0];
        $fails = ['enlaces' => 0, 'botones' => 0, 'campos' => 0];
        $ev = [];
        $seen = [];

        $evaluate = function (DOMElement $el, string $kind, bool $isField) use (&$totals, &$fails, &$ev, &$seen) {
            // getNodePath es estable; spl_object_id se reutiliza entre envoltorios DOM liberados
            $key = $el->getNodePath();
            if (isset($seen[$key]) || DomHelper::isHidden($el)) {
                return;
            }
            $seen[$key] = true;
            $totals[$kind]++;
            $name = $isField ? $this->fieldName($el) : $this->controlName($el);
            if ($name === '') {
                $fails[$kind]++;
                $ev[] = DomHelper::evidence($el);
            }
        };

        foreach ($x->query('//a[@href] | //*[@role="link"]') as $el) {
            $evaluate($el, 'enlaces', false);
        }
        foreach ($x->query('//button | //*[@role="button"]') as $el) {
            $evaluate($el, 'botones', false);
        }
        foreach ($x->query('//input | //select | //textarea') as $el) {
            $type = strtolower(trim($el->getAttribute('type')));
            if (strtolower($el->tagName) === 'input' && in_array($type, self::BUTTON_INPUTS, true)) {
                $evaluate($el, 'botones', false);
            } elseif (strtolower($el->tagName) !== 'input' || !in_array($type, self::NON_FIELD_INPUTS, true)) {
                $evaluate($el, 'campos', true);
            }
        }

        $total = array_sum($totals);
        $failed = array_sum($fails);
        $valor = ['sin_nombre' => $failed, 'evaluados' => $total, 'detalle' => $fails];
        $umbral = '0 elementos interactivos sin nombre accesible';

        if ($total === 0) {
            return $this->result(self::OK, $valor, $umbral, [], 'No hay enlaces, botones ni campos de formulario que evaluar.', 0, false);
        }
        if ($failed === 0) {
            return $this->result(self::OK, $valor, $umbral, [], 'Sin errores detectables automáticamente en ' . $total . ' elementos interactivos.');
        }
        return $this->result(self::FAIL, $valor, $umbral, $ev, sprintf(
            '%d de %d elementos sin nombre accesible (%d enlaces, %d botones, %d campos).',
            $failed, $total, $fails['enlaces'], $fails['botones'], $fails['campos']
        ), $failed);
    }

    /** Nombre de enlaces y botones. */
    private function controlName(DOMElement $el): string
    {
        $name = $this->ariaName($el);
        if ($name !== '') {
            return $name;
        }
        if (strtolower($el->tagName) === 'input') {
            $type = strtolower(trim($el->getAttribute('type')));
            if ($type === 'image') {
                return trim($el->getAttribute('alt')) !== '' ? trim($el->getAttribute('alt')) : trim($el->getAttribute('title'));
            }
            $value = trim($el->getAttribute('value'));
            if ($value !== '') {
                return $value;
            }
            // submit y reset tienen un texto por defecto del navegador
            return ($type === 'submit' || $type === 'reset') ? '(texto por defecto del navegador)' : trim($el->getAttribute('title'));
        }
        $content = $this->contentName($el);
        return $content !== '' ? $content : trim($el->getAttribute('title'));
    }

    /** Nombre de campos de formulario. */
    private function fieldName(DOMElement $el): string
    {
        $name = $this->ariaName($el);
        if ($name !== '') {
            return $name;
        }
        $id = $el->getAttribute('id');
        if ($id !== '' && isset($this->labelsFor[$id])) {
            foreach ($this->labelsFor[$id] as $label) {
                $t = $this->contentName($label);
                if ($t !== '') {
                    return $t;
                }
            }
        }
        for ($p = $el->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
            if (strtolower($p->tagName) === 'label') {
                $t = $this->contentName($p);
                if ($t !== '') {
                    return $t;
                }
                break;
            }
        }
        return trim($el->getAttribute('title'));
    }

    private function ariaName(DOMElement $el): string
    {
        $ids = trim($el->getAttribute('aria-labelledby'));
        if ($ids !== '' && $this->doc) {
            $t = '';
            foreach (preg_split('/\s+/', $ids) as $id) {
                $ref = $this->doc->getElementById($id);
                if ($ref) {
                    $t .= ' ' . $this->contentName($ref);
                }
            }
            if (trim($t) !== '') {
                return trim($t);
            }
        }
        return trim($el->getAttribute('aria-label'));
    }

    /** Nombre a partir del contenido: texto, alt de img, title de svg, aria-label de descendientes. */
    private function contentName(DOMNode $n): string
    {
        $s = '';
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) {
                $s .= $c->nodeValue;
                continue;
            }
            if (!$c instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($c->tagName);
            if (in_array($tag, ['script', 'style', 'template', 'noscript', 'input', 'select', 'textarea'], true)
                || $c->hasAttribute('hidden') || strtolower(trim($c->getAttribute('aria-hidden'))) === 'true') {
                continue;
            }
            $aria = trim($c->getAttribute('aria-label'));
            if ($aria !== '') {
                $s .= ' ' . $aria . ' ';
            } elseif ($tag === 'img') {
                $s .= ' ' . $c->getAttribute('alt') . ' ';
            } elseif ($tag === 'svg') {
                foreach ($c->getElementsByTagName('title') as $t) {
                    $s .= ' ' . $t->textContent . ' ';
                    break;
                }
            } else {
                $s .= ' ' . $this->contentName($c) . ' ';
            }
        }
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }
}
