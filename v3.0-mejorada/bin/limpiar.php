<?php
/**
 * Limpieza periódica de datos temporales, independiente de que lleguen peticiones.
 * Uso (solo consola): php bin/limpiar.php
 * Programarla cada 15–60 minutos (Programador de tareas de Windows o cron); ver README.
 *
 * Borra: resultados temporales y reservas caducados (storage/runs), contadores del límite de uso sin
 * actividad (storage/ratelimit) y, si el historial está activado, las filas de más de `meses` meses.
 * No imprime claves ni datos de los análisis.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../src/bootstrap.php';

$config = Config::load();
$storage = getenv('WEBSEO_STORAGE_DIR') ?: __DIR__ . '/../storage';
$report = [];

$report[] = 'Temporales de análisis borrados: ' . RunStore::purge();

// Perfiles temporales de Chrome abandonados (sesiones interrumpidas) y sus procesos
class_exists('Navegador');
$report[] = 'Perfiles de Chrome abandonados borrados: ' . Navegador::limpiar_huerfanos();

// Registro técnico de errores: se conserva 90 días
$logs = 0;
foreach (glob($storage . '/logs/errores.log*') ?: [] as $log) {
    if ((int) @filemtime($log) < time() - 90 * 86400 && @unlink($log)) {
        $logs++;
    }
}
$report[] = 'Registros de errores antiguos borrados: ' . $logs;

$window = (int) ($config['rate_limit']['window_seconds'] ?? 600);
$limiter = new RateLimiter($storage . '/ratelimit', 1, $window);
$before = count(glob($storage . '/ratelimit/rl_*.json') ?: []);
$limiter->cleanup();
$report[] = 'Contadores de límite de uso borrados: ' . ($before - count(glob($storage . '/ratelimit/rl_*.json') ?: []));

if (History::enabled($config)) {
    $history = History::fromConfig($config);
    if ($history) {
        try {
            $report[] = 'Filas del historial caducadas borradas: ' . $history->purgeExpired();
        } catch (Throwable $e) {
            $report[] = 'Historial: no se ha podido limpiar (ver el registro de errores).';
            ErrorLog::record('limpiar', $e);
        }
    } else {
        $report[] = 'Historial: no se ha podido conectar.';
    }
}

echo implode(PHP_EOL, $report), PHP_EOL;
