<?php
/**
 * C03 Contenido en HTML crudo: palabras del contenido principal en el HTML inicial.
 * Contenido principal: main; si no, article; si no, body sin nav/header/footer/aside/script/style/noscript.
 * < mínimo + contenedor vacío (root, app, __next, __nuxt) → AVISO (posible dependencia de JavaScript).
 * < mínimo sin ese contenedor → NO_CUMPLE (supuesto: contenido insuficiente en el HTML).
 */
class C03RawContent extends BaseCheck
{
    private const APP_CONTAINERS = ['root', 'app', '__next', '__nuxt'];

    protected string $id = 'C03';
    protected string $severity = 'CRITICO';
    protected string $criterio = 'Contenido disponible sin ejecutar JavaScript (buena práctica para rastreadores y asistentes de IA). No es un criterio WCAG.';
    protected string $impacto = 'ALTO';
    protected string $esfuerzo = 'ALTO';

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
        if ($empty) {
            $ev = array_map([DomHelper::class, 'evidence'], $empty);
            return $this->result(self::WARN, $valor, $umbral, $ev,
                'Solo ' . $words . ' palabras en el HTML inicial y un contenedor de aplicación vacío: posible web que depende de JavaScript para mostrar el contenido.');
        }
        return $this->result(self::FAIL, $valor, $umbral, $ev,
            'Solo ' . $words . ' palabras en el contenido principal (' . $source . ') del HTML inicial.', 1);
    }
}
