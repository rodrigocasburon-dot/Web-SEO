<?php
// Mini marco de pruebas (sin dependencias).
$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

/** Prueba que no se puede ejecutar en este equipo (p. ej. falta un navegador): se informa como omitida. */
class SkippedTest extends Exception
{
}

function skipTest(string $reason): void
{
    throw new SkippedTest($reason);
}

function assertTrue($cond, string $msg = ''): void
{
    if (!$cond) {
        throw new RuntimeException('Se esperaba true. ' . $msg);
    }
}

function assertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Esperado ' . var_export($expected, true) . ', obtenido ' . var_export($actual, true) . '. ' . $msg);
    }
}

/** Comprueba que $fn lanza UrlGuardException con el código indicado. */
function assertGuardCode(string $code, callable $fn): void
{
    try {
        $fn();
    } catch (UrlGuardException $e) {
        assertSame($code, $e->getErrorCode(), 'Mensaje: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException("No se lanzó UrlGuardException $code.");
}

/**
 * Arranca el servidor integrado de PHP en 127.0.0.1 con un puerto libre.
 * @return array{0: resource|null, 1: int} proceso (null si no se pudo) y puerto
 */
function startPhpServer(string $docroot, ?string $router = null, array $env = []): array
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false || !function_exists('proc_open')) {
        return [null, 0];
    }
    $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $docroot];
    if ($router !== null) {
        $cmd[] = $router;
    }
    $proc = @proc_open($cmd, [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $docroot,
        $env ? array_merge(getenv(), $env) : null);
    for ($i = 0; $proc && $i < 50; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($c) {
            fclose($c);
            return [$proc, $port];
        }
        usleep(100000);
    }
    if ($proc) {
        proc_terminate($proc);
    }
    return [null, 0];
}

function stopPhpServer($proc): void
{
    if ($proc) {
        proc_terminate($proc);
        proc_close($proc);
    }
}

/** Petición HTTP sencilla para pruebas. @return array{status:int, headers:string, body:string} */
function httpRequest(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROXY => '',
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
}

/** Directorio temporal nuevo para una prueba. */
function tempDir(string $prefix): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    return $dir;
}

function runTests(): int
{
    $pass = 0;
    $fail = 0;
    $skip = 0;
    foreach ($GLOBALS['__tests'] as [$name, $fn]) {
        try {
            $fn();
            $pass++;
            echo "  ok    $name\n";
        } catch (SkippedTest $e) {
            $skip++;
            echo "  OMITIDA $name\n        " . $e->getMessage() . "\n";
        } catch (Throwable $e) {
            $fail++;
            echo "  FALLO $name\n        " . $e->getMessage() . "\n";
        }
    }
    echo "\n$pass correctas, $fail fallidas" . ($skip ? ", $skip omitidas" : '') . ".\n";
    return $fail === 0 ? 0 : 1;
}
