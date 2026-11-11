<?php
/**
 * v3.0 · Almacén temporal de resultados calculados por el servidor durante un análisis, en storage/runs/.
 * Sirve para guardar el informe completo en el historial con datos del servidor y no con lo que envía el navegador:
 * psi.php deja aquí su respuesta, site_page.php cada página, browser.php las pruebas con Chrome, y después
 * score.php o site_record.php los recogen por su firma. storage/ no es accesible por web ni se sube a git.
 * v4: se guarda 4 horas, porque analizar un sitio entero (miles de páginas) puede llevar bastante tiempo.
 */
class RunStore
{
    public const TTL = 14400;

    private static function dir(): string
    {
        return (getenv('WEBSEO_STORAGE_DIR') ?: __DIR__ . '/../storage') . '/runs';
    }

    private static function file(string $kind, string $key): string
    {
        return self::dir() . '/' . preg_replace('/[^a-z]/', '', $kind) . '_' . hash('sha256', $key) . '.json';
    }

    public static function put(string $kind, string $key, array $data): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        // Limpieza ocasional de lo caducado
        if (mt_rand(1, 200) === 1) {
            foreach (glob($dir . '/*.json') ?: [] as $old) {
                if (@filemtime($old) < time() - self::TTL) {
                    @unlink($old);
                }
            }
        }
        @file_put_contents(self::file($kind, $key),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
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
}
