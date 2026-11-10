<?php
/**
 * C23 Subtítulos en vídeo (v3.0, traída de la v1.0 · UX-15): cada <video> declara una pista
 * <track kind="captions|subtitles">. Los vídeos de YouTube/Vimeo incrustados no se pueden comprobar.
 * - vídeo con controles o sonido sin pista → NO_CUMPLE (k = nº de vídeos)
 * - vídeo en silencio, autoplay y sin controles (fondo decorativo) sin pista → AVISO
 * - sin <video> → no aplica
 * La calidad de los subtítulos requiere revisión manual.
 */
class C23VideoCaptions extends BaseCheck
{
    protected string $id = 'C23';
    protected string $severity = 'ALTO';
    protected string $criterio = 'Referencia: WCAG 2.2 SC 1.2.2 (Subtítulos, grabados).';
    protected string $impacto = 'ALTO';
    protected string $esfuerzo = 'MEDIO';
    protected bool $manual = true;

    protected function evaluate(CheckContext $ctx): array
    {
        $x = $ctx->xpath();
        $umbral = 'Cada <video> con <track kind="captions"> o kind="subtitles"';
        $videos = $x->query('//video[not(ancestor::template)]');
        if ($videos->length === 0) {
            $embeds = $x->query('//iframe[contains(@src,"youtube") or contains(@src,"vimeo")]')->length;
            return $this->result(self::OK, 0, $umbral, [], $embeds
                ? "La página no tiene etiquetas video; hay $embeds vídeos incrustados (YouTube o Vimeo) cuyos subtítulos no se pueden comprobar."
                : 'La página no tiene vídeos.', 0, false);
        }
        $fail = [];
        $decorative = [];
        foreach ($videos as $v) {
            $tracks = $x->query('track[@kind="captions" or @kind="subtitles" or not(@kind)]', $v)->length;
            if ($tracks > 0) {
                continue;
            }
            $silentBackground = $v->hasAttribute('muted') && $v->hasAttribute('autoplay') && !$v->hasAttribute('controls');
            if ($silentBackground) {
                $decorative[] = $v;
            } else {
                $fail[] = $v;
            }
        }
        $total = $videos->length;
        if ($fail) {
            $ev = array_map(fn(DOMElement $v) => DomHelper::evidence($v), array_slice($fail, 0, 10));
            return $this->result(self::FAIL, count($fail), $umbral, $ev, count($fail) . " de $total vídeos no tienen subtítulos.", count($fail));
        }
        if ($decorative) {
            $ev = array_map(fn(DOMElement $v) => DomHelper::evidence($v), array_slice($decorative, 0, 10));
            return $this->result(self::WARN, count($decorative), $umbral, $ev,
                count($decorative) . " de $total vídeos no tienen subtítulos, pero están en silencio y sin controles (parecen de fondo). Si tienen sonido o información, necesitan subtítulos.");
        }
        return $this->result(self::OK, 0, $umbral, [], "Los $total vídeos declaran subtítulos. Su calidad requiere revisión manual.");
    }
}
