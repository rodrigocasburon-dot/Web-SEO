<?php
/** Lectura de JSON-LD de la página (C30 y C31). Los bloques con JSON no válido se ignoran (los señala C13). */
class JsonLd
{
    /** Tipos que no son la organización o el negocio aunque lleven address o telephone. */
    private const NOT_BUSINESS = ['person', 'postaladdress', 'website', 'webpage', 'product', 'offer', 'event', 'contactpoint',
        'imageobject', 'breadcrumblist', 'article', 'blogposting', 'newsarticle', 'review', 'aggregaterating', 'faqpage',
        'question', 'answer', 'itemlist', 'listitem', 'searchaction', 'jobposting', 'videoobject'];

    /** @return array[] nodos de nivel superior y de @graph de todos los bloques application/ld+json */
    public static function nodes(DOMXPath $x): array
    {
        $out = [];
        foreach ($x->query('//script') as $s) {
            if (strtolower(trim($s->getAttribute('type'))) !== 'application/ld+json') {
                continue;
            }
            $data = json_decode(trim($s->textContent), true);
            if (is_array($data)) {
                $out = array_merge($out, self::flatten($data));
            }
        }
        return $out;
    }

    /** @return string[] tipos del nodo, en minúsculas y sin prefijo de vocabulario */
    public static function types(array $node): array
    {
        $out = [];
        foreach ((array) ($node['@type'] ?? []) as $t) {
            if (is_string($t) && trim($t) !== '') {
                $t = strtolower(trim($t));
                $out[] = (string) preg_replace('#^(https?://schema\.org/|schema:)#', '', $t);
            }
        }
        return $out;
    }

    /**
     * ¿Es la organización o el negocio de la web? Supuesto: tipos Organization/LocalBusiness y sus subtipos
     * habituales por nombre, o cualquier tipo con address o telephone que no sea de otra clase conocida.
     */
    public static function isBusiness(array $node): bool
    {
        $types = self::types($node);
        if (!$types) {
            return false;
        }
        foreach ($types as $t) {
            if (preg_match('/organization|business|corporation|store|shop|restaurant|dentist|physician|clinic|hotel|agency|office|service$/', $t)) {
                return true;
            }
        }
        if (array_intersect($types, self::NOT_BUSINESS)) {
            return false;
        }
        return isset($node['address']) || isset($node['telephone']);
    }

    private static function flatten(array $data): array
    {
        if (array_keys($data) === range(0, count($data) - 1)) {
            $out = [];
            foreach ($data as $item) {
                if (is_array($item)) {
                    $out = array_merge($out, self::flatten($item));
                }
            }
            return $out;
        }
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            return self::flatten($data['@graph']);
        }
        return [$data];
    }
}
