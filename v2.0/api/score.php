<?php
/**
 * POST api/score.php  (JSON: {"checks": [{"id", "estado", "ocurrencias", "aplica"}, ...], "bloqueado": false})
 * Recalcula la puntuación y el informe cuando llegan C08 y C14 desde api/psi.php, con el mismo Scorer
 * y el mismo Report del servidor (una sola implementación). No descarga nada.
 * Gravedad, reparto entre áreas, recomendación, impacto y esfuerzo se toman siempre del servidor.
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

    $catalog = CheckRunner::catalog();
    $states = [BaseCheck::OK, BaseCheck::FAIL, BaseCheck::WARN, BaseCheck::NO_DATA];

    $records = [];
    $seen = [];
    foreach ($list as $c) {
        $id = is_array($c) && is_string($c['id'] ?? null) ? $c['id'] : '';
        $estado = is_array($c) && is_string($c['estado'] ?? null) ? $c['estado'] : '';
        if (!isset($catalog[$id]) || !in_array($estado, $states, true) || isset($seen[$id])) {
            Api::fail(400, 'INVALID_REQUEST', 'La petición no es válida.');
        }
        $seen[$id] = true;   // cada comprobación se evalúa una sola vez
        // Gravedad, reparto, recomendación, impacto y esfuerzo salen de la clase, no de la petición
        $records[] = $catalog[$id]->describe(
            $estado,
            max(0, min(100000, (int) ($c['ocurrencias'] ?? 0))),
            ($c['aplica'] ?? true) !== false
        );
    }

    $score = (new Scorer())->score($records);
    $config = Config::load();
    Api::respond(200, [
        'ok' => true,
        'puntuacion' => $score,
        'informe' => Report::build($records, $score, ($input['bloqueado'] ?? false) === true,
            (int) ($config['simulator_top_n'] ?? Simulator::DEFAULT_TOP)),
    ]);
} catch (Throwable $e) {
    error_log('[score] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    Api::fail(500, 'INTERNAL_ERROR', 'Error interno del analizador.');
}
