<?php
/**
 * Análisis complementario (no puntúa): vistas informativas calculadas con la misma descarga que las
 * comprobaciones. No asigna estados ni resta puntos; solo muestra lo extraído de la página.
 */
class Insights
{
    /** Máximo de caracteres del texto «lo que ve un bot» que se envían a la pantalla */
    public const BOT_TEXT_MAX = 100000;

    public static function build(CheckContext $ctx): array
    {
        $reason = self::contentUnavailable($ctx);
        return [
            'sin_contenido' => $reason,
            'vista_bot' => $reason === null ? self::botView($ctx) : null,
        ];
    }

    /** Motivo por el que no se puede usar el HTML recibido; null si se puede. */
    public static function contentUnavailable(CheckContext $ctx): ?string
    {
        if ($ctx->blocked) {
            return 'El sitio bloquea el acceso automático o exige acceso: el contenido recibido no es el de la página real.';
        }
        if (!$ctx->isHtml()) {
            return 'El recurso no es HTML.';
        }
        return null;
    }

    /**
     * Función 02 «Lo que ve un bot»: texto del contenido principal en el HTML inicial, sin ejecutar
     * JavaScript. Usa la misma extracción y el mismo recuento que C03 (DomHelper::mainContent / countWords).
     */
    public static function botView(CheckContext $ctx): array
    {
        [$source, $text] = DomHelper::mainContent($ctx->xpath());
        $min = (int) ($ctx->config['min_words_raw_html'] ?? 50);
        $words = DomHelper::countWords($text);
        $chars = mb_strlen($text, 'UTF-8');
        $cut = $chars > self::BOT_TEXT_MAX;
        return [
            'fuente' => $source,
            'palabras' => $words,
            'minimo_palabras' => $min,
            'poco_texto' => $words < $min,
            'caracteres' => $chars,
            'texto' => $cut ? mb_substr($text, 0, self::BOT_TEXT_MAX, 'UTF-8') : $text,
            'texto_recortado' => $cut,
            'html_recortado' => $ctx->truncated,
        ];
    }
}
