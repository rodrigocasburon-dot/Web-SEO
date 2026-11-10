<?php
/**
 * C13 Datos estructurados: al menos un JSON-LD, todos con sintaxis válida y @type en cada nodo
 * (nivel superior o elementos de @graph). No valida el vocabulario schema.org ni la elegibilidad
 * para resultados enriquecidos.
 * Supuesto: la ausencia total de JSON-LD es NO_CUMPLE.
 */
class C13StructuredData extends BaseCheck
{
    protected string $id = 'C13';
    protected string $severity = 'MEDIO';
    protected string $criterio = 'JSON-LD 1.1 (W3C) y vocabulario schema.org. No es un criterio WCAG.';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'MEDIO';

    protected function evaluate(CheckContext $ctx): array
    {
        $scripts = [];
        foreach ($ctx->xpath()->query('//script[@type]') as $s) {
            if (strtolower(trim($s->getAttribute('type'))) === 'application/ld+json') {
                $scripts[] = $s;
            }
        }
        $umbral = 'Al menos un JSON-LD válido con @type definido';
        if (!$scripts) {
            return $this->result(self::FAIL, ['bloques' => 0], $umbral, [DomHelper::note('(no hay script type="application/ld+json")', 'script[type="application/ld+json"]')], 'La página no contiene datos estructurados JSON-LD.', 1);
        }

        $types = [];
        $errors = [];
        foreach ($scripts as $s) {
            $raw = trim(preg_replace('/^\xEF\xBB\xBF/', '', $s->textContent));
            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                $e = DomHelper::evidence($s);
                $e['extracto'] = 'JSON no válido (' . json_last_error_msg() . '): ' . $e['extracto'];
                $errors[] = $e;
                continue;
            }
            foreach ($this->nodes($data) as $node) {
                $type = $node['@type'] ?? null;
                $valid = (is_string($type) && trim($type) !== '')
                    || (is_array($type) && $type && count(array_filter($type, fn($t) => is_string($t) && trim($t) !== '')) === count($type));
                if ($valid) {
                    foreach ((array) $type as $t) {
                        $types[] = $t;
                    }
                } else {
                    $e = DomHelper::evidence($s);
                    $e['extracto'] = 'Nodo sin @type: ' . mb_substr((string) json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 200);
                    $errors[] = $e;
                }
            }
        }
        $types = array_values(array_unique($types));
        $valor = ['bloques' => count($scripts), 'tipos' => $types, 'errores' => count($errors)];
        if ($errors) {
            return $this->result(self::FAIL, $valor, $umbral, $errors, count($errors) . ' error(es) en los datos estructurados (sintaxis o @type ausente).', count($errors));
        }
        return $this->result(self::OK, $valor, $umbral, [DomHelper::evidence($scripts[0])], 'JSON-LD válido con tipos: ' . implode(', ', $types) . '. No se valida el vocabulario schema.org.');
    }

    /** Nodos a revisar: lista de nivel superior, elementos de @graph o el objeto único. */
    private function nodes(array $data): array
    {
        if (array_keys($data) === range(0, count($data) - 1)) {
            $out = [];
            foreach ($data as $item) {
                if (is_array($item)) {
                    $out = array_merge($out, $this->nodes($item));
                } else {
                    $out[] = ['valor' => $item];
                }
            }
            return $out;
        }
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            $out = [];
            foreach ($data['@graph'] as $item) {
                // Las referencias {"@id": "..."} no necesitan tipo
                if (is_array($item) && array_keys($item) === ['@id']) {
                    continue;
                }
                $out[] = is_array($item) ? $item : ['valor' => $item];
            }
            return $out;
        }
        return [$data];
    }
}
