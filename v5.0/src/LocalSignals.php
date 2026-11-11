<?php
/**
 * Señales de negocio con sede física en la página (C30). Supuesto: dirección postal española (vía + código
 * postal de 01000 a 52999) en el texto o en <address>, o un mapa incrustado. Un teléfono por sí solo no
 * cuenta: casi todas las webs corporativas lo muestran aunque no atiendan en un local.
 */
class LocalSignals
{
    private const STREET = '(?:c\/|calle|avda\.?|avenida|plaza|pza\.?|paseo|ctra\.?|carretera|camino|ronda|traves[ií]a|pol[ií]gono|p\.\s?i\.)';
    private const POSTAL = '\b(?:0[1-9]|[1-4]\d|5[0-2])\d{3}\b';
    private const MAPS = '#(google\.[a-z.]+/maps|maps\.google\.|openstreetmap\.org|bing\.com/maps|maps\.apple\.com)#i';

    /** @return string[] señales encontradas (texto breve para el detalle) */
    public static function find(DOMXPath $x): array
    {
        $out = [];
        $body = $x->query('//body')->item(0);
        $text = $body ? DomHelper::text($body) : '';
        if (preg_match('/' . self::STREET . '[^.\n]{2,80}?' . self::POSTAL . '/iu', $text, $m)) {
            $out[] = 'dirección postal en el texto («' . mb_substr(trim($m[0]), 0, 80) . '»)';
        } else {
            foreach ($x->query('//address') as $a) {
                if (preg_match('/' . self::POSTAL . '/', DomHelper::text($a))) {
                    $out[] = 'dirección con código postal en <address>';
                    break;
                }
            }
        }
        foreach ($x->query('//iframe[@src] | //a[@href]') as $el) {
            $url = $el->getAttribute($el->tagName === 'iframe' ? 'src' : 'href');
            if (preg_match(self::MAPS, $url)) {
                $out[] = $el->tagName === 'iframe' ? 'mapa incrustado' : 'enlace a un mapa';
                break;
            }
        }
        return $out;
    }
}
