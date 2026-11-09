<?php
/**
 * Historial de notas por URL en MySQL (Fase 4, aprobado por el grupo).
 *
 * Solo guarda: URL analizada, su clave de comparación (UrlNormalizer), fecha (UTC), las tres notas y la
 * global, si alguna es incompleta, si hubo bloqueo, si incluye PageSpeed y el número de fallos por
 * gravedad. Nunca guarda contenido, evidencias ni extractos de la web analizada. Las filas de más de
 * `meses` meses (12 por defecto) se borran al guardar una nueva.
 *
 * Sin 'historial' => ['dsn' => …] en la configuración, el historial está desactivado.
 *
 * Flujo: api/analyze.php guarda la nota sin PageSpeed y firma (HMAC) los estados que ha calculado;
 * api/psi.php firma C08 y C14; api/score.php comprueba las dos firmas antes de actualizar la fila con la
 * nota final. Así el navegador no puede escribir en el historial notas que no haya calculado el servidor.
 */
class History
{
    public const PSI_IDS = ['C08', 'C14'];
    private const SEVERITIES = ['CRITICO' => 'criticos', 'ALTO' => 'altos', 'MEDIO' => 'medios', 'BAJO' => 'bajos'];

    /** @var PDO */
    private $pdo;
    /** @var string */
    private $key;
    /** @var int */
    private $months;

    public function __construct(PDO $pdo, string $key, int $months = 12)
    {
        $this->pdo = $pdo;
        $this->key = $key;
        $this->months = max(1, $months);
    }

    public static function enabled(array $config): bool
    {
        return is_array($config['historial'] ?? null) && (string) ($config['historial']['dsn'] ?? '') !== '';
    }

    public static function months(array $config): int
    {
        return max(1, (int) ($config['historial']['meses'] ?? 12));
    }

