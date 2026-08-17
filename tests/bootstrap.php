<?php

/**
 * A tiny zero-dependency test harness, so the package stays installable
 * without composer. Each suite is a plain PHP script that requires this file,
 * calls check()/throws() and finishes with summary().
 *
 * Run everything:  php tests/run.php
 * Run one suite:   php tests/compile_test.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
date_default_timezone_set('Asia/Tehran');

spl_autoload_register(function (string $class): void {
    $prefix = 'Or81\\Eloquent\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

$pass = 0;
$fail = 0;

/**
 * The plain arrays behind a Row, a Model, or a list of either, so results can
 * be compared against literals.
 */
function plain($value)
{
    if (is_array($value)) {
        return array_map('plain', $value);
    }

    if ($value instanceof Or81\Eloquent\Row || $value instanceof Or81\Eloquent\Model) {
        return $value->toArray();
    }

    return $value;
}

/**
 * Assert that a value is exactly what it should be.
 */
function check(string $label, $actual, $expected): void
{
    global $pass, $fail;

    if ($actual === $expected) {
        $pass++;
        echo "  ok   {$label}\n";

        return;
    }

    $fail++;
    echo "  FAIL {$label}\n";
    echo "       expected: " . var_export($expected, true) . "\n";
    echo "       actual:   " . var_export($actual, true) . "\n";
}

/**
 * Assert that a callback throws, optionally with a message containing $contains.
 */
function throws(string $label, callable $callback, string $exception = Throwable::class, ?string $contains = null): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        if (! $e instanceof $exception) {
            check($label, get_class($e), $exception);

            return;
        }

        check($label, $contains === null || strpos($e->getMessage(), $contains) !== false, true);

        return;
    }

    check($label, 'nothing thrown', $exception);
}

function section(string $name): void
{
    echo "\n== {$name} ==\n";
}

/**
 * Print the tally and exit with a status the runner can read.
 */
function summary(): void
{
    global $pass, $fail;

    echo "\npassed: {$pass}, failed: {$fail}\n";

    exit($fail === 0 ? 0 : 1);
}
