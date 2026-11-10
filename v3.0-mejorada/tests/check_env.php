<?php
// Comprobación del entorno (CLI o navegador). No necesita internet salvo el último punto.
$isCli = PHP_SAPI === 'cli';
$results = [];

function add(array &$r, string $name, string $status, string $detail): void
{
    $r[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
}

add($results, 'Versión de PHP >= 8.0', version_compare(PHP_VERSION, '8.0.0', '>=') ? 'OK' : 'FALLO', PHP_VERSION);

foreach (['curl', 'openssl', 'mbstring', 'dom', 'json'] as $ext) {
    add($results, "Extensión $ext", extension_loaded($ext) ? 'OK' : 'FALLO', extension_loaded($ext) ? 'activa' : 'no activa');
}
// Opcionales: su falta no impide analizar, pero desactiva lo que se indica
foreach ([
    'pdo_mysql' => 'necesaria para el historial en MySQL',
    'zlib' => 'necesaria para guardar el informe en el historial',
    'intl' => 'sin ella no se admiten dominios con caracteres no ASCII',
    'pdo_sqlite' => 'solo para las pruebas del historial (tests/run.php)',
] as $ext => $why) {
    add($results, "Extensión $ext (opcional)", extension_loaded($ext) ? 'OK' : 'AVISO', extension_loaded($ext) ? 'activa' : 'no activa: ' . $why);
}
$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
foreach (['proc_open', 'exec'] as $fn) {
    $ok = function_exists($fn) && !in_array($fn, $disabled, true);
    add($results, "Función $fn (pruebas y Chrome)", $ok ? 'OK' : 'AVISO', $ok ? 'disponible' : 'deshabilitada');
}

$cainfo = (string) ini_get('curl.cainfo');
$cafile = (string) ini_get('openssl.cafile');
$caOk = ($cainfo !== '' && is_file($cainfo));
add($results, 'curl.cainfo (cacert.pem)', $caOk ? 'OK' : 'FALLO',
    $cainfo === '' ? 'sin configurar en php.ini' : ($caOk ? $cainfo : "no existe el fichero: $cainfo"));
add($results, 'openssl.cafile', ($cafile !== '' && is_file($cafile)) ? 'OK' : 'AVISO', $cafile === '' ? 'sin configurar' : $cafile);

// Petición HTTPS real con verificación de certificado
if (extension_loaded('curl')) {
    $ch = curl_init('https://www.example.com/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err === '') {
        add($results, 'HTTPS con verificación de certificado', 'OK', "HTTP $code");
    } else {
        add($results, 'HTTPS con verificación de certificado', 'FALLO', $err);
    }
}

$storage = __DIR__ . '/../storage';
add($results, 'storage/ escribible', is_writable($storage) ? 'OK' : 'FALLO', realpath($storage) ?: $storage);
add($results, 'storage/.htaccess presente', is_file($storage . '/.htaccess') ? 'OK' : 'FALLO', '');
add($results, 'config/config.php', is_file(__DIR__ . '/../config/config.php') ? 'OK' : 'AVISO', 'copiar desde config.example.php si falta');

$failed = count(array_filter($results, fn($x) => $x['status'] === 'FALLO'));

if ($isCli) {
    foreach ($results as $x) {
        printf("[%-5s] %s%s\n", $x['status'], $x['name'], $x['detail'] !== '' ? ' — ' . $x['detail'] : '');
    }
    echo $failed === 0 ? "\nEntorno correcto.\n" : "\n$failed comprobación(es) fallida(s).\n";
    exit($failed === 0 ? 0 : 1);
}
?><!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Comprobación del entorno</title></head>
<body><main>
<h1>Comprobación del entorno</h1>
<table border="1" cellpadding="6">
<tr><th>Comprobación</th><th>Estado</th><th>Detalle</th></tr>
<?php foreach ($results as $x): ?>
<tr><td><?= htmlspecialchars($x['name']) ?></td><td><?= htmlspecialchars($x['status']) ?></td><td><?= htmlspecialchars($x['detail']) ?></td></tr>
<?php endforeach; ?>
</table>
<p><?= $failed === 0 ? 'Entorno correcto.' : htmlspecialchars((string) $failed) . ' comprobación(es) fallida(s).' ?></p>
</main></body>
</html>
