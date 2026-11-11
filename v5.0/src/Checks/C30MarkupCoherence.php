<?php
/**
 * C30 Coherencia del marcado (GEO-30). Google pide que los datos estructurados describan lo que se ve en la
 * página. Se compara el nombre, la dirección y el teléfono del JSON-LD de la organización o el negocio con
 * el texto visible.
 * - Teléfono: solo cifras, sin el prefijo +34/0034. Dirección: código postal (o, si no lo hay, la calle).
 * - Teléfono o dirección que no aparecen en el texto → NO_CUMPLE (uno por dato).
 * - Nombre que no aparece (ni sin la forma jurídica: S.L., S.A.…) → AVISO: el nombre legal suele diferir del
 *   comercial.
 * - Sin JSON-LD de negocio: AVISO si hay señales de sede física (LocalSignals); si no, no aplica.
 */
class C30MarkupCoherence extends BaseCheck
{
    private const LEGAL_FORMS = '/[\s,]+(s\.?\s?l\.?\s?u?\.?|s\.?\s?a\.?\s?u?\.?|s\.?\s?coop\.?|s\.?\s?l\.?\s?l\.?|sociedad limitada|sociedad an[oó]nima)$/iu';

    protected string $id = 'C30';
    protected string $severity = 'ALTO';
    protected string $criterio = 'Directrices generales de datos estructurados de Google: el marcado debe representar el contenido visible de la página. No es un criterio WCAG.';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'BAJO';
    protected string $respaldo = 'oficial';

    protected function evaluate(CheckContext $ctx): array
    {
        $x = $ctx->xpath();
        $umbral = 'Nombre, dirección y teléfono del JSON-LD presentes en el texto visible';
        $business = array_values(array_filter(JsonLd::nodes($x), [JsonLd::class, 'isBusiness']));

        if (!$business) {
            $signals = LocalSignals::find($x);
            if ($signals) {
                return $this->result(self::WARN, ['negocio_json_ld' => false, 'senales_sede' => $signals], $umbral, [],
                    'Hay señales de negocio con sede (' . implode(', ', $signals) . ') pero no hay JSON-LD de la organización o el negocio con su nombre, dirección y teléfono.');
            }
            return $this->result(self::OK, ['negocio_json_ld' => false], $umbral, [],
                'No hay JSON-LD de negocio ni señales de sede física: no aplica.', 0, false);
        }

        $body = $x->query('//body')->item(0);
        $visible = $body ? DomHelper::text($body) : '';
        $plain = self::plain($visible);
        // Cifras del texto con los separadores entre cifras quitados: «987 12 34 56» → «987123456»
        $digits = (string) preg_replace('/(?<=\d)[\s.\-()\/]+(?=\d)/u', '', $visible);

        $fails = [];
        $warns = [];
        $checked = [];
        foreach ($business as $node) {
            $label = implode('/', (array) ($node['@type'] ?? ''));
            if (is_string($node['name'] ?? null) && trim($node['name']) !== '') {
                $name = trim($node['name']);
                $checked[] = 'nombre';
                $short = trim((string) preg_replace(self::LEGAL_FORMS, '', $name));
                if (strpos($plain, self::plain($name)) === false && ($short === '' || strpos($plain, self::plain($short)) === false)) {
                    $warns[] = DomHelper::note($label . ' · name «' . $name . '» no aparece en el texto visible', 'script[type="application/ld+json"]');
                }
            }
            foreach ((array) ($node['telephone'] ?? []) as $tel) {
                if (!is_string($tel) || trim($tel) === '') {
                    continue;
                }
                $checked[] = 'teléfono';
                $num = self::phone($tel);
                if (strlen($num) < 6 || strpos($digits, $num) === false) {
                    $fails[] = DomHelper::note($label . ' · telephone «' . $tel . '» no aparece en el texto visible', 'script[type="application/ld+json"]');
                }
            }
            $addr = $node['address'] ?? null;
            if (is_array($addr) && array_keys($addr) === range(0, count($addr) - 1)) {
                $addr = $addr[0] ?? null;               // varias direcciones: se compara la primera
            }
            $address = self::addressKey($addr);
            if ($address !== null) {
                $checked[] = 'dirección';
                if (strpos($plain, self::plain($address[1])) === false) {
                    $fails[] = DomHelper::note($label . ' · address (' . $address[0] . ' «' . $address[1] . '») no aparece en el texto visible', 'script[type="application/ld+json"]');
                }
            }
        }
        $checked = array_values(array_unique($checked));
        $valor = ['negocio_json_ld' => true, 'datos_comparados' => $checked, 'discrepancias' => count($fails) + count($warns)];

        if (!$checked) {
            return $this->result(self::NO_DATA, $valor, $umbral, [], 'El JSON-LD del negocio no incluye nombre, dirección ni teléfono que comparar.');
        }
        if ($fails) {
            return $this->result(self::FAIL, $valor, $umbral, array_merge($fails, $warns),
                count($fails) . ' dato(s) del JSON-LD no coinciden con el texto visible (teléfono o dirección).', count($fails));
        }
        if ($warns) {
            return $this->result(self::WARN, $valor, $umbral, $warns,
                'El nombre del JSON-LD no aparece tal cual en el texto visible; puede ser el nombre legal frente al comercial.');
        }
        return $this->result(self::OK, $valor, $umbral, [],
            'El JSON-LD coincide con el texto visible en: ' . implode(', ', $checked) . '.');
    }

    /** Minúsculas, sin tildes ni signos, con espacios simples. */
    private static function plain(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ò' => 'o', 'ç' => 'c', 'º' => '', 'ª' => '']);
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);
        return ' ' . trim($s) . ' ';
    }

    /** Teléfono normalizado: solo cifras y sin prefijo de España (+34 / 0034). */
    public static function phone(string $tel): string
    {
        $d = (string) preg_replace('/\D+/', '', $tel);
        if (strlen($d) > 9 && strncmp($d, '0034', 4) === 0) {
            $d = substr($d, 4);
        } elseif (strlen($d) > 9 && strncmp($d, '34', 2) === 0) {
            $d = substr($d, 2);
        }
        return $d;
    }

    /** @return array{0:string,1:string}|null [campo comparado, valor] */
    private static function addressKey($addr): ?array
    {
        if (is_string($addr) && trim($addr) !== '') {
            return preg_match('/\b\d{5}\b/', $addr, $m) ? ['postalCode', $m[0]] : ['address', trim($addr)];
        }
        if (!is_array($addr)) {
            return null;
        }
        if (is_scalar($addr['postalCode'] ?? null) && trim((string) $addr['postalCode']) !== '') {
            return ['postalCode', trim((string) $addr['postalCode'])];
        }
        if (is_string($addr['streetAddress'] ?? null) && trim($addr['streetAddress']) !== '') {
            return ['streetAddress', trim($addr['streetAddress'])];
        }
        return null;
    }
}
