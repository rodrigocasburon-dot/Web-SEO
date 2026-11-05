<?php
/** Utilidades de DOM para las comprobaciones: selectores CSS, extractos, texto visible. */
class DomHelper
{
    private const INLINE_TAGS = ['a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'dfn', 'em', 'font', 'i', 'kbd',
        'label', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var'];

    /** Selector CSS aproximado del elemento (id si es sencillo; si no, ruta con nth-of-type). */
    public static function cssPath(DOMNode $n): string
    {
        $parts = [];
        while ($n instanceof DOMElement) {
            $tag = strtolower($n->tagName);
            if ($tag === 'html' || $tag === 'body') {
                $parts[] = $tag;
                if ($tag === 'html') {
                    break;
                }
                $n = $n->parentNode;
                continue;
            }
            $id = $n->getAttribute('id');
            if ($id !== '') {
                $parts[] = preg_match('/^[A-Za-z][\w\-]*$/', $id) ? $tag . '#' . $id : $tag . '[id="' . addcslashes($id, '"\\') . '"]';
                break;
            }
            $index = 1;
            $same = 0;
            foreach ($n->parentNode ? $n->parentNode->childNodes : [] as $sib) {
                if ($sib instanceof DOMElement && strtolower($sib->tagName) === $tag) {
                    $same++;
                    if ($sib->isSameNode($n)) {
                        $index = $same;
                    }
                }
            }
            $parts[] = $same > 1 ? $tag . ':nth-of-type(' . $index . ')' : $tag;
            $n = $n->parentNode;
        }
        return implode(' > ', array_reverse($parts));
    }

    /** Extracto del HTML original del nodo (recortado). Sin escapar: escapar al imprimir. */
    public static function excerpt(DOMNode $n, int $max = 240): string
    {
        $html = $n->ownerDocument ? (string) $n->ownerDocument->saveHTML($n) : '';
        // Si el motor devuelve entidades numéricas para caracteres no ASCII, se restauran
        $html = preg_replace_callback('/&#(\d+);/', function ($m) {
            return (int) $m[1] > 127 ? mb_chr((int) $m[1], 'UTF-8') : $m[0];
        }, $html);
        $html = trim(preg_replace('/\s+/u', ' ', $html) ?? $html);
        return mb_strlen($html) > $max ? mb_substr($html, 0, $max) . '…' : $html;
    }

    /** Elemento de evidencia: selector, extracto y línea del HTML original. */
    public static function evidence(DOMElement $el): array
    {
        return [
            'selector' => self::cssPath($el),
            'extracto' => self::excerpt($el),
            'linea' => $el->getLineNo() ?: null,
        ];
    }

    /** Evidencia sin elemento (cabecera, ausencia de elemento…). */
    public static function note(string $text, ?string $selector = null): array
    {
        return ['selector' => $selector, 'extracto' => $text, 'linea' => null];
    }

    /** Texto del nodo con espacios normalizados, sin script/style/noscript/template. */
    public static function text(DOMNode $n, array $skip = ['script', 'style', 'noscript', 'template', 'head']): string
    {
        $raw = self::rawText($n, $skip);
        return trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    }

    private static function rawText(DOMNode $n, array $skip): string
    {
        $s = '';
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) {
                $s .= $c->nodeValue;
            } elseif ($c instanceof DOMElement) {
                $tag = strtolower($c->tagName);
                if (in_array($tag, $skip, true)) {
                    continue;
                }
                $inner = self::rawText($c, $skip);
                $s .= in_array($tag, self::INLINE_TAGS, true) ? $inner : ' ' . $inner . ' ';
            }
        }
        return $s;
    }

    /** ¿Oculto a todos los usuarios, o dentro de noscript/template? (atributos; no evalúa CSS externo) */
    public static function isHidden(DOMElement $el): bool
    {
        for ($n = $el; $n instanceof DOMElement; $n = $n->parentNode) {
            $tag = strtolower($n->tagName);
            if ($tag === 'noscript' || $tag === 'template') {
                return true;
            }
            if ($n->hasAttribute('hidden') || strtolower(trim($n->getAttribute('aria-hidden'))) === 'true') {
                return true;
            }
            if (preg_match('/display\s*:\s*none|visibility\s*:\s*hidden/i', $n->getAttribute('style'))) {
                return true;
            }
        }
        return false;
    }

    /** Etiqueta de apertura reconstruida (para evidencia compacta). */
    public static function openTag(DOMElement $el): string
    {
        $attrs = '';
        foreach ($el->attributes as $a) {
            $attrs .= ' ' . $a->name . '="' . $a->value . '"';
        }
        return '<' . strtolower($el->tagName) . $attrs . '>';
    }
}
