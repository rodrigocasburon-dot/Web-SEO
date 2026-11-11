<?php
/**
 * C03 Contenido en HTML crudo: palabras del contenido principal en el HTML inicial.
 * Contenido principal: main; si no, article; si no, body sin nav/header/footer/aside/script/style/noscript.
 * < mínimo + contenedor vacío (root, app, __next, __nuxt) → AVISO: riesgo alto de depender de JavaScript.
 * < mínimo + mucho <script> frente a poco texto → AVISO: riesgo probable.
 * < mínimo sin esas señales → NO_CUMPLE (supuesto: contenido insuficiente en el HTML).
 * Es un indicio, no una certeza: la única medición independiente de bots de IA que no ejecutan JavaScript es
 * de 2024 y no cubre los bots de búsqueda más recientes. (GEO-02; GEO-24 no existe en la v3.0.)
 */
class C03RawContent extends BaseCheck
{
    private const APP_CONTAINERS = ['root', 'app', '__next', '__nuxt'];
    /** Umbrales orientativos de «mucho script»: nº de <script> o caracteres de script en línea por cada carácter de texto. */
    private const MANY_SCRIPTS = 5;
    private const SCRIPT_TEXT_RATIO = 10;

    protected string $id = 'C03';
    protected string $severity = 'CRITICO';
    protected string $criterio = 'Contenido disponible sin ejecutar JavaScript (buena práctica para rastreadores y asistentes de IA). No es un criterio WCAG.';
    protected string $impacto = 'ALTO';
    protected string $esfuerzo = 'ALTO';
    protected string $respaldo = 'experimento';

    protected function evaluate(CheckContext $ctx): array
    {
        $x = $ctx->xpath();
        $min = (int) ($ctx->config['min_words_raw_html'] ?? 50);
        [$source, $text, $nodes] = DomHelper::mainContent($x);
        $words = DomHelper::countWords($text);

        $valor = ['palabras' => $words, 'fuente' => $source];
        $umbral = 'Al menos ' . $min . ' palabras en el contenido principal del HTML inicial';
        $ev = $nodes ? [DomHelper::evidence($nodes[0])] : [];

        if ($words >= $min) {
            return $this->result(self::OK, $valor, $umbral, $ev, $words . ' palabras en el contenido principal (' . $source . ') del HTML inicial.');
        }

        $empty = [];
        foreach (self::APP_CONTAINERS as $id) {
            foreach ($x->query('//*[@id="' . $id . '"]') as $el) {
                if (DomHelper::text($el) === '') {
                    $empty[] = $el;
                }
            }
        }
        // Scripts ejecutables (no JSON-LD ni otros datos)
        $scripts = 0;
        $scriptChars = 0;
        foreach ($x->query('//script') as $s) {
            $type = strtolower(trim($s->getAttribute('type')));
            if ($type === '' || $type === 'module' || strpos($type, 'javascript') !== false) {
                $scripts++;
                $scriptChars += strlen($s->textContent);
            }
        }
        $valor['scripts'] = $scripts;
        $heavy = $scripts >= self::MANY_SCRIPTS || $scriptChars > self::SCRIPT_TEXT_RATIO * max(1, strlen($text));
        $caveat = ' Es un indicio, no una certeza: conviene confirmarlo viendo la página sin JavaScript.';
        if ($empty) {
            $ev = array_map([DomHelper::class, 'evidence'], $empty);
            $valor['riesgo'] = 'alto';
            return $this->result(self::WARN, $valor, $umbral, $ev,
                'Riesgo alto de que el contenido dependa de JavaScript: solo ' . $words . ' palabras en el HTML inicial y un contenedor de aplicación vacío.' . $caveat);
        }
        if ($heavy) {
            $valor['riesgo'] = 'probable';
            return $this->result(self::WARN, $valor, $umbral, $ev,
                'Riesgo probable de que el contenido dependa de JavaScript: solo ' . $words . ' palabras en el HTML inicial frente a ' . $scripts . ' scripts.' . $caveat);
        }
        return $this->result(self::FAIL, $valor, $umbral, $ev,
            'Solo ' . $words . ' palabras en el contenido principal (' . $source . ') del HTML inicial.', 1);
    }
}
