<?php
// Calibración de la normalización frente a una referencia externa del sector (Lighthouse, vía PageSpeed Insights).
// CON internet. No forma parte de tests/run.php. Ver docs/CALIBRACION.md.
//
//   php tests/calibracion.php --maximos                       resta máxima por área según el catálogo
//   php tests/calibracion.php --medir <corpus.txt> <salida.json> [--clave]
//        analiza cada URL con el Radar (escala original + C08 y C14 de PageSpeed) y guarda las categorías SEO y
//        Accesibilidad de Lighthouse. Sin --clave usa PageSpeed sin clave (cuota baja); con --clave, la clave de la
//        configuración del servidor (nunca se imprime ni se guarda).
//   php tests/calibracion.php --ajustar <salida.json>          correlación, error y referencia que mejor ajusta
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
error_reporting(E_ALL);
require __DIR__ . '/../src/bootstrap.php';

$args = array_slice($argv, 1);
$mode = $args[0] ?? '';

/** Resta máxima por área: cada comprobación que puede restar, fallando una vez con su gravedad por defecto. */
function calMaxPenalty(): array
{
    $matrix = require __DIR__ . '/../config/weights.php';
    $max = ['seo' => 0.0, 'ia' => 0.0, 'accesibilidad' => 0.0];
    foreach (CheckRunner::catalog() as $id => $check) {
        $sev = (string) ($check->describe(BaseCheck::NO_DATA)['severidad'] ?? '');
        if (in_array($id, ['C29', 'C31'], true) || !isset($matrix[$id], Scorer::PENALTIES[$sev])) {
            continue;
        }
        foreach (Scorer::AREAS as $i => $a) {
            $max[$a] += Scorer::PENALTIES[$sev] * $matrix[$id][$i];
        }
    }
    return array_map(fn($v) => round($v, 2), $max);
}

/** Correlación de Spearman (rangos medios en los empates). */
function calSpearman(array $x, array $y): ?float
{
    $n = count($x);
    if ($n < 3) {
        return null;
    }
    $rank = function (array $v): array {
        $idx = array_keys($v);
        usort($idx, fn($a, $b) => $v[$a] <=> $v[$b]);
        $r = [];
        for ($i = 0; $i < count($idx);) {
            $j = $i;
            while ($j + 1 < count($idx) && $v[$idx[$j + 1]] == $v[$idx[$i]]) {
                $j++;
            }
            for ($k = $i; $k <= $j; $k++) {
                $r[$idx[$k]] = ($i + $j) / 2 + 1;
            }
            $i = $j + 1;
        }
        ksort($r);
        return array_values($r);
    };
    $rx = $rank(array_values($x));
    $ry = $rank(array_values($y));
    $mx = array_sum($rx) / $n;
    $my = array_sum($ry) / $n;
    $num = 0.0;
    $dx = 0.0;
    $dy = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $num += ($rx[$i] - $mx) * ($ry[$i] - $my);
        $dx += ($rx[$i] - $mx) ** 2;
        $dy += ($ry[$i] - $my) ** 2;
    }
    return $dx > 0 && $dy > 0 ? $num / sqrt($dx * $dy) : null;
}

if ($mode === '--maximos') {
    echo json_encode(calMaxPenalty(), JSON_PRETTY_PRINT), "\n";
    exit(0);
}

