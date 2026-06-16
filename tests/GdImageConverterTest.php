<?php

declare(strict_types=1);

namespace Componenta\Image\Tests;

use Componenta\Detector\FinfoDetector;
use Componenta\Image\GdAvifConverter;
use Componenta\Image\GdJpegConverter;
use Componenta\Image\GdPngConverter;
use Componenta\Image\GdWebPConverter;
use PHPUnit\Framework\TestCase;

final class GdImageConverterTest extends TestCase
{
    public function testConvertsPngToWebP(): void
    {
        $image = (new GdWebPConverter())->convert($this->pngFixture());

        self::assertSame('image/webp', $image->mimeType);
        self::assertSame('webp', $image->extension);
        self::assertSame('image/webp', (new FinfoDetector())->detectMimeType($image->content));
    }

    public function testConvertsPngToJpeg(): void
    {
        $image = (new GdJpegConverter())->convert($this->pngFixture());

        self::assertSame('image/jpeg', $image->mimeType);
        self::assertSame('jpg', $image->extension);
        self::assertSame('image/jpeg', (new FinfoDetector())->detectMimeType($image->content));
    }

    public function testConvertsJpegToPng(): void
    {
        $image = (new GdPngConverter())->convert($this->jpegFixture());

        self::assertSame('image/png', $image->mimeType);
        self::assertSame('png', $image->extension);
        self::assertSame('image/png', (new FinfoDetector())->detectMimeType($image->content));
    }

    public function testConvertsPngToAvifWhenAvailable(): void
    {
        if (!function_exists('imageavif')) {
            self::markTestSkipped('AVIF is not available in the current GD build.');
        }

        $image = (new GdAvifConverter())->convert($this->pngFixture());

        self::assertSame('image/avif', $image->mimeType);
        self::assertSame('avif', $image->extension);
        self::assertSame('image/avif', (new FinfoDetector())->detectMimeType($image->content));
    }

    private function pngFixture(): string
    {
        $gd = imagecreatetruecolor(2, 2);

        if ($gd === false) {
            self::fail('Unable to create PNG fixture image.');
        }

        imagealphablending($gd, false);
        imagesavealpha($gd, true);
        $color = imagecolorallocatealpha($gd, 30, 120, 220, 20);

        if ($color === false) {
            imagedestroy($gd);
            self::fail('Unable to allocate PNG fixture color.');
        }

        imagefill($gd, 0, 0, $color);

        ob_start();
        imagepng($gd);
        $content = ob_get_clean();
        imagedestroy($gd);

        self::assertIsString($content);

        return $content;
    }

    private function jpegFixture(): string
    {
        $gd = imagecreatetruecolor(2, 2);

        if ($gd === false) {
            self::fail('Unable to create JPEG fixture image.');
        }

        $color = imagecolorallocate($gd, 240, 180, 60);

        if ($color === false) {
            imagedestroy($gd);
            self::fail('Unable to allocate JPEG fixture color.');
        }

        imagefill($gd, 0, 0, $color);

        ob_start();
        imagejpeg($gd);
        $content = ob_get_clean();
        imagedestroy($gd);

        self::assertIsString($content);

        return $content;
    }
}
