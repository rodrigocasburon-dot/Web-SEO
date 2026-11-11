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
    /** MEDIUMBLOB admite 16 MB; se deja margen */
    public const MAX_REPORT_BYTES = 16000000;
    private const SEVERITIES = ['CRITICO' => 'criticos', 'ALTO' => 'altos', 'MEDIO' => 'medios', 'BAJO' => 'bajos'];

    /** @var PDO */
    private $pdo;
    /** @var string */
    private $key;
    /** @var int */
    private $months;
    /** @var bool|null */
    private $reliabilityColumns = null;

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
            ErrorLog::record('historial:conexion', $e);
            return null;
        }
    }

    /**
     * Clave de firma: se genera una vez en storage/ (no se sube a git ni es accesible por web).
     * No es una credencial de terceros: solo sirve para firmar estados entre las llamadas de un análisis.
     */
    public static function secretKey(?string $file = null): string
    {
        $file = $file ?? (getenv('WEBSEO_STORAGE_DIR') ?: __DIR__ . '/../storage') . '/historial.key';
        $read = function () use ($file): string {
            $key = is_file($file) ? trim((string) @file_get_contents($file)) : '';
            return preg_match('/^[0-9a-f]{64}$/', $key) ? $key : '';
        };
        $key = $read();
        if ($key !== '') {
            return $key;
        }
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0700, true);
        }
        // Creación exclusiva ('x'): si dos peticiones la generan a la vez, gana la primera y la otra la lee.
        // Antes cada una escribía la suya y las firmas de un análisis en curso dejaban de coincidir.
        $fh = @fopen($file, 'x');
        if ($fh) {
            $key = bin2hex(random_bytes(32));
            $ok = fwrite($fh, $key) === strlen($key);
            fclose($fh);
            @chmod($file, 0600);
            if ($ok) {
                return $key;
            }
            @unlink($file);
            throw new RuntimeException('No se puede guardar la clave de firma del historial.');
        }
        // Otra petición la está creando, o el archivo existe con contenido no válido
        for ($i = 0; $i < 10; $i++) {
            $key = $read();
            if ($key !== '') {
                return $key;
            }
            usleep(20000);
        }
        // Archivo dañado (p. ej. escritura cortada): se sustituye de forma atómica
        $key = bin2hex(random_bytes(32));
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $key, LOCK_EX) !== strlen($key) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('No se puede guardar la clave de firma del historial.');
        }
        @chmod($file, 0600);
        return $key;
    }

    /**
     * Ejecuta $fn dentro de una transacción: o se guarda todo (fila e informe) o no se guarda nada.
     * Si ya hay una transacción abierta, $fn se ejecuta dentro de ella.
     * @return mixed lo que devuelva $fn
     */
    public function atomic(callable $fn)
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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

    /** Borra las filas de más de `meses` meses. Devuelve cuántas. La usan record(), recordSite() y bin/limpiar.php. */
    public function purgeExpired(?DateTimeImmutable $now = null): int
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = $now->modify('-' . $this->months . ' months')->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('DELETE FROM historial WHERE fecha < ?');
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    /** Guarda la nota de un análisis (sin PageSpeed) y borra las filas caducadas. Devuelve el id. */
    public function record(string $url, array $score, array $checks, bool $blocked, ?DateTimeImmutable $now = null): int
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->purgeExpired($now);

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

    /**
     * v3.0 · Guarda un análisis de sitio: notas medias de las páginas y nº de comprobaciones distintas que
     * fallan en alguna página, por gravedad. $failing = ['C04' => 'CRITICO', …]. Devuelve el id.
     */
    public function recordSite(string $url, ?array $means, array $failing, int $pages, bool $blocked, ?DateTimeImmutable $now = null): int
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->purgeExpired($now);
        $note = fn(string $k) => $blocked || $means === null || !isset($means[$k]) ? null : round((float) $means[$k], 1);
        $row = [
            'url' => mb_substr($url, 0, 2048), 'url_clave' => self::urlKey($url), 'fecha' => $now->format('Y-m-d H:i:s'),
            'nota_seo' => $note('seo'), 'nota_ia' => $note('ia'), 'nota_accesibilidad' => $note('accesibilidad'), 'nota_global' => $note('global'),
            'incompleta' => 0, 'bloqueado' => $blocked ? 1 : 0, 'pagespeed' => 0,
            'criticos' => 0, 'altos' => 0, 'medios' => 0, 'bajos' => 0,
            'tipo' => 'sitio', 'paginas' => $blocked ? 0 : max(0, min(65535, $pages)),
        ];
        foreach ($failing as $severity) {
            if (isset(self::SEVERITIES[$severity])) {
                $row[self::SEVERITIES[$severity]]++;
            }
        }
        $cols = array_keys($row);
        $this->pdo->prepare('INSERT INTO historial (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($row);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * v3.0 · Últimos análisis (todas las URL), del más reciente al más antiguo, para historial.php.
     * $search filtra por texto de la URL; $type: '' (todos), 'url' o 'sitio'.
     */
    public function latest(string $search = '', string $type = '', int $limit = 100, int $offset = 0): array
    {
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = "url LIKE ? ESCAPE '!'";
            $params[] = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        if (in_array($type, ['url', 'sitio'], true)) {
            $where[] = 'tipo = ?';
            $params[] = $type;
        }
        $sql = 'SELECT id, url, tipo, paginas, (informe IS NOT NULL) AS tiene_informe, fecha, nota_seo, nota_ia, nota_accesibilidad, nota_global, incompleta,'
            . ' bloqueado, pagespeed, criticos, altos, medios, bajos' . $this->reliabilitySelect() . ' FROM historial'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY fecha DESC, id DESC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(function (array $r) {
            return ['id' => (int) $r['id'], 'url' => (string) $r['url'], 'tipo' => (string) $r['tipo'], 'paginas' => (int) $r['paginas'],
                'tiene_informe' => (int) $r['tiene_informe'] === 1]
                + self::rowOut($r);
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * v3.0 · Guarda el informe completo de un análisis (JSON comprimido). Lo llama SIEMPRE el servidor con
     * datos que ha calculado él: nunca con lo que envía el navegador.
     */
    public function saveReport(int $id, array $report): void
    {
        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('No se puede codificar el informe: ' . json_last_error_msg());
        }
        $blob = gzencode($json, 6);
        if ($blob === false) {
            throw new RuntimeException('No se puede comprimir el informe.');
        }
        if (strlen($blob) > self::MAX_REPORT_BYTES) {
            throw new RuntimeException('El informe comprimido supera el tamaño máximo de la columna (' . strlen($blob) . ' bytes).');
        }
        $stmt = $this->pdo->prepare('UPDATE historial SET informe = :informe WHERE id = :id');
        $stmt->bindValue(':informe', $blob, PDO::PARAM_LOB);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        // Antes no se comprobaba: si la fila no existía, el informe se perdía sin avisar
        if ($stmt->rowCount() !== 1 && !$this->exists($id)) {
            throw new RuntimeException('No existe la fila del historial en la que guardar el informe.');
        }
    }

    private function exists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM historial WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * ¿Existen las columnas de fiabilidad (fiab_estado, fiab_evaluados, fiab_posibles)? Son opcionales: se
     * añaden con docs/migracion-fiabilidad.sql. Sin ellas todo funciona igual y la fiabilidad queda solo
     * dentro del informe guardado.
     */
    public function hasReliability(): bool
    {
        if ($this->reliabilityColumns === null) {
            try {
                $this->pdo->query('SELECT fiab_estado, fiab_evaluados, fiab_posibles FROM historial LIMIT 1');
                $this->reliabilityColumns = true;
            } catch (Throwable $e) {
                $this->reliabilityColumns = false;
            }
        }
        return $this->reliabilityColumns;
    }

    /** Guarda el resumen de fiabilidad de una fila (Reliability). No hace nada si faltan las columnas. */
    public function saveReliability(int $id, ?array $reliability): void
    {
        $r = Reliability::forHistory($reliability);
        if ($r === null || !$this->hasReliability()) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE historial SET fiab_estado = ?, fiab_evaluados = ?, fiab_posibles = ? WHERE id = ?');
        $stmt->execute([mb_substr($r['estado'], 0, 10), min(65535, $r['evaluados']), min(65535, $r['posibles']), $id]);
    }

    private function reliabilitySelect(): string
    {
        return $this->hasReliability() ? ', fiab_estado, fiab_evaluados, fiab_posibles' : '';
    }

    /**
     * v4 · Añade el análisis del sitio entero a la fila de la URL buscada (una búsqueda = una fila del historial):
     * el informe guardado gana la clave 'sitio' y la fila, el nº de páginas. Devuelve false si la fila no existe.
     */
    public function attachSite(int $id, int $pages, array $siteReport): bool
    {
        $saved = $this->report($id);
        if (!$saved) {
            return false;
        }
        $report = $saved['informe'];
        $report['sitio'] = $siteReport;
        $this->saveReport($id, $report);
        $stmt = $this->pdo->prepare('UPDATE historial SET paginas = :paginas WHERE id = :id');
        $stmt->execute(['paginas' => max(0, min(65535, $pages)), 'id' => $id]);
        return true;
    }

    /**
     * v4 · Como report(), pero con el informe en JSON sin decodificar ('json'): un sitio de miles de páginas
     * ocupa decenas de MB como array de PHP y así se envía al navegador sin pasar por memoria dos veces.
     */
    public function reportJson(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, url, tipo, fecha, informe FROM historial WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['informe'] === null) {
            return null;
        }
        $raw = is_resource($r['informe']) ? stream_get_contents($r['informe']) : (string) $r['informe'];
        $json = @gzdecode($raw);
        if ($json === false || $json === '' || $json[0] !== '{') {
            return null;
        }
        return ['id' => (int) $r['id'], 'url' => (string) $r['url'], 'tipo' => (string) $r['tipo'],
            'fecha' => str_replace(' ', 'T', (string) $r['fecha']) . 'Z', 'json' => $json];
    }

    /** v3.0 · Informe guardado de un análisis, con su URL, tipo y fecha; null si no existe o no tiene informe. */
    public function report(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, url, tipo, fecha, informe FROM historial WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['informe'] === null) {
            return null;
        }
        $raw = is_resource($r['informe']) ? stream_get_contents($r['informe']) : (string) $r['informe'];
        $json = @gzdecode($raw);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        return ['id' => (int) $r['id'], 'url' => (string) $r['url'], 'tipo' => (string) $r['tipo'],
            'fecha' => str_replace(' ', 'T', (string) $r['fecha']) . 'Z', 'informe' => $data];
    }

    /** Análisis anteriores de la misma URL y tipo (sin el actual), del más reciente al más antiguo. */
    public function previous(string $url, int $excludeId, int $limit = 10, string $type = 'url'): array
    {
        $stmt = $this->pdo->prepare('SELECT id, (informe IS NOT NULL) AS tiene_informe, fecha, nota_seo, nota_ia, nota_accesibilidad, nota_global, incompleta,'
            . ' bloqueado, pagespeed, criticos, altos, medios, bajos, paginas' . $this->reliabilitySelect() . ' FROM historial'
            . ' WHERE url_clave = ? AND tipo = ? AND id <> ? ORDER BY fecha DESC, id DESC LIMIT ' . max(1, min(50, $limit)));
        $stmt->execute([self::urlKey($url), $type, $excludeId]);
        return array_map(function (array $r) use ($type) {
            // v4: id e informe, para comparar con la auditoría actual desde el informe
            return ['id' => (int) $r['id'], 'tiene_informe' => (int) $r['tiene_informe'] === 1]
                + self::rowOut($r) + ($type === 'sitio' ? ['paginas' => (int) $r['paginas']] : []);
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function rowOut(array $r): array
    {
        return (function (array $r) {
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
            // Fiabilidad de la nota (null en filas anteriores o si no se ha aplicado la migración)
            $out['fiabilidad'] = isset($r['fiab_estado']) && $r['fiab_estado'] !== ''
                ? ['estado' => (string) $r['fiab_estado'], 'evaluados' => (int) $r['fiab_evaluados'], 'posibles' => (int) $r['fiab_posibles']]
                : null;
            return $out;
        })($r);
    }
}
