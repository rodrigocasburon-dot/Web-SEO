<?php
/**
 * v3.0 · POST api/history_report.php  (JSON: {"id": 123})
 * Devuelve el informe completo guardado de un análisis del historial, para volver a verlo
 * (index.php?informe=ID). Sin login, como el resto del historial.
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
    $report = $id > 0 ? $history->reportJson($id) : null;
    if (!$report) {
        Api::fail(404, 'NOT_FOUND', 'Este análisis no tiene un informe guardado (puede ser anterior a la v3.0 o haberse borrado a los ' . History::months($config) . ' meses).');
    }
    // El informe ya es JSON (lo generó el servidor al guardarlo): se envía tal cual, sin decodificarlo
    $meta = json_encode(['ok' => true, 'id' => $report['id'], 'url' => $report['url'], 'tipo' => $report['tipo'], 'fecha' => $report['fecha']],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    http_response_code(200);
    echo substr((string) $meta, 0, -1) . ',"informe":' . $report['json'] . '}';
    exit;
} catch (Throwable $e) {
    error_log('[history_report] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    Api::fail(500, 'INTERNAL_ERROR', 'Error interno del analizador.');
}