if ($mode === '--medir') {
    $corpus = $args[1] ?? '';
    $outFile = $args[2] ?? '';
    if (!is_file($corpus) || $outFile === '') {
        fwrite(STDERR, "Uso: php tests/calibracion.php --medir <corpus.txt> <salida.json> [--clave]\n");
        exit(2);
    }
    $config = Config::load();
    $key = in_array('--clave', $args, true) ? (string) ($config['psi_api_key'] ?? '') : '';
    $urls = array_values(array_filter(array_map('trim', file($corpus)), fn($l) => $l !== '' && $l[0] !== '#'));
    // Lighthouse: además de lo que pide el Radar, la categoría SEO y la puntuación de cada categoría
    $transport = function (string $url, int $timeout) {
        $url = str_replace('&category=ACCESSIBILITY', '&category=ACCESSIBILITY&category=SEO', $url);
        $url = preg_replace('/(&fields=)([^&]*)/', '$1$2' . rawurlencode(',lighthouseResult/categories'), $url);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_PROXY => '']);
        $body = (string) curl_exec($ch);
        $r = ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body, 'errno' => curl_errno($ch), 'error' => curl_error($ch)];
        curl_close($ch);
        return $r;
    };
    $psi = new PsiClient($key, 90, (string) ($config['user_agent'] ?? 'ProconsiAnalyzer/0.1'), $transport);
    $rows = [];
    foreach ($urls as $i => $url) {
        fwrite(STDERR, sprintf("[%d/%d] %s\n", $i + 1, count($urls), $url));
        $row = ['url' => $url];
        try {
            $r = (new Analyzer($config))->analyze($url, []);
        } catch (Throwable $e) {
            $rows[] = $row + ['error' => 'radar: ' . $e->getMessage()];
            continue;
        }
        if (!empty($r['blocked'])) {
            $rows[] = $row + ['error' => 'radar: acceso bloqueado'];
            continue;
        }
        $checks = $r['checks'];
        $p = $psi->run($url);
        $lh = null;
        if ($p['ok']) {
            $ctx = CheckContext::forPsi($url, $p['data'], null, $config);
            $byId = [];
            foreach ($checks as $c) {
                $byId[$c['id']] = $c;
            }
            foreach ((new CheckRunner())->runPsi($ctx) as $c) {
                $byId[$c['id']] = $c;
            }
            $checks = array_values($byId);
            $cats = $p['data']['lighthouseResult']['categories'] ?? [];
            $lh = [
                'seo' => isset($cats['seo']['score']) ? round($cats['seo']['score'] * 100) : null,
                'accesibilidad' => isset($cats['accessibility']['score']) ? round($cats['accessibility']['score'] * 100) : null,
                'rendimiento' => isset($cats['performance']['score']) ? round($cats['performance']['score'] * 100) : null,
            ];
        } else {
            $row['error_psi'] = $p['error']['code'] ?? 'PSI';
        }
        $raw = Scorer::raw()->score($checks);
        $row['resta'] = [];
        $row['sin_datos'] = [];
        foreach (Scorer::AREAS as $a) {
            $row['resta'][$a] = round($raw['areas'][$a]['penalizacion'], 3);
            $row['sin_datos'][$a] = $raw['areas'][$a]['sin_datos'] . '/' . $raw['areas'][$a]['comprobaciones'];
        }
        $row['fallos'] = array_values(array_map(fn($c) => $c['id'] . ':' . $c['severidad'] . (($c['ocurrencias'] ?? 1) > 1 ? 'x' . $c['ocurrencias'] : ''),
            array_filter($checks, fn($c) => ($c['estado'] ?? '') === 'NO_CUMPLE')));
        $row['lighthouse'] = $lh;
        $rows[] = $row;
        file_put_contents($outFile, json_encode(['fecha' => date('c'), 'maximos' => calMaxPenalty(), 'sitios' => $rows],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        sleep(2);   // cuota de PageSpeed
    }
    fwrite(STDERR, "Listo: $outFile\n");
    exit(0);
}

if ($mode === '--ajustar') {
    $d = json_decode((string) file_get_contents($args[1] ?? ''), true);
    if (!is_array($d)) {
        fwrite(STDERR, "Uso: php tests/calibracion.php --ajustar <salida.json>\n");
        exit(2);
    }
    $max = $d['maximos'];
    $pairs = ['seo' => 'seo', 'accesibilidad' => 'accesibilidad'];
    $out = [];
    foreach ($pairs as $area => $lhKey) {
        $pen = [];
        $ref = [];
        foreach ($d['sitios'] as $s) {
            if (isset($s['resta'][$area], $s['lighthouse'][$lhKey])) {
                $pen[] = $s['resta'][$area];
                $ref[] = $s['lighthouse'][$lhKey];
            }
        }
        $n = count($pen);
        $score = fn(float $R) => array_map(fn($p) => max(0.0, 100 - $p * $R / $max[$area]), $pen);
        $mae = function (array $a) use ($ref, $n) {
            $t = 0.0;
            foreach ($a as $i => $v) {
                $t += abs($v - $ref[$i]);
            }
            return $n ? $t / $n : null;
        };
        $best = null;
        for ($R = 5; $R <= 200; $R += 0.5) {
            $e = $mae($score($R));
            if ($best === null || $e < $best[1] - 1e-9) {
                $best = [$R, $e];
            }
        }
        $out[$area] = [
            'n' => $n,
            'spearman' => $n ? round((float) calSpearman(array_map(fn($p) => -$p, $pen), $ref), 3) : null,
            'error_medio_con_60' => $n ? round($mae($score(60)), 1) : null,
            'referencia_ajustada' => $best[0] ?? null,
            'error_medio_ajustado' => isset($best[1]) ? round($best[1], 1) : null,
            'media_lighthouse' => $n ? round(array_sum($ref) / $n, 1) : null,
            'media_radar_con_60' => $n ? round(array_sum($score(60)) / $n, 1) : null,
        ];
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

fwrite(STDERR, "Uso: php tests/calibracion.php --maximos | --medir <corpus> <salida> [--clave] | --ajustar <salida>\n");
exit(2);
