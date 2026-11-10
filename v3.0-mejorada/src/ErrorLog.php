<?php
/**
 * Registro técnico de errores internos, en storage/logs/errores.log (no accesible por web ni subido a git).
 *
 * Cada error recibe un identificador corto (E-xxxxxxxx). A la persona que usa la herramienta solo se le
 * muestra un mensaje fijo con ese identificador; el detalle técnico queda aquí para quien la mantiene.
 *
 * Qué se anota: fecha (UTC), identificador, contexto (p. ej. «check:C04» o «api:analyze»), clase de la
 * excepción, archivo y línea del código propio y el mensaje saneado. Qué NO se anota: la URL analizada,
 * contenido de la web, direcciones IP de quien usa la herramienta, datos de conexión ni claves. Los
 * mensajes de la base de datos (PDOException) se reducen a su código SQLSTATE porque pueden incluir
 * el usuario o el servidor de la conexión.
 */
class ErrorLog
{
    private const MAX_BYTES = 1048576;   // al superarlo, el archivo se rota (se conserva una copia: errores.log.1)

    public static function file(): string
    {
        return (getenv('WEBSEO_STORAGE_DIR') ?: __DIR__ . '/../storage') . '/logs/errores.log';
    }

    public static function newId(): string
    {
        return 'E-' . bin2hex(random_bytes(4));
    }

    /** Anota el error y devuelve su identificador. Nunca lanza excepciones. */
    public static function record(string $context, Throwable $e): string
    {
        $id = self::newId();
        try {
            $line = sprintf(
                "%s\t%s\t%s\t%s\t%s:%d\t%s\n",
                gmdate('Y-m-d\TH:i:s\Z'),
                $id,
                preg_replace('/[^A-Za-z0-9:_.-]/', '', $context),
                get_class($e),
                basename($e->getFile()),
                $e->getLine(),
                self::sanitize($e)
            );
            self::append($line);
        } catch (Throwable $ignored) {
            // El registro de errores no debe provocar otro error
        }
        return $id;
    }

    /** Anota un error fatal de PHP (no es una excepción). */
    public static function recordFatal(string $context, array $error): string
    {
        $id = self::newId();
        try {
            self::append(sprintf(
                "%s\t%s\t%s\t%s\t%s:%d\t%s\n",
                gmdate('Y-m-d\TH:i:s\Z'),
                $id,
                preg_replace('/[^A-Za-z0-9:_.-]/', '', $context),
                'FatalError(' . (int) ($error['type'] ?? 0) . ')',
                basename((string) ($error['file'] ?? '')),
                (int) ($error['line'] ?? 0),
                self::clean((string) ($error['message'] ?? ''))
            ));
        } catch (Throwable $ignored) {
        }
        return $id;
    }

    /** Mensaje fijo que se muestra a la persona: sin detalles de PHP. */
    public static function userMessage(string $id): string
    {
        return 'Error interno de la herramienta (referencia ' . $id . ').';
    }

    private static function sanitize(Throwable $e): string
    {
        if ($e instanceof PDOException) {
            return 'Error de base de datos, SQLSTATE ' . preg_replace('/[^A-Za-z0-9]/', '', (string) $e->getCode());
        }
        return self::clean($e->getMessage());
    }

    /** Quita URL, rutas absolutas y saltos de línea, y recorta. */
    private static function clean(string $message): string
    {
        $m = preg_replace('#\b[a-z][a-z0-9+.-]*://\S+#i', '[url]', $message);
        $m = preg_replace('#(?:[A-Za-z]:)?[\\\\/](?:[^\\\\/\s:*?"<>|]+[\\\\/])+#', '', (string) $m);   // rutas del servidor
        $m = preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string) $m);
        return mb_substr(trim((string) $m), 0, 300);
    }

    private static function append(string $line): void
    {
        $file = self::file();
        $dir = dirname($file);
        if ((!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir))) {
            error_log('[radar-web] ' . trim($line));   // sin storage/ se usa el registro de PHP
            return;
        }
        if (is_file($file) && (int) @filesize($file) > self::MAX_BYTES) {
            @rename($file, $file . '.1');
        }
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('[radar-web] ' . trim($line));
        }
    }
}
