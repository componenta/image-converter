<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Tests;

use Componenta\Image\Converter\Exception\ConversionException;
use Componenta\Image\Converter\ImageConversionLimits;
use Componenta\Image\Converter\Codec\GdWebPConverter;
use Componenta\Image\Converter\Codec\GdJpegConverter;
use Componenta\Image\Converter\Internal\EncodedOutput;
use PHPUnit\Framework\TestCase;

final class ConverterGdSafetyTest extends TestCase
{
    public function testDirectCodecRejectsUnsupportedSourceFormat(): void
    {
        $codec = new GdWebPConverter();
        self::assertFalse($codec->supports('image/gif'));
        $this->expectException(ConversionException::class);
        $codec->convert(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true));
    }

    public function testDirectCodecEnforcesDimensionLimit(): void
    {
        $codec = new GdJpegConverter(limits: new ImageConversionLimits(maxPixels: 3));
        $this->expectException(ConversionException::class);
        $codec->convert($this->png());
    }

    public function testDirectCodecEnforcesByteLimit(): void
    {
        $codec = new GdJpegConverter(limits: new ImageConversionLimits(maxInputBytes: 3));
        $this->expectException(ConversionException::class);
        $codec->convert($this->png());
    }

    public function testOutputIsDecodableAndDimensionsArePreserved(): void
    {
        $image = (new GdJpegConverter())->convert($this->png());
        $decoded = imagecreatefromstring($image->content);
        self::assertInstanceOf(\GdImage::class, $decoded);
        self::assertSame(2, imagesx($decoded));
        self::assertSame(2, imagesy($decoded));
        self::assertSame('image/jpeg', $image->mimeType);
    }

    public function testCodecFailureDoesNotLeakOutputBuffer(): void
    {
        $level = ob_get_level();
        try {
            EncodedOutput::capture(static function (): bool {
                echo 'partial';
                throw new \RuntimeException('encoding failed');
            });
            self::fail('Expected encoding failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('encoding failed', $error->getMessage());
            self::assertSame($level, ob_get_level());
        }
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(2, 2);
        self::assertInstanceOf(\GdImage::class, $image);

        return EncodedOutput::capture(static fn(): bool => imagepng($image));
    }
}
