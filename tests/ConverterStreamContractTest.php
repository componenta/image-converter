<?php

declare(strict_types=1);

namespace Componenta\Image\Tests;

use Componenta\Detector\MimeType;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Image\ConversionException;
use Componenta\Image\ConvertedImage;
use Componenta\Image\ImageConverter;
use Componenta\Image\ImageConverterInterface;
use Componenta\Image\ImageConversionLimits;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

final class ConverterStreamContractTest extends TestCase
{
    public function testOneShotStreamIsReadOnlyOnceAndProbeDoesNotConsumeIt(): void
    {
        $stream = $this->stream('image-bytes', false, 0);
        $service = $this->service('image-bytes');
        self::assertFalse($service->canConvert($stream, 'webp'));
        self::assertSame('result', $service->convert($stream, 'webp')->content);
        self::assertTrue($stream->eof());
    }

    public function testSeekableStreamIsReadFromStartAndPositionIsPreserved(): void
    {
        $stream = $this->stream('image-bytes', true, 3);
        $service = $this->service('image-bytes');
        self::assertTrue($service->canConvert($stream, 'webp'));
        self::assertSame(3, $stream->tell());
        self::assertSame('result', $service->convert($stream, 'webp')->content);
        self::assertSame(3, $stream->tell());
    }

    public function testByteLimitIsEnforcedAndPositionRestoredOnFailure(): void
    {
        $stream = $this->stream('too-large', true, 2);
        $service = $this->service('unused', new ImageConversionLimits(maxInputBytes: 3));
        self::assertFalse($service->canConvert($stream, 'webp'));
        try {
            $service->convert($stream, 'webp');
            self::fail('Expected input limit failure.');
        } catch (ConversionException $error) {
            self::assertStringContainsString('byte limit', $error->getMessage());
            self::assertSame(2, $stream->tell());
        }
    }

    public function testOversizedStringIsRejectedBeforeDetection(): void
    {
        $service = $this->service('unused', new ImageConversionLimits(maxInputBytes: 3));
        $this->expectException(ConversionException::class);
        $service->convert('too-large', 'webp');
    }

    private function service(string $expected, ImageConversionLimits $limits = new ImageConversionLimits()): ImageConverter
    {
        $detector = $this->createMock(MimeTypeDetectorInterface::class);
        $detector->method('detectMimeType')->willReturnCallback(
            static function (string|StreamInterface $content) use ($expected): MimeType {
                self::assertSame($expected, $content);

                return new MimeType('image/png');
            },
        );
        $handler = $this->createMock(ImageConverterInterface::class);
        $handler->method('supports')->with('image/png')->willReturn(true);
        $handler->method('convert')->willReturnCallback(
            static function (string|StreamInterface $source, ?int $quality = null) use ($expected): ConvertedImage {
                self::assertSame($expected, $source);

                return new ConvertedImage('result', 'image/webp', 'webp');
            },
        );
        $service = new ImageConverter($detector, limits: $limits);
        $service->addConverter($handler, 'webp');

        return $service;
    }

    private function stream(string $bytes, bool $seekable, int $position): StreamInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isReadable')->willReturn(true);
        $stream->method('isSeekable')->willReturn($seekable);
        $stream->method('tell')->willReturnCallback(static function () use (&$position): int {
            return $position;
        });
        $stream->method('rewind')->willReturnCallback(static function () use (&$position): void {
            $position = 0;
        });
        $stream->method('seek')->willReturnCallback(static function (int $offset) use (&$position): void {
            $position = $offset;
        });
        $stream->method('eof')->willReturnCallback(static function () use (&$position, $bytes): bool {
            return $position >= strlen($bytes);
        });
        $stream->method('read')->willReturnCallback(static function (int $length) use (&$position, $bytes): string {
            $chunk = substr($bytes, $position, $length);
            $position += strlen($chunk);

            return $chunk;
        });
        return $stream;
    }
}
