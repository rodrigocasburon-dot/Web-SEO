<?php
/**
 * C24 Reproducción automática y movimiento (v3.0, traída de la v1.0 · UX-10): audio o vídeo con
 * autoplay y sonido, o marquee/blink. Desde el HTML no se sabe cuánto dura ni si hay botón de pausa
 * propio, así que nunca es NO_CUMPLE:
 * - autoplay con sonido, <marquee> o <blink> → AVISO
 * - nada → CUMPLE
 */
class C24Autoplay extends BaseCheck
{
    protected string $id = 'C24';
    protected string $severity = 'MEDIO';
    protected string $criterio = 'Referencia: WCAG 2.2 SC 1.4.2 (Control del audio) y SC 2.2.2 (Poner en pausa, detener, ocultar).';
    protected string $impacto = 'MEDIO';
    protected string $esfuerzo = 'BAJO';
    protected bool $manual = true;

    protected function evaluate(CheckContext $ctx): array
    {
        $x = $ctx->xpath();
        $umbral = 'Sin audio ni vídeo con sonido que empiece solo; sin marquee ni blink';
        $sound = [];
        foreach ($x->query('//audio[@autoplay]|//video[@autoplay and not(@muted)]') as $el) {
            $sound[] = $el;
        }
        $moving = [];
        foreach ($x->query('//marquee|//blink') as $el) {
            $moving[] = $el;
        }
        if (!$sound && !$moving) {
            return $this->result(self::OK, 0, $umbral, [], 'No hay reproducción automática con sonido ni marquee o blink.');
        }
        $ev = array_map(fn(DOMElement $e) => DomHelper::evidence($e), array_slice(array_merge($sound, $moving), 0, 10));
        $parts = array_filter([
            $sound ? count($sound) . ' elementos de audio o vídeo empiezan solos con sonido' : '',
            $moving ? count($moving) . ' elementos marquee o blink' : '',
        ]);
        return $this->result(self::WARN, count($sound) + count($moving), $umbral, $ev,
            ucfirst(implode('; ', $parts)) . '. Revisa que duren menos de 3 s o que haya un control visible para pararlos.');
    }
}
