<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Tests;

use Componenta\Image\Converter\Codec\GdPngConverter;
use Componenta\Image\Converter\Exception\ConversionException;
use Componenta\Image\Converter\Internal\EncodedOutput;
use PHPUnit\Framework\TestCase;

final class ConverterOrientationTest extends TestCase
{
    public function testJpegOrientationIsAppliedBeforeMetadataIsRemoved(): void
    {
        $source = imagecreatetruecolor(3, 2);
        self::assertInstanceOf(\GdImage::class, $source);
        $jpeg = EncodedOutput::capture(static fn(): bool => imagejpeg($source));
        // EXIF IFD0, little endian, one SHORT entry with Orientation = 6.
        $exif = hex2bin('45786966000049492a0008000000010012010300010000000600000000000000');
        self::assertIsString($exif);
        $jpeg = substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
        $result = (new GdPngConverter())->convert($jpeg);
        $decoded = imagecreatefromstring($result->content);
        self::assertInstanceOf(\GdImage::class, $decoded);
        self::assertSame(2, imagesx($decoded));
        self::assertSame(3, imagesy($decoded));
        self::assertSame('image/png', $result->mimeType);
    }
}
