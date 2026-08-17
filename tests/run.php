<?php

/**
 * Runs every *_test.php in this directory, each in its own process so that
 * static state and in-memory databases cannot leak between suites.
 *
 *     php tests/run.php            all suites
 *     php tests/run.php jalali     only suites whose name contains "jalali"
 *     php tests/run.php -v         show every assertion, not just failures
 */

declare(strict_types=1);

$arguments = array_slice($argv, 1);
$verbose = in_array('-v', $arguments, true) || in_array('--verbose', $arguments, true);
$filter = null;

foreach ($arguments as $argument) {
    if (strpos($argument, '-') !== 0) {
        $filter = $argument;
    }
}

$suites = glob(__DIR__ . '/*_test.php');
sort($suites);

if ($filter !== null) {
    $suites = array_values(array_filter($suites, fn ($suite) => strpos(basename($suite), $filter) !== false));
}

if ($suites === []) {
    echo "No suites matched.\n";
    exit(1);
}

$php = PHP_BINARY;
$totalPassed = 0;
$totalFailed = 0;
$failedSuites = [];
$started = microtime(true);

foreach ($suites as $suite) {
    $name = basename($suite, '.php');

    $output = [];
    $status = 0;
    exec(escapeshellarg($php) . ' ' . escapeshellarg($suite) . ' 2>&1', $output, $status);

    $text = implode("\n", $output);

    $passed = 0;
    $failed = 0;

    if (preg_match('/passed: (\d+), failed: (\d+)/', $text, $matches)) {
        $passed = (int) $matches[1];
        $failed = (int) $matches[2];
    } elseif ($status !== 0) {
        $failed = 1;
    }

    $totalPassed += $passed;
    $totalFailed += $failed;

    printf("%-26s %3d passed  %3d failed%s\n", $name, $passed, $failed, $failed > 0 ? '   <-- FAILED' : '');

    if ($verbose) {
        echo $text . "\n";
    } elseif ($failed > 0 || $status !== 0) {
        $failedSuites[] = $name;

        foreach ($output as $line) {
            if (strpos($line, '  ok') !== 0 && trim($line) !== '') {
                echo '    ' . $line . "\n";
            }
        }
    }
}

$elapsed = round((microtime(true) - $started) * 1000);

echo str_repeat('-', 58) . "\n";
printf("%-26s %3d passed  %3d failed  (%d ms)\n", 'total', $totalPassed, $totalFailed, $elapsed);

if ($failedSuites !== []) {
    echo "\nfailing suites: " . implode(', ', $failedSuites) . "\n";
}

exit($totalFailed === 0 ? 0 : 1);
