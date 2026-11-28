<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Benchmarks;

/** Compare three independent process measurements against a pinned v2 reference. */
final class RssRegression
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $expectedCases
     * @return list<string>
     */
    public static function detect(array $rows, array $expectedCases): array
    {
        if ($expectedCases === [] || count(array_unique($expectedCases)) !== count($expectedCases)) {
            throw new \InvalidArgumentException('Expected a nonempty list of distinct benchmark cases.');
        }
        $groups = array_fill_keys($expectedCases, []);
        foreach ($rows as $row) {
            $case = $row['case'] ?? null;
            $version = $row['version'] ?? null;
            if (!is_string($case) || !array_key_exists($case, $groups)
                || !in_array($version, ['reference', 'v2'], true)) {
                throw new \InvalidArgumentException('Unexpected benchmark case or version.');
            }
            foreach (['time_ms', 'peak_rss_kib'] as $metric) {
                $value = $row[$metric] ?? null;
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value <= 0) {
                    throw new \InvalidArgumentException('Invalid benchmark metric: ' . $metric);
                }
                $groups[$case][$version][$metric][] = $value;
            }
        }

        $regressions = [];
        foreach ($groups as $case => $versions) {
            foreach (['time_ms' => [0.20, 10], 'peak_rss_kib' => [0.15, 8192]] as $metric => [$relative, $absolute]) {
                $reference = self::median($versions['reference'][$metric] ?? []);
                $candidate = self::median($versions['v2'][$metric] ?? []);
                $delta = $candidate - $reference;
                // Require both a meaningful relative change and an absolute floor.
                if ($delta > $reference * $relative && $delta > $absolute) {
                    $regressions[] = sprintf('%s %s: %.2f -> %.2f (+%.1f%%)', $case, $metric, $reference, $candidate, 100 * $delta / $reference);
                }
            }
        }

        return $regressions;
    }

    /** @param list<int|float> $values */
    private static function median(array $values): int|float
    {
        if (count($values) !== 3) {
            throw new \InvalidArgumentException('Expected exactly three measurements for each case, version and metric.');
        }
        sort($values, SORT_NUMERIC);

        return $values[1];
    }
}
