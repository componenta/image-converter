<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Tests;

use Componenta\Image\Converter\Exception\ConversionException;
use Componenta\Image\Converter\Codec\GdPngConverter;
use Componenta\Image\Converter\ImageConversionOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageConversionOptionsTest extends TestCase
{
    public function testAcceptsBoundaryCodecOptions(): void
    {
        self::assertSame(0, new ImageConversionOptions(quality: 0)->quality);
        self::assertSame(100, new ImageConversionOptions(quality: 100)->quality);
        self::assertSame(9, new ImageConversionOptions(compressionLevel: 9)->compressionLevel);
        self::assertSame(10, new ImageConversionOptions(avifSpeed: 10)->avifSpeed);
    }

    #[DataProvider('invalidOptions')]
    public function testRejectsInvalidOrMixedOptions(?int $quality, ?int $level, ?int $speed): void
    {
        $this->expectException(ConversionException::class);
        new ImageConversionOptions($quality, $level, $speed);
    }

    public static function invalidOptions(): iterable
    {
        yield [-1, null, null];
        yield [101, null, null];
        yield [null, -1, null];
        yield [null, 10, null];
        yield [null, null, -1];
        yield [null, null, 11];
        yield [80, 6, null];
        yield [null, 6, 5];
    }

    public function testPngRejectsLossyQuality(): void
    {
        $this->expectException(ConversionException::class);
        (new GdPngConverter())->convert('unused', new ImageConversionOptions(quality: 80));
    }
}
