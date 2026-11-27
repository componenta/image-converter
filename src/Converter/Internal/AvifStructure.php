<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Internal;

use Componenta\Image\ConversionException;
use Generator;

/**
 * Inspect the BMFF metadata that GD does not apply when decoding AVIF pixels.
 * This is a bounded structural guard, not a complete AVIF validator.
 *
 * @internal
 */
final class AvifStructure
{
    public static function assertSupported(string $raw): void
    {
        $hasFileType = false;
        foreach (self::boxes($raw, 0, strlen($raw)) as [$type, $start, $end]) {
            if ($type === 'ftyp') {
                $hasFileType = true;
                if ($end - $start < 8 || ($end - $start) % 4 !== 0) {
                    throw new ConversionException('Invalid AVIF file type box.');
                }
                if (substr($raw, $start, 4) === 'avis') {
                    throw new ConversionException('Animated AVIF is not supported.');
                }
                for ($offset = $start + 8; $offset < $end; $offset += 4) {
                    if (substr($raw, $offset, 4) === 'avis') {
                        throw new ConversionException('Animated AVIF is not supported.');
                    }
                }
            } elseif ($type === 'meta') {
                if ($end - $start < 4 || ord($raw[$start]) !== 0) {
                    throw new ConversionException('Invalid AVIF metadata box.');
                }
                foreach (self::boxes($raw, $start + 4, $end) as [$child, $from, $to]) {
                    if ($child === 'iprp') {
                        self::assertProperties($raw, $from, $to);
                    }
                }
            }
        }
        if (!$hasFileType) {
            throw new ConversionException('Missing AVIF file type box.');
        }
    }

    private static function assertProperties(string $raw, int $start, int $end): void
    {
        $properties = [];
        $count = 0;
        $hasContainer = false;
        $hasDisplayProperties = false;
        foreach (self::boxes($raw, $start, $end) as [$type, $from, $to]) {
            if ($type !== 'ipco') {
                continue;
            }
            if ($hasContainer) {
                throw new ConversionException('Duplicate AVIF property container.');
            }
            $hasContainer = true;
            foreach (self::boxes($raw, $from, $to) as [$property, $payload, $limit]) {
                ++$count;
                if ($property === 'ispe') {
                    $properties[$count] = [$property, $payload, $limit];
                } elseif (in_array($property, ['irot', 'imir', 'clap', 'pasp'], true)) {
                    $hasDisplayProperties = true;
                    $properties[$count] = [$property, $payload, $limit];
                }
            }
        }
        if (!$hasDisplayProperties) {
            return;
        }

        // Only associated properties affect an item. An unused property must not
        // prevent a valid image from being processed.
        foreach (self::boxes($raw, $start, $end) as [$type, $from, $to]) {
            if ($type === 'ipma') {
                self::assertAssociations($raw, $from, $to, $properties, $count);
            }
        }
    }

