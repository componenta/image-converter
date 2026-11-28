<?php

declare(strict_types=1);

use Componenta\Image\Benchmarks\WorkerMemoryRegression;

require_once __DIR__ . '/../benchmarks/WorkerMemoryRegression.php';

function workerRegressionSamples(string $mode, array $rss, array $php = [2000000, 2000000, 2000000, 2000000]): array
{
    return array_map(static fn(int $index): array => [
        'mode' => $mode,
        'case' => 'webp->avif',
        'iterations' => [20, 80, 140, 200][$index],
        'rss_kib' => $rss[$index],
        'php_bytes' => $php[$index],
    ], [0, 1, 2, 3]);
}

test('worker regression guard distinguishes native growth from additional library growth', function (): void {
    $native = workerRegressionSamples('native', [70000, 99000, 128000, 157000]);
    $library = workerRegressionSamples('library', [75000, 104000, 133000, 162000]);
    $report = WorkerMemoryRegression::evaluate($library, $native);
    expect($report['nativeGrowth'])->toBeTrue();
    expect($report['errors'])->toBe([]);
    $library = workerRegressionSamples('library', [75000, 124000, 163000, 202000]);
    expect(WorkerMemoryRegression::evaluate($library, $native)['errors'])->toHaveCount(1);
});

test('worker regression guard rejects persistent library growth when native GD is stable', function (): void {
    $native = workerRegressionSamples('native', [70000, 70000, 70000, 70000]);
    $library = workerRegressionSamples('library', [75000, 80000, 100000, 110000]);
    $report = WorkerMemoryRegression::evaluate($library, $native);
    expect($report['nativeGrowth'])->toBeFalse();
    expect($report['errors'])->toHaveCount(1);
    $singleOutlier = workerRegressionSamples('library', [75000, 80000, 80000, 150000]);
    expect(WorkerMemoryRegression::evaluate($singleOutlier, $native)['errors'])->toBe([]);
});

test('native growth never excuses live PHP allocation growth', function (): void {
    $native = workerRegressionSamples('native', [70000, 99000, 128000, 157000]);
    $library = workerRegressionSamples('library', [75000, 104000, 133000, 162000], [2000000, 4000000, 5000000, 6000000]);
    expect(WorkerMemoryRegression::evaluate($library, $native)['errors'])->toHaveCount(1);
});

test('worker regression guard rejects missing or reordered measurements', function (): void {
    $native = workerRegressionSamples('native', [70000, 99000, 128000, 157000]);
    $library = workerRegressionSamples('library', [75000, 104000, 133000, 162000]);
    expect(fn() => WorkerMemoryRegression::evaluate(array_slice($library, 1), $native))->toThrow(InvalidArgumentException::class);
    [$library[1], $library[2]] = [$library[2], $library[1]];
    expect(fn() => WorkerMemoryRegression::evaluate($library, $native))->toThrow(InvalidArgumentException::class);
});