    /** Conexión a partir de la configuración; null si está desactivado o no se puede conectar. */
    public static function fromConfig(array $config): ?History
    {
        if (!self::enabled($config)) {
            return null;
        }
        $h = $config['historial'];
        try {
            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3];
            if (strncmp((string) $h['dsn'], 'mysql:', 6) === 0 && defined('PDO::MYSQL_ATTR_FOUND_ROWS')) {
                // rowCount() cuenta las filas encontradas aunque el UPDATE no cambie ningún valor
                $options[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
            }
            $pdo = new PDO((string) $h['dsn'], (string) ($h['usuario'] ?? ''), (string) ($h['clave'] ?? ''), $options);
            return new History($pdo, self::secretKey(), self::months($config));
        } catch (Throwable $e) {
            error_log('[historial] No se puede conectar: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Clave de firma: se genera una vez en storage/ (no se sube a git ni es accesible por web).
     * No es una credencial de terceros: solo sirve para firmar estados entre las llamadas de un análisis.
     */
    public static function secretKey(?string $file = null): string
    {
        $file = $file ?? __DIR__ . '/../storage/historial.key';
        $key = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if (!preg_match('/^[0-9a-f]{64}$/', $key)) {
            $key = bin2hex(random_bytes(32));
            file_put_contents($file, $key, LOCK_EX);
            @chmod($file, 0600);
        }
        return $key;
    }

    /** Texto canónico de los estados de una lista de comprobaciones (orden por id). */
    public static function canonical(array $checks): string
    {
        $lines = [];
        foreach ($checks as $c) {
            $lines[(string) ($c['id'] ?? '')] = ($c['id'] ?? '') . '|' . ($c['estado'] ?? '') . '|'
                . max(0, (int) ($c['ocurrencias'] ?? 0)) . '|' . ((($c['aplica'] ?? true) !== false) ? 1 : 0);
        }
        ksort($lines, SORT_STRING);
        return implode("\n", $lines);
    }

    /** Separa las comprobaciones de PageSpeed (C08, C14) del resto. */
    public static function split(array $checks): array
    {
        $psi = [];
        $rest = [];
        foreach ($checks as $c) {
            if (in_array($c['id'] ?? '', self::PSI_IDS, true)) {
                $psi[] = $c;
            } else {
                $rest[] = $c;
            }
        }
        return [$rest, $psi];
    }

    public static function sign(string $key, string $scope, array $checks): string
    {
        return hash_hmac('sha256', $scope . "\n" . self::canonical($checks), $key);
    }

    /** Firma de los estados de api/analyze.php (sin C08 ni C14), ligada a la fila del historial. */
    public function signAnalysis(int $id, array $checks): string
    {
        return self::sign($this->key, 'analisis|' . $id, self::split($checks)[0]);
    }

    /** Resumen que se guarda: notas y fallos por gravedad. Con bloqueo no hay notas. */
    public static function summary(array $score, array $checks, bool $blocked): array
    {
        $areas = $score['areas'] ?? [];
        $row = [
            'nota_seo' => $blocked ? null : ($areas['seo']['redondeada'] ?? null),
            'nota_ia' => $blocked ? null : ($areas['ia']['redondeada'] ?? null),
            'nota_accesibilidad' => $blocked ? null : ($areas['accesibilidad']['redondeada'] ?? null),
            'nota_global' => $blocked ? null : ($score['global']['redondeada'] ?? null),
            'incompleta' => !$blocked && !empty($score['global']['incompleta']) ? 1 : 0,
            'bloqueado' => $blocked ? 1 : 0,
            'pagespeed' => 0,
            'criticos' => 0, 'altos' => 0, 'medios' => 0, 'bajos' => 0,
        ];
        foreach ($checks as $c) {
            if (($c['estado'] ?? '') === BaseCheck::FAIL && ($c['aplica'] ?? true) !== false
                && isset(self::SEVERITIES[$c['severidad'] ?? ''])) {
                $row[self::SEVERITIES[$c['severidad']]]++;
            }
            if (in_array($c['id'] ?? '', self::PSI_IDS, true) && ($c['estado'] ?? '') !== BaseCheck::NO_DATA) {
                $row['pagespeed'] = 1;
            }
        }
        return $row;
    }

    public static function urlKey(string $url): string
    {
        try {
            $key = UrlNormalizer::comparisonKey($url);
        } catch (Throwable $e) {
            $key = $url;
        }
        return sha1($key);
    }

    /** Guarda la nota de un análisis (sin PageSpeed) y borra las filas caducadas. Devuelve el id. */
    public function record(string $url, array $score, array $checks, bool $blocked, ?DateTimeImmutable $now = null): int
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = $now->modify('-' . $this->months . ' months')->format('Y-m-d H:i:s');
        $this->pdo->prepare('DELETE FROM historial WHERE fecha < ?')->execute([$cutoff]);

        $row = ['url' => mb_substr($url, 0, 2048), 'url_clave' => self::urlKey($url), 'fecha' => $now->format('Y-m-d H:i:s')]
            + self::summary($score, $checks, $blocked);
        $cols = array_keys($row);
        $sql = 'INSERT INTO historial (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')';
        $this->pdo->prepare($sql)->execute($row);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Actualiza la fila con la nota final (con C08 y C14) si las dos firmas son válidas. Devuelve false si
     * no lo son o si la fila no existe o ya incluye PageSpeed.
     */
    public function finalize(int $id, array $checks, string $sigAnalysis, string $sigPsi, array $score, bool $blocked): bool
    {
        [$rest, $psi] = self::split($checks);
        if (!$psi || !hash_equals(self::sign($this->key, 'analisis|' . $id, $rest), $sigAnalysis)
            || !hash_equals(self::sign($this->key, 'psi', $psi), $sigPsi)) {
            return false;
        }
        $row = self::summary($score, $checks, $blocked);
        $sets = implode(', ', array_map(function ($c) { return $c . ' = :' . $c; }, array_keys($row)));
        $stmt = $this->pdo->prepare('UPDATE historial SET ' . $sets . ' WHERE id = :id AND pagespeed = 0');
        $stmt->execute($row + ['id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /** Análisis anteriores de la misma URL (sin el actual), del más reciente al más antiguo. */
    public function previous(string $url, int $excludeId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare('SELECT fecha, nota_seo, nota_ia, nota_accesibilidad, nota_global, incompleta,'
            . ' bloqueado, pagespeed, criticos, altos, medios, bajos FROM historial'
            . ' WHERE url_clave = ? AND id <> ? ORDER BY fecha DESC, id DESC LIMIT ' . max(1, min(50, $limit)));
        $stmt->execute([self::urlKey($url), $excludeId]);
        return array_map(function (array $r) {
            $out = ['fecha' => str_replace(' ', 'T', (string) $r['fecha']) . 'Z'];
            foreach (['nota_seo', 'nota_ia', 'nota_accesibilidad', 'nota_global'] as $k) {
                $out[$k] = $r[$k] === null ? null : (float) $r[$k];
            }
            foreach (['incompleta', 'bloqueado', 'pagespeed'] as $k) {
                $out[$k] = (int) $r[$k] === 1;
            }
            foreach (['criticos', 'altos', 'medios', 'bajos'] as $k) {
                $out[$k] = (int) $r[$k];
            }
            return $out;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
