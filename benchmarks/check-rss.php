<?php

declare(strict_types=1);

use Componenta\Image\Converter\Benchmarks\RssRegression;

require __DIR__ . '/RssRegression.php';

try {
    $path = $argv[1] ?? throw new InvalidArgumentException('Usage: check-rss.php LOG CASE...');
    $cases = array_map(static fn(string $case): string => str_replace(':', '->', $case), array_slice($argv, 2));
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read benchmark log.');
    }
    $rows = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($row)) {
            throw new InvalidArgumentException('Invalid benchmark row.');
        }
        $rows[] = $row;
    }
    $regressions = RssRegression::detect($rows, $cases);
} catch (Throwable $error) {
    fwrite(STDERR, 'Invalid benchmark data: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}

if ($regressions !== []) {
    fwrite(STDERR, implode(PHP_EOL, $regressions) . PHP_EOL);
    exit(1);
}
echo 'No material time or peak RSS regression against the pinned v2 reference.', PHP_EOL;
