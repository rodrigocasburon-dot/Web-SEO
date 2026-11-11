<?php
// Prueba manual CON internet (no forma parte de run.php): php tests/live_fetch.php [url ...]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/bootstrap.php';

$urls = array_slice($argv, 1) ?: [
    'https://www.example.com/',
    'http://github.com/',
    'https://httpstat.us/403',
    'http://127.0.0.1/',
    'https://expired.badssl.com/',
];
$fetcher = new Fetcher(new UrlGuard());

foreach ($urls as $u) {
    $r = $fetcher->fetch($u);
    echo "\n== $u\n";
    echo '  ok=' . var_export($r['ok'], true) . ' estado=' . var_export($r['status'], true)
        . ' final=' . var_export($r['final_url'], true) . "\n";
    echo '  bytes=' . $r['bytes'] . ' truncado=' . var_export($r['truncated'], true)
        . ' tipo=' . var_export($r['content_type'], true) . ' total=' . $r['timings']['total_ms'] . " ms\n";
    foreach ($r['redirects'] as $rd) {
        echo "  redirección {$rd['status']}: {$rd['from']} -> {$rd['to']}\n";
    }
    foreach ($r['hops'] as $h) {
        echo "  salto {$h['url']} ip={$h['ip']} dns={$h['timings']['namelookup_ms']}ms ttfb={$h['timings']['ttfb_ms']}ms\n";
    }
    if ($r['blocked']) {
        echo '  BLOQUEO: ' . $r['blocked']['reason'] . ' — ' . $r['blocked']['message'] . "\n";
    }
    if ($r['error']) {
        echo '  ERROR: ' . $r['error']['code'] . ' — ' . $r['error']['message'] . "\n";
    }
}