    /** @param array<int, array{string, int, int}> $properties */
    private static function assertAssociations(
        string $raw,
        int $offset,
        int $end,
        array $properties,
        int $propertyCount,
    ): void {
        if ($end - $offset < 8) {
            throw new ConversionException('Invalid AVIF property associations.');
        }
        $version = ord($raw[$offset]);
        $flags = self::uint32($raw, $offset) & 0xffffff;
        if ($version > 1 || $flags > 1) {
            throw new ConversionException('Unsupported AVIF property association encoding.');
        }
        $idSize = $version === 0 ? 2 : 4;
        $associationSize = $flags === 1 ? 2 : 1;
        $entries = self::uint32($raw, $offset + 4);
        $offset += 8;

        for ($entry = 0; $entry < $entries; ++$entry) {
            if ($end - $offset < $idSize + 1) {
                throw new ConversionException('Truncated AVIF property association entry.');
            }
            $offset += $idSize;
            $count = ord($raw[$offset++]);
            if ($count > intdiv($end - $offset, $associationSize)) {
                throw new ConversionException('Truncated AVIF property association list.');
            }
            $geometry = null;
            $transforms = [];
            for ($index = 0; $index < $count; ++$index) {
                $propertyIndex = ord($raw[$offset++]) & 0x7f;
                if ($associationSize === 2) {
                    $propertyIndex = $propertyIndex * 256 + ord($raw[$offset++]);
                }
                if ($propertyIndex > $propertyCount) {
                    throw new ConversionException('Invalid AVIF property index.');
                }
                $property = $properties[$propertyIndex] ?? null;
                if ($property === null) {
                    continue;
                }
                if ($property[0] === 'ispe') {
                    if ($property[2] - $property[1] !== 12) {
                        throw new ConversionException('Invalid AVIF spatial extent.');
                    }
                    $geometry = [
                        self::uint32($raw, $property[1] + 4),
                        self::uint32($raw, $property[1] + 8),
                    ];
                } else {
                    $transforms[] = $property;
                }
            }
            foreach ($transforms as $property) {
                if (!self::isIdentity($raw, $property, $geometry)) {
                    throw new ConversionException('AVIF display transformation is unsupported by GD: ' . $property[0] . '.');
                }
            }
        }
        if ($offset !== $end) {
            throw new ConversionException('Invalid AVIF property association length.');
        }
    }

    /**
     * @param array{string, int, int} $property
     * @param array{int, int}|null $geometry
     */
    private static function isIdentity(string $raw, array $property, ?array $geometry): bool
    {
        [$type, $start, $end] = $property;
        $size = $end - $start;
        if ($type === 'irot') {
            return $size === 1 && ord($raw[$start]) === 0;
        }
        if ($type === 'pasp') {
            return $size === 8 && self::uint32($raw, $start) > 0
                && self::uint32($raw, $start) === self::uint32($raw, $start + 4);
        }
        if ($type !== 'clap' || $size !== 32 || $geometry === null) {
            return false;
        }

        // A clean aperture covering the entire associated item is neutral.
        $widthNumerator = self::uint32($raw, $start);
        $widthDenominator = self::uint32($raw, $start + 4);
        $heightNumerator = self::uint32($raw, $start + 8);
        $heightDenominator = self::uint32($raw, $start + 12);

        return $widthDenominator > 0 && $heightDenominator > 0
            && $widthNumerator % $widthDenominator === 0
            && $heightNumerator % $heightDenominator === 0
            && intdiv($widthNumerator, $widthDenominator) === $geometry[0]
            && intdiv($heightNumerator, $heightDenominator) === $geometry[1]
            && self::uint32($raw, $start + 16) === 0
            && self::uint32($raw, $start + 20) > 0
            && self::uint32($raw, $start + 24) === 0
            && self::uint32($raw, $start + 28) > 0;
    }

    /** @return Generator<int, array{string, int, int}> */
    private static function boxes(string $raw, int $offset, int $end): Generator
    {
        while ($offset < $end) {
            $remaining = $end - $offset;
            if ($remaining < 8) {
                throw new ConversionException('Truncated AVIF box header.');
            }
            $size = self::uint32($raw, $offset);
            $type = substr($raw, $offset + 4, 4);
            $header = 8;
            if ($size === 1) {
                $header = 16;
                if ($remaining < $header || self::uint32($raw, $offset + 8) !== 0) {
                    throw new ConversionException('Unsupported AVIF extended box length.');
                }
                $size = self::uint32($raw, $offset + 12);
            } elseif ($size === 0) {
                $size = $remaining;
            }
            if ($size < $header || $size > $remaining) {
                throw new ConversionException('Invalid AVIF box length.');
            }
            $next = $offset + $size;
            yield [$type, $offset + $header, $next];
            $offset = $next;
        }
    }

    private static function uint32(string $raw, int $offset): int
    {
        // Callers bound the complete field against its containing box.
        $value = unpack('Nvalue', $raw, $offset)['value'] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new ConversionException('Unsupported AVIF integer field.');
        }

        return $value;
    }
}
