<?php
/**
 * POST api/score.php  (JSON: {"checks": [{"id", "estado", "ocurrencias", "aplica"}, ...]})
 * Recalcula la puntuación cuando llegan C08 y C14 desde api/psi.php, con el mismo Scorer del servidor
 * (una sola implementación de la fórmula). No descarga nada.
 * Gravedad y reparto entre áreas se toman siempre del servidor, no de la petición.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../src/bootstrap.php';

Api::headers();

try {
    $input = Api::readJsonPost(16384);
    $list = $input['checks'] ?? null;
    if (!is_array($list) || count($list) > 50) {
        Api::fail(400, 'INVALID_REQUEST', 'La petición no es válida.');
    }

    $severities = [];
    foreach (array_merge(CheckRunner::checks(), CheckRunner::psiChecks()) as $check) {
        $severities[$check->id()] = $check->severity();
    }
    $states = [BaseCheck::OK, BaseCheck::FAIL, BaseCheck::WARN, BaseCheck::NO_DATA];

    $records = [];
    $seen = [];
    foreach ($list as $c) {
        $id = is_array($c) && is_string($c['id'] ?? null) ? $c['id'] : '';
        $estado = is_array($c) && is_string($c['estado'] ?? null) ? $c['estado'] : '';
        if (!isset($severities[$id]) || !in_array($estado, $states, true) || isset($seen[$id])) {
            Api::fail(400, 'INVALID_REQUEST', 'La petición no es válida.');
        }
        $seen[$id] = true;   // cada comprobación se evalúa una sola vez
        $records[] = [
            'id' => $id,
            'estado' => $estado,
            'severidad' => $severities[$id],
            'ocurrencias' => max(0, min(100000, (int) ($c['ocurrencias'] ?? 0))),
            'aplica' => ($c['aplica'] ?? true) !== false,
        ];
    }

    Api::respond(200, ['ok' => true, 'puntuacion' => (new Scorer())->score($records)]);
} catch (Throwable $e) {
    error_log('[score] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    Api::fail(500, 'INTERNAL_ERROR', 'Error interno del analizador.');
}
