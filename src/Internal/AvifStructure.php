<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Internal;

use Componenta\Image\Converter\Exception\ConversionException;
use Generator;

/**
 * Inspect the BMFF metadata that GD does not apply when decoding AVIF pixels.
 * This is a bounded structural guard, not a complete AVIF validator.
 *
 * @internal
 */
final class AvifStructure
{
    // ipma has at most 15 bits for a nonzero property index.
    private const int MAX_PROPERTY_COUNT = 0x7fff;

    /** @throws ConversionException */
    public static function assertSupported(string $raw): void
    {
        $majorBrand = null;
        $hasMeta = false;
        $hasMovie = false;
        foreach (self::boxes($raw, 0, strlen($raw)) as [$type, $start, $end]) {
            if ($type === 'ftyp') {
                if ($majorBrand !== null) {
                    throw new ConversionException('Duplicate AVIF file type box.');
                }
                if ($end - $start < 8 || ($end - $start) % 4 !== 0) {
                    throw new ConversionException('Invalid AVIF file type box.');
                }
                $majorBrand = substr($raw, $start, 4);
                if ($majorBrand === 'avis') {
                    throw new ConversionException('Animated AVIF is not supported.');
                }
                for ($offset = $start + 8; $offset < $end; $offset += 4) {
                    if (substr($raw, $offset, 4) === 'avis') {
                        throw new ConversionException('Animated AVIF is not supported.');
                    }
                }
            } elseif ($type === 'moov') {
                $hasMovie = true;
                // With a generic major brand, libavif's AUTO source can select
                // tracks instead of the primary item, depending on box order.
                if ($majorBrand !== null && $majorBrand !== 'avif') {
                    throw new ConversionException('AVIF movie tracks require an explicit still-image major brand.');
                }
            } elseif ($type === 'meta') {
                if ($hasMeta) {
                    throw new ConversionException('Duplicate AVIF metadata box.');
                }
                $hasMeta = true;
                if ($end - $start < 4 || ord($raw[$start]) !== 0) {
                    throw new ConversionException('Invalid AVIF metadata box.');
                }
                $itemProperties = null;
                $primaryItem = null;
                foreach (self::boxes($raw, $start + 4, $end) as [$child, $from, $to]) {
                    if ($child === 'pitm') {
                        if ($primaryItem !== null) {
                            throw new ConversionException('Duplicate AVIF primary item box.');
                        }
                        $primaryItem = self::primaryItem($raw, $from, $to);
                    } elseif ($child === 'iprp') {
                        if ($itemProperties !== null) {
                            throw new ConversionException('Duplicate AVIF item properties box.');
                        }
                        $itemProperties = [$from, $to];
                    }
                }
                // pitm and iprp may appear in either order.
                if ($itemProperties !== null) {
                    self::assertProperties($raw, $itemProperties[0], $itemProperties[1], $primaryItem);
                }
            }
        }
        if ($majorBrand === null) {
            throw new ConversionException('Missing AVIF file type box.');
        }
        if ($hasMovie && $majorBrand !== 'avif') {
            throw new ConversionException('AVIF movie tracks require an explicit still-image major brand.');
        }
    }

    /** @throws ConversionException */
    private static function primaryItem(string $raw, int $start, int $end): int
    {
        $length = $end - $start;
        if ($length < 4) {
            throw new ConversionException('Truncated AVIF primary item box.');
        }
        $version = ord($raw[$start]);
        if (($version !== 0 && $version !== 1)
            || $length !== ($version === 0 ? 6 : 8)
            || (self::uint32($raw, $start) & 0xffffff) !== 0) {
            throw new ConversionException('Invalid AVIF primary item box.');
        }
        $id = $version === 0
            ? ord($raw[$start + 4]) * 256 + ord($raw[$start + 5])
            : self::uint32($raw, $start + 4);
        if ($id === 0) {
            throw new ConversionException('Invalid AVIF primary item identifier.');
        }

        return $id;
    }

    /** @throws ConversionException */
    private static function assertProperties(string $raw, int $start, int $end, ?int $primaryItem): void
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
                if (++$count > self::MAX_PROPERTY_COUNT) {
                    throw new ConversionException('AVIF property count exceeds supported limit.');
                }
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

        if ($primaryItem === null) {
            throw new ConversionException('Missing AVIF primary item box.');
        }

        // libavif reports display transformations of the primary color item.
        // Other items, including thumbnails and auxiliary items, do not define
        // the display geometry of the image decoded by GD.
        foreach (self::boxes($raw, $start, $end) as [$type, $from, $to]) {
            if ($type === 'ipma') {
                self::assertAssociations($raw, $from, $to, $properties, $count, $primaryItem);
            }
        }
    }

    /**
     * @param array<int, array{string, int, int}> $properties
     * @throws ConversionException
     */
    private static function assertAssociations(
        string $raw,
        int $offset,
        int $end,
        array $properties,
        int $propertyCount,
        int $primaryItem,
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
            $itemId = $version === 0
                ? ord($raw[$offset]) * 256 + ord($raw[$offset + 1])
                : self::uint32($raw, $offset);
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
                // Validate the indices for every association, but apply the
                // geometry policy only to the actual primary image.
                if ($itemId !== $primaryItem) {
                    continue;
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
     * @throws ConversionException
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

    /**
     * @return Generator<int, array{string, int, int}>
     * @throws ConversionException
     */
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

    /** @throws ConversionException */
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
