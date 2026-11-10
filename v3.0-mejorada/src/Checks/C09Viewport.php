<?php
/**
 * C09 Zoom y móvil: meta viewport presente y sin user-scalable=no.
 * Supuestos (AVISO): maximum-scale inferior a 2 y ausencia de width=device-width.
 */
class C09Viewport extends BaseCheck
{
    protected string $id = 'C09';
    protected string $severity = 'ALTO';
    protected string $criterio = 'Referencia: WCAG 2.2 SC 1.4.4 (Cambio de tamaño del texto) y SC 1.4.10 (Reajuste del contenido).';
    protected string $impacto = 'ALTO';
    protected string $esfuerzo = 'BAJO';

    protected function evaluate(CheckContext $ctx): array
    {
        $meta = null;
        foreach ($ctx->xpath()->query('//meta[@name]') as $m) {
            if (strtolower(trim($m->getAttribute('name'))) === 'viewport') {
                $meta = $m;                              // el navegador usa la última
            }
        }
        $umbral = 'meta viewport presente, sin user-scalable=no';
        if (!$meta) {
            return $this->result(self::FAIL, null, $umbral, [DomHelper::note('(no hay meta viewport)', 'meta[name="viewport"]')], 'Falta la etiqueta meta viewport: la página no se adapta a móviles.', 1);
        }

        $content = $meta->getAttribute('content');
        $props = [];
        foreach (preg_split('/[,;]/', strtolower($content)) as $pair) {
            $kv = array_map('trim', explode('=', $pair, 2));
            if ($kv[0] !== '') {
                $props[$kv[0]] = $kv[1] ?? '';
            }
        }
        $ev = [DomHelper::evidence($meta)];

        $scalable = $props['user-scalable'] ?? null;
        if ($scalable === 'no' || $scalable === '0') {
            return $this->result(self::FAIL, $content, $umbral, $ev, 'El viewport impide el zoom (user-scalable=' . $scalable . ').', 1);
        }
        $warnings = [];
        if (isset($props['maximum-scale']) && is_numeric($props['maximum-scale']) && (float) $props['maximum-scale'] < 2) {
            $warnings[] = 'maximum-scale=' . $props['maximum-scale'] . ' limita el zoom';
        }
        if (($props['width'] ?? '') !== 'device-width') {
            $warnings[] = 'no declara width=device-width';
        }
        if ($warnings) {
            return $this->result(self::WARN, $content, $umbral, $ev, 'Viewport presente, pero ' . implode(' y ', $warnings) . '.');
        }
        return $this->result(self::OK, $content, $umbral, $ev, 'Viewport adaptable a móviles y sin bloqueo del zoom.');
    }
}
