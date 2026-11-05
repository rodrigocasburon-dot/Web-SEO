<?php
// Análisis manual CON internet: php tests/analyze_cli.php <url> [--json] [--noindex-intencionado]
require __DIR__ . '/../src/bootstrap.php';

$args = array_slice($argv, 1);
$url = null;
foreach ($args as $a) {
    if ($a[0] !== '-') {
        $url = $a;
    }
}
if ($url === null) {
    fwrite(STDERR, "Uso: php tests/analyze_cli.php <url> [--json] [--noindex-intencionado]\n");
    exit(2);
}
$options = ['noindex_intentional' => in_array('--noindex-intencionado', $args, true)];
$result = (new Analyzer(Config::load()))->analyze($url, $options);

if (in_array('--json', $args, true)) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}
if (!$result['ok']) {
    echo 'ERROR: ', $result['error']['code'], ' — ', $result['error']['message'], "\n";
    exit(1);
}
echo 'URL final: ', $result['url'], '  (robots.txt: ', var_export($result['robots']['status'], true), ")\n";
if ($result['blocked']) {
    echo 'BLOQUEO: ', $result['blocked']['message'], "\n";
}
foreach ($result['checks'] as $c) {
    printf("%-4s %-10s %-32s %s\n", $c['id'], $c['estado'] . (($c['aplica'] ?? true) ? '' : '*'), mb_strimwidth($c['nombre'], 0, 32), $c['detalle']);
}
echo "\n* no aplica\n\n";
$p = $result['puntuacion'];
foreach (Scorer::AREAS as $a) {
    printf("%-22s %5.1f%s\n", Lang::all()['areas'][$a], $p['areas'][$a]['redondeada'], $p['areas'][$a]['incompleta'] ? '  (incompleta)' : '');
}
printf("%-22s %5.1f%s\n", 'Nota global', $p['global']['redondeada'], $p['global']['incompleta'] ? '  (incompleta)' : '');
if ($p['riesgo_alto']) {
    echo "\n", $p['riesgo_mensaje'], ' — ', implode('; ', $p['riesgo_motivos']), "\n";
}
