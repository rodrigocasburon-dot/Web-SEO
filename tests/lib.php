<?php
// Mini marco de pruebas (sin dependencias).
$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
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

function runTests(): int
{
    $pass = 0;
    $fail = 0;
    foreach ($GLOBALS['__tests'] as [$name, $fn]) {
        try {
            $fn();
            $pass++;
            echo "  ok    $name\n";
        } catch (Throwable $e) {
            $fail++;
            echo "  FALLO $name\n        " . $e->getMessage() . "\n";
        }
    }
    echo "\n$pass correctas, $fail fallidas.\n";
    return $fail === 0 ? 0 : 1;
}
