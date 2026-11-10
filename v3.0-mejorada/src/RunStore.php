<?php
/**
 * v3.0 · Almacén temporal (1 hora) de resultados calculados por el servidor durante un análisis, en storage/runs/.
 * Sirve para guardar el informe completo en el historial con datos del servidor y no con lo que envía el navegador:
 * psi.php deja aquí su respuesta, site_page.php cada página, browser.php las pruebas con Chrome, y después
 * score.php o site_record.php los recogen por su firma. storage/ no es accesible por web ni se sube a git.
 *
 * Escritura atómica: se escribe en un archivo temporal y se renombra, de modo que un lector nunca ve un
 * archivo a medias. put() devuelve false si no se pudo guardar (y lo anota en el registro de errores de PHP).
 *
 * También lleva las «reservas» de un solo uso (claim/confirm/release) con las que api/site_record.php
 * garantiza que un análisis se guarda una vez y solo se da por guardado cuando la base de datos lo confirma.
 */
class RunStore
{
    public const TTL = 3600;
    /** Segundos entre limpiezas automáticas de lo caducado */
    private const PURGE_EVERY = 600;
    /** Una reserva sin confirmar más antigua que esto se considera abandonada (petición interrumpida) */
    public const CLAIM_STALE = 120;

    private static function dir(): string
    {
        return (getenv('WEBSEO_STORAGE_DIR') ?: __DIR__ . '/../storage') . '/runs';
    }

    private static function ensureDir(): ?string
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            error_log('[runstore] No se puede crear el directorio temporal de análisis.');
            return null;
        }
        return $dir;
    }

    private static function file(string $kind, string $key): string
    {
        return self::dir() . '/' . preg_replace('/[^a-z]/', '', $kind) . '_' . hash('sha256', $key) . '.json';
    }

    /** Guarda un resultado. Devuelve false si no se pudo escribir. */
    public static function put(string $kind, string $key, array $data): bool
    {
        $dir = self::ensureDir();
        if ($dir === null) {
            return false;
        }
        self::purgeIfDue($dir);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            error_log('[runstore] No se puede codificar el resultado (' . preg_replace('/[^a-z]/', '', $kind) . ').');
            return false;
        }
        return self::writeAtomic(self::file($kind, $key), $json);
    }

    /** Escribe en un temporal del mismo directorio y lo renombra sobre el destino. */
    private static function writeAtomic(string $file, string $content): bool
    {
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $written = @file_put_contents($tmp, $content, LOCK_EX);
        if ($written !== strlen($content)) {
            @unlink($tmp);
            error_log('[runstore] Escritura incompleta en el almacén temporal (¿disco lleno o sin permisos?).');
            return false;
        }
        // En Windows rename() puede fallar un instante si otro proceso está leyendo el destino
        for ($i = 0; $i < 5; $i++) {
            if (@rename($tmp, $file)) {
                return true;
            }
            usleep(20000);
        }
        @unlink($tmp);
        error_log('[runstore] No se puede renombrar el archivo temporal del almacén.');
        return false;
    }

    public static function get(string $kind, string $key): ?array
    {
        $file = self::file($kind, $key);
        if ($key === '' || !is_file($file) || filemtime($file) < time() - self::TTL) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /** Segundos desde que se guardó (para el tiempo total del análisis de sitio). */
    public static function age(string $kind, string $key): ?int
    {
        $file = self::file($kind, $key);
        return is_file($file) ? max(0, time() - (int) filemtime($file)) : null;
    }

    // ---- Reservas de un solo uso ------------------------------------------------------------

    private static function claimFile(string $key): string
    {
        return self::dir() . '/claim_' . hash('sha256', $key) . '.json';
    }

    /**
     * Reserva una operación de un solo uso. Devuelve:
     *  - 'ok'        la reserva es de esta petición (debe terminar con confirm() o release());
     *  - 'en_curso'  otra petición la está haciendo ahora mismo;
     *  - 'hecho'     ya se completó (confirm()).
     * Una reserva sin confirmar de más de CLAIM_STALE segundos se considera abandonada y se puede retomar.
     */
    public static function claim(string $key, ?int $now = null): string
    {
        if (self::ensureDir() === null) {
            throw new RuntimeException('No se puede escribir en el almacén temporal.');
        }
        $now = $now ?? time();
        $file = self::claimFile($key);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            // 'x': creación exclusiva; si dos peticiones llegan a la vez, solo una la crea
            $fh = @fopen($file, 'x');
            if ($fh) {
                fwrite($fh, json_encode(['estado' => 'pendiente', 't' => $now]));
                fclose($fh);
                return 'ok';
            }
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data) && ($data['estado'] ?? '') === 'hecho') {
                return 'hecho';
            }
            $since = is_array($data) ? (int) ($data['t'] ?? 0) : (int) @filemtime($file);
            if ($since > $now - self::CLAIM_STALE) {
                return 'en_curso';
            }
            @unlink($file);   // abandonada: se retoma
        }
        return 'en_curso';
    }

    /** Marca la reserva como completada: la operación ya no se puede repetir. */
    public static function confirm(string $key, array $info = []): bool
    {
        $json = json_encode(['estado' => 'hecho', 't' => time()] + $info);
        return self::writeAtomic(self::claimFile($key), (string) $json);
    }

    /** Libera una reserva sin completar (la operación falló y se puede reintentar). */
    public static function release(string $key): void
    {
        $file = self::claimFile($key);
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || ($data['estado'] ?? '') !== 'hecho') {
            @unlink($file);
        }
    }

    /** Datos guardados con confirm() (p. ej. el id del historial); null si no está completada. */
    public static function claimInfo(string $key): ?array
    {
        $data = json_decode((string) @file_get_contents(self::claimFile($key)), true);
        return is_array($data) && ($data['estado'] ?? '') === 'hecho' ? $data : null;
    }

    // ---- Limpieza ---------------------------------------------------------------------------

    /** Limpia como mucho cada PURGE_EVERY segundos (no depende del azar). */
    private static function purgeIfDue(string $dir): void
    {
        $mark = $dir . '/.purga';
        $last = (int) @filemtime($mark);
        if ($last > time() - self::PURGE_EVERY) {
            return;
        }
        @touch($mark);
        self::purge();
    }

    /**
     * Borra resultados, reservas y temporales caducados. La llama put() cada cierto tiempo y también
     * bin/limpiar.php (tarea programada), para que la limpieza no dependa de que lleguen peticiones.
     * @return int archivos borrados
     */
    public static function purge(?int $now = null): int
    {
        $now = $now ?? time();
        $dir = self::dir();
        $deleted = 0;
        foreach (array_merge(glob($dir . '/*.json') ?: [], glob($dir . '/*.tmp') ?: []) as $old) {
            // Los .tmp solo viven milisegundos: si llevan un minuto, son restos de una escritura interrumpida
            $ttl = substr($old, -4) === '.tmp' ? 60 : self::TTL;
            if ((int) @filemtime($old) < $now - $ttl && @unlink($old)) {
                $deleted++;
            }
        }
        // Marcas del formato anterior (storage/site_records/*.used)
        foreach (glob(dirname($dir) . '/site_records/*.used') ?: [] as $old) {
            if ((int) @filemtime($old) < $now - self::TTL && @unlink($old)) {
                $deleted++;
            }
        }
        return $deleted;
    }
}
