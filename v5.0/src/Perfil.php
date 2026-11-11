<?php
/**
 * Perfiles de lectura del informe (marketing, dirección, desarrollo web): validación y datos para la pantalla.
 *
 * El perfil llega de la persona usuaria (?perfil=): se valida con lista blanca y cualquier otro valor cae al
 * perfil por defecto, sin reflejarlo. El perfil nunca entra en el análisis, la puntuación ni el historial:
 * solo cambia cómo se explica el mismo resultado (config/perfiles.php y lang/perfiles.php).
 */
class Perfil
{
    /** Lista blanca: no depende de ningún archivo, para que un error de configuración no abra la puerta. */
    public const VALIDOS = ['marketing', 'direccion', 'desarrollo'];
    public const RELEVANCIAS = ['alta', 'media', 'baja'];

    private static ?array $config = null;
    private static ?array $texts = null;

    public static function config(): array
    {
        if (self::$config === null) {
            self::$config = (array) require __DIR__ . '/../config/perfiles.php';
        }
        return self::$config;
    }

    public static function texts(): array
    {
        if (self::$texts === null) {
            self::$texts = (array) require __DIR__ . '/../lang/perfiles.php';
        }
        return self::$texts;
    }

    public static function defaultProfile(): string
    {
        $d = (string) (self::config()['por_defecto'] ?? '');
        return in_array($d, self::VALIDOS, true) ? $d : 'desarrollo';
    }

    /** Valor recibido → perfil válido. Cualquier cosa que no esté en la lista blanca (incluidos arrays) → por defecto. */
    public static function normalize($value): string
    {
        return is_string($value) && in_array($value, self::VALIDOS, true) ? $value : self::defaultProfile();
    }

    /** Rango de una nota (0–100) con los umbrales de config/perfiles.php; null si no hay nota. */
    public static function range(?float $score): ?array
    {
        if ($score === null) {
            return null;
        }
        $v = round($score, 1);
        foreach (self::config()['rangos'] as $r) {
            if ($v >= (float) $r['desde']) {
                return ['clave' => (string) $r['clave'], 'texto' => (string) $r['texto']];
            }
        }
        return null;
    }

    /**
     * Datos para la pantalla (index.php → i18n): perfiles, rangos, relevancias y textos. Cada relevancia se
     * normaliza (un valor desconocido cuenta como «media»).
     */
    public static function forScreen(): array
    {
        $cfg = self::config();
        $txt = self::texts();
        $rel = [];
        foreach ((array) ($cfg['relevancia'] ?? []) as $id => $values) {
            foreach (self::VALIDOS as $i => $p) {
                $v = (string) ($values[$i] ?? 'media');
                $rel[$id][$p] = in_array($v, self::RELEVANCIAS, true) ? $v : 'media';
            }
        }
        $profiles = [];
        foreach (self::VALIDOS as $p) {
            $profiles[$p] = [
                'nombre' => (string) ($txt['perfiles'][$p]['nombre'] ?? $p),
                'descripcion' => (string) ($txt['perfiles'][$p]['descripcion'] ?? ''),
            ];
        }
        return [
            'por_defecto' => self::defaultProfile(),
            'lista' => self::VALIDOS,
            'perfiles' => $profiles,
            'rangos' => array_values((array) ($cfg['rangos'] ?? [])),
            'aviso_sin_datos' => (float) ($cfg['aviso_sin_datos'] ?? 0.25),
            'relevancia' => $rel,
            'respaldo' => (array) ($txt['respaldo'] ?? []),
            'puntos' => (array) ($txt['puntos'] ?? []),
            'glosario' => (array) ($txt['glosario'] ?? []),
        ];
    }

    /**
     * Variantes que faltan: [['id' => 'C08', 'perfil' => 'marketing', 'campos' => ['hacer']], …]. Los campos
     * de desarrollo «significa» y «hacer» salen de lang/es.php, así que solo se exigen «importa» y «comprobar».
     * @param string[] $ids IDs que deben tener texto (comprobaciones del catálogo y puntos WEB)
     */
    public static function missing(array $ids): array
    {
        $required = [
            'marketing' => ['significa', 'importa', 'hacer'],
            'direccion' => ['significa', 'importa', 'hacer'],
            'desarrollo' => ['importa', 'comprobar'],
        ];
        $points = (array) (self::texts()['puntos'] ?? []);
        $out = [];
        foreach ($ids as $id) {
            foreach ($required as $p => $fields) {
                $miss = [];
                foreach ($fields as $f) {
                    if (trim((string) ($points[$id][$p][$f] ?? '')) === '') {
                        $miss[] = $f;
                    }
                }
                if ($miss) {
                    $out[] = ['id' => $id, 'perfil' => $p, 'campos' => $miss];
                }
            }
        }
        return $out;
    }
}
