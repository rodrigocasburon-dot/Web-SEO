<?php
/**
 * POST api/score.php  (JSON: {"checks": [{"id", "estado", "ocurrencias", "aplica"}, ...], "bloqueado": false})
 * Recalcula la puntuación y el informe cuando llegan C08 y C14 desde api/psi.php, con el mismo Scorer
 * y el mismo Report del servidor (una sola implementación). No descarga nada.
 * Con "historial": {"id", "firma_analisis", "firma_psi"} actualiza además la fila del historial (Fase 4).
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
    $blocked = ($input['bloqueado'] ?? false) === true;

    // Historial (Fase 4): se actualiza con la nota final solo si las firmas de analyze.php y psi.php son válidas
    $updated = false;
    $h = $input['historial'] ?? null;
    if (is_array($h) && is_int($h['id'] ?? null) && is_string($h['firma_analisis'] ?? null) && is_string($h['firma_psi'] ?? null)) {
        $history = History::fromConfig($config);
        if ($history) {
            try {
                // Nota final e informe completado se guardan en una transacción: si el informe no se puede
                // actualizar, la fila conserva la nota sin PageSpeed, que es la que corresponde a su informe.
                $updated = $history->atomic(function (History $history) use ($h, $records, $score, $blocked, $config): bool {
                    if (!$history->finalize($h['id'], $records, $h['firma_analisis'], $h['firma_psi'], $score, $blocked)) {
                        return false;
                    }
                    // v3.0: el informe guardado se completa con PageSpeed, con datos del servidor (no de esta petición)
                    $saved = $history->report($h['id']);
                    $psi = RunStore::get('psi', $h['firma_psi']);
                    if ($saved && $psi && is_array($saved['informe']['data']['checks'] ?? null)) {
                        $rep = $saved['informe'];
                        $merged = array_merge(History::split($rep['data']['checks'])[0], (array) $psi['checks']);
                        $finalScore = (new Scorer())->score($merged);
                        $rep['data']['checks'] = $merged;
                        $rep['data']['puntuacion'] = $finalScore;
                        $rep['data']['informe'] = Report::build($merged, $finalScore, !empty($rep['data']['blocked']),
                            (int) ($config['simulator_top_n'] ?? Simulator::DEFAULT_TOP),
                            ['truncado' => !empty($rep['data']['fetch']['truncated']), 'umbrales' => (array) ($config['fiabilidad'] ?? [])]);
                        $rep['psi'] = $psi;
                        $rep['psiMerged'] = true;
                        $rep['psiFailure'] = null;
                        $history->saveReport($h['id'], $rep);
                        $history->saveReliability($h['id'], $rep['data']['informe']['fiabilidad']);
                    } else {
                        // Sin informe guardado (filas anteriores a la v3.0): fiabilidad a partir de los estados firmados
                        $history->saveReliability($h['id'], Reliability::build($records, $blocked));
                    }
                    return true;
                });
            } catch (Throwable $e) {
                $updated = false;
                ErrorLog::record('historial:score', $e);
            }
        }
    }

    Api::respond(200, [
        'ok' => true,
        'historial_actualizado' => $updated,
        'puntuacion' => $score,
        // 'truncado' lo indica el navegador (lo recibió de analyze.php); solo afecta al texto de fiabilidad de
        // esta respuesta: lo que se guarda en el historial se calcula arriba con los datos del servidor
        'informe' => Report::build($records, $score, $blocked,
            (int) ($config['simulator_top_n'] ?? Simulator::DEFAULT_TOP),
            ['truncado' => ($input['truncado'] ?? false) === true, 'umbrales' => (array) ($config['fiabilidad'] ?? [])]),
    ]);
} catch (Throwable $e) {
    Api::internalError('score', $e);
}
