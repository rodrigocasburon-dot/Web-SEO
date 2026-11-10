<?php
/**
 * C02 Acceso de bots e indexación (GEO-01 y la parte de indexación de GEO-26).
 * Bots por categoría en config/ai_bots.php:
 * - Bot de búsqueda que penaliza (OpenAI, Anthropic, Perplexity, Google, Bing) bloqueado → NO_CUMPLE, con su nombre.
 *   «User-agent: *» con «Disallow: /» bloquea a todos los que no tienen grupo propio, así que también es NO_CUMPLE.
 * - noindex en meta robots/googlebot o X-Robots-Tag → NO_CUMPLE (AVISO si se declara intencionado).
 * - robots.txt con 401/403 → AVISO. Otro bot de búsqueda (Applebot) bloqueado → AVISO.
 * - Solo bots de entrenamiento bloqueados → CUMPLE: es una decisión de no entrenar y no afecta a la búsqueda.
 *   Google-Extended no afecta a la Búsqueda de Google ni a AI Overviews.
 * - Bots a petición del usuario: solo informativos (robots.txt puede no aplicarse a ellos).
 * - robots.txt no disponible (error, 5xx…): SIN_DATOS si no hay otro hallazgo. 404: se permite todo.
 * - Applebot sin grupo propio hereda las reglas de Googlebot.
 */
class C02BotsIndexing extends BaseCheck
{
    protected string $id = 'C02';
    protected string $severity = 'CRITICO';
    protected string $criterio = 'Protocolo de exclusión de robots (RFC 9309), documentación de cada proveedor de bots y directivas noindex (meta robots / X-Robots-Tag). No es un criterio WCAG.';
    protected string $impacto = 'ALTO';
    protected string $esfuerzo = 'BAJO';
    protected string $respaldo = 'oficial';
    protected bool $needsHtml = false;

    public const TRAINING_NOTE = 'decisión de no entrenar, no afecta a la búsqueda';
    public const USER_NOTE = 'robots.txt puede no aplicarse a estos agentes; solo un bloqueo en cortafuegos o por IP lo garantiza';

