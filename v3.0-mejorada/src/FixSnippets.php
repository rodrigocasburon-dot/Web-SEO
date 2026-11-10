<?php
/**
 * Función 09: fragmentos de corrección «antes / después» para correcciones sencillas, a partir del código real
 * de la evidencia y de las plantillas de lang/es.php («correcciones»). Es un primer borrador que debe revisar el
 * equipo. Nunca inventa código del sitio: «antes» es siempre la etiqueta tal como aparece en la evidencia (o
 * null si el elemento no existe) y, si la plantilla no encaja o la etiqueta está recortada, no se propone nada.
 * Informativo: no cambia estados ni puntuación.
 */
class FixSnippets
{
    /** Fragmentos como máximo por comprobación */
    public const MAX = 3;
    /** Atributos con URL que se pasan de http a https en C20 */
    private const URL_ATTRS = ['src', 'href', 'action', 'data', 'poster', 'srcset'];

    /** Añade 'correccion' (array o null) a cada registro. */
    public static function attach(array $records, string $finalUrl): array
    {
        foreach ($records as $i => $r) {
            $records[$i]['correccion'] = self::forRecord($r, $finalUrl);
        }
        return $records;
    }

    public static function forRecord(array $r, string $finalUrl): ?array
    {
        $estado = $r['estado'] ?? '';
        if ($estado !== BaseCheck::FAIL && $estado !== BaseCheck::WARN) {
            return null;
        }
        $all = Lang::all()['correcciones'] ?? [];
        $id = (string) ($r['id'] ?? '');
        $t = $all[$id] ?? null;
        if (!is_array($t)) {
            return null;
        }
        $ev = (array) ($r['evidencia'] ?? []);
        $fail = $estado === BaseCheck::FAIL;
        // valor_medido null = el elemento no existe (C09, C10, C12, C15)
        $absent = array_key_exists('valor_medido', $r) && $r['valor_medido'] === null;
        $note = (string) ($t['nota'] ?? '');
        $pairs = [];

        switch ($id) {
            case 'C04':
                foreach ($ev as $e) {
                    $tag = self::openTag((string) ($e['extracto'] ?? ''));
                    $kind = $tag === null ? null : self::controlKind($tag);
                    if ($kind !== null) {
                        $pairs[] = self::pair($tag, self::setAttr($tag, $t['atributo'], $t['textos'][$kind]));
                    }
                }
                break;
            case 'C06':
                foreach ($ev as $e) {
                    $tag = self::openTag((string) ($e['extracto'] ?? ''));
                    if ($tag !== null && self::tagName($tag) === 'img') {
                        $pairs[] = self::pair($tag, self::setAttr($tag, $t['atributo'], $t['texto']));
                    }
                }
                break;
            case 'C07':
                $tag = self::openTag((string) ($ev[0]['extracto'] ?? ''));
                if ($fail && $tag !== null && self::tagName($tag) === 'html') {
                    $current = (string) ($r['valor_medido'] ?? '');
                    $fixed = null;
                    if (preg_match('/^([a-z]{2,3})_([a-z]{2}|\d{3})$/i', $current, $m)) {
                        $fixed = strtolower($m[1]) . '-' . strtoupper($m[2]);
                        $note = (string) $t['nota_formato'];
                    }
                    $pairs[] = self::pair($tag, self::setAttr($tag, $t['atributo'], $fixed ?? $t['idioma']));
                }
                break;
            case 'C09':
                $tag = self::openTag((string) ($ev[0]['extracto'] ?? ''));
                if ($tag === null) {
                    if ($fail && $absent) {
                        $pairs[] = self::pair(null, $t['nuevo']);           // no hay meta viewport
                    }
                    break;
                }
                $content = self::attrValue($tag, 'content');
                if ($content !== null) {
                    $fixed = self::fixViewport($content);
                    if ($fixed !== $content) {
                        $pairs[] = self::pair($tag, self::setAttr($tag, 'content', $fixed));
                    }
                }
                break;
            case 'C10':
                if ($fail && $absent && count($ev) <= 1 && strpos((string) ($ev[0]['extracto'] ?? ''), '<') !== 0) {
                    $pairs[] = self::pair(null, $t['nuevo']);               // no hay title
                } elseif ($fail && is_array($r['valor_medido'] ?? null) && ($r['valor_medido']['texto'] ?? null) === '') {
                    $old = (string) ($ev[0]['extracto'] ?? '');
                    if (preg_match('#^<title[^>]*>\s*</title>$#i', $old)) {
                        $pairs[] = self::pair($old, $t['nuevo']);           // title vacío
                    }
                }
                break;
            case 'C12':
                if (!$fail || !preg_match('#^https?://#i', $finalUrl)) {
                    break;
                }
                $url = htmlspecialchars($finalUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($absent) {
                    $pairs[] = self::pair(null, str_replace('{url}', $url, $t['nuevo']));   // no hay canonical
                } elseif (($r['valor_medido'] ?? null) === '') {
                    $tag = self::openTag((string) ($ev[0]['extracto'] ?? ''));
                    if ($tag !== null && self::tagName($tag) === 'link') {
                        $pairs[] = self::pair($tag, self::setAttr($tag, 'href', $url));     // href vacío
                    }
                }
                // Canonical que apunta a otra página: puede ser intencionado; no se propone nada
                break;
            case 'C15':
                if ($fail && $absent) {
                    $pairs[] = self::pair(null, $t['nuevo']);
                } elseif ($fail) {
                    $tag = self::openTag((string) ($ev[0]['extracto'] ?? ''));
                    if ($tag !== null && self::tagName($tag) === 'meta') {
                        $pairs[] = self::pair($tag, self::setAttr($tag, 'content', $t['texto']));
                    }
                }
                break;
            case 'C20':
                foreach ($ev as $e) {
                    $tag = self::openTag((string) ($e['extracto'] ?? ''));
                    if ($tag !== null) {
                        $fixed = self::httpsUrls($tag);
                        if ($fixed !== $tag) {
                            $pairs[] = self::pair($tag, $fixed);
                        }
                    }
                }
                break;
        }

        if (!$pairs) {
            return null;
        }
        return [
            'fragmentos' => array_slice($pairs, 0, self::MAX),
            'total' => count($pairs),
            'nota' => $note,
            'borrador' => (string) ($all['borrador'] ?? ''),
        ];
    }

    private static function pair(?string $before, string $after): array
    {
        return ['antes' => $before, 'despues' => $after];
    }

    /**
     * Etiqueta de apertura completa al principio del extracto (respetando comillas); null si el extracto no
     * empieza por una etiqueta o está recortado antes de cerrarla.
     */
    public static function openTag(string $html): ?string
    {
        if (!preg_match('/^<[a-zA-Z][a-zA-Z0-9\-]*/', $html)) {
            return null;
        }
        $quote = null;
        $len = strlen($html);
        for ($i = 1; $i < $len; $i++) {
            $c = $html[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '>') {
                return substr($html, 0, $i + 1);
            } elseif ($c === '<') {
                return null;
            }
        }
        return null;
    }

    private static function tagName(string $tag): string
    {
        return preg_match('/^<([a-zA-Z][a-zA-Z0-9\-]*)/', $tag, $m) ? strtolower($m[1]) : '';
    }

    /**
     * Atributos de la etiqueta en orden, leídos de uno en uno (los valores entre comillas se consumen enteros,
     * así que un texto como title="ver alt" no se confunde con un atributo).
     * @return array<int, array{name:string, start:int, length:int, value:?string}>
     */
    private static function attrs(string $tag): array
    {
        $out = [];
        if (!preg_match('/^<[a-zA-Z][a-zA-Z0-9\-]*/', $tag, $m)) {
            return $out;
        }
        $pos = strlen($m[0]);
        $re = '/\G\s+([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/';
        while (preg_match($re, $tag, $a, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $pos)) {
            $raw = $a[2][0] ?? $a[3][0] ?? $a[4][0] ?? null;
            $out[] = [
                'name' => strtolower($a[1][0]),
                'start' => $a[0][1],
                'length' => strlen($a[0][0]),
                'value' => $raw === null ? null : html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
            $pos = $a[0][1] + strlen($a[0][0]);
        }
        return $out;
    }

    /** Valor de un atributo de la etiqueta (null si no está o no tiene valor). */
    public static function attrValue(string $tag, string $name): ?string
    {
        foreach (self::attrs($tag) as $a) {
            if ($a['name'] === strtolower($name)) {
                return $a['value'];
            }
        }
        return null;
    }

    /** Pone el atributo con ese valor: lo sustituye si existe y, si no, lo añade al final de la etiqueta. */
    public static function setAttr(string $tag, string $name, string $value): string
    {
        $attr = $name . '="' . str_replace('"', '&quot;', $value) . '"';
        foreach (self::attrs($tag) as $a) {
            if ($a['name'] === strtolower($name)) {
                return substr($tag, 0, $a['start']) . ' ' . $attr . substr($tag, $a['start'] + $a['length']);
            }
        }
        if (substr($tag, -2) === '/>') {
            return rtrim(substr($tag, 0, -2)) . ' ' . $attr . '>';
        }
        return rtrim(substr($tag, 0, -1)) . ' ' . $attr . '>';
    }

    /** Tipo de control de C04 según la etiqueta: enlace, boton o campo (null si no se reconoce). */
    private static function controlKind(string $tag): ?string
    {
        $name = self::tagName($tag);
        $role = strtolower(trim((string) self::attrValue($tag, 'role')));
        $type = strtolower(trim((string) self::attrValue($tag, 'type')));
        if ($role === 'link' || ($name === 'a' && $role === '')) {
            return 'enlace';
        }
        if ($role === 'button' || $name === 'button' || ($name === 'input' && in_array($type, ['submit', 'reset', 'button', 'image'], true))) {
            return 'boton';
        }
        if (in_array($name, ['input', 'select', 'textarea'], true)) {
            return 'campo';
        }
        return null;
    }

    /** Contenido de viewport sin bloqueo del zoom y con width=device-width; conserva el resto. */
    public static function fixViewport(string $content): string
    {
        $props = [];
        foreach (preg_split('/[,;]/', $content) as $pair) {
            $kv = array_map('trim', explode('=', $pair, 2));
            if ($kv[0] !== '') {
                $props[strtolower($kv[0])] = $kv[1] ?? '';
            }
        }
        $scalable = strtolower($props['user-scalable'] ?? '');
        if ($scalable === 'no' || $scalable === '0') {
            unset($props['user-scalable']);
        }
        if (isset($props['maximum-scale']) && is_numeric($props['maximum-scale']) && (float) $props['maximum-scale'] < 2) {
            unset($props['maximum-scale']);
        }
        $props = ['width' => 'device-width'] + $props;
        $props['width'] = 'device-width';
        $out = [];
        foreach ($props as $k => $v) {
            $out[] = $v === '' ? $k : $k . '=' . $v;
        }
        $fixed = implode(', ', $out);
        // Sin cambios de fondo, se devuelve el original (evita proponer solo cambios de formato)
        return self::sameProps($fixed, $content) ? $content : $fixed;
    }

    private static function sameProps(string $a, string $b): bool
    {
        $norm = function (string $s): array {
            $p = [];
            foreach (preg_split('/[,;]/', strtolower($s)) as $pair) {
                $kv = array_map('trim', explode('=', $pair, 2));
                if ($kv[0] !== '') {
                    $p[$kv[0]] = $kv[1] ?? '';
                }
            }
            ksort($p);
            return $p;
        };
        return $norm($a) === $norm($b);
    }

    /** Cambia http:// por https:// en los atributos con URL de la etiqueta (en srcset, en cada candidato). */
    private static function httpsUrls(string $tag): string
    {
        foreach (self::attrs($tag) as $a) {
            if (in_array($a['name'], self::URL_ATTRS, true) && $a['value'] !== null) {
                $fixed = (string) preg_replace('#(^|,\s*|\s)http://#i', '$1https://', trim($a['value']));
                if ($fixed !== trim($a['value'])) {
                    $tag = self::setAttr($tag, $a['name'], $fixed);
                }
            }
        }
        return $tag;
    }
}
