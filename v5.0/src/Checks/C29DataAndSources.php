<?php
/**
 * C29 Datos y fuentes en el texto (GEO-29). Un experimento con motores generativos (Aggarwal y otros, «GEO:
 * Generative Engine Optimization», 2024) observó más citas en textos con estadísticas, citas textuales y
 * fuentes. Se cuentan en el contenido principal:
 * - cifras con unidad (%, €, km, kg, años, millones…);
 * - citas entre comillas de al menos 4 palabras;
 * - enlaces a otros dominios (fuentes externas; no se valora su calidad).
 * Solo en páginas de contenido con texto largo (umbral orientativo de 300 palabras); en contacto y textos
 * legales no aplica. Sin ninguna de las tres señales → AVISO. Nunca NO_CUMPLE.
 */
class C29DataAndSources extends BaseCheck
{
    public const MIN_WORDS = 300;
    private const SKIP_PATHS = '#/(contacto|contact|aviso-legal|legal|privacidad|privacy|politica-de-privacidad|cookies|politica-de-cookies|condiciones|terminos|terms)(/|$|[-_.])#i';
    private const NUMBER_UNIT = '/(?<![\w.,])\d+(?:[.,]\d+)*\s?(?:[%€$]|(?:euros?|d[oó]lares|km|kg|mg|m2|m²|cm|mm|ml|kwh|kw|mw|gb|mb|tb|horas?|minutos?|segundos?|d[ií]as?|semanas?|meses|a[nñ]os|millones|mil|veces|personas|clientes|empresas|usuarios)(?![\p{L}\d]))/iu';
    private const QUOTE = '/[«“"]([^«»“”"]{12,400})[»”"]/u';

    protected string $id = 'C29';
    protected string $severity = 'BAJO';
    protected string $criterio = 'Experimento publicado sobre motores generativos (2024): las estadísticas, las citas y las fuentes en el texto se asociaron a más citas. No es un criterio WCAG.';
    protected string $impacto = 'BAJO';
    protected string $esfuerzo = 'MEDIO';
    protected string $respaldo = 'experimento';

    protected function evaluate(CheckContext $ctx): array
    {
        $umbral = 'En textos de ' . self::MIN_WORDS . ' palabras o más (orientativo): alguna cifra con unidad, cita entre comillas o enlace a una fuente externa';
        $path = (string) (parse_url($ctx->url, PHP_URL_PATH) ?: '/');
        if (preg_match(self::SKIP_PATHS, $path)) {
            return $this->result(self::OK, ['tipo' => 'contacto o legal'], $umbral, [], 'Página de contacto o de textos legales: no aplica.', 0, false);
        }
        $x = $ctx->xpath();
        [$source, $text, $nodes] = DomHelper::mainContent($x);
        $words = DomHelper::countWords($text);
        if ($words < self::MIN_WORDS) {
            return $this->result(self::OK, ['palabras' => $words], $umbral, [],
                'El texto principal tiene ' . $words . ' palabras (menos de ' . self::MIN_WORDS . '): no aplica.', 0, false);
        }

        $numbers = preg_match_all(self::NUMBER_UNIT, $text, $mNum) ?: 0;
        $quotes = 0;
        $mQuote = [];
        if (preg_match_all(self::QUOTE, $text, $mq)) {
            foreach ($mq[1] as $q) {
                if (DomHelper::countWords($q) >= 4) {
                    $quotes++;
                    $mQuote[] = $q;
                }
            }
        }
        $host = strtolower((string) parse_url($ctx->url, PHP_URL_HOST));
        $base = (string) preg_replace('/^www\./', '', $host);
        $links = [];
        foreach ($nodes as $n) {
            foreach ($x->query('.//a[@href]', $n) as $a) {
                $h = strtolower((string) parse_url(trim($a->getAttribute('href')), PHP_URL_HOST));
                if ($h === '' || $h === $host || preg_replace('/^www\./', '', $h) === $base || substr($h, -strlen('.' . $base)) === '.' . $base) {
                    continue;
                }
                if ($source !== 'main' && $source !== 'article' && $a->parentNode && self::inChrome($a)) {
                    continue;                               // enlaces del menú o del pie no son fuentes
                }
                $links[] = $a;
            }
        }
        $valor = ['palabras' => $words, 'cifras_con_unidad' => $numbers, 'citas' => $quotes, 'enlaces_externos' => count($links)];
        $ev = [];
        foreach (array_slice($mNum[0] ?? [], 0, 3) as $n) {
            $ev[] = DomHelper::note('Cifra: ' . $n, $source);
        }
        foreach (array_slice($mQuote, 0, 2) as $q) {
            $ev[] = DomHelper::note('Cita: «' . mb_substr($q, 0, 120) . '»', $source);
        }
        foreach (array_slice($links, 0, 3) as $a) {
            $ev[] = DomHelper::evidence($a);
        }

        if ($numbers + $quotes + count($links) === 0) {
            return $this->result(self::WARN, $valor, $umbral, [],
                'Texto de ' . $words . ' palabras sin cifras con unidad, citas entre comillas ni enlaces a fuentes externas.');
        }
        return $this->result(self::OK, $valor, $umbral, $ev,
            $numbers . ' cifra(s) con unidad, ' . $quotes . ' cita(s) y ' . count($links) . ' enlace(s) externo(s) en ' . $words . ' palabras. No se valora la calidad de las fuentes.');
    }

    private static function inChrome(DOMNode $n): bool
    {
        for ($p = $n->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
            if (in_array(strtolower($p->tagName), ['nav', 'header', 'footer', 'aside'], true)) {
                return true;
            }
        }
        return false;
    }
}