    protected function evaluate(CheckContext $ctx): array
    {
        $path = RobotsAnalyzer::pathOf($ctx->url);
        [$robotsState, $robots, $robotsNote] = RobotsAnalyzer::state($ctx->robots);

        $ev = [];
        $notes = $robotsNote !== '' ? [$robotsNote] : [];
        $access = ['robots_txt' => RobotsAnalyzer::stateLabel($robotsState)];
        $blocked = [RobotsAnalyzer::SEARCH => [], 'busqueda_aviso' => [], RobotsAnalyzer::TRAINING => [], RobotsAnalyzer::USER => []];

        foreach (RobotsAnalyzer::bots() as $cat => $list) {
            $access[$cat] = [];
            foreach ($list as $b) {
                $a = RobotsAnalyzer::access($robotsState, $robots, $b['bot'], $b['hereda'], $path);
                $access[$cat][$b['bot']] = $a['acceso'];
                if ($a['acceso'] !== 'bloqueado') {
                    continue;
                }
                $how = $a['heredado_de'] ? ' (sin reglas propias: hereda las de ' . $a['heredado_de'] . ')' : '';
                $ev[] = DomHelper::note('robots.txt, grupo «' . $a['grupo'] . '»: ' . $a['regla'] . ' → bloquea a ' . $b['bot'] . ' en ' . $path . $how, 'robots.txt');
                if ($cat === RobotsAnalyzer::SEARCH) {
                    $blocked[$b['penaliza'] ? RobotsAnalyzer::SEARCH : 'busqueda_aviso'][] = $b['bot'] . $how;
                } else {
                    $blocked[$cat][] = $b['bot'];
                }
            }
        }

        // --- noindex (solo si el contenido recibido es el de la página real)
        $noindex = [];
        if (!$ctx->blocked) {
            $noindex = $this->findNoindex($ctx);
            foreach ($noindex as $item) {
                $ev[] = $item;
            }
        }
        $access['noindex'] = (bool) $noindex;

        // Notas informativas (no cambian el estado)
        if ($blocked[RobotsAnalyzer::TRAINING]) {
            $notes[] = 'Bots de entrenamiento bloqueados (' . implode(', ', $blocked[RobotsAnalyzer::TRAINING]) . '): ' . self::TRAINING_NOTE . '.';
        }
        if ($blocked[RobotsAnalyzer::USER]) {
            $notes[] = 'Agentes a petición del usuario bloqueados en robots.txt (' . implode(', ', $blocked[RobotsAnalyzer::USER]) . '); no puntúa: ' . self::USER_NOTE . '.';
        }

        $umbral = 'Bots de búsqueda de OpenAI, Anthropic, Perplexity, Google y Bing permitidos; sin noindex';
        $suffix = $notes ? ' ' . implode(' ', $notes) : '';
        $intentional = !empty($ctx->options['noindex_intentional']);

        if ($blocked[RobotsAnalyzer::SEARCH]) {
            $extra = $noindex ? ' Además, la página declara noindex.' : '';
            return $this->result(self::FAIL, $access, $umbral, $ev, 'robots.txt bloquea a bots de búsqueda: ' . implode(', ', $blocked[RobotsAnalyzer::SEARCH]) . '.' . $extra . $suffix, count($blocked[RobotsAnalyzer::SEARCH]));
        }
        if ($noindex) {
            return $intentional
                ? $this->result(self::WARN, $access, $umbral, $ev, 'La página declara noindex y se ha indicado que es intencionado.' . $suffix)
                : $this->result(self::FAIL, $access, $umbral, $ev, 'La página declara noindex: no se indexará en buscadores.' . $suffix, 1);
        }
        if ($robotsState === 'forbidden') {
            return $this->result(self::WARN, $access, $umbral, $ev, 'No se ha podido leer robots.txt porque el servidor lo prohíbe; no hay noindex en la página.' . $suffix);
        }
        if ($blocked['busqueda_aviso']) {
            return $this->result(self::WARN, $access, $umbral, $ev, 'robots.txt bloquea a otros bots de búsqueda: ' . implode(', ', $blocked['busqueda_aviso']) . '.' . $suffix);
        }
        if ($robotsState === 'unknown') {
            return $this->result(self::NO_DATA, $access, $umbral, $ev, 'No se ha podido obtener robots.txt; no hay noindex en la página.' . $suffix);
        }
        return $this->result(self::OK, $access, $umbral, $ev, 'Los bots de búsqueda comprobados tienen acceso y no hay noindex.' . $suffix);
    }

    /** Busca noindex/none en meta robots o googlebot y en X-Robots-Tag (sin prefijo o con googlebot). */
    private function findNoindex(CheckContext $ctx): array
    {
        $found = [];
        if ($ctx->isHtml()) {
            foreach ($ctx->xpath()->query('//meta[@name]') as $meta) {
                $name = strtolower(trim($meta->getAttribute('name')));
                if (($name === 'robots' || $name === 'googlebot') && $this->hasNoindex($meta->getAttribute('content'))) {
                    $found[] = DomHelper::evidence($meta);
                }
            }
        }
        $header = $ctx->header('x-robots-tag');
        if ($header !== '') {
            foreach (RobotsDirectives::fromHeader($header) as $d) {
                if ($d === 'noindex' || $d === 'none') {
                    $found[] = DomHelper::note('X-Robots-Tag: ' . $header, 'cabecera HTTP');
                    break;
                }
            }
        }
        return $found;
    }

    private function hasNoindex(string $content): bool
    {
        foreach (RobotsDirectives::fromContent($content) as $t) {
            if ($t === 'noindex' || $t === 'none') {
                return true;
            }
        }
        return false;
    }
}
