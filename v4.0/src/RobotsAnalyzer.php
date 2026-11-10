<?php
/**
 * Acceso de los bots a la URL según robots.txt. Lo usan C02 (que puntúa) y la tabla informativa de
 * bots de IA (función 04, que no puntúa), para que ambos lean robots.txt de la misma manera.
 * Reglas de RobotsTxt (RFC 9309): grupo del user-agent más específico (el propio bot; si no, «*»),
 * coincidencia Allow/Disallow más larga y, en empate, Allow.
 */
class RobotsAnalyzer
{
    public const SEARCH = 'busqueda';
    public const TRAINING = 'entrenamiento';

    /**
     * Estado de robots.txt a partir de la descarga (status, body, error).
     * absent: no existe (404/410) o devuelve HTML → se permite todo. unknown: no se pudo obtener o interpretar.
     * @return array{0:string,1:?RobotsTxt,2:string} [present|absent|unknown, intérprete, nota]
     */
    public static function state(?array $r): array
    {
        if (!$r || ($r['status'] ?? null) === null) {
            return ['unknown', null, 'robots.txt no disponible' . (!empty($r['error']) ? ' (' . $r['error'] . ')' : '') . '.'];
        }
        $status = (int) $r['status'];
        if ($status === 404 || $status === 410) {
            return ['absent', null, 'No existe robots.txt (HTTP ' . $status . '): se permite todo.'];
        }
        if ($status >= 200 && $status < 300) {
            $body = (string) ($r['body'] ?? '');
            if (preg_match('/^\s*<(!doctype|html)/i', $body)) {
                return ['absent', null, 'robots.txt devuelve una página HTML: se trata como inexistente.'];
            }
            return ['present', RobotsTxt::parse($body), ''];
        }
        return ['unknown', null, 'robots.txt respondió HTTP ' . $status . ': no se puede interpretar.'];
    }

    /** Ruta y consulta de la URL, como las compara robots.txt. */
    public static function pathOf(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = parse_url($url, PHP_URL_QUERY);
        return $query !== null && $query !== '' ? $path . '?' . $query : $path;
    }

    /**
     * Bots de la tabla, separados por tipo (configuración): buscadores (search_engine_bots) y bots de
     * búsqueda de IA (ai_search_bots) por un lado; bots de entrenamiento de IA (ai_training_bots) por otro.
     * @return array<string,string[]>
     */
    public static function bots(array $config): array
    {
        $search = array_merge((array) ($config['search_engine_bots'] ?? ['Googlebot']), (array) ($config['ai_search_bots'] ?? []));
        $training = (array) ($config['ai_training_bots'] ?? []);
        $clean = fn(array $list) => array_values(array_unique(array_filter(array_map('trim', array_map('strval', $list)), 'strlen')));
        return [self::SEARCH => $clean($search), self::TRAINING => $clean($training)];
    }

    /**
     * Función 04: tabla informativa del acceso de cada bot a la URL. robots.txt ausente o con error se
     * informa (sin datos) y no penaliza: la puntuación la decide solo C02.
     */
    public static function table(string $url, ?array $robots, array $config): array
    {
        [$state, $parser, $note] = self::state($robots);
        $path = self::pathOf($url);
        $rows = [];
        foreach (self::bots($config) as $type => $list) {
            foreach ($list as $bot) {
                $row = ['bot' => $bot, 'tipo' => $type, 'acceso' => 'sin_datos', 'grupo' => null, 'regla' => null];
                if ($state === 'absent') {
                    $row['acceso'] = 'permitido';
                } elseif ($state === 'present') {
                    $e = $parser->explain($bot, $path);
                    $row['acceso'] = $e['allowed'] ? 'permitido' : 'bloqueado';
                    $row['grupo'] = $e['group'];
                    $row['regla'] = $e['rule'];
                }
                $rows[] = $row;
            }
        }
        return [
            'robots_txt' => $state === 'present' ? 'presente' : ($state === 'absent' ? 'ausente' : 'sin_datos'),
            'nota' => $note === '' ? null : $note,
            'ruta' => $path,
            'bots' => $rows,
        ];
    }
}
