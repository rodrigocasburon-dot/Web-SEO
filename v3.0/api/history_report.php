<?php
/**
 * v3.0 · POST api/history_report.php  (JSON: {"id": 123})
 * Devuelve el informe completo guardado de un análisis del historial, para volver a verlo
 * (index.php?informe=ID o sitio.php?informe=ID). Sin login, como el resto del historial.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../src/bootstrap.php';

Api::headers();

try {
    $input = Api::readJsonPost();
    $config = Config::load();
    $id = is_int($input['id'] ?? null) ? $input['id'] : 0;
    $history = History::enabled($config) ? History::fromConfig($config) : null;
    if (!$history) {
        Api::fail(404, 'HISTORY_DISABLED', 'El historial no está activado.');
    }
    $report = $id > 0 ? $history->report($id) : null;
    if (!$report) {
        Api::fail(404, 'NOT_FOUND', 'Este análisis no tiene un informe guardado (puede ser anterior a la v3.0 o haberse borrado a los ' . History::months($config) . ' meses).');
    }
    Api::respond(200, ['ok' => true] + $report);
} catch (Throwable $e) {
    error_log('[history_report] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    Api::fail(500, 'INTERNAL_ERROR', 'Error interno del analizador.');
}
