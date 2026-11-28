<?php

declare(strict_types=1);

namespace Componenta\Image\Benchmarks;

/** Distinguish persistent library growth from growth reproduced by native GD. */
final class WorkerMemoryRegression
{
    private const int RSS_TOLERANCE_KIB = 16384;
    private const int PHP_TOLERANCE_BYTES = 2097152;

    /**
     * @param list<array<string, mixed>> $library
     * @param list<array<string, mixed>> $native
     * @return array{case: string, nativeGrowth: bool, errors: list<string>, libraryRssDelta: int, nativeRssDelta: int}
     */
    public static function evaluate(array $library, array $native): array
    {
        $case = self::validate($library, 'library');
        if (self::validate($native, 'native') !== $case) {
            throw new \InvalidArgumentException('Worker benchmark cases do not match.');
        }

        $nativeGrowth = self::persistent($native, 'rss_kib', self::RSS_TOLERANCE_KIB);
        $rssExcess = [];
        foreach ([2, 3] as $index) {
            $nativeDelta = $native[$index]['rss_kib'] - $native[0]['rss_kib'];
            $libraryDelta = $library[$index]['rss_kib'] - $library[0]['rss_kib'];
            $rssExcess[] = $libraryDelta - ($nativeGrowth ? max(0, $nativeDelta) : 0);
        }

        $errors = [];
        if (min($rssExcess) > self::RSS_TOLERANCE_KIB) {
            $errors[] = 'Persistent RSS growth not explained by native GD.';
        }
        if (self::persistent($library, 'php_bytes', self::PHP_TOLERANCE_BYTES)) {
            $errors[] = 'Persistent live PHP allocation growth.';
        }

        return [
            'case' => $case,
            'nativeGrowth' => $nativeGrowth,
            'errors' => $errors,
            'libraryRssDelta' => $library[3]['rss_kib'] - $library[0]['rss_kib'],
            'nativeRssDelta' => $native[3]['rss_kib'] - $native[0]['rss_kib'],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function validate(array $rows, string $mode): string
    {
        if (count($rows) !== 4 || !array_is_list($rows)) {
            throw new \InvalidArgumentException('Expected four worker memory samples.');
        }
        $case = $rows[0]['case'] ?? null;
        if (!is_string($case) || $case === '') {
            throw new \InvalidArgumentException('Missing worker benchmark case.');
        }
        foreach ([20, 80, 140, 200] as $index => $iterations) {
            $row = $rows[$index];
            if (($row['mode'] ?? null) !== $mode || ($row['case'] ?? null) !== $case
                || ($row['iterations'] ?? null) !== $iterations) {
                throw new \InvalidArgumentException('Unexpected worker sample order, mode or case.');
            }
            foreach (['rss_kib', 'php_bytes'] as $metric) {
                if (!is_int($row[$metric] ?? null) || $row[$metric] <= 0) {
                    throw new \InvalidArgumentException('Invalid worker sample metric: ' . $metric);
                }
            }
        }
        return $case;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function persistent(array $rows, string $metric, int $tolerance): bool
    {
        return $rows[2][$metric] - $rows[0][$metric] > $tolerance
            && $rows[3][$metric] - $rows[0][$metric] > $tolerance;
    }
}
