<?php

declare(strict_types=1);

namespace Componenta\Image\Tests;

use Componenta\Detector\MimeType;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Image\ConvertedImage;
use Componenta\Image\ConversionException;
use Componenta\Image\ImageConverter;
use Componenta\Image\ImageConverterInterface;
use PHPUnit\Framework\TestCase;

final class ConverterRegistrationContractTest extends TestCase
{
    public function testConflictingRegistrationIsAtomic(): void
    {
        $detector = $this->createStub(MimeTypeDetectorInterface::class);
        $detector->method('detectMimeType')->willReturn(new MimeType('image/png'));
        $first = $this->createStub(ImageConverterInterface::class);
        $first->method('supports')->willReturn(true);
        $second = $this->createStub(ImageConverterInterface::class);
        $service = new ImageConverter($detector);
        $service->addConverter($first, 'webp', 'image/webp');
        try {
            $service->addConverter($second, 'custom', 'webp');
            self::fail('Expected conflicting target rejection.');
        } catch (\InvalidArgumentException) {
            self::assertTrue($service->canConvert('source', 'webp'));
            self::assertFalse($service->canConvert('source', 'custom'));
        }
    }

    public function testRejectsEmptyTarget(): void
    {
        $service = new ImageConverter($this->createStub(MimeTypeDetectorInterface::class));
        $this->expectException(\InvalidArgumentException::class);
        $service->addConverter($this->createStub(ImageConverterInterface::class), 'image/');
    }

    public function testRejectsWrongResultFormat(): void
    {
        $detector = $this->createStub(MimeTypeDetectorInterface::class);
        $detector->method('detectMimeType')->willReturn(new MimeType('image/png'));
        $codec = $this->createStub(ImageConverterInterface::class);
        $codec->method('supports')->willReturn(true);
        $codec->method('convert')->willReturn(new ConvertedImage('bytes', 'image/jpeg', 'jpg'));
        $service = new ImageConverter($detector);
        $service->addConverter($codec, 'webp');
        $this->expectException(ConversionException::class);
        $service->convert('source', 'webp');
    }
}
