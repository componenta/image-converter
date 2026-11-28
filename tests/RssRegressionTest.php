<?php

declare(strict_types=1);

use Componenta\Image\Benchmarks\RssRegression;

require_once __DIR__ . '/../benchmarks/RssRegression.php';

function rssRegressionRows(array $times = [100, 100, 100], array $rss = [100000, 100000, 100000]): array
{
    $rows = [];
    foreach (['reference', 'v2'] as $version) {
        for ($i = 0; $i < 3; ++$i) {
            $rows[] = [
                'case' => 'jpeg->png',
                'version' => $version,
                'time_ms' => $version === 'reference' ? 100 : $times[$i],
                'peak_rss_kib' => $version === 'reference' ? 100000 : $rss[$i],
            ];
        }
    }

    return $rows;
}

test('RSS regression guard tolerates noise and single outliers', function (): void {
    expect(RssRegression::detect(rssRegressionRows([101, 119, 1000], [100000, 114000, 500000]), ['jpeg->png']))->toBe([]);
});

test('RSS regression guard detects sustained independent time and memory regressions', function (): void {
    expect(RssRegression::detect(rssRegressionRows([130, 131, 100], [100000, 100000, 100000]), ['jpeg->png']))->toHaveCount(1);
    expect(RssRegression::detect(rssRegressionRows([100, 100, 100], [116000, 117000, 100000]), ['jpeg->png']))->toHaveCount(1);
    expect(RssRegression::detect(rssRegressionRows([140, 130, 130], [120000, 120000, 120000]), ['jpeg->png']))->toHaveCount(2);
});

test('RSS regression guard requires an absolute and relative increase', function (): void {
    $rows = rssRegressionRows([6, 6, 6], [6000, 6000, 6000]);
    foreach ($rows as &$row) {
        if ($row['version'] === 'reference') {
            $row['time_ms'] = 4;
            $row['peak_rss_kib'] = 4000;
        }
    }
    unset($row);
    expect(RssRegression::detect($rows, ['jpeg->png']))->toBe([]);
});

test('RSS regression guard fails closed on incomplete or invalid samples', function (): void {
    $rows = rssRegressionRows();
    expect(fn() => RssRegression::detect(array_slice($rows, 1), ['jpeg->png']))->toThrow(InvalidArgumentException::class);
    expect(fn() => RssRegression::detect($rows, ['jpeg->png', 'missing']))->toThrow(InvalidArgumentException::class);
    $rows[0]['time_ms'] = 0;
    expect(fn() => RssRegression::detect($rows, ['jpeg->png']))->toThrow(InvalidArgumentException::class);
});
