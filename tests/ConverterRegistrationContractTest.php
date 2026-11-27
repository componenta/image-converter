<?php

declare(strict_types=1);

namespace Componenta\Image\Tests;

use Componenta\Detector\MimeType;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Image\ConvertedImage;
use Componenta\Image\ConversionException;
use Componenta\Image\ImageConverter;
use Componenta\Image\ImageConverterInterface;
use Componenta\Image\ImageConversionTargetsProviderInterface;
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

    public function testCustomAliasesEnforceTheSameCanonicalResultMimeType(): void
    {
        $detector = $this->createStub(MimeTypeDetectorInterface::class);
        $detector->method('detectMimeType')->willReturn(new MimeType('image/png'));
        $codec = $this->createStub(ImageConverterInterface::class);
        $codec->method('supports')->willReturn(true);
        $codec->method('convert')->willReturn(new ConvertedImage('bytes', 'image/jpeg', 'jpg'));
        $service = new ImageConverter($detector);
        $service->addConverter($codec, 'special', 'image/x-special');
        foreach (['special', 'image/x-special', 'x-special'] as $target) {
            try {
                $service->convert('source', $target);
                self::fail('Expected rejection of a mismatched result for ' . $target);
            } catch (ConversionException $error) {
                self::assertSame('Converter returned a different image format.', $error->getMessage());
            }
        }
    }

    public function testCustomAliasCanReturnItsDeclaredFormat(): void
    {
        $detector = $this->createStub(MimeTypeDetectorInterface::class);
        $detector->method('detectMimeType')->willReturn(new MimeType('image/png'));
        $codec = $this->createStub(ImageConverterInterface::class);
        $codec->method('supports')->willReturn(true);
        $codec->method('convert')->willReturn(new ConvertedImage('bytes', 'image/x-special', 'special'));
        $service = new ImageConverter($detector);
        $service->addConverter($codec, 'special', 'image/x-special');
        self::assertSame('image/x-special', $service->convert('source', 'special')->mimeType);
        self::assertSame('image/x-special', $service->convert('source', 'image/x-special')->mimeType);
    }

    public function testConflictingCanonicalFormatsAreRejectedAtomically(): void
    {
        $service = new ImageConverter($this->createStub(MimeTypeDetectorInterface::class));
        $codec = $this->createStub(ImageConverterInterface::class);
        try {
            $service->addConverter($codec, 'alias', 'image/jpeg', 'image/png');
            self::fail('Expected conflicting formats rejection.');
        } catch (\InvalidArgumentException) {
            self::assertFalse($service->canConvert('source', 'alias'));
            self::assertFalse($service->canConvert('source', 'jpeg'));
        }
    }

    public function testMultipleCustomAliasesWithoutCanonicalMimeAreRejected(): void
    {
        $service = new ImageConverter($this->createStub(MimeTypeDetectorInterface::class));
        $this->expectException(\InvalidArgumentException::class);
        $service->addConverter($this->createStub(ImageConverterInterface::class), 'special', 'other');
    }

    public function testAdditionalShortAliasInheritsExistingCanonicalFormat(): void
    {
        $detector = $this->createStub(MimeTypeDetectorInterface::class);
        $detector->method('detectMimeType')->willReturn(new MimeType('image/png'));
        $codec = $this->createStub(ImageConverterInterface::class);
        $codec->method('supports')->willReturn(true);
        $codec->method('convert')->willReturn(new ConvertedImage('bytes', 'image/x-special', 'special'));
        $service = new ImageConverter($detector);
        $service->addConverter($codec, 'image/x-special');
        $service->addConverter($codec, 'special', 'other');
        self::assertSame('image/x-special', $service->convert('source', 'other')->mimeType);
    }

    public function testFixedConverterCannotRegisterDifferentCanonicalFormatLater(): void
    {
        $service = new ImageConverter($this->createStub(MimeTypeDetectorInterface::class));
        $codec = $this->createStub(ImageConverterInterface::class);
        $service->addConverter($codec, 'image/x-special');
        $this->expectException(\InvalidArgumentException::class);
        $service->addConverter($codec, 'image/x-other');
    }

    public function testSingleProviderAliasDeterminesFormatForExplicitRegistration(): void
    {
        $detector = $this->createStub(MimeTypeDetectorInterface::class);
        $detector->method('detectMimeType')->willReturn(new MimeType('image/png'));
        $codec = new class implements ImageConverterInterface, ImageConversionTargetsProviderInterface {
            public array $conversionTargets {
                get => ['special'];
            }

            public function supports(string $mimeType): bool
            {
                return true;
            }

            public function convert(string|\Psr\Http\Message\StreamInterface $source, ?\Componenta\Image\ImageConversionOptions $options = null): ConvertedImage
            {
                return new ConvertedImage('bytes', 'image/special', 'special');
            }
        };
        $service = new ImageConverter($detector);
        $service->addConverter($codec, 'custom-alias');
        self::assertSame('image/special', $service->convert('source', 'custom-alias')->mimeType);
    }
}
