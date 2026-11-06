<?php
/**
 * C02 Acceso de bots e indexación.
 * - robots.txt debe permitir a Googlebot y a los bots de búsqueda de IA (NO_CUMPLE si no).
 * - Sin noindex en meta robots/googlebot ni en X-Robots-Tag. Si la petición declara que el
 *   noindex es intencionado (opción noindex_intentional), AVISO; si no, NO_CUMPLE.
 * - Bloquear solo bots de entrenamiento: AVISO.
 * - robots.txt no disponible (error, 5xx, 403…): SIN_DATOS si no hay otro hallazgo.
 */
class C02BotsIndexing extends BaseCheck
{
    protected string $id = 'C02';
    protected string $severity = 'CRITICO';
    protected string $criterio = 'Protocolo de exclusión de robots (RFC 9309) y directivas noindex (meta robots / X-Robots-Tag). No es un criterio WCAG.';
    protected string $impacto = 'ALTO';
    protected string $esfuerzo = 'BAJO';
    protected bool $needsHtml = false;

    protected function evaluate(CheckContext $ctx): array
    {
        $searchBots = (array) ($ctx->config['ai_search_bots'] ?? ['OAI-SearchBot', 'PerplexityBot']);
        $trainingBots = (array) ($ctx->config['ai_training_bots'] ?? ['GPTBot', 'ClaudeBot', 'Google-Extended']);
        $path = RobotsAnalyzer::pathOf($ctx->url);

        $ev = [];
        $notes = [];

        // --- robots.txt (misma lectura que la tabla de bots de IA)
        [$robotsState, $robots, $robotsNote] = RobotsAnalyzer::state($ctx->robots);
        if ($robotsNote !== '') {
            $notes[] = $robotsNote;
        }
        $access = ['googlebot' => 'sin_datos', 'bots_busqueda_ia' => [], 'bots_entrenamiento' => []];
        $blockedHard = [];
        $blockedTraining = [];
        $explainer = function (string $bot) use ($robotsState, $robots, $path, &$ev): string {
            if ($robotsState === 'unknown') {
                return 'sin_datos';
            }
            if ($robotsState === 'absent') {
                return 'permitido';
            }
            $e = $robots->explain($bot, $path);
            if (!$e['allowed']) {
                $ev[] = DomHelper::note('robots.txt, grupo «' . $e['group'] . '»: ' . $e['rule'] . ' → bloquea a ' . $bot . ' en ' . $path, 'robots.txt');
            }
            return $e['allowed'] ? 'permitido' : 'bloqueado';
        };
        $access['googlebot'] = $explainer('Googlebot');
        if ($access['googlebot'] === 'bloqueado') {
            $blockedHard[] = 'Googlebot';
        }
        foreach ($searchBots as $bot) {
            $access['bots_busqueda_ia'][$bot] = $explainer($bot);
            if ($access['bots_busqueda_ia'][$bot] === 'bloqueado') {
                $blockedHard[] = $bot;
            }
        }
        foreach ($trainingBots as $bot) {
            $access['bots_entrenamiento'][$bot] = $explainer($bot);
            if ($access['bots_entrenamiento'][$bot] === 'bloqueado') {
                $blockedTraining[] = $bot;
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
        $access['robots_txt'] = $robotsState === 'present' ? 'presente' : ($robotsState === 'absent' ? 'ausente' : 'sin_datos');

        $umbral = 'Googlebot y bots de búsqueda de IA permitidos; sin noindex';
        $suffix = $notes ? ' ' . implode(' ', $notes) : '';
        $intentional = !empty($ctx->options['noindex_intentional']);

        if ($blockedHard) {
            $extra = $noindex ? ' Además, la página declara noindex.' : '';
            return $this->result(self::FAIL, $access, $umbral, $ev, 'robots.txt bloquea a: ' . implode(', ', $blockedHard) . '.' . $extra . $suffix, count($blockedHard));
        }
        if ($noindex) {
            return $intentional
                ? $this->result(self::WARN, $access, $umbral, $ev, 'La página declara noindex y se ha indicado que es intencionado.' . $suffix)
                : $this->result(self::FAIL, $access, $umbral, $ev, 'La página declara noindex: no se indexará en buscadores.' . $suffix, 1);
        }
        if ($blockedTraining) {
            return $this->result(self::WARN, $access, $umbral, $ev, 'robots.txt bloquea solo a bots de entrenamiento de IA (' . implode(', ', $blockedTraining) . '); Googlebot y los bots de búsqueda de IA tienen acceso.' . $suffix);
        }
        if ($robotsState === 'unknown') {
            return $this->result(self::NO_DATA, $access, $umbral, $ev, 'No se ha podido obtener robots.txt; no hay noindex en la página.' . $suffix);
        }
        return $this->result(self::OK, $access, $umbral, $ev, 'Googlebot y los bots de IA configurados tienen acceso y no hay noindex.' . $suffix);
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
            $scope = '';
            foreach (explode(',', $header) as $token) {
                $token = strtolower(trim($token));
                if (preg_match('/^([a-z0-9_\-]+)\s*:\s*(.*)$/', $token, $m) && $m[1] !== 'unavailable_after') {
                    $scope = $m[1];
                    $token = trim($m[2]);
                }
                if (($scope === '' || $scope === 'googlebot' || $scope === '*') && ($token === 'noindex' || $token === 'none')) {
                    $found[] = DomHelper::note('X-Robots-Tag: ' . $header, 'cabecera HTTP');
                    break;
                }
            }
        }
        return $found;
    }

    private function hasNoindex(string $content): bool
    {
        foreach (explode(',', strtolower($content)) as $t) {
            $t = trim($t);
            if ($t === 'noindex' || $t === 'none') {
                return true;
            }
        }
        return false;
    }
}
