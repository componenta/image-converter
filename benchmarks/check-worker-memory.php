<?php

declare(strict_types=1);

use Componenta\Image\Benchmarks\WorkerMemoryRegression;

require __DIR__ . '/WorkerMemoryRegression.php';

/** @return list<array<string, mixed>> */
function readWorkerSamples(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read worker samples.');
    }
    $rows = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($row)) {
            throw new InvalidArgumentException('Invalid worker sample.');
        }
        $rows[] = $row;
    }
    return $rows;
}

try {
    if (count($argv) !== 3) {
        throw new InvalidArgumentException('Usage: check-worker-memory.php LIBRARY_LOG NATIVE_LOG');
    }
    $report = WorkerMemoryRegression::evaluate(readWorkerSamples($argv[1]), readWorkerSamples($argv[2]));
} catch (Throwable $error) {
    fwrite(STDERR, 'Invalid worker benchmark: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}
printf("%s: library RSS growth %d KiB, native GD growth %d KiB.\n", $report['case'], $report['libraryRssDelta'], $report['nativeRssDelta']);
if ($report['nativeGrowth']) {
    echo '::warning title=Native GD memory growth::Persistent growth reproduced outside Componenta. Check the system libgd AVIF memory fix (libgd PR #832).', PHP_EOL;
}
if ($report['errors'] !== []) {
    fwrite(STDERR, implode(PHP_EOL, $report['errors']) . PHP_EOL);
    exit(1);
}
echo 'No additional material persistent worker memory growth detected.', PHP_EOL;
