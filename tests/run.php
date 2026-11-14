<?php

declare(strict_types=1);

/**
 * Runner de pruebas sin dependencias.
 *
 * Uso:
 *   php tests/run.php
 *
 * Cada archivo en tests/cases/ devuelve un array [nombre => closure].
 * Las closures reciben el objeto Assertions para comprobar resultados.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

final class Assertions
{
    public int $count = 0;

    public function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->count++;

        if ($expected !== $actual) {
            throw new RuntimeException(sprintf(
                "%s\nEsperado: %s\nRecibido: %s",
                $message !== '' ? $message : 'Valores distintos',
                $this->dump($expected),
                $this->dump($actual),
            ));
        }
    }

    public function true(bool $value, string $message = ''): void
    {
        $this->same(true, $value, $message !== '' ? $message : 'Se esperaba true');
    }

    public function false(bool $value, string $message = ''): void
    {
        $this->same(false, $value, $message !== '' ? $message : 'Se esperaba false');
    }

    public function null(mixed $value, string $message = ''): void
    {
        $this->same(null, $value, $message !== '' ? $message : 'Se esperaba null');
    }

    public function contains(string $needle, string $haystack, string $message = ''): void
    {
        $this->count++;

        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException(sprintf(
                "%s\nNo se encontró %s en:\n%s",
                $message !== '' ? $message : 'Texto no encontrado',
                $this->dump($needle),
                $haystack,
            ));
        }
    }

    private function dump(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return var_export($value, true);
    }
}

$cases = [];
foreach (glob(__DIR__ . '/cases/*.php') ?: [] as $file) {
    $suite = require $file;
    if (!is_array($suite)) {
        fwrite(STDERR, "· {$file} no devolvió un array de pruebas.\n");
        exit(1);
    }

    $cases = array_merge($cases, $suite);
}

if ($cases === []) {
    fwrite(STDERR, "No hay pruebas en tests/cases/.\n");
    exit(1);
}

$assert = new Assertions();
$passed = 0;
$failed = 0;
$started = microtime(true);

foreach ($cases as $name => $closure) {
    try {
        $closure($assert);
        $passed++;
        echo "✓ {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "✗ {$name}\n";
        echo '  ' . str_replace("\n", "\n  ", trim($error->getMessage())) . "\n";
    }
}

$elapsed = number_format((microtime(true) - $started) * 1000, 1);
echo PHP_EOL . sprintf(
    '%d/%d pruebas · %d aserciones · %sms%s',
    $passed,
    $passed + $failed,
    $assert->count,
    $elapsed,
    PHP_EOL,
);

exit($failed === 0 ? 0 : 1);
