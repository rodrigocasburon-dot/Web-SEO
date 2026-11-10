<?php
/**
 * Acceso de los bots a la URL según robots.txt. Lo usan C02 (que puntúa) y la tabla informativa de
 * bots (función 04, que no puntúa), para que ambos lean robots.txt de la misma manera.
 * Reglas de RobotsTxt (RFC 9309): grupo del user-agent más específico (el propio bot; si no, «*»),
 * coincidencia Allow/Disallow más larga y, en empate, Allow.
 * Bots y categorías: config/ai_bots.php (entrenamiento, busqueda, usuario).
 */
class RobotsAnalyzer
{
    public const SEARCH = 'busqueda';
    public const TRAINING = 'entrenamiento';
    public const USER = 'usuario';
    public const CATEGORIES = [self::TRAINING, self::SEARCH, self::USER];

    private static ?array $catalog = null;

    /**
     * Estado de robots.txt a partir de la descarga (status, body, error).
     * absent: no existe (404/410) o devuelve HTML → se permite todo.
     * forbidden: 401/403. RFC 9309 trata los 4xx como «sin restricciones», pero un 403 suele venir de un
     *   cortafuegos que también puede bloquear a los bots: no se da por permitido (C02 avisa).
     * unknown: no se pudo obtener o interpretar (error, 5xx…). RFC 9309 trata los 5xx como bloqueo total; no
     *   se da por hecho (sin datos).
     * @return array{0:string,1:?RobotsTxt,2:string} [present|absent|forbidden|unknown, intérprete, nota]
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
        if ($status === 401 || $status === 403) {
            return ['forbidden', null, 'robots.txt respondió HTTP ' . $status . ': puede ser un cortafuegos que también bloquee a los bots.'];
        }
        if ($status >= 500) {
            return ['unknown', null, 'robots.txt respondió HTTP ' . $status . ' (error del servidor). Según RFC 9309 los bots pueden tratarlo como bloqueo total; no se da por hecho.'];
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
     * Bots por categoría (config/ai_bots.php). Se puede pasar otro catálogo en las pruebas.
     * @return array<string, array<int, array{bot:string, proveedor:string, penaliza:bool, hereda:?string}>>
     */
    public static function bots(?array $catalog = null): array
    {
        if ($catalog === null) {
            if (self::$catalog === null) {
                self::$catalog = (array) require __DIR__ . '/../config/ai_bots.php';
            }
            $catalog = self::$catalog;
        }
        $out = [];
        foreach (self::CATEGORIES as $cat) {
            $seen = [];
            $out[$cat] = [];
            foreach ((array) ($catalog[$cat] ?? []) as $b) {
                $name = trim((string) ($b['bot'] ?? ''));
                if ($name === '' || isset($seen[strtolower($name)])) {
                    continue;
                }
                $seen[strtolower($name)] = true;
                $out[$cat][] = [
                    'bot' => $name,
                    'proveedor' => (string) ($b['proveedor'] ?? ''),
                    'penaliza' => $cat === self::SEARCH && !empty($b['penaliza']),
                    'hereda' => isset($b['hereda']) && trim((string) $b['hereda']) !== '' ? trim((string) $b['hereda']) : null,
                ];
            }
        }
        return $out;
    }

    /**
     * Acceso de un bot a la ruta. Si el bot tiene «hereda» y robots.txt no tiene grupo propio para él, se
     * aplican las reglas del bot del que hereda (también su grupo propio, si lo tiene).
     * @return array{acceso:string, grupo:?string, regla:?string, heredado_de:?string}
     */
    public static function access(string $state, ?RobotsTxt $parser, string $bot, ?string $inherits, string $path): array
    {
        $row = ['acceso' => 'sin_datos', 'grupo' => null, 'regla' => null, 'heredado_de' => null];
        if ($state === 'absent') {
            $row['acceso'] = 'permitido';
            return $row;
        }
        if ($state !== 'present' || $parser === null) {
            return $row;
        }
        $agent = $bot;
        if ($inherits !== null && !$parser->hasGroup($bot) && $parser->hasGroup($inherits)) {
            $agent = $inherits;
            $row['heredado_de'] = $inherits;
        }
        $e = $parser->explain($agent, $path);
        $row['acceso'] = $e['allowed'] ? 'permitido' : 'bloqueado';
        $row['grupo'] = $e['group'];
        $row['regla'] = $e['rule'];
        return $row;
    }

    /**
     * Función 04: tabla informativa del acceso de cada bot a la URL, por categoría. robots.txt ausente o con
     * error se informa (sin datos) y no penaliza: la puntuación la decide solo C02.
     */
    public static function table(string $url, ?array $robots, ?array $catalog = null): array
    {
        [$state, $parser, $note] = self::state($robots);
        $path = self::pathOf($url);
        $rows = [];
        foreach (self::bots($catalog) as $type => $list) {
            foreach ($list as $b) {
                $rows[] = ['bot' => $b['bot'], 'tipo' => $type, 'proveedor' => $b['proveedor'], 'penaliza' => $b['penaliza']]
                    + self::access($state, $parser, $b['bot'], $b['hereda'], $path);
            }
        }
        return [
            'robots_txt' => self::stateLabel($state),
            'nota' => $note === '' ? null : $note,
            'ruta' => $path,
            'bots' => $rows,
        ];
    }

    public static function stateLabel(string $state): string
    {
        return ['present' => 'presente', 'absent' => 'ausente', 'forbidden' => 'prohibido'][$state] ?? 'sin_datos';
    }
}
