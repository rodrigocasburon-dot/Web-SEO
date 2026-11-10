<?php
/**
 * C22 IDs repetidos (v3.0, traída de la v1.0 · UX-19, afinada): un id repetido rompe las referencias
 * que lo usan (label for, aria-labelledby, aria-describedby, aria-controls, enlaces #id).
 * - algún id repetido al que algo hace referencia → NO_CUMPLE (k = nº de esos id)
 * - id repetidos sin referencias → AVISO (WCAG 2.2 retiró el SC 4.1.1; puede no afectar a nadie)
 * - ninguno → CUMPLE
 */
class C22DuplicateIds extends BaseCheck
{
    protected string $id = 'C22';
    protected string $severity = 'MEDIO';
    protected string $criterio = 'Referencia: WCAG 2.2 SC 1.3.1 (Información y relaciones) y SC 4.1.2 cuando el id se usa en una relación.';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'BAJO';

    protected function evaluate(CheckContext $ctx): array
    {
        $x = $ctx->xpath();
        $umbral = 'Ningún id repetido, sobre todo si se usa en label, aria-* o enlaces internos';
        $count = [];
        $first = [];
        foreach ($x->query('//*[@id and not(ancestor::template) and not(ancestor-or-self::svg)]') as $el) {
            $id = trim($el->getAttribute('id'));
            if ($id === '') {
                continue;
            }
            $count[$id] = ($count[$id] ?? 0) + 1;
            $first[$id] ??= $el;
        }
        $dups = array_keys(array_filter($count, fn($n) => $n > 1));
        if (!$dups) {
            return $this->result(self::OK, 0, $umbral, [], 'Ningún id se repite (' . count($count) . ' id revisados).');
        }
        // Referencias que apuntan a un id
        $refs = [];
        foreach ($x->query('//label/@for') as $a) {
            $refs[trim($a->value)] = true;
        }
        foreach ($x->query('//@aria-labelledby|//@aria-describedby|//@aria-controls|//@aria-owns|//@aria-activedescendant') as $a) {
            foreach (preg_split('/\s+/', trim($a->value), -1, PREG_SPLIT_NO_EMPTY) as $t) {
                $refs[$t] = true;
            }
        }
        foreach ($x->query('//a/@href') as $a) {
            if (strpos($a->value, '#') === 0 && strlen($a->value) > 1) {
                $refs[rawurldecode(substr($a->value, 1))] = true;
            }
        }
        $referenced = array_values(array_filter($dups, fn($id) => isset($refs[$id])));
        $show = $referenced ?: $dups;
        $ev = [];
        foreach (array_slice($show, 0, 10) as $id) {
            $ev[] = DomHelper::evidence($first[$id]) + ['nota' => 'id="' . $id . '" aparece ' . $count[$id] . ' veces'];
        }
        $list = implode(', ', array_map(fn($id) => '«' . $id . '»', array_slice($show, 0, 5)));
        if ($referenced) {
            return $this->result(self::FAIL, count($referenced), $umbral, $ev,
                count($referenced) . ' id repetidos se usan en etiquetas, ARIA o enlaces internos: ' . $list . '.', count($referenced));
        }
        return $this->result(self::WARN, count($dups), $umbral, $ev,
            count($dups) . ' id repetidos, aunque nada hace referencia a ellos: ' . $list . '.');
    }
}
