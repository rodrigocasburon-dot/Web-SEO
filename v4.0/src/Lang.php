<?php
/** Acceso a los textos de lang/es.php. */
class Lang
{
    private static ?array $data = null;

    public static function all(): array
    {
        if (self::$data === null) {
            self::$data = (array) require __DIR__ . '/../lang/es.php';
        }
        return self::$data;
    }

    /** Campo de texto de una comprobación (nombre, descripcion, recomendacion, recomendacion_aviso). */
    public static function check(string $id, string $key): string
    {
        return (string) (self::all()['checks'][$id][$key] ?? '');
    }
}
